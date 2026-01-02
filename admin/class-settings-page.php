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
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
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

		// Handle disconnect
		if ( isset( $_POST['wpcusn_disconnect'] ) && check_admin_referer( 'wpcusn_disconnect' ) ) {
			$oauth = WPCUSN_ClickUp_OAuth::get_instance();
			$oauth->disconnect();
			wp_safe_redirect( admin_url( 'options-general.php?page=wpcusn&disconnected=1' ) );
			exit;
		}
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
	}

	/**
	 * Render settings page
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page() {
		include WPCUSN_PLUGIN_DIR . 'admin/views/settings-page.php';
	}
}

