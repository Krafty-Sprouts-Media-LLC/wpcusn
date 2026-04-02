<?php
/**
 * Webhook Handler
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.2.0
 * @last_modified 02/04/2026
 *
 * Handles incoming webhooks from ClickUp to sync status changes.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Webhook Handler Class
 */
class WPCUSN_Webhook_Handler
{
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
	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	private function __construct()
	{
		add_action('rest_api_init', array($this, 'register_webhook_endpoint'));
	}

	/**
	 * Register webhook endpoint
	 *
	 * @since 1.0.0
	 */
	public function register_webhook_endpoint()
	{
		register_rest_route(
			'clickup/v1',
			'/webhook',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'handle_webhook'),
				'permission_callback' => array($this, 'verify_webhook'),
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
	public function verify_webhook($request)
	{
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
	public function handle_webhook($request)
	{
		// Check if ClickUp → WP sync is enabled
		if (!get_option('wpcusn_sync_clickup_to_wp', true)) {
			// Return success to prevent ClickUp from retrying
			return new WP_REST_Response(array('success' => true, 'message' => 'Sync disabled'), 200);
		}

		$body = $request->get_json_params();

		// Validate event type first
		$event_type = $body['event'] ?? 'unknown';
		if ('taskStatusUpdated' !== $event_type) {
			// Silently ignore non-status events (don't log spam)
			return new WP_REST_Response(array('success' => true, 'message' => 'Event ignored'), 200);
		}

		// Extract task data with better validation
		$task = $body['task'] ?? null;
		if (!is_array($task) || empty($task)) {
			WPCUSN_Sync_Logger::insert( 0, '', 'webhook_error', '', 'Empty task data', false, 'Webhook received with empty task data' );
			return new WP_REST_Response(array('success' => false, 'message' => 'Empty task data'), 400);
		}

		$task_id = $task['id'] ?? '';
		$task_name = $task['name'] ?? '';
		$status = isset($task['status']['status']) ? $task['status']['status'] : '';

		// PHASE 2 (02/04/2026): Log webhook receipt via DB table.
		WPCUSN_Sync_Logger::insert(
			0, $task_id, 'webhook_received', "Event: {$event_type}",
			"Task: '{$task_name}' ({$task_id}), Status: {$status}", true
		);

		// Validate required fields
		if (empty($task_id) || empty($status)) {
			WPCUSN_Sync_Logger::insert( 0, $task_id, 'webhook_error', "Task ID: {$task_id}", 'Missing required fields', false, 'Missing required fields (task_id or status)' );
			return new WP_REST_Response(array('success' => false, 'message' => 'Missing task data'), 400);
		}

		// Find WordPress post by stored task ID
		$posts = get_posts(
			array(
				'post_type' => 'post',
				'posts_per_page' => 1,
				'meta_key' => '_clickup_task_id',
				'meta_value' => $task_id,
				'post_status' => 'any',
			)
		);

		if (empty($posts)) {
			WPCUSN_Sync_Logger::insert( 0, $task_id, 'webhook_error', "Task ID: {$task_id}", 'Post not found', false, 'Post not found for this task' );
			return new WP_REST_Response(array('success' => false, 'message' => 'Post not found for this task'), 404);
		}

		$post = $posts[0];

		// Map ClickUp status to WordPress status
		$mapper = WPCUSN_Status_Mapper::get_instance();
		$wp_status = $mapper->clickup_to_wp($status);

		if (!$wp_status) {
			WPCUSN_Sync_Logger::insert( 0, $task_id, 'webhook_error', "Task ID: {$task_id}", "No mapping for: {$status}", false, 'Status mapping not found for: ' . $status );
			return new WP_REST_Response(array('success' => false, 'message' => 'Status mapping not found'), 400);
		}

		// Update post status
		$old_status = $post->post_status;
		wp_update_post(
			array(
				'ID' => $post->ID,
				'post_status' => $wp_status,
			)
		);

		// Ensure task name is up to date
		if ($task_name) {
			update_post_meta($post->ID, '_clickup_task_name', $task_name);
		}

		// PHASE 2 (02/04/2026): Log via DB table.
		WPCUSN_Sync_Logger::insert( $post->ID, $task_id, 'clickup_to_wp', $old_status, $wp_status, true );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Status synced successfully',
			),
			200
		);
	}

}

