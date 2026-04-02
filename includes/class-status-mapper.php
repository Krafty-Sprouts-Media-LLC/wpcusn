<?php
/**
 * Status Mapper
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.5.0
 * @last_modified 02/04/2026
 *
 * Handles status mapping between WordPress and ClickUp.
 *
 * PHASE 2 CHANGE (02/04/2026):
 * sync_to_clickup() now only validates the transition and schedules a
 * wp_schedule_single_event() cron job, returning to the browser immediately.
 * The actual ClickUp API call happens in run_async_sync() on the next
 * WP-Cron tick — completely off the main admin thread.
 * All logging now uses WPCUSN_Sync_Logger (dedicated DB table) instead
 * of the wpcusn_sync_logs wp_options key.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status Mapper Class
 */
class WPCUSN_Status_Mapper {

	/**
	 * Single instance
	 *
	 * @var WPCUSN_Status_Mapper
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_Status_Mapper
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
		add_action( 'transition_post_status', array( $this, 'sync_to_clickup' ), 10, 3 );
	}

	// ------------------------------------------------------------------
	// Status maps
	// ------------------------------------------------------------------

	/**
	 * Map WordPress status to ClickUp status
	 *
	 * @since 1.0.0
	 * @param string $wp_status WordPress post status.
	 * @return string ClickUp status name, or empty string if unmapped.
	 */
	public function wp_to_clickup( $wp_status ) {
		$mapping = array(
			'draft'       => 'IN PROGRESS',
			'pending'     => 'PENDING',
			'ready'       => 'READY',
			'schedulable' => 'SCHEDULED',
			'scheduled'   => 'SCHEDULED',
			'future'      => 'SCHEDULED',
			'publish'     => 'PUBLISHED',
		);

		return isset( $mapping[ $wp_status ] ) ? $mapping[ $wp_status ] : '';
	}

	/**
	 * Map ClickUp status to WordPress status
	 *
	 * @since 1.0.0
	 * @param string $clickup_status ClickUp status name.
	 * @return string WordPress post status.
	 */
	public function clickup_to_wp( $clickup_status ) {
		$mapping = array(
			'TO DO'       => 'draft',
			'IN PROGRESS' => 'draft',
			'READY'       => 'ready',
			'PENDING'     => 'pending',
			'SCHEDULED'   => 'schedulable',
			'PUBLISHED'   => 'publish',
		);

		return isset( $mapping[ $clickup_status ] ) ? $mapping[ $clickup_status ] : 'draft';
	}

	// ------------------------------------------------------------------
	// Sync: schedule (fast — runs on the admin thread)
	// ------------------------------------------------------------------

