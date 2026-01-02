<?php
/**
 * Settings Page
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.2.0
 * @last_modified 02/01/2026
 *
 * Handles the admin settings page for the plugin.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings Page Class
 */
class WPCUSN_Settings_Page
{
	/**
	 * Single instance
	 *
	 * @var WPCUSN_Settings_Page
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_Settings_Page
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
		add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_post_wpcusn_save_settings', array($this, 'handle_settings_save'));
		add_action('admin_notices', array($this, 'show_admin_notices'));
		add_action('wp_ajax_wpcusn_get_spaces', array($this, 'ajax_get_spaces'));
		add_action('admin_post_wpcusn_disconnect', array($this, 'handle_disconnect'));
	}

	/**
	 * Add settings page to admin menu
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page()
	{
		add_options_page(
			__('WPCUSN Settings', 'wpcusn'),
			__('ClickUp Sync', 'wpcusn'),
			'manage_options',
			'wpcusn',
			array($this, 'render_settings_page')
		);
	}

	/**
	 * Register settings
	 *
	 * @since 1.0.0
	 */
	public function register_settings()
	{
		// Note: We're using a custom form handler, so register_setting is only for compatibility
		// We don't need sanitization callbacks since we handle that in handle_settings_save()
		// OAuth settings
		register_setting('wpcusn_settings', 'wpcusn_oauth_client_id', array('sanitize_callback' => 'sanitize_text_field'));
		register_setting('wpcusn_settings', 'wpcusn_oauth_client_secret', array('sanitize_callback' => 'sanitize_text_field'));

		// API Key (fallback)
		register_setting('wpcusn_settings', 'wpcusn_api_key', array('sanitize_callback' => 'sanitize_text_field'));

		// Space ID (primary)
		register_setting('wpcusn_settings', 'wpcusn_space_id', array('sanitize_callback' => 'sanitize_text_field'));

		// Team ID (used for webhook creation)
		register_setting('wpcusn_settings', 'wpcusn_team_id', array('sanitize_callback' => 'sanitize_text_field'));

		// List ID (optional, for limiting search to specific list)
		register_setting('wpcusn_settings', 'wpcusn_list_id', array('sanitize_callback' => 'sanitize_text_field'));

		// Note: Webhook creation/deletion is now handled in handle_settings_save()
	}

