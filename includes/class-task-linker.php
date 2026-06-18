<?php
/**
 * Task Linker
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 *
 * Handles automatic linking of WordPress posts to ClickUp tasks by slug.
 *
 * PHASE 2 CHANGE (02/04/2026):
 * All private log_auto_link_*() methods now route through WPCUSN_Sync_Logger::insert()
 * (dedicated DB table) instead of the wpcusn_sync_logs wp_options key.
 *
 * PHASE 3 CHANGE (09/04/2026):
 * auto_link_task() no longer runs synchronously on save_post. The hook now
 * only schedules a wp_schedule_single_event() cron job (wpcusn_do_auto_link)
 * and calls spawn_cron() so the admin thread returns immediately. The actual
 * ClickUp search runs inside run_async_auto_link() on the next cron tick.
 * Three additional guards were added:
 *   1. Skip trash / auto-draft / inherit post statuses (no reason to search).
 *   2. "Not found" cooldown transient per slug (6h) prevents repeated futile
 *      searches when the keyword does not exist in ClickUp.
 *   Both guards also apply inside run_async_auto_link() for safety.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Task Linker Class
 */
class WPCUSN_Task_Linker {

	/**
	 * WP-Cron schedule slug for periodic auto-link (registered via cron_schedules).
	 *
	 * @since 1.5.4
	 * @var string
	 */
	const AUTO_LINK_CRON_SCHEDULE = 'wpcusn_every_6_hours';

