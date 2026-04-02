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
		add_action( 'save_post', array( $this, 'auto_link_task' ), 10, 2 );
		
		// Schedule cron job for periodic auto-linking
		add_action( 'wpcusn_cron_auto_link', array( $this, 'cron_auto_link_posts' ) );
		
		// Schedule the cron event if not already scheduled
		if ( ! wp_next_scheduled( 'wpcusn_cron_auto_link' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'wpcusn_cron_auto_link' );
		}
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

		// Remove common punctuation characters to avoid mismatch on trailing symbols like "?"
		$name = preg_replace( '/[[:punct:]]+/u', '', $name );

		// Collapse multiple whitespace characters into a single space.
		$name = preg_replace( '/\s+/u', ' ', $name );

		return $name;
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
	 * Auto-link post to ClickUp task
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

		// Get space ID (primary) or list ID (fallback)
		$space_id = get_option( 'wpcusn_space_id' );
		$list_id = get_option( 'wpcusn_list_id' );

		if ( ! $space_id && ! $list_id ) {
			return;
		}

		// Convert slug to task name
		$task_name = $this->slug_to_title( $slug );

		// Log auto-linking attempt
		$this->log_auto_link_attempt( $post_id, $slug, $task_name, $space_id, $list_id );

		// Search for task in ClickUp
		$api = WPCUSN_ClickUp_API::get_instance();
		$result = $api->search_tasks( $space_id ?: $list_id, $task_name, $list_id );

		if ( is_wp_error( $result ) ) {
			$this->log_auto_link_failure( $post_id, $slug, $task_name, 'API Error: ' . $result->get_error_message() );
			return;
		}

		// Find exact match using normalized comparison (case-insensitive, punctuation-insensitive)
		$matched = false;
		$search_normalized = $this->normalize_task_name( $task_name );

		if ( isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
			foreach ( $result['tasks'] as $task ) {
				// Match task name using normalized comparison.
				$task_name_match = isset( $task['name'] ) ? $task['name'] : '';
				$task_name_normalized = $this->normalize_task_name( $task_name_match );

				if ( $task_name_normalized === $search_normalized ) {
					// Store task ID and list ID
					update_post_meta( $post_id, '_clickup_task_id', $task['id'] );
					update_post_meta( $post_id, '_clickup_task_name', $task['name'] );
					if ( isset( $task['list']['id'] ) ) {
						update_post_meta( $post_id, '_clickup_list_id', $task['list']['id'] );
					}
					update_post_meta( $post_id, '_clickup_linked_at', current_time( 'mysql' ) );

					// Log successful link
					$this->log_auto_link_success( $post_id, $slug, $task['id'], $task['name'] );

					// Add admin notice
					set_transient( 'wpcusn_linked_' . $post_id, true, 30 );
					$matched = true;
					break;
				}
			}
		}

		// Log if no match found
		if ( ! $matched ) {
			$task_count = isset( $result['tasks'] ) ? count( $result['tasks'] ) : 0;
			$found_names = array();
			if ( isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
				foreach ( $result['tasks'] as $task ) {
					if ( isset( $task['name'] ) ) {
						$found_names[] = $task['name'];
					}
				}
			}
			$found_list = ! empty( $found_names ) ? " Found tasks: " . implode( ', ', array_slice( $found_names, 0, 5 ) ) . ( count( $found_names ) > 5 ? '...' : '' ) : '';
			$diagnostics = $this->format_search_diagnostics( $result );
			$this->log_auto_link_failure( $post_id, $slug, $task_name, "No matching task found. Searched for: '{$task_name}' (from slug: '{$slug}'). Returned {$task_count} candidate task(s).{$found_list}{$diagnostics}" );
		}
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
	 */
	private function log_auto_link_success( $post_id, $slug, $task_id, $task_name ) {
		// PHASE 2 (02/04/2026): Log via dedicated DB table.
		WPCUSN_Sync_Logger::insert(
			$post_id, $task_id, 'auto_link_success',
			"Slug: {$slug}",
			"Linked to: '{$task_name}' ({$task_id})",
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

