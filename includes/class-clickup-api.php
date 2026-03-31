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

		// If list_id is provided, search only that list with full pagination.
		if ($list_id) {
			$include_closed = get_option('wpcusn_include_closed_tasks', false) ? 'true' : 'false';
			$list_search = $this->search_tasks_in_list($list_id, $task_name, $include_closed);

			if (is_wp_error($list_search)) {
				return $list_search;
			}

			if (!empty($list_search['tasks'])) {
				return $list_search;
			}

			// List ID may point at the wrong list or tasks may only appear under space-wide search.
			$configured_space_id = get_option('wpcusn_space_id');
			if ($team_id && $configured_space_id) {
				$this->log_api_debug('List search returned no matches; falling back to team/space search.');
				$team_result = $this->search_tasks_in_team($team_id, $configured_space_id, $task_name);
				if (!is_wp_error($team_result) && !empty($team_result['tasks'])) {
					return $team_result;
				}

				if (!is_wp_error($team_result) && isset($team_result['_search_meta'])) {
					$list_search['_search_meta']['fallback_to_team_search'] = true;
					$list_search['_search_meta']['fallback_team_search'] = $team_result['_search_meta'];
				}
			}

			return $list_search;
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
	 * Includes performance optimizations:
	 * - Early exit when match found
	 * - Configurable include_closed setting
	 * - Max pages limit
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
		$search_lower = strtolower(trim($task_name));

		// Configurable: include closed tasks (default OFF for performance)
		$include_closed = get_option('wpcusn_include_closed_tasks', false) ? 'true' : 'false';

		$this->log_api_debug("Team search: looking for '{$task_name}' (include_closed={$include_closed})");

		do {
			$endpoint = "/team/{$team_id}/task?page={$page}&space_ids[]={$space_id}&include_closed={$include_closed}&subtasks=true";
			$result = $this->request($endpoint);

			if (is_wp_error($result)) {
				$this->log_api_debug("Team search error on page {$page}: " . $result->get_error_message());
				if (empty($all_tasks)) {
					return $result;
				}
				break;
			}

			$page_tasks = isset($result['tasks']) ? $result['tasks'] : array();

			// EARLY EXIT: Check for exact match on each page to avoid fetching all pages
			foreach ($page_tasks as $task) {
				if (isset($task['name'])) {
					$task_lower = strtolower(trim($task['name']));
					if (
						$task_lower === $search_lower ||
						$this->normalize_for_match($task_lower) === $this->normalize_for_match($search_lower)
					) {
						$this->log_api_debug("Team search: MATCH found on page {$page}! Returning early.");
						return array(
							'tasks'        => array($task),
							'_search_meta' => array(
								'strategy'       => 'team',
								'team_id'        => $team_id,
								'space_id'       => $space_id,
								'include_closed' => $include_closed,
								'pages_scanned'  => $page + 1,
								'tasks_scanned'  => count($all_tasks) + count($page_tasks),
								'completed_scan' => false,
								'early_match'    => true,
							),
						);
					}
				}
			}

			$all_tasks = array_merge($all_tasks, $page_tasks);

			// Check if there are more pages
			$has_more = count($page_tasks) >= 100;
			$page++;

		} while ($has_more);

		$total_count = count($all_tasks);
		$this->log_api_debug("Team search complete: {$total_count} tasks scanned across {$page} page(s), exhausted all available pages");

		// No exact match found - try fuzzy matching
		$filtered = $this->filter_tasks_by_name($all_tasks, $task_name, true);
		$match_count = isset($filtered['tasks']) ? count($filtered['tasks']) : 0;

		if ($match_count > 0) {
			$this->log_api_debug("Team search: {$match_count} fuzzy match(es) found");
		}

		$filtered['_search_meta'] = array(
			'strategy'       => 'team',
			'team_id'        => $team_id,
			'space_id'       => $space_id,
			'include_closed' => $include_closed,
			'pages_scanned'  => $page,
			'tasks_scanned'  => $total_count,
			'completed_scan' => true,
			'early_match'    => false,
		);

		return $filtered;
	}

	/**
	 * Search tasks inside a single ClickUp list.
	 *
	 * @since 1.4.2
	 * @param string $list_id List ID.
	 * @param string $task_name Task name to search for.
	 * @param string $include_closed Whether closed tasks should be included.
	 * @return array|WP_Error
	 */
	private function search_tasks_in_list($list_id, $task_name, $include_closed)
	{
		$all_tasks = array();
		$page = 0;
		$search_lower = strtolower(trim($task_name));

		$this->log_api_debug("List search: looking for '{$task_name}' in list {$list_id} (include_closed={$include_closed})");

		do {
			// subtasks=true: keywords are often subtasks; default API response excludes them.
			// include_timl=true: include tasks whose home list differs but appear on this list.
			$endpoint = "/list/{$list_id}/task?page={$page}&include_closed={$include_closed}&subtasks=true&include_timl=true";
			$result = $this->request($endpoint);

			if (is_wp_error($result)) {
				$this->log_api_debug("List search error on page {$page}: " . $result->get_error_message());
				if (empty($all_tasks)) {
					return $result;
				}
				break;
			}

			$page_tasks = isset($result['tasks']) ? $result['tasks'] : array();

			foreach ($page_tasks as $task) {
				if (isset($task['name'])) {
					$task_lower = strtolower(trim($task['name']));
					if (
						$task_lower === $search_lower ||
						$this->normalize_for_match($task_lower) === $this->normalize_for_match($search_lower)
					) {
						$this->log_api_debug("List search: MATCH found on page {$page}! Returning early.");
						return array(
							'tasks'        => array($task),
							'_search_meta' => array(
								'strategy'       => 'list',
								'list_id'        => $list_id,
								'include_closed' => $include_closed,
								'pages_scanned'  => $page + 1,
								'tasks_scanned'  => count($all_tasks) + count($page_tasks),
								'completed_scan' => false,
								'early_match'    => true,
							),
						);
					}
				}
			}

			$all_tasks = array_merge($all_tasks, $page_tasks);

			$has_more = count($page_tasks) >= 100;
			$page++;

		} while ($has_more);

		$this->log_api_debug("List search complete: " . count($all_tasks) . " tasks scanned across {$page} page(s), exhausted all available pages");

		$filtered = $this->filter_tasks_by_name($all_tasks, $task_name, true);
		$filtered['_search_meta'] = array(
			'strategy'       => 'list',
			'list_id'        => $list_id,
			'include_closed' => $include_closed,
			'pages_scanned'  => $page,
			'tasks_scanned'  => count($all_tasks),
			'completed_scan' => true,
			'early_match'    => false,
		);

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

		// Get configurable limits
		$log_limit = (int) get_option('wpcusn_log_limit', 200);
		$retention_days = (int) get_option('wpcusn_log_retention_days', 7);

		// Time-based cleanup: remove logs older than retention period
		if ($retention_days > 0) {
			$cutoff = strtotime("-{$retention_days} days");
			$logs = array_filter($logs, function ($log) use ($cutoff) {
				$timestamp = isset($log['timestamp']) ? strtotime($log['timestamp']) : 0;
				return $timestamp > $cutoff;
			});
			$logs = array_values($logs); // Re-index
		}

		// Enforce max log limit
		if (count($logs) > $log_limit) {
			$logs = array_slice($logs, -$log_limit);
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
	 * @param bool   $fuzzy_fallback If true, return partial matches when no exact match found
	 * @return array Filtered array with 'tasks' key
	 */
	private function filter_tasks_by_name($tasks, $task_name, $fuzzy_fallback = false)
	{
		$filtered = array();
		$partial_match_tasks = array();
		$partial_match_names = array();
		$search_lower = strtolower(trim($task_name));

		// Sample first 5 task names for debugging
		$samples = array();
		$count = 0;

		foreach ($tasks as $task) {
			if (isset($task['name'])) {
				$task_lower = strtolower(trim($task['name']));

				// Collect samples (first 5)
				if ($count < 5) {
					$samples[] = $task['name'];
					$count++;
				}

				// Exact match (case-insensitive, trimmed)
				if ($task_lower === $search_lower) {
					$filtered[] = $task;
				}
				// Normalized exact match - strips punctuation (e.g. "Neighbor's" → "Neighbors")
				elseif ($this->normalize_for_match($task_lower) === $this->normalize_for_match($search_lower)) {
					$filtered[] = $task;
				}
				// Partial match - task name contains search term
				elseif (strpos($task_lower, $search_lower) !== false) {
					$partial_match_tasks[] = $task;
					$partial_match_names[] = $task['name'];
				}
				// Partial match - search term contains task name
				elseif (strpos($search_lower, $task_lower) !== false) {
					$partial_match_tasks[] = $task;
					$partial_match_names[] = $task['name'];
				}
			}
		}

		// Log samples if no exact match found
		if (empty($filtered)) {
			$this->log_api_debug("Sample task names from API: " . implode(' | ', $samples));
			if (!empty($partial_match_names)) {
				$this->log_api_debug("Partial matches found: " . implode(' | ', array_slice($partial_match_names, 0, 5)));
			}

			// Fuzzy fallback: if no exact match but have partial matches, use them
			if ($fuzzy_fallback && !empty($partial_match_tasks)) {
				$this->log_api_debug("Using fuzzy match fallback: returning " . count($partial_match_tasks) . " partial match(es)");
				return array('tasks' => $partial_match_tasks);
			}
		}

		return array('tasks' => $filtered);
	}

	/**
	 * Normalize a task name for comparison by removing punctuation and collapsing whitespace.
	 * Mirrors the normalization in WPCUSN_Task_Linker::normalize_task_name().
	 *
	 * @since 1.3.9
	 * @param string $name Already-lowercased name to normalize.
	 * @return string Normalized name.
	 */
	private function normalize_for_match($name)
	{
		$name = preg_replace('/[[:punct:]]+/u', '', $name);
		return preg_replace('/\s+/u', ' ', trim($name));
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

