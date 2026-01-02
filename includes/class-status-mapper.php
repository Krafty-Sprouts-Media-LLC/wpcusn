<?php
/**
 * Status Mapper
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Handles status mapping between WordPress and ClickUp.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status Mapper Class
 */
class WPCUSN_Status_Mapper {
	/**
	 * Single instance
	 *
	 * @var WPCUSN_Status_Mapper
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_Status_Mapper
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
		add_action( 'transition_post_status', array( $this, 'sync_to_clickup' ), 10, 3 );
	}

	/**
	 * Map WordPress status to ClickUp status
	 *
	 * @since 1.0.0
	 * @param string $wp_status WordPress post status
	 * @return string ClickUp status name
	 */
	public function wp_to_clickup( $wp_status ) {
		$mapping = array(
			'draft'       => 'IN PROGRESS',
			'ready'       => 'READY',
			'schedulable' => 'PENDING',
			'scheduled'   => 'PENDING',
			'publish'     => 'PUBLISHED',
		);

		return isset( $mapping[ $wp_status ] ) ? $mapping[ $wp_status ] : '';
	}

	/**
	 * Map ClickUp status to WordPress status
	 *
	 * @since 1.0.0
	 * @param string $clickup_status ClickUp status name
	 * @return string WordPress post status
	 */
	public function clickup_to_wp( $clickup_status ) {
		$mapping = array(
			'TO DO'      => 'draft',
			'IN PROGRESS' => 'draft',
			'READY'      => 'ready',
			'PENDING'    => 'schedulable',
			'PUBLISHED'  => 'publish',
		);

		return isset( $mapping[ $clickup_status ] ) ? $mapping[ $clickup_status ] : 'draft';
	}

	/**
	 * Sync WordPress post status to ClickUp
	 *
	 * @since 1.0.0
	 * @param string  $new_status New post status
	 * @param string  $old_status Old post status
	 * @param WP_Post $post Post object
	 */
	public function sync_to_clickup( $new_status, $old_status, $post ) {
		// Only sync for posts
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Skip if status hasn't changed
		if ( $new_status === $old_status ) {
			return;
		}

		// Get task ID
		$task_id = get_post_meta( $post->ID, '_clickup_task_id', true );
		if ( ! $task_id ) {
			return;
		}

		// Get ClickUp status
		$clickup_status = $this->wp_to_clickup( $new_status );
		if ( ! $clickup_status ) {
			return;
		}

		// Get list ID
		$list_id = get_option( 'wpcusn_list_id' );
		if ( ! $list_id ) {
			return;
		}

		// Get status ID from ClickUp
		$status_id = $this->get_status_id( $list_id, $clickup_status );
		if ( ! $status_id ) {
			return;
		}

		// Update task status
		$api = WPCUSN_ClickUp_API::get_instance();
		$result = $api->update_task_status( $task_id, $status_id );

		// Log sync
		$this->log_sync( $post->ID, $task_id, 'wp_to_clickup', $old_status, $new_status, ! is_wp_error( $result ) );
	}

	/**
	 * Get status ID from ClickUp list
	 *
	 * @since 1.0.0
	 * @param string $list_id List ID
	 * @param string $status_name Status name
	 * @return string|false Status ID
	 */
	private function get_status_id( $list_id, $status_name ) {
		$api = WPCUSN_ClickUp_API::get_instance();
		$list = $api->get_list( $list_id );

		if ( is_wp_error( $list ) || ! isset( $list['statuses'] ) ) {
			return false;
		}

		foreach ( $list['statuses'] as $status ) {
			if ( $status['status'] === $status_name ) {
				return $status['id'];
			}
		}

		return false;
	}

	/**
	 * Log sync event
	 *
	 * @since 1.0.0
	 * @param int    $post_id Post ID
	 * @param string $task_id Task ID
	 * @param string $direction Sync direction
	 * @param string $old_status Old status
	 * @param string $new_status New status
	 * @param bool   $success Whether sync was successful
	 */
	private function log_sync( $post_id, $task_id, $direction, $old_status, $new_status, $success ) {
		// Store in option for now (will move to DB table in Phase 6)
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => $post_id,
			'task_id'    => $task_id,
			'direction'  => $direction,
			'old_status' => $old_status,
			'new_status' => $new_status,
			'success'    => $success,
			'timestamp'  => current_time( 'mysql' ),
		);

		// Keep only last 50 logs
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}

		update_option( 'wpcusn_sync_logs', $logs );
	}
}

