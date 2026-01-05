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
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Post Meta Box Class
 */
class WPCUSN_Post_Meta_Box
{
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
		add_action('add_meta_boxes', array($this, 'add_meta_box'));
		add_action('admin_footer', array($this, 'auto_link_on_load'));
		add_action('wp_ajax_wpcusn_force_sync', array($this, 'handle_force_sync'));
		add_action('wp_ajax_wpcusn_unlink_task', array($this, 'handle_unlink_task'));
		add_action('wp_ajax_wpcusn_try_auto_link', array($this, 'handle_try_auto_link'));
		add_action('wp_ajax_wpcusn_link_task', array($this, 'handle_link_task'));
	}

	/**
	 * Add meta box to post editor
	 *
	 * @since 1.0.0
	 */
	public function add_meta_box()
	{
		add_meta_box(
			'wpcusn-clickup-sync',
			__('ClickUp Sync', 'wpcusn'),
			array($this, 'render_meta_box'),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Auto-link on post edit page load if not linked
	 *
	 * @since 1.2.0
	 */
	public function auto_link_on_load()
	{
		global $post;
		if (!$post || 'post' !== $post->post_type) {
			return;
		}

		// Only on post edit screen
		$screen = get_current_screen();
		if (!$screen || 'post' !== $screen->id) {
			return;
		}

		// Skip if already linked
		$task_id = get_post_meta($post->ID, '_clickup_task_id', true);
		if ($task_id) {
			return;
		}

		// Skip if no slug
		if (!$post->post_name) {
			return;
		}

		// Skip if Space ID not configured
		$space_id = get_option('wpcusn_space_id');
		$list_id = get_option('wpcusn_list_id');
		if (!$space_id && !$list_id) {
			return;
		}

		// Auto-trigger linking via AJAX
		?>
		<script>
			jQuery(document).ready(function ($) {
				// Auto-trigger linking when page loads (after 500ms delay to ensure page is ready)
				// Only run once - check if already running
				if ($('#wpcusn-try-auto-link').length && !window.wpcusnAutoLinkTriggered) {
					window.wpcusnAutoLinkTriggered = true;
					setTimeout(function () {
						$('#wpcusn-try-auto-link').trigger('click');
					}, 500);
				}
			});
		</script>
		<?php
	}

	/**
	 * Render meta box content
	 *
	 * @since 1.0.0
	 * @param WP_Post $post Post object
	 */
	public function render_meta_box($post)
	{
		$task_id = get_post_meta($post->ID, '_clickup_task_id', true);
		$task_name = get_post_meta($post->ID, '_clickup_task_name', true);
		$linked_at = get_post_meta($post->ID, '_clickup_linked_at', true);

		wp_nonce_field('wpcusn_meta_box', 'wpcusn_meta_box_nonce');
		?>

		<div id="wpcusn-metabox-content">
		<?php if ($task_id): ?>
			<p>
				<strong><?php esc_html_e('Linked Task:', 'wpcusn'); ?></strong><br />
				<?php echo esc_html($task_name ? $task_name : $task_id); ?>
			</p>
			<?php if ($linked_at): ?>
				<p>
					<small><?php esc_html_e('Linked:', 'wpcusn'); ?> 				<?php echo esc_html($linked_at); ?></small>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" class="button" id="wpcusn-force-sync" data-post-id="<?php echo esc_attr($post->ID); ?>">
					<?php esc_html_e('Force Sync Now', 'wpcusn'); ?>
				</button>
				<button type="button" class="button" id="wpcusn-unlink-task" data-post-id="<?php echo esc_attr($post->ID); ?>">
					<?php esc_html_e('Unlink Task', 'wpcusn'); ?>
				</button>
			</p>
			<div id="wpcusn-sync-message"></div>
		<?php else: ?>
			<p><?php esc_html_e('No ClickUp task linked.', 'wpcusn'); ?></p>
			<p>
				<button type="button" class="button" id="wpcusn-try-auto-link" data-post-id="<?php echo esc_attr($post->ID); ?>"
					style="margin-top: 5px;">
					<?php esc_html_e('Try Auto-Link Now', 'wpcusn'); ?>
				</button>
				<small style="display: block; margin-top: 5px; color: #666;">
					<?php esc_html_e('Searches for a task matching the post slug: ', 'wpcusn'); ?>
					<code><?php echo esc_html($post->post_name ?: '(no slug)'); ?></code>
				</small>
			</p>
			<p>
				<label
					for="wpcusn-manual-task-id"><strong><?php esc_html_e('Or link manually:', 'wpcusn'); ?></strong></label><br />
				<input type="text" id="wpcusn-manual-task-id"
					placeholder="<?php esc_attr_e('Enter ClickUp Task ID', 'wpcusn'); ?>"
					style="margin-top: 5px; width: 100%; max-width: 200px;" />
				<button type="button" class="button" id="wpcusn-link-task" data-post-id="<?php echo esc_attr($post->ID); ?>"
					style="margin-top: 5px;">
					<?php esc_html_e('Link Task', 'wpcusn'); ?>
				</button>
			</p>
			<div id="wpcusn-link-message"></div>
		<?php endif; ?>
		</div>

		<script>
			jQuery(document).ready(function ($) {
				$('#wpcusn-force-sync').on('click', function () {
					var postId = $(this).data('post-id');
					var button = $(this);
					button.prop('disabled', true).text('<?php esc_html_e('Syncing...', 'wpcusn'); ?>');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wpcusn_force_sync',
							post_id: postId,
							nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_force_sync')); ?>'
						},
						success: function (response) {
							if (response.success) {
								$('#wpcusn-sync-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							} else {
								$('#wpcusn-sync-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
							}
							button.prop('disabled', false).text('<?php esc_html_e('Force Sync Now', 'wpcusn'); ?>');
						}
					});
				});

				$('#wpcusn-unlink-task').on('click', function () {
					if (!confirm('<?php esc_html_e('Are you sure you want to unlink this task?', 'wpcusn'); ?>')) {
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
							nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_unlink_task')); ?>'
						},
						success: function (response) {
							if (response.success) {
								location.reload();
							} else {
								alert(response.data.message);
								button.prop('disabled', false);
							}
						}
					});
				});

				$('#wpcusn-try-auto-link').on('click', function () {
					var postId = $(this).data('post-id');
					var button = $(this);
					button.prop('disabled', true).text('<?php esc_html_e('Searching...', 'wpcusn'); ?>');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wpcusn_try_auto_link',
							post_id: postId,
							nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_try_auto_link')); ?>'
						},
					success: function (response) {
						if (response.success) {
							if (response.data.linked && response.data.task_id) {
								// Update metabox content immediately without page reload
								var taskName = response.data.task_name || response.data.task_id;
								var linkedAt = response.data.linked_at || '';
								var metaboxContent = '<p><strong><?php esc_html_e('Linked Task:', 'wpcusn'); ?></strong><br />' + 
									$('<div>').text(taskName).html() + '</p>';
								if (linkedAt) {
									metaboxContent += '<p><small><?php esc_html_e('Linked:', 'wpcusn'); ?> ' + 
										$('<div>').text(linkedAt).html() + '</small></p>';
								}
								metaboxContent += '<p>' +
									'<button type="button" class="button" id="wpcusn-force-sync" data-post-id="' + postId + '">' +
									'<?php esc_html_e('Force Sync Now', 'wpcusn'); ?>' +
									'</button> ' +
									'<button type="button" class="button" id="wpcusn-unlink-task" data-post-id="' + postId + '">' +
									'<?php esc_html_e('Unlink Task', 'wpcusn'); ?>' +
									'</button>' +
									'</p>' +
									'<div id="wpcusn-sync-message"></div>';
								
								// Replace the entire metabox content
								$('#wpcusn-metabox-content').html(metaboxContent);
								
								// Show success message temporarily
								$('#wpcusn-sync-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
								
								// Re-attach event handlers for the new buttons
								$('#wpcusn-force-sync').on('click', function () {
									var postId = $(this).data('post-id');
									var button = $(this);
									button.prop('disabled', true).text('<?php esc_html_e('Syncing...', 'wpcusn'); ?>');

									$.ajax({
										url: ajaxurl,
										type: 'POST',
										data: {
											action: 'wpcusn_force_sync',
											post_id: postId,
											nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_force_sync')); ?>'
										},
										success: function (response) {
											if (response.success) {
												$('#wpcusn-sync-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
											} else {
												$('#wpcusn-sync-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
											}
											button.prop('disabled', false).text('<?php esc_html_e('Force Sync Now', 'wpcusn'); ?>');
										}
									});
								});

								$('#wpcusn-unlink-task').on('click', function () {
									if (!confirm('<?php esc_html_e('Are you sure you want to unlink this task?', 'wpcusn'); ?>')) {
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
											nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_unlink_task')); ?>'
										},
										success: function (response) {
											if (response.success) {
												location.reload();
											} else {
												alert(response.data.message);
												button.prop('disabled', false);
											}
										}
									});
								});
							} else {
								$('#wpcusn-link-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							}
						} else {
							$('#wpcusn-link-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
						}
						button.prop('disabled', false).text('<?php esc_html_e('Try Auto-Link Now', 'wpcusn'); ?>');
					}
					});
				});

				$('#wpcusn-link-task').on('click', function () {
					var postId = $(this).data('post-id');
					var taskId = $('#wpcusn-manual-task-id').val().trim();
					var button = $(this);

					if (!taskId) {
						alert('<?php esc_html_e('Please enter a Task ID', 'wpcusn'); ?>');
						return;
					}

					button.prop('disabled', true).text('<?php esc_html_e('Linking...', 'wpcusn'); ?>');

					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'wpcusn_link_task',
							post_id: postId,
							task_id: taskId,
							nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_link_task')); ?>'
						},
					success: function (response) {
						if (response.success) {
							if (response.data.linked && response.data.task_id) {
								// Update metabox content immediately without page reload
								var taskName = response.data.task_name || response.data.task_id;
								var linkedAt = response.data.linked_at || '';
								var metaboxContent = '<p><strong><?php esc_html_e('Linked Task:', 'wpcusn'); ?></strong><br />' + 
									$('<div>').text(taskName).html() + '</p>';
								if (linkedAt) {
									metaboxContent += '<p><small><?php esc_html_e('Linked:', 'wpcusn'); ?> ' + 
										$('<div>').text(linkedAt).html() + '</small></p>';
								}
								metaboxContent += '<p>' +
									'<button type="button" class="button" id="wpcusn-force-sync" data-post-id="' + postId + '">' +
									'<?php esc_html_e('Force Sync Now', 'wpcusn'); ?>' +
									'</button> ' +
									'<button type="button" class="button" id="wpcusn-unlink-task" data-post-id="' + postId + '">' +
									'<?php esc_html_e('Unlink Task', 'wpcusn'); ?>' +
									'</button>' +
									'</p>' +
									'<div id="wpcusn-sync-message"></div>';
								
								// Replace the entire metabox content
								$('#wpcusn-metabox-content').html(metaboxContent);
								
								// Show success message temporarily
								$('#wpcusn-sync-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
								
								// Re-attach event handlers for the new buttons
								$('#wpcusn-force-sync').on('click', function () {
									var postId = $(this).data('post-id');
									var button = $(this);
									button.prop('disabled', true).text('<?php esc_html_e('Syncing...', 'wpcusn'); ?>');

									$.ajax({
										url: ajaxurl,
										type: 'POST',
										data: {
											action: 'wpcusn_force_sync',
											post_id: postId,
											nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_force_sync')); ?>'
										},
										success: function (response) {
											if (response.success) {
												$('#wpcusn-sync-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
											} else {
												$('#wpcusn-sync-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
											}
											button.prop('disabled', false).text('<?php esc_html_e('Force Sync Now', 'wpcusn'); ?>');
										}
									});
								});

								$('#wpcusn-unlink-task').on('click', function () {
									if (!confirm('<?php esc_html_e('Are you sure you want to unlink this task?', 'wpcusn'); ?>')) {
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
											nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_unlink_task')); ?>'
										},
										success: function (response) {
											if (response.success) {
												location.reload();
											} else {
												alert(response.data.message);
												button.prop('disabled', false);
											}
										}
									});
								});
							} else {
								$('#wpcusn-link-message').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							}
						} else {
							$('#wpcusn-link-message').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
							button.prop('disabled', false).text('<?php esc_html_e('Link Task', 'wpcusn'); ?>');
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
	public function handle_force_sync()
	{
		check_ajax_referer('wpcusn_force_sync', 'nonce');

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
		if (!$post_id) {
			wp_send_json_error(array('message' => __('Invalid post ID', 'wpcusn')));
		}

		$post = get_post($post_id);
		if (!$post) {
			wp_send_json_error(array('message' => __('Post not found', 'wpcusn')));
		}

		$task_id = get_post_meta($post_id, '_clickup_task_id', true);
		if (!$task_id) {
			wp_send_json_error(array('message' => __('No task linked', 'wpcusn')));
		}

		// Trigger sync
		$mapper = WPCUSN_Status_Mapper::get_instance();
		$mapper->sync_to_clickup($post->post_status, $post->post_status, $post);

		wp_send_json_success(array('message' => __('Sync completed', 'wpcusn')));
	}

	/**
	 * Handle unlink task AJAX request
	 *
	 * @since 1.0.0
	 */
	public function handle_unlink_task()
	{
		check_ajax_referer('wpcusn_unlink_task', 'nonce');

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
		if (!$post_id) {
			wp_send_json_error(array('message' => __('Invalid post ID', 'wpcusn')));
		}

		$linker = WPCUSN_Task_Linker::get_instance();
		$linker->unlink_task($post_id);

		wp_send_json_success(array('message' => __('Task unlinked', 'wpcusn')));
	}

	/**
	 * Handle try auto-link AJAX request
	 *
	 * @since 1.2.0
	 */
	public function handle_try_auto_link()
	{
		check_ajax_referer('wpcusn_try_auto_link', 'nonce');

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
		if (!$post_id) {
			wp_send_json_error(array('message' => __('Invalid post ID', 'wpcusn')));
		}

		$post = get_post($post_id);
		if (!$post) {
			wp_send_json_error(array('message' => __('Post not found', 'wpcusn')));
		}

		// Temporarily remove the "already linked" check by clearing the meta
		// This allows re-trying auto-link
		$existing_task_id = get_post_meta($post_id, '_clickup_task_id', true);
		if ($existing_task_id) {
			wp_send_json_error(array('message' => __('Post is already linked to a task. Unlink it first to try auto-linking again.', 'wpcusn')));
		}

		// Trigger auto-link
		$linker = WPCUSN_Task_Linker::get_instance();
		$linker->auto_link_task($post_id, $post);

		// Check if it was linked
		$new_task_id = get_post_meta($post_id, '_clickup_task_id', true);
		if ($new_task_id) {
			$task_name = get_post_meta($post_id, '_clickup_task_name', true);
			$linked_at = get_post_meta($post_id, '_clickup_linked_at', true);
			wp_send_json_success(array(
				'message' => sprintf(__('Successfully linked to task: %s (%s)', 'wpcusn'), $task_name, $new_task_id),
				'linked' => true,
				'task_id' => $new_task_id,
				'task_name' => $task_name,
				'linked_at' => $linked_at
			));
		} else {
			wp_send_json_error(array('message' => __('No matching task found. Check the sync log for details.', 'wpcusn')));
		}
	}

	/**
	 * Handle manual link task AJAX request
	 *
	 * @since 1.2.0
	 */
	public function handle_link_task()
	{
		check_ajax_referer('wpcusn_link_task', 'nonce');

		$post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
		$task_id = isset($_POST['task_id']) ? sanitize_text_field($_POST['task_id']) : '';

		if (!$post_id) {
			wp_send_json_error(array('message' => __('Invalid post ID', 'wpcusn')));
		}

		if (!$task_id) {
			wp_send_json_error(array('message' => __('Task ID is required', 'wpcusn')));
		}

		$linker = WPCUSN_Task_Linker::get_instance();
		$result = $linker->link_task($post_id, $task_id);

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => __('Failed to link task: ', 'wpcusn') . $result->get_error_message()));
		}

		$task_name = get_post_meta($post_id, '_clickup_task_name', true);
		$linked_at = get_post_meta($post_id, '_clickup_linked_at', true);
		wp_send_json_success(array(
			'message' => sprintf(__('Successfully linked to task: %s (%s)', 'wpcusn'), $task_name ?: $task_id, $task_id),
			'linked' => true,
			'task_id' => $task_id,
			'task_name' => $task_name ?: $task_id,
			'linked_at' => $linked_at
		));
	}
}

