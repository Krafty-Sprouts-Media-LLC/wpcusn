<?php
/**
 * Settings Page
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Handles the admin settings page for the plugin.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Page Class
 */
class WPCUSN_Settings_Page {
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
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ), 999 );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'wp_ajax_wpcusn_get_spaces', array( $this, 'ajax_get_spaces' ) );
		add_action( 'admin_post_wpcusn_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Add settings page to admin menu
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'WPCUSN Settings', 'wpcusn' ),
			__( 'ClickUp Sync', 'wpcusn' ),
			'manage_options',
			'wpcusn',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings
	 *
	 * @since 1.0.0
	 */
	public function register_settings() {
		// OAuth settings
		register_setting( 'wpcusn_settings', 'wpcusn_oauth_client_id' );
		register_setting( 'wpcusn_settings', 'wpcusn_oauth_client_secret' );

		// API Key (fallback)
		register_setting( 'wpcusn_settings', 'wpcusn_api_key' );

		// Space ID (primary)
		register_setting( 'wpcusn_settings', 'wpcusn_space_id' );

		// List ID (optional, for limiting search to specific list)
		register_setting( 'wpcusn_settings', 'wpcusn_list_id' );

		// Redirect back to settings page after save
		add_filter( 'wp_redirect', array( $this, 'redirect_after_save' ), 10, 2 );

		// Handle disconnect moved to separate handler

		// Handle webhook creation
		if ( isset( $_POST['wpcusn_action'] ) && 'create_webhook' === $_POST['wpcusn_action'] && check_admin_referer( 'wpcusn_create_webhook' ) ) {
			$space_id = get_option( 'wpcusn_space_id' );
			$list_id = get_option( 'wpcusn_list_id' );
			$webhook_url = rest_url( 'clickup/v1/webhook' );

			if ( ! $space_id ) {
				add_settings_error( 'wpcusn_settings', 'wpcusn_webhook_error', __( 'Please configure Space ID first.', 'wpcusn' ), 'error' );
			} else {
				$api = WPCUSN_ClickUp_API::get_instance();
				$result = $api->create_webhook( $webhook_url, $space_id, $list_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error( 'wpcusn_settings', 'wpcusn_webhook_error', __( 'Failed to create webhook: ', 'wpcusn' ) . $result->get_error_message(), 'error' );
				} elseif ( isset( $result['webhook']['id'] ) ) {
					update_option( 'wpcusn_webhook_id', $result['webhook']['id'] );
					if ( isset( $result['webhook']['secret'] ) ) {
						update_option( 'wpcusn_webhook_secret', $result['webhook']['secret'] );
					}
					add_settings_error( 'wpcusn_settings', 'wpcusn_webhook_created', __( 'Webhook created successfully!', 'wpcusn' ), 'success' );
				} else {
					add_settings_error( 'wpcusn_settings', 'wpcusn_webhook_error', __( 'Unexpected response from ClickUp API.', 'wpcusn' ), 'error' );
				}
			}
		}

		// Handle webhook deletion
		if ( isset( $_POST['wpcusn_action'] ) && 'delete_webhook' === $_POST['wpcusn_action'] && check_admin_referer( 'wpcusn_delete_webhook' ) ) {
			$webhook_id = get_option( 'wpcusn_webhook_id' );
			if ( $webhook_id ) {
				$api = WPCUSN_ClickUp_API::get_instance();
				$result = $api->delete_webhook( $webhook_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error( 'wpcusn_settings', 'wpcusn_webhook_error', __( 'Failed to delete webhook: ', 'wpcusn' ) . $result->get_error_message(), 'error' );
				} else {
					delete_option( 'wpcusn_webhook_id' );
					delete_option( 'wpcusn_webhook_secret' );
					add_settings_error( 'wpcusn_settings', 'wpcusn_webhook_deleted', __( 'Webhook deleted successfully.', 'wpcusn' ), 'success' );
				}
			}
		}
	}

	/**
	 * Redirect back to settings page after save
	 *
	 * @since 1.1.0
	 * @param string $location Redirect URL
	 * @param int    $status   HTTP status code
	 * @return string
	 */
	public function redirect_after_save( $location, $status ) {
		// Only redirect if we're coming from our settings page
		// Check if this is our settings form submission
		if ( isset( $_POST['option_page'] ) && 'wpcusn_settings' === $_POST['option_page'] ) {
			// Always redirect back to our settings page
			$location = admin_url( 'options-general.php?page=wpcusn&settings-updated=1' );
		}
		// Also check if location contains options.php and we're on our page
		elseif ( strpos( $location, 'options.php' ) !== false && isset( $_GET['page'] ) && 'wpcusn' === $_GET['page'] ) {
			$location = admin_url( 'options-general.php?page=wpcusn&settings-updated=1' );
		}
		return $location;
	}

	/**
	 * Show admin notices
	 *
	 * @since 1.0.0
	 */
	public function show_admin_notices() {
		if ( ! isset( $_GET['page'] ) || 'wpcusn' !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_GET['oauth_success'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Successfully connected to ClickUp!', 'wpcusn' ) . '</p></div>';
		}

		if ( isset( $_GET['oauth_error'] ) ) {
			$error = isset( $_GET['oauth_error'] ) ? sanitize_text_field( $_GET['oauth_error'] ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'OAuth error: ', 'wpcusn' ) . esc_html( $error ) . '</p></div>';
		}

		if ( isset( $_GET['disconnected'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Disconnected from ClickUp.', 'wpcusn' ) . '</p></div>';
		}

		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wpcusn' ) . '</p></div>';
		}
	}

	/**
	 * Render settings page
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page() {
		include WPCUSN_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Handle disconnect action
	 *
	 * @since 1.0.9
	 */
	public function handle_disconnect() {
		check_admin_referer( 'wpcusn_disconnect', 'wpcusn_disconnect_nonce' );
		
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Unauthorized', 'wpcusn' ) );
		}
		
		$oauth = WPCUSN_ClickUp_OAuth::get_instance();
		$oauth->disconnect();
		wp_safe_redirect( admin_url( 'options-general.php?page=wpcusn&disconnected=1' ) );
		exit;
	}

	/**
	 * AJAX handler to get spaces
	 *
	 * @since 1.0.7
	 */
	public function ajax_get_spaces() {
		check_ajax_referer( 'wpcusn_get_spaces', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'wpcusn' ) ) );
		}

		$api = WPCUSN_ClickUp_API::get_instance();

		// Get teams first
		$teams_result = $api->get_teams();
		if ( is_wp_error( $teams_result ) ) {
			wp_send_json_error( array( 'message' => $teams_result->get_error_message() ) );
		}

		$all_spaces = array();

		// Get spaces from all teams
		if ( isset( $teams_result['teams'] ) && is_array( $teams_result['teams'] ) ) {
			foreach ( $teams_result['teams'] as $team ) {
				$team_id = $team['id'] ?? '';
				$team_name = $team['name'] ?? '';

				if ( ! $team_id ) {
					continue;
				}

				$spaces_result = $api->get_spaces( $team_id );
				if ( ! is_wp_error( $spaces_result ) && isset( $spaces_result['spaces'] ) && is_array( $spaces_result['spaces'] ) ) {
					foreach ( $spaces_result['spaces'] as $space ) {
						$all_spaces[] = array(
							'id'       => $space['id'] ?? '',
							'name'     => $space['name'] ?? '',
							'team_id'  => $team_id,
							'team_name' => $team_name,
						);
					}
				}
			}
		}

		wp_send_json_success( array( 'spaces' => $all_spaces ) );
	}
}

