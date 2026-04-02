<?php
/**
 * Sync Logger
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.5.0
 * @version 1.5.0
 * @last_modified 02/04/2026
 *
 * Manages a dedicated database table for sync event logging.
 * Replaces the wp_options / wpcusn_sync_logs approach (Phase 2).
 *
 * Table schema: {prefix}wpcusn_sync_logs
 *   id         — BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   post_id    — BIGINT UNSIGNED NOT NULL
 *   task_id    — VARCHAR(64)  DEFAULT ''
 *   direction  — VARCHAR(32)  NOT NULL  (wp_to_clickup | clickup_to_wp | auto_link | cron)
 *   old_status — VARCHAR(64)  DEFAULT ''
 *   new_status — VARCHAR(64)  DEFAULT ''
 *   success    — TINYINT(1)   NOT NULL DEFAULT 0
 *   message    — TEXT
 *   created_at — DATETIME     NOT NULL  (UTC)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WPCUSN_Sync_Logger
 *
 * Static utility class — no singleton needed, all methods are class-level.
 */
class WPCUSN_Sync_Logger {

	/** @var string Base table name (without prefix). */
	const TABLE_BASE = 'wpcusn_sync_logs';

	// ------------------------------------------------------------------
	// Schema
	// ------------------------------------------------------------------

	/**
	 * Create or upgrade the sync log table.
	 *
	 * Called on plugin activation and on version upgrades.
	 * Uses dbDelta() so it is safe to call repeatedly.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table      = $wpdb->prefix . self::TABLE_BASE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			task_id    VARCHAR(64)     NOT NULL DEFAULT '',
			direction  VARCHAR(32)     NOT NULL DEFAULT '',
			old_status VARCHAR(64)     NOT NULL DEFAULT '',
			new_status VARCHAR(64)     NOT NULL DEFAULT '',
			success    TINYINT(1)      NOT NULL DEFAULT 0,
			message    TEXT,
			created_at DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY idx_post_id (post_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Record schema version so future upgrades know current state.
		update_option( 'wpcusn_db_version', '1.5.0', false );
	}

	/**
	 * Drop the sync log table on plugin uninstall.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BASE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( 'wpcusn_db_version' );
	}

	// ------------------------------------------------------------------
	// Write
	// ------------------------------------------------------------------

	/**
	 * Insert a single sync log entry.
	 *
	 * @since 1.5.0
	 *
	 * @param int    $post_id    WordPress post ID.
	 * @param string $task_id    ClickUp task ID (empty string if unknown).
	 * @param string $direction  Sync direction constant.
	 * @param string $old_status Previous status label.
	 * @param string $new_status New status label.
	 * @param bool   $success    Whether the sync succeeded.
	 * @param string $message    Optional detail message.
	 * @return int|false Inserted row ID, or false on failure.
	 */
	public static function insert(
		$post_id,
		$task_id,
		$direction,
		$old_status,
		$new_status,
		$success,      // bool|null — pass null for informational entries (no outcome yet)
		$message = ''
	) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_BASE;

		// Map null → -1 in the TINYINT column so informational entries are
		// distinguishable from both success (1) and failure (0).
		$success_int = ( null === $success ) ? null : ( $success ? 1 : 0 );

		$result = $wpdb->insert(
			$table,
			array(
				'post_id'    => (int) $post_id,
				'task_id'    => (string) $task_id,
				'direction'  => (string) $direction,
				'old_status' => (string) $old_status,
				'new_status' => (string) $new_status,
				'success'    => $success_int,
				'message'    => (string) $message,
				'created_at' => current_time( 'mysql', true ), // UTC
			),
			array( '%d', '%s', '%s', '%s', '%s', null === $success_int ? '%s' : '%d', '%s', '%s' )
		);

		// Prune old rows beyond the configured limit in the background.
		// We only prune occasionally (1-in-20 chance) to avoid adding a
		// DELETE query to every single write.
		if ( $result && wp_rand( 1, 20 ) === 1 ) {
			self::prune();
		}

		return $result ? $wpdb->insert_id : false;
	}

	// ------------------------------------------------------------------
	// Read
	// ------------------------------------------------------------------

	/**
	 * Retrieve recent log entries.
	 *
	 * @since 1.5.0
	 *
	 * @param int $limit  Maximum rows to return (default 200).
	 * @param int $offset Offset for pagination (default 0).
	 * @return array Array of row objects.
	 */
	public static function get_logs( $limit = 200, $offset = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_BASE;
		$limit = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Return the total number of log rows.
	 *
	 * @since 1.5.0
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BASE;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	// ------------------------------------------------------------------
	// Maintenance
	// ------------------------------------------------------------------

	/**
	 * Delete rows beyond the configured log limit.
	 *
	 * Keeps the newest N rows (configurable via wpcusn_log_limit option,
	 * default 500). Far more efficient than wp_options serialised blobs.
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function prune() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_BASE;
		$limit = max( 50, (int) get_option( 'wpcusn_log_limit', 500 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE id NOT IN (
					SELECT id FROM (
						SELECT id FROM {$table} ORDER BY created_at DESC LIMIT %d
					) AS keep_rows
				 )",
				$limit
			)
		);
	}

	/**
	 * Delete all log rows (admin action / reset).
	 *
	 * @since 1.5.0
	 * @return void
	 */
	public static function clear() {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_BASE;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}
