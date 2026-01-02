<?php
/**
 * Post Meta Box
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * Adds meta box to post editor for ClickUp sync controls.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post Meta Box Class
 */
class WPCUSN_Post_Meta_Box {
	/**
	 * Single instance
	 *
	 * @var WPCUSN_Post_Meta_Box
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 * @return WPCUSN_Post_Meta_Box
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
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'wp_ajax_wpcusn_force_sync', array( $this, 'handle_force_sync' ) );
		add_action( 'wp_ajax_wpcusn_unlink_task', array( $this, 'handle_unlink_task' ) );
	}

	/**
	 * Add meta box to post editor
	 *
	 * @since 1.0.0
	 */
	public function add_meta_box() {
		add_meta_box(
			'wpcusn-clickup-sync',
			__( 'ClickUp Sync', 'wpcusn' ),
			array( $this, 'render_meta_box' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Render meta box content
	 *
	 * @since 1.0.0
	 * @param WP_Post $post Post object
	 */
	public function render_meta_box( $post ) {
		$task_id = get_post_meta( $post->ID, '_clickup_task_id', true );
		$task_name = get_post_meta( $post->ID, '_clickup_task_name', true );
		$linked_at = get_post_meta( $post->ID, '_clickup_linked_at', true );

		wp_nonce_field( 'wpcusn_meta_box', 'wpcusn_meta_box_nonce' );
		?>

		<?php if ( $task_id ) : ?>
			<p>
				<strong><?php esc_html_e( 'Linked Task:', 'wpcusn' ); ?></strong><br />
				<?php echo esc_html( $task_name ? $task_name : $task_id ); ?>
			</p>
			<?php if ( $linked_at ) : ?>
				<p>
					<small><?php esc_html_e( 'Linked:', 'wpcusn' ); ?> <?php echo esc_html( $linked_at ); ?></small>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button" id="wpcusn-force-sync" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Force Sync Now', 'wpcusn' ); ?>
				</button>
				<button type="button" class="button" id="wpcusn-unlink-task" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'Unlink Task', 'wpcusn' ); ?>
				</button>
			</p>
			<div id="wpcusn-sync-message"></div>
		<?php else : ?>
			<p><?php esc_html_e( 'No ClickUp task linked. Task will be auto-linked when post is saved with a matching slug.', 'wpcusn' ); ?></p>
		<?php endif; ?>

		<script>
		jQuery(document).ready(function($) {
			$('#wpcusn-force-sync').on('click', function() {
				var postId = $(this).data('post-id');
				var button = $(this);
				button.prop('disabled', true).text('<?php esc_html_e( 'Syncing...', 'wpcusn' ); ?>');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wpcusn_force_sync',
						post_id: postId,
						nonce: '<?php echo esc_js( wp_create_nonce( 'wpcusn_force_sync' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							$('#wpcusn-sync-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
						} else {
							$('#wpcusn-sync-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
						button.prop('disabled', false).text('<?php esc_html_e( 'Force Sync Now', 'wpcusn' ); ?>');
					}
				});
			});

			$('#wpcusn-unlink-task').on('click', function() {
				if (!confirm('<?php esc_html_e( 'Are you sure you want to unlink this task?', 'wpcusn' ); ?>')) {
					return;
				}
				
				var postId = $(this).data('post-id');
				var button = $(this);
				button.prop('disabled', true);
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wpcusn_unlink_task',
						post_id: postId,
						nonce: '<?php echo esc_js( wp_create_nonce( 'wpcusn_unlink_task' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							location.reload();
						} else {
							alert(response.data.message);
							button.prop('disabled', false);
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Handle force sync AJAX request
	 *
	 * @since 1.0.0
	 */
	public function handle_force_sync() {
		check_ajax_referer( 'wpcusn_force_sync', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'wpcusn' ) ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found', 'wpcusn' ) ) );
		}

		$task_id = get_post_meta( $post_id, '_clickup_task_id', true );
		if ( ! $task_id ) {
			wp_send_json_error( array( 'message' => __( 'No task linked', 'wpcusn' ) ) );
		}

		// Trigger sync
		$mapper = WPCUSN_Status_Mapper::get_instance();
		$mapper->sync_to_clickup( $post->post_status, $post->post_status, $post );

		wp_send_json_success( array( 'message' => __( 'Sync completed', 'wpcusn' ) ) );
	}

	/**
	 * Handle unlink task AJAX request
	 *
	 * @since 1.0.0
	 */
	public function handle_unlink_task() {
		check_ajax_referer( 'wpcusn_unlink_task', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID', 'wpcusn' ) ) );
		}

		$linker = WPCUSN_Task_Linker::get_instance();
		$linker->unlink_task( $post_id );

		wp_send_json_success( array( 'message' => __( 'Task unlinked', 'wpcusn' ) ) );
	}
}

