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
if (!defined('ABSPATH')) {
	exit;
}

/**
 * ClickUp API Class
 */
class WPCUSN_ClickUp_API
{
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
		// Constructor
	}

	/**
	 * Get authentication headers
	 *
	 * @since 1.0.0
	 * @return array
	 */
	private function get_auth_headers()
	{
		$headers = array(
			'Content-Type' => 'application/json',
		);

		// Try OAuth token first
		$access_token = get_option('wpcusn_oauth_access_token');
		if ($access_token) {
			$headers['Authorization'] = $access_token;
			return $headers;
		}

		// Fallback to API key
		$api_key = get_option('wpcusn_api_key');
		if ($api_key) {
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
	private function request($endpoint, $method = 'GET', $body = null)
	{
		$url = $this->api_base . $endpoint;
		$headers = $this->get_auth_headers();

		$args = array(
			'method' => $method,
			'headers' => $headers,
			'timeout' => 30,
		);

		if ($body && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
			$args['body'] = wp_json_encode($body);
		}

		$response = wp_remote_request($url, $args);

		if (is_wp_error($response)) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if ($status_code >= 200 && $status_code < 300) {
			return $data;
		}

		// Better error message extraction
		$error_message = 'Unknown error';
		if (isset($data['err'])) {
			$error_message = $data['err'];
		} elseif (isset($data['error'])) {
			$error_message = $data['error'];
		} elseif (isset($data['message'])) {
			$error_message = $data['message'];
		} elseif (!empty($body)) {
			$error_message = 'API Error: ' . substr($body, 0, 200);
		}

		// Log the error for debugging
		if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			error_log('[WPCUSN API Error] Endpoint: ' . $endpoint . ', Status: ' . $status_code . ', Response: ' . $body);
		}

		return new WP_Error('clickup_api_error', $error_message, array('status' => $status_code, 'response' => $data));
	}

	/**
	 * Get task by ID
	 *
	 * @since 1.0.0
	 * @param string $task_id Task ID
	 * @return array|WP_Error
	 */
	public function get_task($task_id)
	{
		return $this->request("/task/{$task_id}");
	}

	/**
	 * Update task status
	 *
	 * @since 1.0.0
	 * @param string $task_id Task ID
	 * @param string $status_id Status ID
	 * @return array|WP_Error
	 */
	public function update_task_status($task_id, $status_id)
	{
		// ClickUp API requires status in the body
		return $this->request("/task/{$task_id}", 'PUT', array('status' => $status_id));
	}

	/**
	 * Search tasks in space (across all lists, folders, and subfolders)
	 *
	 * Uses the Get Filtered Team Tasks endpoint which properly searches across
	 * all folders and lists in a space, not just direct children.
	 *
	 * @since 1.0.0
	 * @param string $space_id Space ID
	 * @param string $task_name Task name to search
	 * @param string $list_id Optional list ID to limit search to specific list
	 * @return array|WP_Error
	 */
	public function search_tasks($space_id, $task_name, $list_id = null)
	{
		// Get team ID for the workspace-wide search endpoint
		$team_id = get_option('wpcusn_team_id');

		// If list_id is provided, search only that list (direct list query is fine)
		if ($list_id) {
			$endpoint = "/list/{$list_id}/task";
			$result = $this->request($endpoint);

			if (is_wp_error($result)) {
				return $result;
			}

			$all_tasks = array();
			if (isset($result['tasks']) && is_array($result['tasks'])) {
				$all_tasks = $result['tasks'];
			}

			// Filter tasks by case-insensitive name match
			return $this->filter_tasks_by_name($all_tasks, $task_name);
		}

		// Use Get Filtered Team Tasks endpoint - searches across ALL folders and lists
		if ($team_id) {
			$result = $this->search_tasks_in_team($team_id, $space_id, $task_name);
			if (!is_wp_error($result)) {
				return $result;
			}
			// Log but continue to fallback
			if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
				error_log('[WPCUSN] Team search failed, falling back to folders/lists method: ' . $result->get_error_message());
			}
		}

		// Fallback: Search across all folders and their lists
		$all_tasks = $this->get_tasks_from_space_folders($space_id);
		if (is_wp_error($all_tasks)) {
			return $all_tasks;
		}

		// Also get direct lists (not in folders)
		$lists_result = $this->get_lists($space_id);
		if (!is_wp_error($lists_result) && isset($lists_result['lists']) && is_array($lists_result['lists'])) {
			foreach ($lists_result['lists'] as $list) {
				$lid = $list['id'] ?? '';
				if (!$lid) {
					continue;
				}

				$endpoint = "/list/{$lid}/task";
				$result = $this->request($endpoint);

				if (!is_wp_error($result) && isset($result['tasks']) && is_array($result['tasks'])) {
					$all_tasks = array_merge($all_tasks, $result['tasks']);
				}
			}
		}

		// Filter tasks by case-insensitive name match
		return $this->filter_tasks_by_name($all_tasks, $task_name);
	}

	/**
	 * Search tasks using Get Filtered Team Tasks endpoint
	 *
	 * This endpoint searches across ALL lists in a space.
	 *
	 * @since 1.2.1
	 * @param string $team_id Team/Workspace ID
	 * @param string $space_id Space ID to filter by
	 * @param string $task_name Task name to search for
	 * @return array|WP_Error
	 */
	public function search_tasks_in_team($team_id, $space_id, $task_name)
	{
		$all_tasks = array();
		$page = 0;
		$max_pages = 10; // Safety limit

		// Log the search attempt
		$this->log_api_debug("Team search starting: team_id={$team_id}, space_id={$space_id}, looking for: '{$task_name}'");

		do {
			// Use query params - ClickUp API v2 expects space_ids[] as array parameter
			$endpoint = "/team/{$team_id}/task?page={$page}&space_ids[]={$space_id}";
			$result = $this->request($endpoint);

			if (is_wp_error($result)) {
				$this->log_api_debug("Team search error on page {$page}: " . $result->get_error_message());
				// If no tasks found yet, return error; otherwise return what we have
				if (empty($all_tasks)) {
					return $result;
				}
				break;
			}

			$page_count = isset($result['tasks']) ? count($result['tasks']) : 0;
			$this->log_api_debug("Team search page {$page}: found {$page_count} tasks");

			if (isset($result['tasks']) && is_array($result['tasks'])) {
				$all_tasks = array_merge($all_tasks, $result['tasks']);
			}

			// Check if there are more pages (ClickUp returns up to 100 tasks per page)
			$has_more = isset($result['tasks']) && count($result['tasks']) >= 100;
			$page++;

		} while ($has_more && $page < $max_pages);

		$total_count = count($all_tasks);
		$this->log_api_debug("Team search complete: total {$total_count} tasks found across {$page} page(s)");

		// Filter tasks by case-insensitive name match
		$filtered = $this->filter_tasks_by_name($all_tasks, $task_name);
		$match_count = isset($filtered['tasks']) ? count($filtered['tasks']) : 0;
		$this->log_api_debug("Team search filter result: {$match_count} task(s) matched '{$task_name}'");

		return $filtered;
	}

	/**
	 * Log API debug info to sync logs for production debugging
	 *
	 * @since 1.2.1
	 * @param string $message Debug message
	 */
	private function log_api_debug($message)
	{
		$logs = get_option('wpcusn_sync_logs', array());
		$logs[] = array(
			'post_id' => 0,
			'task_id' => '',
			'direction' => 'api_debug',
			'old_status' => '',
			'new_status' => $message,
			'success' => null,
			'timestamp' => current_time('mysql'),
		);
		if (count($logs) > 50) {
			$logs = array_slice($logs, -50);
		}
		update_option('wpcusn_sync_logs', $logs);
	}

	/**
	 * Get tasks from all folders in a space
	 *
	 * @since 1.2.1
	 * @param string $space_id Space ID
	 * @return array|WP_Error Array of tasks or error
	 */
	private function get_tasks_from_space_folders($space_id)
	{
		$all_tasks = array();

		// Get all folders in the space
		$folders_result = $this->get_folders($space_id);
		if (is_wp_error($folders_result)) {
			return $folders_result;
		}

		if (!isset($folders_result['folders']) || !is_array($folders_result['folders'])) {
			return array();
		}

		// Get tasks from all lists in each folder
		foreach ($folders_result['folders'] as $folder) {
			$folder_id = $folder['id'] ?? '';
			if (!$folder_id) {
				continue;
			}

			// Get lists in this folder
			$lists_result = $this->get_folder_lists($folder_id);
			if (is_wp_error($lists_result) || !isset($lists_result['lists'])) {
				continue;
			}

			foreach ($lists_result['lists'] as $list) {
				$list_id = $list['id'] ?? '';
				if (!$list_id) {
					continue;
				}

				$endpoint = "/list/{$list_id}/task";
				$result = $this->request($endpoint);

				if (!is_wp_error($result) && isset($result['tasks']) && is_array($result['tasks'])) {
					$all_tasks = array_merge($all_tasks, $result['tasks']);
				}
			}
		}

		return $all_tasks;
	}

	/**
	 * Get folders in a space
	 *
	 * @since 1.2.1
	 * @param string $space_id Space ID
	 * @return array|WP_Error
	 */
	public function get_folders($space_id)
	{
		return $this->request("/space/{$space_id}/folder");
	}

	/**
	 * Get lists in a folder
	 *
	 * @since 1.2.1
	 * @param string $folder_id Folder ID
	 * @return array|WP_Error
	 */
	public function get_folder_lists($folder_id)
	{
		return $this->request("/folder/{$folder_id}/list");
	}

	/**
	 * Filter tasks by case-insensitive name match
	 *
	 * @since 1.2.1
	 * @param array  $tasks Array of task objects
	 * @param string $task_name Task name to match
	 * @return array Filtered array with 'tasks' key
	 */
	private function filter_tasks_by_name($tasks, $task_name)
	{
		$filtered = array();
		foreach ($tasks as $task) {
			if (isset($task['name'])) {
				// Case-insensitive match
				if (strcasecmp($task['name'], $task_name) === 0) {
					$filtered[] = $task;
				}
			}
		}
		return array('tasks' => $filtered);
	}

	/**
	 * Get list by ID
	 *
	 * @since 1.0.0
	 * @param string $list_id List ID
	 * @return array|WP_Error
	 */
	public function get_list($list_id)
	{
		return $this->request("/list/{$list_id}");
	}

	/**
	 * Get all lists in workspace
	 *
	 * @since 1.0.0
	 * @param string $space_id Space ID
	 * @return array|WP_Error
	 */
	public function get_lists($space_id)
	{
		return $this->request("/space/{$space_id}/list");
	}

	/**
	 * Get teams (workspaces)
	 *
	 * @since 1.0.7
	 * @return array|WP_Error
	 */
	public function get_teams()
	{
		return $this->request('/team');
	}

	/**
	 * Get spaces for a team
	 *
	 * @since 1.0.7
	 * @param string $team_id Team/Workspace ID
	 * @return array|WP_Error
	 */
	public function get_spaces($team_id)
	{
		return $this->request("/team/{$team_id}/space");
	}

	/**
	 * Get user info (for testing authentication)
	 *
	 * @since 1.0.0
	 * @return array|WP_Error
	 */
	public function get_user()
	{
		return $this->request('/user');
	}

	/**
	 * Create webhook
	 *
	 * @since 1.0.6
	 * @param string $webhook_url The URL to receive webhook events
	 * @param string $space_id Space ID to subscribe to
	 * @param string $list_id Optional list ID to limit to specific list
	 * @param string $team_id Team/Workspace ID (required for endpoint)
	 * @param array  $events Array of events to subscribe to (default: taskStatusUpdated)
	 * @return array|WP_Error
	 */
	public function create_webhook($webhook_url, $space_id, $list_id = null, $team_id = null, $events = array('taskStatusUpdated'))
	{
		$body = array(
			'endpoint' => $webhook_url,
			'events' => $events,
		);

		// Add location filters
		if ($list_id) {
			$body['list_id'] = $list_id;
		}
		$body['space_id'] = $space_id;

		// ClickUp webhooks are created per team
		$team_segment = $team_id ? "/team/{$team_id}" : '';

		return $this->request("{$team_segment}/webhook", 'POST', $body);
	}

	/**
	 * Get webhooks
	 *
	 * @since 1.0.7
	 * @param string $team_id Team/Workspace ID
	 * @return array|WP_Error
	 */
	public function get_webhooks($team_id)
	{
		return $this->request("/team/{$team_id}/webhook");
	}

	/**
	 * Delete webhook
	 *
	 * @since 1.0.7
	 * @param string $webhook_id Webhook ID
	 * @return array|WP_Error
	 */
	public function delete_webhook($webhook_id)
	{
		return $this->request("/webhook/{$webhook_id}", 'DELETE');
	}
}

