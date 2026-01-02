<?php
/**
 * ClickUp API Wrapper
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Handles all ClickUp API interactions including authentication and API calls.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ClickUp API Class
 */
class WPCUSN_ClickUp_API {
	/**
	 * Single instance
	 *
	 * @var WPCUSN_ClickUp_API
	 */
	private static $instance = null;

	/**
	 * API base URL
	 *
	 * @var string
	 */
	private $api_base = 'https://api.clickup.com/api/v2';

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_ClickUp_API
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
		// Constructor
	}

	/**
	 * Get authentication headers
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_auth_headers() {
		$headers = array(
			'Content-Type' => 'application/json',
		);

		// Try OAuth token first
		$access_token = get_option( 'wpcusn_oauth_access_token' );
		if ( $access_token ) {
			$headers['Authorization'] = $access_token;
			return $headers;
		}

		// Fallback to API key
		$api_key = get_option( 'wpcusn_api_key' );
		if ( $api_key ) {
			$headers['Authorization'] = $api_key;
			return $headers;
		}

		return $headers;
	}

	/**
	 * Make API request
	 *
	 * @since 1.0.0
	 * @param string $endpoint API endpoint
	 * @param string $method HTTP method
	 * @param array  $body Request body
	 * @return array|WP_Error
	 */
	private function request( $endpoint, $method = 'GET', $body = null ) {
		$url = $this->api_base . $endpoint;
		$headers = $this->get_auth_headers();

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ( $body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $status_code >= 200 && $status_code < 300 ) {
			return $data;
		}

		$error_message = isset( $data['err'] ) ? $data['err'] : 'Unknown error';
		return new WP_Error( 'clickup_api_error', $error_message, array( 'status' => $status_code ) );
	}

	/**
	 * Get task by ID
	 *
	 * @since 1.0.0
	 * @param string $task_id Task ID
	 * @return array|WP_Error
	 */
	public function get_task( $task_id ) {
		return $this->request( "/task/{$task_id}" );
	}

	/**
	 * Update task status
	 *
	 * @since 1.0.0
	 * @param string $task_id Task ID
	 * @param string $status_id Status ID
	 * @return array|WP_Error
	 */
	public function update_task_status( $task_id, $status_id ) {
		// ClickUp API requires status in the body
		return $this->request( "/task/{$task_id}", 'PUT', array( 'status' => $status_id ) );
	}

	/**
	 * Search tasks in space (across all lists)
	 *
	 * @since 1.0.0
	 * @param string $space_id Space ID
	 * @param string $task_name Task name to search
	 * @param string $list_id Optional list ID to limit search to specific list
	 * @return array|WP_Error
	 */
	public function search_tasks( $space_id, $task_name, $list_id = null ) {
		$all_tasks = array();

		// If list_id is provided, search only that list
		if ( $list_id ) {
			$endpoint = "/list/{$list_id}/task";
			$result = $this->request( $endpoint );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
				$all_tasks = $result['tasks'];
			}
		} else {
			// Search across all lists in the space
			$lists_result = $this->get_lists( $space_id );
			if ( is_wp_error( $lists_result ) ) {
				return $lists_result;
			}

			if ( ! isset( $lists_result['lists'] ) || ! is_array( $lists_result['lists'] ) ) {
				return array( 'tasks' => array() );
			}

			// Get tasks from all lists
			foreach ( $lists_result['lists'] as $list ) {
				$list_id = $list['id'] ?? '';
				if ( ! $list_id ) {
					continue;
				}

				$endpoint = "/list/{$list_id}/task";
				$result = $this->request( $endpoint );

				if ( ! is_wp_error( $result ) && isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
					$all_tasks = array_merge( $all_tasks, $result['tasks'] );
				}
			}
		}

		// Filter tasks by exact name match
		$filtered = array();
		foreach ( $all_tasks as $task ) {
			if ( isset( $task['name'] ) && $task['name'] === $task_name ) {
				$filtered[] = $task;
			}
		}

		return array( 'tasks' => $filtered );
	}

	/**
	 * Get list by ID
	 *
	 * @since 1.0.0
	 * @param string $list_id List ID
	 * @return array|WP_Error
	 */
	public function get_list( $list_id ) {
		return $this->request( "/list/{$list_id}" );
	}

	/**
	 * Get all lists in workspace
	 *
	 * @since 1.0.0
	 * @param string $space_id Space ID
	 * @return array|WP_Error
	 */
	public function get_lists( $space_id ) {
		return $this->request( "/space/{$space_id}/list" );
	}

	/**
	 * Get teams (workspaces)
	 *
	 * @since 1.0.7
	 * @return array|WP_Error
	 */
	public function get_teams() {
		return $this->request( '/team' );
	}

	/**
	 * Get spaces for a team
	 *
	 * @since 1.0.7
	 * @param string $team_id Team/Workspace ID
	 * @return array|WP_Error
	 */
	public function get_spaces( $team_id ) {
		return $this->request( "/team/{$team_id}/space" );
	}

	/**
	 * Get user info (for testing authentication)
	 *
	 * @since 1.0.0
	 * @return array|WP_Error
	 */
	public function get_user() {
		return $this->request( '/user' );
	}

	/**
	 * Create webhook
	 *
	 * @since 1.0.6
	 * @param string $webhook_url The URL to receive webhook events
	 * @param string $space_id Space ID to subscribe to
	 * @param string $list_id Optional list ID to limit to specific list
	 * @param array  $events Array of events to subscribe to (default: taskStatusUpdated)
	 * @return array|WP_Error
	 */
	public function create_webhook( $webhook_url, $space_id, $list_id = null, $events = array( 'taskStatusUpdated' ) ) {
		$body = array(
			'endpoint' => $webhook_url,
			'events'   => $events,
		);

		// Add location filters
		if ( $list_id ) {
			$body['list_id'] = $list_id;
		}
		$body['space_id'] = $space_id;

		return $this->request( '/webhook', 'POST', $body );
	}

	/**
	 * Get webhooks
	 *
	 * @since 1.0.6
	 * @param string $team_id Team/Workspace ID
	 * @return array|WP_Error
	 */
	public function get_webhooks( $team_id ) {
		return $this->request( "/team/{$team_id}/webhook" );
	}

	/**
	 * Delete webhook
	 *
	 * @since 1.0.6
	 * @param string $webhook_id Webhook ID
	 * @return array|WP_Error
	 */
	public function delete_webhook( $webhook_id ) {
		return $this->request( "/webhook/{$webhook_id}", 'DELETE' );
	}
}