	/**
	 * Handle WordPress post status transition.
	 *
	 * PHASE 2 (02/04/2026): All heavy work (API calls) is deferred to
	 * run_async_sync() via wp_schedule_single_event(). This method only
	 * validates the transition and enqueues the job, so it returns to the
	 * browser in milliseconds instead of blocking for up to 10 seconds.
	 *
	 * @since 1.0.0
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function sync_to_clickup( $new_status, $old_status, $post ) {
		// Check if WP → ClickUp sync is enabled.
		if ( ! get_option( 'wpcusn_sync_wp_to_clickup', true ) ) {
			return;
		}

		// Only sync for posts.
		if ( 'post' !== $post->post_type ) {
			return;
		}

		// Skip if status hasn't changed.
		if ( $new_status === $old_status ) {
			return;
		}

		// Must have a mapped ClickUp status — fail fast before scheduling.
		$clickup_status = $this->wp_to_clickup( $new_status );
		if ( ! $clickup_status ) {
			WPCUSN_Sync_Logger::insert(
				$post->ID, '', 'wp_to_clickup', $old_status, $new_status, false,
				"WordPress status '{$new_status}' has no ClickUp mapping — sync skipped."
			);
			return;
		}

		// Must have a linked task ID — fail fast before scheduling.
		$task_id = get_post_meta( $post->ID, '_clickup_task_id', true );
		if ( ! $task_id ) {
			WPCUSN_Sync_Logger::insert(
				$post->ID, '', 'wp_to_clickup', $old_status, $new_status, false,
				'No ClickUp task linked — sync skipped.'
			);
			return;
		}

		// All guards passed. Schedule the API work for the next cron tick.
		// wp_schedule_single_event() deduplicates: if the exact same event
		// with the same args is already pending it will not be duplicated.
		wp_schedule_single_event(
			time(),
			'wpcusn_do_sync_to_clickup',
			array( $post->ID, $task_id, $old_status, $new_status )
		);

		// Spawn cron immediately so the job runs on the CURRENT page load's
		// shutdown rather than waiting for the next organic visitor.
		if ( ! defined( 'DOING_CRON' ) ) {
			spawn_cron();
		}
	}

	// ------------------------------------------------------------------
	// Sync: execute (slow — runs inside WP-Cron, off the admin thread)
	// ------------------------------------------------------------------

	/**
	 * Execute the deferred ClickUp status update.
	 *
	 * Called by the wpcusn_do_sync_to_clickup cron action registered in
	 * wpcusn.php. Runs completely outside of the admin request cycle.
	 *
	 * @since 1.5.0
	 * @param int    $post_id    WordPress post ID.
	 * @param string $task_id    ClickUp task ID.
	 * @param string $old_status Previous WordPress post status.
	 * @param string $new_status New WordPress post status.
	 */
	public function run_async_sync( $post_id, $task_id, $old_status, $new_status ) {
		$clickup_status = $this->wp_to_clickup( $new_status );
		if ( ! $clickup_status ) {
			return; // Mapping gone by the time cron fires — bail silently.
		}

		$api = WPCUSN_ClickUp_API::get_instance();

		// Resolve list ID (stored on post, or fetched from task, or setting fallback).
		$list_id = get_post_meta( $post_id, '_clickup_list_id', true );

		if ( ! $list_id ) {
			$task = $api->get_task( $task_id );
			if ( ! is_wp_error( $task ) && isset( $task['list']['id'] ) ) {
				$list_id = $task['list']['id'];
				update_post_meta( $post_id, '_clickup_list_id', $list_id );
			}
		}

		if ( ! $list_id ) {
			$list_id = get_option( 'wpcusn_list_id' );
		}

		if ( ! $list_id ) {
			WPCUSN_Sync_Logger::insert(
				$post_id, $task_id, 'wp_to_clickup', $old_status, $new_status, false,
				'Could not resolve ClickUp list ID — sync aborted.'
			);
			return;
		}

		// Validate status exists in list (transient-cached, cheap after first call).
		if ( ! $this->status_exists_in_list( $list_id, $clickup_status ) ) {
			WPCUSN_Sync_Logger::insert(
				$post_id, $task_id, 'wp_to_clickup', $old_status, $new_status, false,
				"ClickUp status '{$clickup_status}' not found in list {$list_id}."
			);
			return;
		}

		// Push the status update to ClickUp.
		$result  = $api->update_task_status( $task_id, $clickup_status );
		$success = ! is_wp_error( $result );

		WPCUSN_Sync_Logger::insert(
			$post_id, $task_id, 'wp_to_clickup', $old_status, $new_status, $success,
			$success ? '' : $result->get_error_message()
		);
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Check if a status exists in a ClickUp list.
	 *
	 * Results are cached in a 24-hour transient so the ClickUp API is
	 * only called once per list per day rather than on every status change.
	 *
	 * @since 1.0.0
	 * @param string $list_id     ClickUp list ID.
	 * @param string $status_name Status name to look for.
	 * @return bool True if the status exists.
	 */
	private function status_exists_in_list( $list_id, $status_name ) {
		// PERFORMANCE FIX (02/04/2026): Cache list statuses in a transient.
		// Previously this made a fresh API call on EVERY post status change.
		// List statuses rarely change, so 24-hour caching is safe.
		$transient_key = 'wpcusn_list_statuses_' . $list_id;
		$statuses      = get_transient( $transient_key );

		if ( false === $statuses ) {
			$api  = WPCUSN_ClickUp_API::get_instance();
			$list = $api->get_list( $list_id );

			if ( is_wp_error( $list ) || ! isset( $list['statuses'] ) ) {
				return false;
			}

			$statuses = $list['statuses'];
			set_transient( $transient_key, $statuses, DAY_IN_SECONDS );
		}

		foreach ( $statuses as $status ) {
			if ( strtoupper( $status['status'] ) === strtoupper( $status_name ) ) {
				return true;
			}
		}

		return false;
	}
}
