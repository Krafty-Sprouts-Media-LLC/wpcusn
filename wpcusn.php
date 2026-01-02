<?php
/**
 * Plugin Name: WPCUSN - WordPress ClickUp Sync-nator
 * Plugin URI: https://github.com/Krafty-Sprouts-Media-LLC/wpcusn
 * Description: Two-way status synchronization between WordPress and ClickUp
 * Version: 1.2.7
 * Author: Krafty Sprouts Media, LLC
 * Author URI: https://animalofthings.com
 * GitHub URI: https://github.com/Krafty-Sprouts-Media-LLC/wpcusn
 * Text Domain: wpcusn
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('WPCUSN_VERSION', '1.2.7');
define('WPCUSN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPCUSN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPCUSN_PLUGIN_FILE', __FILE__);
define('WPCUSN_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class WPCUSN
{
	/**
	 * Single instance of the plugin
	 *
	 * @var WPCUSN
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN
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
		$this->init();
	}

	/**
	 * Initialize plugin
	 *
	 * @since 1.0.0
	 */
	private function init()
	{
		// Load plugin update checker
		$this->load_update_checker();

		// Load plugin files
		$this->load_dependencies();

		// Initialize components
		add_action('plugins_loaded', array($this, 'load_components'));
	}

	/**
	 * Load plugin update checker
	 *
	 * @since 1.0.0
	 */
	private function load_update_checker()
	{
		// Try root path first (user added plugin-update-checker-master)
		$update_checker_path = WPCUSN_PLUGIN_DIR . 'plugin-update-checker-master/plugin-update-checker.php';
		if (!file_exists($update_checker_path)) {
			// Fallback to vendor path
			$update_checker_path = WPCUSN_PLUGIN_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		}
		if (file_exists($update_checker_path)) {
			require_once $update_checker_path;
			// Use buildFromHeader which reads GitHub URI from plugin header automatically
			$update_checker = \YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildFromHeader(
				WPCUSN_PLUGIN_FILE,
				array(
					'slug' => 'wpcusn',
					'checkPeriod' => 1, // Check every hour instead of default 12 hours
				)
			);
		}
	}

	/**
	 * Load plugin dependencies
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies()
	{
		require_once WPCUSN_PLUGIN_DIR . 'includes/class-clickup-api.php';
		require_once WPCUSN_PLUGIN_DIR . 'includes/class-clickup-oauth.php';
		require_once WPCUSN_PLUGIN_DIR . 'includes/class-status-mapper.php';
		require_once WPCUSN_PLUGIN_DIR . 'includes/class-task-linker.php';
		require_once WPCUSN_PLUGIN_DIR . 'includes/class-webhook-handler.php';
		require_once WPCUSN_PLUGIN_DIR . 'admin/class-settings-page.php';
		require_once WPCUSN_PLUGIN_DIR . 'admin/class-post-meta-box.php';
	}

	/**
	 * Load and initialize components
	 *
	 * @since 1.0.0
	 */
	public function load_components()
	{
		// Initialize admin
		if (is_admin()) {
			WPCUSN_Settings_Page::get_instance();
			WPCUSN_Post_Meta_Box::get_instance();
		}

		// Initialize OAuth
		WPCUSN_ClickUp_OAuth::get_instance();

		// Initialize API
		WPCUSN_ClickUp_API::get_instance();

		// Initialize status mapper
		WPCUSN_Status_Mapper::get_instance();

		// Initialize task linker
		WPCUSN_Task_Linker::get_instance();

		// Initialize webhook handler
		WPCUSN_Webhook_Handler::get_instance();
	}
}

// Initialize plugin
WPCUSN::get_instance();