	/**
	 * Handle settings save via custom form handler
	 *
	 * @since 1.1.6
	 */
	public function handle_settings_save()
	{
		// Debug logging
		$this->debug_log('handle_settings_save called');

		// Check permissions
		if (!current_user_can('manage_options')) {
			wp_die(__('Unauthorized', 'wpcusn'));
		}

		// Verify nonce
		if (!isset($_POST['wpcusn_settings_nonce']) || !wp_verify_nonce($_POST['wpcusn_settings_nonce'], 'wpcusn_save_settings')) {
			wp_die(__('Security check failed', 'wpcusn'));
		}

		$this->debug_log('Nonce verified, processing settings');
		$this->debug_log('POST data keys: ' . implode(', ', array_keys($_POST)));
		$this->debug_log('Checking if disconnected transient exists: ' . (get_transient('wpcusn_disconnected_notice') ? 'YES' : 'NO'));
		
		// DEBUG: Log all POST values for space_id related fields
		$this->debug_log('DEBUG SPACE_ID - POST wpcusn_space_id (dropdown): ' . (isset($_POST['wpcusn_space_id']) ? $_POST['wpcusn_space_id'] : 'NOT SET'));
		$this->debug_log('DEBUG SPACE_ID - POST wpcusn_space_id_manual (manual input): ' . (isset($_POST['wpcusn_space_id_manual']) ? $_POST['wpcusn_space_id_manual'] : 'NOT SET'));
		$this->debug_log('DEBUG SPACE_ID - POST wpcusn_team_id: ' . (isset($_POST['wpcusn_team_id']) ? $_POST['wpcusn_team_id'] : 'NOT SET'));
		$this->debug_log('DEBUG SPACE_ID - Current DB wpcusn_space_id: ' . get_option('wpcusn_space_id', 'NOT SET'));
		$this->debug_log('DEBUG SPACE_ID - Current DB wpcusn_team_id: ' . get_option('wpcusn_team_id', 'NOT SET'));
		
		// Check if this is a webhook action WITHOUT settings fields (webhook-only submission)
		// If settings fields are present, save them even if wpcusn_action is set
		$has_settings_fields = isset($_POST['wpcusn_api_key']) || isset($_POST['wpcusn_space_id']) || isset($_POST['wpcusn_oauth_client_id']);
		$is_webhook_only = isset($_POST['wpcusn_action']) && in_array($_POST['wpcusn_action'], array('create_webhook', 'delete_webhook'), true) && !$has_settings_fields;
		
		$this->debug_log('Has settings fields: ' . ($has_settings_fields ? 'YES' : 'NO'));
		$this->debug_log('Is webhook only: ' . ($is_webhook_only ? 'YES' : 'NO'));
		
		if (!$is_webhook_only) {
			// This is a settings form submission - save all settings
			$settings = array(
				'wpcusn_oauth_client_id'     => isset($_POST['wpcusn_oauth_client_id']) ? sanitize_text_field($_POST['wpcusn_oauth_client_id']) : '',
				'wpcusn_oauth_client_secret' => isset($_POST['wpcusn_oauth_client_secret']) ? sanitize_text_field($_POST['wpcusn_oauth_client_secret']) : '',
				'wpcusn_api_key'             => isset($_POST['wpcusn_api_key']) ? sanitize_text_field($_POST['wpcusn_api_key']) : '',
				'wpcusn_space_id'            => isset($_POST['wpcusn_space_id']) ? sanitize_text_field($_POST['wpcusn_space_id']) : '',
				'wpcusn_team_id'             => isset($_POST['wpcusn_team_id']) ? sanitize_text_field($_POST['wpcusn_team_id']) : '',
				'wpcusn_list_id'             => isset($_POST['wpcusn_list_id']) ? sanitize_text_field($_POST['wpcusn_list_id']) : '',
			);

			foreach ($settings as $key => $value) {
				$old_value = get_option($key);
				
				// DEBUG: Extra logging for space_id
				if ($key === 'wpcusn_space_id') {
					$this->debug_log("=== DEBUG SPACE_ID SAVE ===");
					$this->debug_log("Key: {$key}");
					$this->debug_log("POST value (raw): '" . $value . "'");
					$this->debug_log("POST value (length): " . strlen($value));
					$this->debug_log("Old DB value: '" . $old_value . "'");
					$this->debug_log("Values match? " . ($value === $old_value ? 'YES' : 'NO'));
				}
				
				// Always update, even if value appears the same
				// update_option returns false if value is unchanged, but that's OK
				$result = update_option($key, $value);
				
				// Verify it was actually saved by reading it back
				$saved_value = get_option($key);
				$actually_saved = ($saved_value === $value);
				
				$this->debug_log("Saving {$key}: POST='" . (empty($value) ? '(empty)' : substr($value, 0, 15) . '...') . "', OLD='" . (empty($old_value) ? '(empty)' : substr($old_value, 0, 15) . '...') . "', SAVED='" . (empty($saved_value) ? '(empty)' : substr($saved_value, 0, 15) . '...') . "', update_option=" . ($result ? 'true' : 'false') . ", verified=" . ($actually_saved ? 'YES' : 'NO'));
				
				// DEBUG: Extra logging for space_id after save
				if ($key === 'wpcusn_space_id') {
					$this->debug_log("After save - DB value: '" . $saved_value . "'");
					$this->debug_log("After save - Match? " . ($saved_value === $value ? 'YES' : 'NO'));
					$this->debug_log("=== END DEBUG SPACE_ID SAVE ===");
				}
				
				if (!$actually_saved) {
					$this->debug_log("ERROR: {$key} MISMATCH! Expected: '" . $value . "', Got: '" . $saved_value . "'");
					// Force update by deleting first, then adding
					delete_option($key);
					add_option($key, $value);
					$saved_value = get_option($key);
					$this->debug_log("Force update result: " . ($saved_value === $value ? 'SUCCESS' : 'STILL FAILED - ' . $saved_value));
				}
				
				// Check if we're clearing OAuth credentials
				if (in_array($key, array('wpcusn_oauth_client_id', 'wpcusn_oauth_client_secret')) && !empty($old_value) && empty($value)) {
					$this->debug_log("WARNING: Clearing {$key} - this might trigger disconnect logic");
				}
			}
		} else {
			$this->debug_log('Webhook-only action, skipping settings save');
		}

		// Handle webhook creation/deletion if requested
		// Only process if this is NOT a main form submission (check for submit button from main form)
		// If 'submit' button is present, it's the main form, so skip webhook actions
		if (isset($_POST['wpcusn_action']) && !isset($_POST['submit'])) {
			$this->debug_log('Processing webhook action: ' . $_POST['wpcusn_action']);
			if ('create_webhook' === $_POST['wpcusn_action'] && check_admin_referer('wpcusn_create_webhook', '_wpnonce')) {
				$this->handle_webhook_creation();
			} elseif ('delete_webhook' === $_POST['wpcusn_action'] && check_admin_referer('wpcusn_delete_webhook', '_wpnonce')) {
				$this->handle_webhook_deletion();
			}
		} elseif (isset($_POST['wpcusn_action']) && isset($_POST['submit'])) {
			$this->debug_log('wpcusn_action present but submit button also present - skipping webhook action (main form submission)');
		}

		// Store settings errors in transient for display after redirect
		// WordPress Settings API stores errors automatically, but with custom handlers we need to ensure they persist
		$errors = get_settings_errors('wpcusn_settings');
		if (!empty($errors)) {
			set_transient('wpcusn_settings_errors', $errors, 30);
			$this->debug_log('Stored ' . count($errors) . ' settings errors in transient');
		}

		$this->debug_log('Settings saved, redirecting...');

		// Redirect back to settings page - explicitly remove any disconnected parameter
		$redirect_url = admin_url('options-general.php?page=wpcusn&settings-updated=1');
		$this->debug_log('Redirecting to: ' . $redirect_url);
		wp_safe_redirect($redirect_url);
		exit;
	}

