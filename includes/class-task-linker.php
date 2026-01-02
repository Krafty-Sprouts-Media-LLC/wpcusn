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

		// Find exact match (case-insensitive)
		$matched = false;
		if ( isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
			foreach ( $result['tasks'] as $task ) {
				// Match task name (case-insensitive)
				$task_name_match = isset( $task['name'] ) ? $task['name'] : '';
				if ( strcasecmp( $task_name_match, $task_name ) === 0 ) {
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
}

