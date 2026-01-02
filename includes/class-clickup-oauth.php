<?php
/**
 * ClickUp OAuth2 Handler
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Handles OAuth2 authentication flow with ClickUp.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ClickUp OAuth Class
 */
class WPCUSN_ClickUp_OAuth {
	/**
	 * Single instance
	 *
	 * @var WPCUSN_ClickUp_OAuth
	 */
	private static $instance = null;

	/**
	 * OAuth base URL
	 *
	 * @var string
	 */
	private $oauth_base = 'https://api.clickup.com/api/v2/oauth/token';

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_ClickUp_OAuth
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
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'wp_loaded', array( $this, 'maybe_refresh_token' ) );
	}

	/**
	 * Get OAuth authorization URL
	 *
	 * @since 1.0.0
	 * @return string|false
	 */
	public function get_authorization_url() {
		$client_id = get_option( 'wpcusn_oauth_client_id' );
		if ( ! $client_id ) {
			return false;
		}

		$redirect_uri = admin_url( 'options-general.php?page=wpcusn&action=oauth_callback' );
		$state = wp_create_nonce( 'wpcusn_oauth_state' );
		set_transient( 'wpcusn_oauth_state', $state, 600 );

		// ClickUp OAuth authorization endpoint (http_build_query will encode the redirect_uri automatically)
		$params = array(
			'client_id'    => $client_id,
			'redirect_uri' => $redirect_uri,
			'state'        => $state,
		);

		return 'https://app.clickup.com/api?' . http_build_query( $params );
	}

	/**
	 * Handle OAuth callback
	 *
	 * @since 1.0.0
	 */
	public function handle_oauth_callback() {
		if ( ! isset( $_GET['page'] ) || 'wpcusn' !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['action'] ) || 'oauth_callback' !== $_GET['action'] ) {
			return;
		}

		// Handle authorization code
		if ( isset( $_GET['code'] ) ) {
			$code = sanitize_text_field( $_GET['code'] );
			$state = isset( $_GET['state'] ) ? sanitize_text_field( $_GET['state'] ) : '';

			// Verify state
			$stored_state = get_transient( 'wpcusn_oauth_state' );
			if ( ! $stored_state || $stored_state !== $state ) {
				wp_die( 'Invalid state parameter. Please try again.' );
			}

			// Exchange code for tokens
			$tokens = $this->exchange_code_for_tokens( $code );
			if ( is_wp_error( $tokens ) ) {
				wp_die( 'OAuth error: ' . $tokens->get_error_message() );
			}

			// Store tokens
			update_option( 'wpcusn_oauth_access_token', $tokens['access_token'] );
			update_option( 'wpcusn_oauth_refresh_token', $tokens['refresh_token'] );
			update_option( 'wpcusn_oauth_expires_at', time() + $tokens['expires_in'] );

			// Redirect to settings page
			wp_safe_redirect( admin_url( 'options-general.php?page=wpcusn&oauth_success=1' ) );
			exit;
		}

		// Handle error
		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_text_field( $_GET['error'] );
			wp_safe_redirect( admin_url( 'options-general.php?page=wpcusn&oauth_error=' . urlencode( $error ) ) );
			exit;
		}
	}

	/**
	 * Exchange authorization code for tokens
	 *
	 * @since 1.0.0
	 * @param string $code Authorization code
	 * @return array|WP_Error
	 */
	private function exchange_code_for_tokens( $code ) {
		$client_id = get_option( 'wpcusn_oauth_client_id' );
		$client_secret = get_option( 'wpcusn_oauth_client_secret' );
		$redirect_uri = admin_url( 'options-general.php?page=wpcusn&action=oauth_callback' );

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error( 'missing_credentials', 'OAuth credentials not configured' );
		}

		$response = wp_remote_post(
			$this->oauth_base,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'code'          => $code,
					'redirect_uri'  => $redirect_uri,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['access_token'] ) ) {
			return $data;
		}

		// Better error handling
		$error_message = 'Unknown error';
		if ( isset( $data['error'] ) ) {
			$error_message = $data['error'];
		} elseif ( isset( $data['err'] ) ) {
			$error_message = $data['err'];
		} elseif ( ! empty( $body ) ) {
			$error_message = 'Response: ' . $body;
		}

		// Log the error for debugging
		error_log( 'WPCUSN OAuth Error: ' . $error_message . ' | Status: ' . $status_code . ' | Body: ' . $body );

		return new WP_Error( 'oauth_error', $error_message, array( 'status' => $status_code, 'response' => $data ) );
	}

	/**
	 * Refresh access token if needed
	 *
	 * @since 1.0.0
	 */
	public function maybe_refresh_token() {
		$expires_at = get_option( 'wpcusn_oauth_expires_at' );
		if ( ! $expires_at ) {
			return;
		}

		// Refresh if expires in less than 5 minutes
		if ( $expires_at - time() < 300 ) {
			$this->refresh_access_token();
		}
	}

	/**
	 * Refresh access token
	 *
	 * @since 1.0.0
	 * @return bool|WP_Error
	 */
	private function refresh_access_token() {
		$refresh_token = get_option( 'wpcusn_oauth_refresh_token' );
		$client_id = get_option( 'wpcusn_oauth_client_id' );
		$client_secret = get_option( 'wpcusn_oauth_client_secret' );

		if ( ! $refresh_token || ! $client_id || ! $client_secret ) {
			return new WP_Error( 'missing_credentials', 'OAuth credentials not configured' );
		}

		$response = wp_remote_post(
			$this->oauth_base,
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh_token,
				) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['access_token'] ) ) {
			update_option( 'wpcusn_oauth_access_token', $data['access_token'] );
			if ( isset( $data['refresh_token'] ) ) {
				update_option( 'wpcusn_oauth_refresh_token', $data['refresh_token'] );
			}
			update_option( 'wpcusn_oauth_expires_at', time() + $data['expires_in'] );
			return true;
		}

		return new WP_Error( 'refresh_failed', 'Failed to refresh token' );
	}

	/**
	 * Disconnect OAuth
	 *
	 * @since 1.0.0
	 */
	public function disconnect() {
		delete_option( 'wpcusn_oauth_access_token' );
		delete_option( 'wpcusn_oauth_refresh_token' );
		delete_option( 'wpcusn_oauth_expires_at' );
	}

	/**
	 * Check if OAuth is connected
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function is_connected() {
		$access_token = get_option( 'wpcusn_oauth_access_token' );
		return ! empty( $access_token );
	}
}