	/**
	 * Handle webhook creation
	 *
	 * @since 1.1.6
	 */
	private function handle_webhook_creation()
	{
		$this->debug_log('handle_webhook_creation called');
		
		$space_id = get_option('wpcusn_space_id');
		$list_id = get_option('wpcusn_list_id');
		$team_id = get_option('wpcusn_team_id');
		$webhook_url = rest_url('clickup/v1/webhook');

		$this->debug_log("Space ID: {$space_id}, Team ID: " . ($team_id ?: 'none') . ", List ID: " . ($list_id ?: 'none') . ", Webhook URL: {$webhook_url}");

		if (!$space_id) {
			$this->debug_log('No Space ID configured');
			add_settings_error('wpcusn_settings', 'wpcusn_webhook_error', __('Please configure Space ID first.', 'wpcusn'), 'error');
			return;
		}

		// If team_id not set, try to fetch first available team
		if (empty($team_id)) {
			$api = WPCUSN_ClickUp_API::get_instance();
			$teams = $api->get_teams();
			if (!is_wp_error($teams) && isset($teams['teams'][0]['id'])) {
				$team_id = $teams['teams'][0]['id'];
				update_option('wpcusn_team_id', $team_id);
				$this->debug_log("Team ID missing; using first team: {$team_id}");
			} else {
				$this->debug_log('No team ID available for webhook creation');
			}
		}

		$api = WPCUSN_ClickUp_API::get_instance();
		$result = $api->create_webhook($webhook_url, $space_id, $list_id, $team_id);

		$this->debug_log('Webhook creation result: ' . (is_wp_error($result) ? 'WP_Error: ' . $result->get_error_message() : 'Success'));

		if (is_wp_error($result)) {
			$error_message = $result->get_error_message();
			$error_data = $result->get_error_data();
			$this->debug_log("Webhook creation failed: {$error_message}, Data: " . print_r($error_data, true));
			add_settings_error('wpcusn_settings', 'wpcusn_webhook_error', __('Failed to create webhook: ', 'wpcusn') . $error_message, 'error');
		} elseif (isset($result['webhook']['id'])) {
			$this->debug_log('Webhook created successfully, ID: ' . $result['webhook']['id']);
			update_option('wpcusn_webhook_id', $result['webhook']['id']);
			if (isset($result['webhook']['secret'])) {
				update_option('wpcusn_webhook_secret', $result['webhook']['secret']);
			}
			add_settings_error('wpcusn_settings', 'wpcusn_webhook_created', __('Webhook created successfully!', 'wpcusn'), 'success');
		} else {
			$this->debug_log('Unexpected webhook response: ' . print_r($result, true));
			add_settings_error('wpcusn_settings', 'wpcusn_webhook_error', __('Unexpected response from ClickUp API.', 'wpcusn'), 'error');
		}
	}

	/**
	 * Handle webhook deletion
	 *
	 * @since 1.1.6
	 */
	private function handle_webhook_deletion()
	{
		$this->debug_log('handle_webhook_deletion called');
		
		$webhook_id = get_option('wpcusn_webhook_id');
		if ($webhook_id) {
			$this->debug_log("Deleting webhook ID: {$webhook_id}");
			$api = WPCUSN_ClickUp_API::get_instance();
			$result = $api->delete_webhook($webhook_id);

			if (is_wp_error($result)) {
				$this->debug_log('Webhook deletion failed: ' . $result->get_error_message());
				add_settings_error('wpcusn_settings', 'wpcusn_webhook_error', __('Failed to delete webhook: ', 'wpcusn') . $result->get_error_message(), 'error');
			} else {
				$this->debug_log('Webhook deleted successfully');
				delete_option('wpcusn_webhook_id');
				delete_option('wpcusn_webhook_secret');
				add_settings_error('wpcusn_settings', 'wpcusn_webhook_deleted', __('Webhook deleted successfully.', 'wpcusn'), 'success');
			}
		} else {
			$this->debug_log('No webhook ID found to delete');
		}
	}