	/**
	 * Single instance
	 *
	 * @var WPCUSN_Task_Linker
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_Task_Linker
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_auto_link_cron_schedule' ) );

		// PHASE 3 (09/04/2026): save_post only SCHEDULES the work — it no longer
		// runs the ClickUp search inline. Zero admin-thread blocking.
		add_action( 'save_post', array( $this, 'schedule_auto_link' ), 10, 2 );

		// Cron: execute the deferred auto-link search (off admin thread).
		add_action( 'wpcusn_do_auto_link', array( $this, 'run_async_auto_link' ), 10, 2 );

		add_action( 'wpcusn_cron_auto_link', array( $this, 'cron_auto_link_posts' ) );

		$this->maybe_reschedule_auto_link_cron();
	}

	/**
	 * Register custom cron interval (~4 runs per day).
	 *
	 * @since 1.5.4
	 * @param array $schedules WP cron schedules.
	 * @return array
	 */
	public static function register_auto_link_cron_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::AUTO_LINK_CRON_SCHEDULE ] ) ) {
			$schedules[ self::AUTO_LINK_CRON_SCHEDULE ] = array(
				'interval' => 6 * HOUR_IN_SECONDS,
				'display'  => __( 'Every 6 hours (WPCUSN auto-link)', 'wpcusn' ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure auto-link cron uses the current schedule (upgrades from twicedaily, etc.).
	 *
	 * @since 1.5.4
	 * @return void
	 */
	private function maybe_reschedule_auto_link_cron() {
		$stored = get_option( 'wpcusn_auto_link_cron_interval', '' );
		if ( self::AUTO_LINK_CRON_SCHEDULE === $stored && wp_next_scheduled( 'wpcusn_cron_auto_link' ) ) {
			return;
		}

		wp_clear_scheduled_hook( 'wpcusn_cron_auto_link' );
		wp_schedule_event( time(), self::AUTO_LINK_CRON_SCHEDULE, 'wpcusn_cron_auto_link' );
		update_option( 'wpcusn_auto_link_cron_interval', self::AUTO_LINK_CRON_SCHEDULE, false );
	}

	/**
	 * Convert slug to title case
	 *
	 * @since 1.0.0
	 * @param string $slug Post slug
	 * @return string Title case string
	 */
	private function slug_to_title( $slug ) {
		// Replace hyphens with spaces - keep original case
		$title = str_replace( '-', ' ', $slug );
		return $title;
	}

	/**
	 * Normalize task name for comparison
	 *
	 * Makes matching more robust by ignoring case, extra whitespace,
	 * and punctuation like question marks at the end of sentences.
	 *
	 * @since 1.3.8
	 *
	 * @param string $name Task name to normalize.
	 * @return string Normalized task name.
	 */
	private function normalize_task_name( $name ) {
		$name = strtolower( trim( (string) $name ) );

		// Match ClickUp API normalize_for_match(): hyphens first → spaces (e.g. "4-Year-Old" vs slug "4 year old").
		$name = preg_replace( '/[-–—]+/u', ' ', $name );

		// Remove common punctuation characters to avoid mismatch on trailing symbols like "?"
		$name = preg_replace( '/[[:punct:]]+/u', '', $name );

		// Collapse multiple whitespace characters into a single space.
		$name = preg_replace( '/\s+/u', ' ', $name );

		return $name;
	}

	/**
	 * Whether a normalized string is long enough for prefix/containment matching.
	 *
	 * @since 1.5.6
	 * @param string $normalized Normalized task or slug string.
	 * @return bool
	 */
	private function is_long_enough_for_partial_match( $normalized ) {
		if ( strlen( $normalized ) >= self::PARTIAL_MATCH_MIN_CHARS ) {
			return true;
		}

		return str_word_count( $normalized ) >= self::PARTIAL_MATCH_MIN_WORDS;
	}

	/**
	 * Whether $shorter is a whole-word prefix of $longer.
	 *
	 * @since 1.5.6
	 * @param string $longer Normalized longer string.
	 * @param string $shorter Normalized shorter string.
	 * @return bool
	 */
	private function is_word_boundary_prefix( $longer, $shorter ) {
		if ( '' === $shorter || strlen( $shorter ) >= strlen( $longer ) ) {
			return false;
		}

		if ( 0 !== strpos( $longer, $shorter ) ) {
			return false;
		}

		$next_char = substr( $longer, strlen( $shorter ), 1 );

		return '' === $next_char || ' ' === $next_char;
	}

	/**
	 * Whether $needle appears inside $haystack at whole-word boundaries.
	 *
	 * @since 1.5.6
	 * @param string $haystack Normalized haystack string.
	 * @param string $needle Normalized needle string.
	 * @return bool
	 */
	private function contains_at_word_boundary( $haystack, $needle ) {
		if ( '' === $needle || strlen( $needle ) > strlen( $haystack ) ) {
			return false;
		}

		if ( $needle === $haystack ) {
			return true;
		}

		$offset     = 0;
		$needle_len = strlen( $needle );
		$hay_len    = strlen( $haystack );

		while ( $offset <= $hay_len - $needle_len ) {
			$pos = strpos( $haystack, $needle, $offset );
			if ( false === $pos ) {
				return false;
			}

			$before_ok = ( 0 === $pos ) || ( ' ' === $haystack[ $pos - 1 ] );
			$after_pos = $pos + $needle_len;
			$after_ok  = ( $after_pos === $hay_len ) || ( ' ' === $haystack[ $after_pos ] );

			if ( $before_ok && $after_ok ) {
				return true;
			}

			$offset = $pos + 1;
		}

		return false;
	}

	/**
	 * Classify how a slug-derived string relates to a ClickUp task name.
	 *
	 * Tiers: 1 = exact, 2 = prefix (AI keyword + title slug), 3 = contains elsewhere in slug.
	 *
	 * @since 1.5.6
	 * @param string $search_normalized Normalized slug-derived search string.
	 * @param string $task_normalized Normalized ClickUp task name.
	 * @return array|null {
	 *     @type int    $tier        Lower is better (1 exact, 2 prefix, 3 contains).
	 *     @type string $type        exact|prefix|contains.
	 *     @type int    $specificity Length of the shorter matching segment.
	 * }
	 */
	private function classify_slug_task_match( $search_normalized, $task_normalized ) {
		if ( '' === $search_normalized || '' === $task_normalized ) {
			return null;
		}

		if ( $task_normalized === $search_normalized ) {
			return array(
				'tier'        => 1,
				'type'        => 'exact',
				'specificity' => strlen( $task_normalized ),
			);
		}

		$shorter = ( strlen( $search_normalized ) <= strlen( $task_normalized ) ) ? $search_normalized : $task_normalized;
		$longer  = ( strlen( $search_normalized ) > strlen( $task_normalized ) ) ? $search_normalized : $task_normalized;

		if ( ! $this->is_long_enough_for_partial_match( $shorter ) ) {
			return null;
		}

		if ( $this->is_word_boundary_prefix( $longer, $shorter ) ) {
			return array(
				'tier'        => 2,
				'type'        => 'prefix',
				'specificity' => strlen( $shorter ),
			);
		}

		if (
			$this->contains_at_word_boundary( $search_normalized, $task_normalized )
			|| $this->contains_at_word_boundary( $task_normalized, $search_normalized )
		) {
			return array(
				'tier'        => 3,
				'type'        => 'contains',
				'specificity' => strlen( $shorter ),
			);
		}

		return null;
	}

	/**
	 * Whether a ClickUp task is already linked to a different WordPress post.
	 *
	 * @since 1.5.6
	 * @param string $task_id ClickUp task ID.
	 * @param int    $current_post_id Post being auto-linked.
	 * @return bool
	 */
	private function is_clickup_task_linked_to_other_post( $task_id, $current_post_id ) {
		$linked = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_clickup_task_id',
				'meta_value'     => (string) $task_id,
				'post__not_in'   => array( (int) $current_post_id ),
			)
		);

		return ! empty( $linked );
	}

	/**
	 * Pick the best single ClickUp task from search candidates.
	 *
	 * Prefers exact matches, then prefix (slug starts/ends with task name), then
	 * word-boundary containment when the task name appears elsewhere in the slug.
	 * Only auto-links when one unambiguous best candidate remains among tasks not
	 * already linked to another post.
	 *
	 * @since 1.5.6
	 * @param array  $tasks Task objects from ClickUp search.
	 * @param string $search_normalized Normalized slug-derived search string.
	 * @param int    $post_id Current post ID.
	 * @return array {
	 *     @type array|null $task       Winning task, or null.
	 *     @type string     $match_type exact|prefix|contains|''.
	 *     @type string     $reason     empty|ambiguous|already_linked_elsewhere.
	 *     @type array      $ambiguous_names Task names when reason is ambiguous.
	 * }
	 */
	private function resolve_task_match( $tasks, $search_normalized, $post_id ) {
		$empty_result = array(
			'task'             => null,
			'match_type'       => '',
			'reason'           => '',
			'ambiguous_names'  => array(),
		);

		if ( ! is_array( $tasks ) || empty( $tasks ) ) {
			return $empty_result;
		}

		$candidates = array();

		foreach ( $tasks as $task ) {
			if ( empty( $task['id'] ) || ! isset( $task['name'] ) ) {
				continue;
			}

			if ( $this->is_clickup_task_linked_to_other_post( $task['id'], $post_id ) ) {
				continue;
			}

			$task_normalized = $this->normalize_task_name( $task['name'] );
			$classification  = $this->classify_slug_task_match( $search_normalized, $task_normalized );

			if ( null === $classification ) {
				continue;
			}

			$candidates[] = array(
				'task'        => $task,
				'tier'        => $classification['tier'],
				'type'        => $classification['type'],
				'specificity' => $classification['specificity'],
			);
		}

		if ( empty( $candidates ) ) {
			return $empty_result;
		}

		$best_tier = $candidates[0]['tier'];
		foreach ( $candidates as $candidate ) {
			if ( $candidate['tier'] < $best_tier ) {
				$best_tier = $candidate['tier'];
			}
		}

		$tier_candidates = array();
		foreach ( $candidates as $candidate ) {
			if ( $candidate['tier'] === $best_tier ) {
				$tier_candidates[] = $candidate;
			}
		}

		$max_specificity = $tier_candidates[0]['specificity'];
		foreach ( $tier_candidates as $candidate ) {
			if ( $candidate['specificity'] > $max_specificity ) {
				$max_specificity = $candidate['specificity'];
			}
		}

		$best_candidates = array();
		foreach ( $tier_candidates as $candidate ) {
			if ( $candidate['specificity'] === $max_specificity ) {
				$best_candidates[] = $candidate;
			}
		}

		if ( 1 !== count( $best_candidates ) ) {
			$ambiguous_names = array();
			foreach ( $tier_candidates as $candidate ) {
				$ambiguous_names[] = $candidate['task']['name'];
			}

			return array(
				'task'            => null,
				'match_type'      => '',
				'reason'          => 'ambiguous',
				'ambiguous_names' => array_values( array_unique( $ambiguous_names ) ),
			);
		}

		return array(
			'task'            => $best_candidates[0]['task'],
			'match_type'      => $best_candidates[0]['type'],
			'reason'          => '',
			'ambiguous_names' => array(),
		);
	}

	/**
	 * Persist a successful post ↔ ClickUp task link.
	 *
	 * @since 1.5.6
	 * @param int    $post_id Post ID.
	 * @param array  $task ClickUp task payload.
	 * @param string $slug Post slug.
	 * @param string $match_type exact|prefix|contains.
	 */
	private function persist_task_link( $post_id, $task, $slug, $match_type ) {
		update_post_meta( $post_id, '_clickup_task_id', $task['id'] );
		update_post_meta( $post_id, '_clickup_task_name', $task['name'] );
		if ( isset( $task['list']['id'] ) ) {
			update_post_meta( $post_id, '_clickup_list_id', $task['list']['id'] );
		}
		update_post_meta( $post_id, '_clickup_linked_at', current_time( 'mysql' ) );

		$this->log_auto_link_success( $post_id, $slug, $task['id'], $task['name'], $match_type );

		set_transient( 'wpcusn_linked_' . $post_id, true, 30 );
	}

	/**
	 * Build a readable search diagnostics string for sync logs.
	 *
	 * @since 1.4.2
	 *
	 * @param array $result Search result payload.
	 * @return string
	 */
	private function format_search_diagnostics( $result ) {
		if ( empty( $result['_search_meta'] ) || ! is_array( $result['_search_meta'] ) ) {
			return '';
		}

		$meta = $result['_search_meta'];
		$parts = array();
		$strategy = isset( $meta['strategy'] ) ? $meta['strategy'] : 'unknown';

		if ( 'list' === $strategy && ! empty( $meta['list_id'] ) ) {
			$parts[] = 'Search path: list ' . $meta['list_id'];
		} elseif ( 'team' === $strategy ) {
			$parts[] = 'Search path: team/space';
			if ( ! empty( $meta['team_id'] ) ) {
				$parts[] = 'Team: ' . $meta['team_id'];
			}
			if ( ! empty( $meta['space_id'] ) ) {
				$parts[] = 'Space: ' . $meta['space_id'];
			}
		}

		if ( isset( $meta['pages_scanned'] ) ) {
			$parts[] = 'Pages scanned: ' . (int) $meta['pages_scanned'];
		}

		if ( isset( $meta['tasks_scanned'] ) ) {
			$parts[] = 'Tasks scanned: ' . (int) $meta['tasks_scanned'];
		}

		if ( isset( $meta['include_closed'] ) ) {
			$parts[] = 'Include closed: ' . $meta['include_closed'];
		}

		if ( ! empty( $meta['completed_scan'] ) ) {
			$parts[] = 'Scan status: exhausted all available pages';
		} elseif ( ! empty( $meta['early_match'] ) ) {
			$parts[] = 'Scan status: exact match found early';
		}

		if ( ! empty( $meta['fallback_to_team_search'] ) ) {
			$parts[] = 'Fallback to team/space search: yes';
		}

		if ( ! empty( $meta['fallback_team_search'] ) && is_array( $meta['fallback_team_search'] ) ) {
			$fallback_meta = $meta['fallback_team_search'];
			$parts[] = 'Fallback pages scanned: ' . ( isset( $fallback_meta['pages_scanned'] ) ? (int) $fallback_meta['pages_scanned'] : 0 );
			$parts[] = 'Fallback tasks scanned: ' . ( isset( $fallback_meta['tasks_scanned'] ) ? (int) $fallback_meta['tasks_scanned'] : 0 );
			if ( isset( $fallback_meta['completed_scan'] ) && $fallback_meta['completed_scan'] ) {
				$parts[] = 'Fallback status: exhausted all available pages';
			}
			if ( ! empty( $fallback_meta['partial_cap_hit'] ) ) {
				$limit = isset( $fallback_meta['partial_cap_limit'] ) ? (int) $fallback_meta['partial_cap_limit'] : 0;
				$parts[] = 'Fallback fuzzy candidate cap reached (' . $limit . ')';
			}
		}

		if ( ! empty( $meta['partial_cap_hit'] ) ) {
			$limit = isset( $meta['partial_cap_limit'] ) ? (int) $meta['partial_cap_limit'] : 0;
			$parts[] = 'Fuzzy candidate cap reached (' . $limit . '); scan stopped early for memory';
		}

		return ! empty( $parts ) ? ' Diagnostics: ' . implode( '. ', $parts ) . '.' : '';
	}

	/**
	 * Statuses in which auto-linking serves no purpose and should be skipped.
	 *
	 * - trash:      Post is being deleted — no point searching ClickUp.
	 * - auto-draft: WordPress pre-save placeholder — has no real slug yet.
	 * - inherit:    Attachment / revision — not a real post.
	 *
	 * @since 1.5.5
	 * @var array
	 */
	const SKIP_AUTO_LINK_STATUSES = array( 'trash', 'auto-draft', 'inherit' );

	/**
	 * Transient TTL for "not found" cooldown (6 hours).
	 *
	 * When a slug search returns no match, a transient is set so the same
	 * exhaustive search is not repeated on every subsequent save within the
	 * cooldown window. The 6-hour TTL aligns with the background cron interval
	 * so any newly-created ClickUp tasks will be picked up by the next cron run.
	 *
	 * @since 1.5.5
	 * @var int
	 */
	const NOT_FOUND_COOLDOWN_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Minimum normalized character length for prefix/containment slug matching.
	 *
	 * @since 1.5.6
	 * @var int
	 */
	const PARTIAL_MATCH_MIN_CHARS = 15;

	/**
	 * Minimum normalized word count when below PARTIAL_MATCH_MIN_CHARS.
	 *
	 * @since 1.5.6
	 * @var int
	 */
	const PARTIAL_MATCH_MIN_WORDS = 3;

	/**
	 * Schedule async auto-link on save_post (fast — runs on the admin thread).
	 *
	 * PHASE 3 (09/04/2026): This replaces the old direct auto_link_task() call
	 * on save_post. It validates cheaply (no API calls) and then schedules a
	 * wpcusn_do_auto_link cron event, returning to the browser immediately.
	 * The actual ClickUp search runs in run_async_auto_link() on the next
	 * WP-Cron tick — completely off the main admin thread.
	 *
	 * @since 1.5.5
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function schedule_auto_link( $post_id, $post ) {
		// Only for posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip bulk edit and Quick Edit.
		if ( isset( $_REQUEST['bulk_edit'] ) ) {
			return;
		}
		if (
			defined( 'DOING_AJAX' ) && DOING_AJAX
			&& isset( $_POST['action'] )
			&& 'inline-save' === $_POST['action']
		) {
			return;
		}

		// GUARD 1: Skip statuses where linking is pointless.
		if ( in_array( $post->post_status, self::SKIP_AUTO_LINK_STATUSES, true ) ) {
			return;
		}

		// Already linked — nothing to do.
		if ( get_post_meta( $post_id, '_clickup_task_id', true ) ) {
			return;
		}

		// No slug yet — nothing to search.
		if ( ! $post->post_name ) {
			return;
		}

		// Plugin not configured — bail fast.
		if ( ! get_option( 'wpcusn_space_id' ) && ! get_option( 'wpcusn_list_id' ) ) {
			return;
		}

		// GUARD 2: "Not found" cooldown — skip if this slug was searched recently.
		$cooldown_key = 'wpcusn_no_match_' . md5( $post->post_name );
		if ( get_transient( $cooldown_key ) ) {
			return;
		}

		// All guards passed. Schedule the API search for the next cron tick.
		wp_schedule_single_event(
			time(),
			'wpcusn_do_auto_link',
			array( $post_id, $post->post_name )
		);

		// Spawn cron immediately so the job runs on this page load's shutdown
		// rather than waiting for the next organic visitor.
		if ( ! defined( 'DOING_CRON' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Execute the deferred auto-link search (slow — runs inside WP-Cron).
	 *
	 * Called by the wpcusn_do_auto_link cron action registered in wpcusn.php.
	 * Runs completely outside of the admin request cycle.
	 *
	 * @since 1.5.5
	 * @param int    $post_id   WordPress post ID.
	 * @param string $slug      Post slug at the time schedule_auto_link() fired.
	 */
	public function run_async_auto_link( $post_id, $slug ) {
		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return;
		}

		// Re-check: may have been linked or trashed between scheduling and execution.
		if ( get_post_meta( $post_id, '_clickup_task_id', true ) ) {
			return;
		}

		if ( in_array( $post->post_status, self::SKIP_AUTO_LINK_STATUSES, true ) ) {
			return;
		}

		// Use the slug passed at schedule time; fall back to current slug.
		$slug = $slug ?: $post->post_name;
		if ( ! $slug ) {
			return;
		}

		// Run the actual search.
		$this->auto_link_task( $post_id, $post );
	}

	/**
	 * Auto-link post to ClickUp task.
	 *
	 * This is the internal search worker. It is called by:
	 *   - run_async_auto_link()  (deferred from save_post via cron)
	 *   - cron_auto_link_posts() (background batch cron every 6 hours)
	 *   - handle_try_auto_link() AJAX (manual "Try Auto-Link Now" button)
	 *
	 * It should NOT be called directly from save_post anymore — use
	 * schedule_auto_link() instead so the admin thread is not blocked.
	 *
	 * @since 1.0.0
	 * @param int     $post_id Post ID
	 * @param WP_Post $post Post object
	 */
	public function auto_link_task( $post_id, $post ) {
		// Only for posts
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Skip autosaves and revisions
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip bulk edit (Posts list → Edit → Update) and Quick Edit — core still fires
		// save_post per post; without this, every unlinked row triggers ClickUp API calls.
		if ( isset( $_REQUEST['bulk_edit'] ) ) {
			return;
		}
		if (
			defined( 'DOING_AJAX' ) && DOING_AJAX
			&& isset( $_POST['action'] )
			&& 'inline-save' === $_POST['action']
		) {
			return;
		}

		// GUARD 1: Skip statuses where linking is pointless.
		if ( in_array( $post->post_status, self::SKIP_AUTO_LINK_STATUSES, true ) ) {
			return;
		}

		// Check if already linked
		$existing_task_id = get_post_meta( $post_id, '_clickup_task_id', true );
		if ( $existing_task_id ) {
			return;
		}

		// Get post slug
		$slug = $post->post_name;
		if ( ! $slug ) {
			return;
		}

		// GUARD 2: "Not found" cooldown — skip if this slug was searched recently
		// and came back empty. The cron will retry after the cooldown expires.
		$cooldown_key = 'wpcusn_no_match_' . md5( $slug );
		if ( get_transient( $cooldown_key ) ) {
			return;
		}

		// Get space ID (primary) or list ID (fallback)
		$space_id = get_option( 'wpcusn_space_id' );
		$list_id  = get_option( 'wpcusn_list_id' );

		if ( ! $space_id && ! $list_id ) {
			return;
		}

		// Convert slug to task name
		$task_name = $this->slug_to_title( $slug );

		// Log auto-linking attempt
		$this->log_auto_link_attempt( $post_id, $slug, $task_name, $space_id, $list_id );

		// Search for task in ClickUp
		$api    = WPCUSN_ClickUp_API::get_instance();
		$result = $api->search_tasks( $space_id ?: $list_id, $task_name, $list_id );

		if ( is_wp_error( $result ) ) {
			$this->log_auto_link_failure( $post_id, $slug, $task_name, 'API Error: ' . $result->get_error_message() );
			return;
		}

		$search_normalized = $this->normalize_task_name( $task_name );
		$tasks             = isset( $result['tasks'] ) && is_array( $result['tasks'] ) ? $result['tasks'] : array();
		$match_resolution  = $this->resolve_task_match( $tasks, $search_normalized, $post_id );

		if ( ! empty( $match_resolution['task'] ) ) {
			$this->persist_task_link(
				$post_id,
				$match_resolution['task'],
				$slug,
				$match_resolution['match_type']
			);
			return;
		}

		// Log if no match found and set cooldown so the same slug is not
		// searched again within the NOT_FOUND_COOLDOWN_TTL window.
		$task_count  = count( $tasks );
		$found_names = array();
		foreach ( $tasks as $task ) {
			if ( isset( $task['name'] ) ) {
				$found_names[] = $task['name'];
			}
		}

		$found_list = ! empty( $found_names )
			? ' Found tasks: ' . implode( ', ', array_slice( $found_names, 0, 5 ) ) . ( count( $found_names ) > 5 ? '...' : '' )
			: '';

		$failure_reason = "No matching task found. Searched for: '{$task_name}' (from slug: '{$slug}'). Returned {$task_count} candidate task(s).{$found_list}";

		if ( 'ambiguous' === $match_resolution['reason'] && ! empty( $match_resolution['ambiguous_names'] ) ) {
			$failure_reason .= ' Ambiguous partial matches: ' . implode(
				', ',
				array_slice( $match_resolution['ambiguous_names'], 0, 5 )
			);
			if ( count( $match_resolution['ambiguous_names'] ) > 5 ) {
				$failure_reason .= '...';
			}
			$failure_reason .= '. Link manually or shorten the slug.';
		}

		$diagnostics = $this->format_search_diagnostics( $result );
		$this->log_auto_link_failure( $post_id, $slug, $task_name, $failure_reason . $diagnostics );

		set_transient( $cooldown_key, true, self::NOT_FOUND_COOLDOWN_TTL );
	}

	/**
	 * Manually link post to task
	 *
	 * @since 1.0.0
	 * @param int    $post_id Post ID
	 * @param string $task_id Task ID
	 * @return bool|WP_Error
	 */
	public function link_task( $post_id, $task_id ) {
		$api = WPCUSN_ClickUp_API::get_instance();
		$task = $api->get_task( $task_id );

		if ( is_wp_error( $task ) ) {
			return $task;
		}

		update_post_meta( $post_id, '_clickup_task_id', $task_id );
		update_post_meta( $post_id, '_clickup_task_name', $task['name'] ?? '' );
		update_post_meta( $post_id, '_clickup_linked_at', current_time( 'mysql' ) );

		return true;
	}

	/**
	 * Unlink task from post
	 *
	 * @since 1.0.0
	 * @param int $post_id Post ID
	 */
	public function unlink_task( $post_id ) {
		$task_id = get_post_meta( $post_id, '_clickup_task_id', true );
		delete_post_meta( $post_id, '_clickup_task_id' );
		delete_post_meta( $post_id, '_clickup_task_name' );
		delete_post_meta( $post_id, '_clickup_linked_at' );
		delete_post_meta( $post_id, '_clickup_list_id' );
		
		// Log unlink
		$this->log_auto_link_unlink( $post_id, $task_id );
	}

	/**
	 * Log auto-linking attempt
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID
	 * @param string $slug Post slug
	 * @param string $task_name Task name being searched
	 * @param string $space_id Space ID
	 * @param string $list_id List ID
	 */
	private function log_auto_link_attempt( $post_id, $slug, $task_name, $space_id, $list_id ) {
		// PHASE 2 (02/04/2026): Log via dedicated DB table.
		WPCUSN_Sync_Logger::insert(
			$post_id, '', 'auto_link_attempt',
			"Slug: {$slug}",
			"Searching: '{$task_name}' (Space: {$space_id}, List: " . ( $list_id ?: 'none' ) . ')',
			null
		);
	}

	/**
	 * Log successful auto-link
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID
	 * @param string $slug Post slug
	 * @param string $task_id Task ID
	 * @param string $task_name Task name
	 * @param string $match_type Optional match type: exact, prefix, or contains.
	 */
	private function log_auto_link_success( $post_id, $slug, $task_id, $task_name, $match_type = 'exact' ) {
		$match_label = in_array( $match_type, array( 'exact', 'prefix', 'contains' ), true )
			? $match_type
			: 'exact';

		// PHASE 2 (02/04/2026): Log via dedicated DB table.
		WPCUSN_Sync_Logger::insert(
			$post_id, $task_id, 'auto_link_success',
			"Slug: {$slug}",
			"Linked to: '{$task_name}' ({$task_id}) via {$match_label} match",
			true
		);
	}

	/**
	 * Log auto-linking failure
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID
	 * @param string $slug Post slug
	 * @param string $task_name Task name searched
	 * @param string $reason Failure reason
	 */
	private function log_auto_link_failure( $post_id, $slug, $task_name, $reason ) {
		// PHASE 2 (02/04/2026): Log via dedicated DB table.
		WPCUSN_Sync_Logger::insert(
			$post_id, '', 'auto_link_failed',
			"Slug: {$slug}, Searched: '{$task_name}'",
			"Failed: {$reason}",
			false
		);
	}

	/**
	 * Log task unlink
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID
	 * @param string $task_id Task ID that was unlinked
	 */
	private function log_auto_link_unlink( $post_id, $task_id ) {
		// PHASE 2 (02/04/2026): Log via dedicated DB table.
		WPCUSN_Sync_Logger::insert(
			$post_id, $task_id, 'task_unlinked',
			"Task ID: {$task_id}",
			'Unlinked',
			true
		);
	}

	/**
	 * Cron job to auto-link unlinked posts
	 * Checks posts with statuses: draft, ready, schedulable, scheduled, pending
	 *
	 * @since 1.3.3
	 */
	public function cron_auto_link_posts() {
		// Get space ID (primary) or list ID (fallback)
		$space_id = get_option( 'wpcusn_space_id' );
		$list_id = get_option( 'wpcusn_list_id' );

		if ( ! $space_id && ! $list_id ) {
			return;
		}

		// Statuses to check for auto-linking
		$statuses = array( 'draft', 'ready', 'schedulable', 'scheduled', 'pending' );

		// Query for unlinked posts with these statuses
		$args = array(
			'post_type'      => 'post',
			'post_status'    => $statuses,
			'posts_per_page' => 50, // Process in batches to avoid timeout
			'meta_query'     => array(
				array(
					'key'     => '_clickup_task_id',
					'compare' => 'NOT EXISTS',
				),
			),
			'fields'         => 'ids',
		);

		$unlinked_posts = get_posts( $args );
		$linked_count = 0;
		$processed_count = 0;

		// PHASE 2 (02/04/2026): Log cron start via DB table.
		WPCUSN_Sync_Logger::insert(
			0, '', 'cron_auto_link_start', 'Cron job started',
			sprintf( 'Found %d unlinked posts to process', count( $unlinked_posts ) ),
			null
		);
		foreach ( $unlinked_posts as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! $post->post_name ) {
				continue;
			}

			$processed_count++;

			// Check if already linked (double-check in case it was linked between query and processing)
			$existing_task_id = get_post_meta( $post_id, '_clickup_task_id', true );
			if ( $existing_task_id ) {
				continue;
			}

			// Attempt to auto-link
			$this->auto_link_task( $post_id, $post );

			// Check if it was successfully linked
			$new_task_id = get_post_meta( $post_id, '_clickup_task_id', true );
			if ( $new_task_id ) {
				$linked_count++;
			}
		}

		// PHASE 2 (02/04/2026): Log cron completion via DB table.
		WPCUSN_Sync_Logger::insert(
			0, '', 'cron_auto_link_complete',
			sprintf( 'Processed %d posts', $processed_count ),
			sprintf( 'Successfully linked %d posts', $linked_count ),
			true
		);
	}

	/**
	 * Clear scheduled cron event
	 * Called on plugin deactivation
	 *
	 * @since 1.3.3
	 */
	public static function clear_cron() {
		$timestamp = wp_next_scheduled( 'wpcusn_cron_auto_link' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wpcusn_cron_auto_link' );
		}
	}
}

