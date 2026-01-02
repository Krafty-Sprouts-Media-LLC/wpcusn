<?php
/**
 * Webhook Handler
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Handles incoming webhooks from ClickUp to sync status changes.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Webhook Handler Class
 */
class WPCUSN_Webhook_Handler {
	/**
	 * Single instance
	 *
	 * @var WPCUSN_Webhook_Handler
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_Webhook_Handler
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
		add_action( 'rest_api_init', array( $this, 'register_webhook_endpoint' ) );
	}

	/**
	 * Register webhook endpoint
	 *
	 * @since 1.0.0
	 */
	public function register_webhook_endpoint() {
		register_rest_route(
			'clickup/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'verify_webhook' ),
			)
		);
	}

	/**
	 * Verify webhook request
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object
	 * @return bool
	 */
	public function verify_webhook( $request ) {
		// Basic verification - can be enhanced with webhook secret
		return true;
	}

	/**
	 * Handle webhook request
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request object
	 * @return WP_REST_Response
	 */
	public function handle_webhook( $request ) {
		$body = $request->get_json_params();

		// Log webhook received
		$event_type = $body['event'] ?? 'unknown';
		$task = $body['task'] ?? array();
		$task_id = $task['id'] ?? '';
		$task_name = $task['name'] ?? '';
		$status = $task['status']['status'] ?? '';

		// Log webhook received event
		$this->log_webhook_received( $event_type, $task_id, $task_name, $status );

		// Extract task information
		if ( ! isset( $body['event'] ) || 'taskStatusUpdated' !== $body['event'] ) {
			$this->log_webhook_error( $task_id, 'Invalid event type: ' . $event_type );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid event type' ), 400 );
		}

		if ( ! $task_id || ! $status ) {
			$this->log_webhook_error( $task_id, 'Missing task data (task_id or status)' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Missing task data' ), 400 );
		}

		// Find WordPress post by stored task ID
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 1,
				'meta_key'       => '_clickup_task_id',
				'meta_value'     => $task_id,
				'post_status'    => 'any',
			)
		);

		if ( empty( $posts ) ) {
			$this->log_webhook_error( $task_id, 'Post not found for this task' );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Post not found for this task' ), 404 );
		}

		$post = $posts[0];

		// Map ClickUp status to WordPress status
		$mapper = WPCUSN_Status_Mapper::get_instance();
		$wp_status = $mapper->clickup_to_wp( $status );

		if ( ! $wp_status ) {
			$this->log_webhook_error( $task_id, 'Status mapping not found for: ' . $status );
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Status mapping not found' ), 400 );
		}

		// Update post status
		$old_status = $post->post_status;
		wp_update_post(
			array(
				'ID'          => $post->ID,
				'post_status' => $wp_status,
			)
		);

		// Ensure task name is up to date
		if ( $task_name ) {
			update_post_meta( $post->ID, '_clickup_task_name', $task_name );
		}

		// Log sync
		$this->log_sync( $post->ID, $task_id, 'clickup_to_wp', $old_status, $wp_status, true );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Status synced successfully',
			),
			200
		);
	}


	/**
	 * Log webhook received event
	 *
	 * @since 1.2.0
	 * @param string $event_type Event type
	 * @param string $task_id Task ID
	 * @param string $task_name Task name
	 * @param string $status Status
	 */
	private function log_webhook_received( $event_type, $task_id, $task_name, $status ) {
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => 0,
			'task_id'    => $task_id,
			'direction'  => 'webhook_received',
			'old_status' => "Event: {$event_type}",
			'new_status' => "Task: '{$task_name}' ({$task_id}), Status: {$status}",
			'success'    => null,
			'timestamp'  => current_time( 'mysql' ),
		);
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}
		update_option( 'wpcusn_sync_logs', $logs );
	}

	/**
	 * Log webhook error
	 *
	 * @since 1.2.0
	 * @param string $task_id Task ID
	 * @param string $error_message Error message
	 */
	private function log_webhook_error( $task_id, $error_message ) {
		$logs = get_option( 'wpcusn_sync_logs', array() );
		$logs[] = array(
			'post_id'    => 0,
			'task_id'    => $task_id,
			'direction'  => 'webhook_error',
			'old_status' => "Task ID: {$task_id}",
			'new_status' => "Error: {$error_message}",
			'success'    => false,
			'timestamp'  => current_time( 'mysql' ),
		);
		if ( count( $logs ) > 50 ) {
			$logs = array_slice( $logs, -50 );
		}
		update_option( 'wpcusn_sync_logs', $logs );
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