	/**
	 * Debug logging helper
	 *
	 * @since 1.1.6
	 * @param string $message Debug message
	 */
	private function debug_log($message)
	{
		if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
			error_log('[WPCUSN Debug] ' . $message);
		}
	}


	/**
	 * Show admin notices
	 *
	 * @since 1.0.0
	 */
	public function show_admin_notices()
	{
		if (!isset($_GET['page']) || 'wpcusn' !== $_GET['page']) {
			return;
		}

		if (isset($_GET['oauth_success'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Successfully connected to ClickUp!', 'wpcusn') . '</p></div>';
		}

		if (isset($_GET['oauth_error'])) {
			$error = isset($_GET['oauth_error']) ? sanitize_text_field($_GET['oauth_error']) : '';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('OAuth error: ', 'wpcusn') . esc_html($error) . '</p></div>';
		}

		// Show disconnected message ONLY if explicitly set in URL AND not from a settings save
		// This prevents the notice from showing when saving settings after a previous disconnect
		if (isset($_GET['disconnected']) && '1' === $_GET['disconnected'] && !isset($_GET['settings-updated'])) {
			$this->debug_log('Showing disconnected notice from URL parameter (no settings-updated present)');
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Disconnected from ClickUp.', 'wpcusn') . '</p></div>';
		} elseif (isset($_GET['disconnected']) && isset($_GET['settings-updated'])) {
			$this->debug_log('disconnected=1 in URL but settings-updated also present - NOT showing disconnected notice');
		}

		if (isset($_GET['settings-updated'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'wpcusn') . '</p></div>';
		}

		// Display settings errors from transient (for custom handler redirects)
		$stored_errors = get_transient('wpcusn_settings_errors');
		if (!empty($stored_errors)) {
			delete_transient('wpcusn_settings_errors');
			foreach ($stored_errors as $error) {
				$type = isset($error['type']) ? $error['type'] : 'error';
				$message = isset($error['message']) ? $error['message'] : '';
				if ($message) {
					echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
				}
			}
		}
		
		// Also display any current settings errors (in case they weren't stored in transient)
		settings_errors('wpcusn_settings');
	}

	/**
	 * Render settings page
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page()
	{
		include WPCUSN_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Handle disconnect action
	 *
	 * @since 1.0.9
	 */
	public function handle_disconnect()
	{
		check_admin_referer('wpcusn_disconnect', 'wpcusn_disconnect_nonce');

		if (!current_user_can('manage_options')) {
			wp_die(__('Unauthorized', 'wpcusn'));
		}

		$oauth = WPCUSN_ClickUp_OAuth::get_instance();
		$oauth->disconnect();
		// Redirect with explicit parameter instead of transient
		wp_safe_redirect(admin_url('options-general.php?page=wpcusn&disconnected=1'));
		exit;
	}

	/**
	 * AJAX handler to get spaces
	 *
	 * @since 1.0.7
	 */
	public function ajax_get_spaces()
	{
		check_ajax_referer('wpcusn_get_spaces', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('Unauthorized', 'wpcusn')));
		}

		$api = WPCUSN_ClickUp_API::get_instance();

		// Get teams first
		$teams_result = $api->get_teams();
		if (is_wp_error($teams_result)) {
			wp_send_json_error(array('message' => $teams_result->get_error_message()));
		}

		$all_spaces = array();

		// Get spaces from all teams
		if (isset($teams_result['teams']) && is_array($teams_result['teams'])) {
			foreach ($teams_result['teams'] as $team) {
				$team_id = $team['id'] ?? '';
				$team_name = $team['name'] ?? '';

				if (!$team_id) {
					continue;
				}

				$spaces_result = $api->get_spaces($team_id);
				if (!is_wp_error($spaces_result) && isset($spaces_result['spaces']) && is_array($spaces_result['spaces'])) {
					foreach ($spaces_result['spaces'] as $space) {
						$all_spaces[] = array(
							'id' => $space['id'] ?? '',
							'name' => $space['name'] ?? '',
							'team_id' => $team_id,
							'team_name' => $team_name,
						);
					}
				}
			}
		}

		wp_send_json_success(array('spaces' => $all_spaces));
	}
}

