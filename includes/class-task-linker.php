<?php
/**
 * Task Linker
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Handles automatic linking of WordPress posts to ClickUp tasks by slug.
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
			$this->log_auto_link_failure( $post_id, $slug, $task_name, "No matching task found. Searched for: '{$task_name}' (from slug: '{$slug}'). Found {$task_count} tasks but none matched case-insensitively.{$found_list}" );
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
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => $post_id,
			'task_id'    => '',
			'direction'  => 'auto_link_attempt',
			'old_status' => "Slug: {$slug}",
			'new_status' => "Searching: '{$task_name}' (Space: {$space_id}, List: " . ( $list_id ?: 'none' ) . ")",
			'success'    => null,
			'timestamp'  => current_time( 'mysql' ),
		);
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}
		update_option( 'wpcusn_sync_logs', $logs );
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
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => $post_id,
			'task_id'    => $task_id,
			'direction'  => 'auto_link_success',
			'old_status' => "Slug: {$slug}",
			'new_status' => "Linked to: '{$task_name}' ({$task_id})",
			'success'    => true,
			'timestamp'  => current_time( 'mysql' ),
		);
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}
		update_option( 'wpcusn_sync_logs', $logs );
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
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => $post_id,
			'task_id'    => '',
			'direction'  => 'auto_link_failed',
			'old_status' => "Slug: {$slug}, Searched: '{$task_name}'",
			'new_status' => "Failed: {$reason}",
			'success'    => false,
			'timestamp'  => current_time( 'mysql' ),
		);
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}
		update_option( 'wpcusn_sync_logs', $logs );
	}

	/**
	 * Log task unlink
	 *
	 * @since 1.2.0
	 * @param int    $post_id Post ID
	 * @param string $task_id Task ID that was unlinked
	 */
	private function log_auto_link_unlink( $post_id, $task_id ) {
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => $post_id,
			'task_id'    => $task_id,
			'direction'  => 'task_unlinked',
			'old_status' => "Task ID: {$task_id}",
			'new_status' => 'Unlinked',
			'success'    => true,
			'timestamp'  => current_time( 'mysql' ),
		);
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}
		update_option( 'wpcusn_sync_logs', $logs );
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

		// Log cron start
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => 0,
			'task_id'    => '',
			'direction'  => 'cron_auto_link_start',
			'old_status' => 'Cron job started',
			'new_status' => sprintf( 'Found %d unlinked posts to process', count( $unlinked_posts ) ),
			'success'    => null,
			'timestamp'  => current_time( 'mysql' ),
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

		// Log cron completion
		$logs[] = array(
			'post_id'    => 0,
			'task_id'    => '',
			'direction'  => 'cron_auto_link_complete',
			'old_status' => sprintf( 'Processed %d posts', $processed_count ),
			'new_status' => sprintf( 'Successfully linked %d posts', $linked_count ),
			'success'    => true,
			'timestamp'  => current_time( 'mysql' ),
		);

		// Apply log limit
		$log_limit = get_option( 'wpcusn_max_log_entries', 200 );
		if ( count( $logs ) > $log_limit ) {
			$logs = array_slice( $logs, -$log_limit );
		}
		update_option( 'wpcusn_sync_logs', $logs );
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

