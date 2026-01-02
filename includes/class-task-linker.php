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

		// Search for task in ClickUp
		$api = WPCUSN_ClickUp_API::get_instance();
		$result = $api->search_tasks( $space_id ?: $list_id, $task_name, $list_id );

		if ( is_wp_error( $result ) ) {
			return;
		}

		// Find exact match
		if ( isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
			foreach ( $result['tasks'] as $task ) {
				if ( isset( $task['name'] ) && $task['name'] === $task_name ) {
					// Store task ID and list ID
					update_post_meta( $post_id, '_clickup_task_id', $task['id'] );
					update_post_meta( $post_id, '_clickup_task_name', $task['name'] );
					if ( isset( $task['list']['id'] ) ) {
						update_post_meta( $post_id, '_clickup_list_id', $task['list']['id'] );
					}
					update_post_meta( $post_id, '_clickup_linked_at', current_time( 'mysql' ) );

					// Add admin notice
					set_transient( 'wpcusn_linked_' . $post_id, true, 30 );
					break;
				}
			}
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
		delete_post_meta( $post_id, '_clickup_task_id' );
		delete_post_meta( $post_id, '_clickup_task_name' );
		delete_post_meta( $post_id, '_clickup_linked_at' );
	}
}

