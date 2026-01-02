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
	 * Search tasks in list
	 *
	 * @since 1.0.0
	 * @param string $list_id List ID
	 * @param string $task_name Task name to search
	 * @return array|WP_Error
	 */
	public function search_tasks( $list_id, $task_name ) {
		// ClickUp API doesn't support name filter, so we get all tasks and filter
		$endpoint = "/list/{$list_id}/task";
		$result = $this->request( $endpoint );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Filter tasks by exact name match
		if ( isset( $result['tasks'] ) && is_array( $result['tasks'] ) ) {
			$filtered = array();
			foreach ( $result['tasks'] as $task ) {
				if ( isset( $task['name'] ) && $task['name'] === $task_name ) {
					$filtered[] = $task;
				}
			}
			$result['tasks'] = $filtered;
		}

		return $result;
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
	 * Get user info (for testing authentication)
	 *
	 * @since 1.0.0
	 * @return array|WP_Error
	 */
	public function get_user() {
		return $this->request( '/user' );
	}
}

