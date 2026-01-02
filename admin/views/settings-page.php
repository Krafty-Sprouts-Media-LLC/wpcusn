<?php
/**
 * Settings Page View
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

$oauth = WPCUSN_ClickUp_OAuth::get_instance();
$is_connected = $oauth->is_connected();
$client_id = get_option('wpcusn_oauth_client_id');
$client_secret = get_option('wpcusn_oauth_client_secret');
$api_key = get_option('wpcusn_api_key');
$space_id = get_option('wpcusn_space_id');
$team_id = get_option('wpcusn_team_id');
$list_id = get_option('wpcusn_list_id');
$webhook_id = get_option('wpcusn_webhook_id');
$webhook_url = rest_url('clickup/v1/webhook');
$logs = get_option('wpcusn_sync_logs', array());
$logs = array_slice(array_reverse($logs), 0, 50); // Last 50 entries
?>

<div class="wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="wpcusn_save_settings" />
		<?php wp_nonce_field('wpcusn_save_settings', 'wpcusn_settings_nonce'); ?>

		<h2><?php esc_html_e('Authentication', 'wpcusn'); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e('API Key (Alternative)', 'wpcusn'); ?></th>
				<td>
					<input type="password" id="wpcusn_api_key" name="wpcusn_api_key"
						value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e('Get your API key from ClickUp Settings → Apps → API Token', 'wpcusn'); ?>
					</p>
					<?php if ($api_key && !$is_connected): ?>
						<p>
							<span style="color: green;">✓ <?php esc_html_e('API Key configured', 'wpcusn'); ?></span>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e('OAuth2 (Recommended)', 'wpcusn'); ?></th>
				<td>
					<p>
						<label for="wpcusn_oauth_client_id"><?php esc_html_e('Client ID:', 'wpcusn'); ?></label><br />
						<input type="text" id="wpcusn_oauth_client_id" name="wpcusn_oauth_client_id"
							value="<?php echo esc_attr($client_id); ?>" class="regular-text" />
					</p>
					<p>
						<label
							for="wpcusn_oauth_client_secret"><?php esc_html_e('Client Secret:', 'wpcusn'); ?></label><br />
						<input type="password" id="wpcusn_oauth_client_secret" name="wpcusn_oauth_client_secret"
							value="<?php echo esc_attr($client_secret); ?>" class="regular-text" />
					</p>
					<p class="description">
						<strong><?php esc_html_e('Redirect URI:', 'wpcusn'); ?></strong><br />
						<code
							style="display: block; padding: 8px; background: #f5f5f5; margin-top: 5px;"><?php echo esc_url(admin_url('options-general.php?page=wpcusn&action=oauth_callback')); ?></code>
						<button type="button" class="button button-small"
							onclick="navigator.clipboard.writeText('<?php echo esc_js(admin_url('options-general.php?page=wpcusn&action=oauth_callback')); ?>'); alert('<?php esc_html_e('Redirect URI copied to clipboard!', 'wpcusn'); ?>');"
							style="margin-top: 5px;">
							<?php esc_html_e('Copy', 'wpcusn'); ?>
						</button>
						<br />
						<small><?php esc_html_e('Add this URL to your ClickUp app\'s Redirect URL(s) field.', 'wpcusn'); ?></small>
					</p>
					<?php if ($client_id && $client_secret && !$is_connected): ?>
						<p>
							<?php
							$auth_url = $oauth->get_authorization_url();
							if ($auth_url):
								?>
								<a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">
									<?php esc_html_e('Connect to ClickUp', 'wpcusn'); ?>
								</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ($is_connected): ?>
						<p>
							<span style="color: green;">✓ <?php esc_html_e('Connected to ClickUp', 'wpcusn'); ?></span>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e('Configuration', 'wpcusn'); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="wpcusn_space_id"><?php esc_html_e('Space', 'wpcusn'); ?></label>
				</th>
				<td>
					<?php $space_id = get_option('wpcusn_space_id'); ?>
					<?php if ($is_connected || $api_key): ?>
						<select id="wpcusn_space_id" name="wpcusn_space_id" class="regular-text">
							<option value=""><?php esc_html_e('-- Select a Space --', 'wpcusn'); ?></option>
							<?php if ($space_id): ?>
								<option value="<?php echo esc_attr($space_id); ?>"
									data-team-id="<?php echo esc_attr(get_option('wpcusn_team_id')); ?>" selected>
									<?php echo esc_html($space_id); ?>
								</option>
							<?php endif; ?>
						</select>
						<input type="hidden" id="wpcusn_current_space_id" value="<?php echo esc_attr($space_id); ?>" />
						<p style="margin:6px 0 4px 0;">
							<label for="wpcusn_team_id"
								style="font-weight:600;"><?php esc_html_e('Team ID (auto-filled)', 'wpcusn'); ?></label><br />
							<input type="text" id="wpcusn_team_id" name="wpcusn_team_id"
								value="<?php echo esc_attr(get_option('wpcusn_team_id')); ?>" class="regular-text"
								readonly />
						</p>
						<button type="button" id="wpcusn-load-spaces" class="button" style="margin-left: 5px;">
							<?php esc_html_e('Load Spaces', 'wpcusn'); ?>
						</button>
						<span id="wpcusn-spaces-loading"
							style="display: none; margin-left: 10px;"><?php esc_html_e('Loading...', 'wpcusn'); ?></span>
						<p class="description">
							<?php esc_html_e('Click "Load Spaces" to fetch your ClickUp spaces. The plugin will search across all lists in the selected space.', 'wpcusn'); ?>
						</p>
						<input type="text" id="wpcusn_space_id_manual" value="<?php echo esc_attr($space_id); ?>"
							class="regular-text" style="display: none; margin-top: 5px;"
							placeholder="<?php esc_attr_e('Or enter Space ID manually', 'wpcusn'); ?>" />
						<p class="description" style="margin-top: 5px;">
							<a href="#" id="wpcusn-toggle-manual-space"
								style="text-decoration: none;"><?php esc_html_e('Enter Space ID manually', 'wpcusn'); ?></a>
						</p>
					<?php else: ?>
						<input type="text" id="wpcusn_space_id" name="wpcusn_space_id"
							value="<?php echo esc_attr($space_id); ?>" class="regular-text" />
						<p class="description">
							<?php esc_html_e('Connect to ClickUp (OAuth) or add an API Key first to load spaces automatically, or enter the Space ID manually. Found in the space URL: app.clickup.com/{space_id}/...', 'wpcusn'); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wpcusn_list_id"><?php esc_html_e('List ID (Optional)', 'wpcusn'); ?></label>
				</th>
				<td>
					<input type="text" id="wpcusn_list_id" name="wpcusn_list_id"
						value="<?php echo esc_attr($list_id); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e('Optional: Limit search to a specific list. If not provided, the plugin will search across all lists in the space.', 'wpcusn'); ?>
					</p>
				</td>
			</tr>
		</table>

	<h2><?php esc_html_e('Sync Direction', 'wpcusn'); ?></h2>
	<p class="description" style="margin-bottom: 15px;">
		<?php esc_html_e('Control which direction status changes are synchronized.', 'wpcusn'); ?>
	</p>

	<table class="form-table">
		<tr>
			<th scope="row">
				<label for="wpcusn_sync_wp_to_clickup"><?php esc_html_e('WordPress → ClickUp', 'wpcusn'); ?></label>
			</th>
			<td>
				<label>
					<input type="checkbox" id="wpcusn_sync_wp_to_clickup" name="wpcusn_sync_wp_to_clickup" value="1" <?php checked(get_option('wpcusn_sync_wp_to_clickup', true)); ?> />
					<?php esc_html_e('Sync WordPress post status changes to ClickUp', 'wpcusn'); ?>
				</label>
				<p class="description">
					<?php esc_html_e('When you change a post status in WordPress, update the linked ClickUp task.', 'wpcusn'); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wpcusn_sync_clickup_to_wp"><?php esc_html_e('ClickUp → WordPress', 'wpcusn'); ?></label>
			</th>
			<td>
				<label>
					<input type="checkbox" id="wpcusn_sync_clickup_to_wp" name="wpcusn_sync_clickup_to_wp" value="1" <?php checked(get_option('wpcusn_sync_clickup_to_wp', true)); ?> />
					<?php esc_html_e('Sync ClickUp task status changes to WordPress', 'wpcusn'); ?>
				</label>
				<p class="description">
					<?php esc_html_e('When a ClickUp task status changes, update the linked WordPress post via webhook.', 'wpcusn'); ?>
				</p>
			</td>
		</tr>
	</table>

		<h2><?php esc_html_e('Search & Log Settings', 'wpcusn'); ?></h2>
		<p class="description" style="margin-bottom: 15px;">
			<?php esc_html_e('Configure search behavior and log retention for optimal performance.', 'wpcusn'); ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label
						for="wpcusn_include_closed_tasks"><?php esc_html_e('Include Closed Tasks', 'wpcusn'); ?></label>
				</th>
				<td>
					<label>
						<input type="checkbox" id="wpcusn_include_closed_tasks" name="wpcusn_include_closed_tasks"
							value="1" <?php checked(get_option('wpcusn_include_closed_tasks', false)); ?> />
						<?php esc_html_e('Search closed/completed tasks when auto-linking', 'wpcusn'); ?>
					</label>
					<p class="description">
						<?php esc_html_e('⚠️ Warning: Enable only if needed. With 10K+ closed tasks, this slows search significantly.', 'wpcusn'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wpcusn_log_limit"><?php esc_html_e('Max Log Entries', 'wpcusn'); ?></label>
				</th>
				<td>
					<?php $log_limit = get_option('wpcusn_log_limit', 200); ?>
					<select id="wpcusn_log_limit" name="wpcusn_log_limit">
						<option value="50" <?php selected($log_limit, 50); ?>>50</option>
						<option value="100" <?php selected($log_limit, 100); ?>>100</option>
						<option value="200" <?php selected($log_limit, 200); ?>>200</option>
						<option value="500" <?php selected($log_limit, 500); ?>>500</option>
					</select>
					<p class="description">
						<?php esc_html_e('Maximum number of log entries to keep. Older entries are automatically removed.', 'wpcusn'); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label
						for="wpcusn_log_retention_days"><?php esc_html_e('Log Retention (Days)', 'wpcusn'); ?></label>
				</th>
				<td>
					<?php $retention_days = get_option('wpcusn_log_retention_days', 7); ?>
					<input type="number" id="wpcusn_log_retention_days" name="wpcusn_log_retention_days"
						value="<?php echo esc_attr($retention_days); ?>" min="1" max="90" style="width: 80px;" />
					<p class="description">
						<?php esc_html_e('Logs older than this many days are automatically deleted. Set 0 to disable.', 'wpcusn'); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e('Status Mapping', 'wpcusn'); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e('WordPress → ClickUp', 'wpcusn'); ?></th>
				<td>
					<ul>
						<li><code>draft</code> → <code>IN PROGRESS</code></li>
						<li><code>ready</code> → <code>READY</code></li>
						<li><code>schedulable</code> → <code>PENDING</code></li>
						<li><code>scheduled</code> → <code>PENDING</code></li>
						<li><code>publish</code> → <code>PUBLISHED</code></li>
					</ul>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e('ClickUp → WordPress', 'wpcusn'); ?></th>
				<td>
					<ul>
						<li><code>TO DO</code> → <code>draft</code></li>
						<li><code>IN PROGRESS</code> → <code>draft</code></li>
						<li><code>READY</code> → <code>ready</code></li>
						<li><code>PENDING</code> → <code>schedulable</code></li>
						<li><code>PUBLISHED</code> → <code>publish</code></li>
					</ul>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e('Webhook Configuration', 'wpcusn'); ?></h2>

	<table class="form-table">
		<tr>
			<th scope="row"><?php esc_html_e('Webhook URL', 'wpcusn'); ?></th>
			<td>
				<input type="text" readonly value="<?php echo esc_url($webhook_url); ?>" class="regular-text"
					onclick="this.select();" />
				<button type="button" class="button"
					onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>'); alert('Copied!');">
					<?php esc_html_e('Copy', 'wpcusn'); ?>
				</button>
				<p class="description">
					<?php esc_html_e('This is the URL that ClickUp will send webhook events to.', 'wpcusn'); ?>
				</p>
			</td>
		</tr>
		<?php if ($webhook_id): ?>
			<tr>
				<th scope="row"><?php esc_html_e('Webhook Status', 'wpcusn'); ?></th>
				<td>
					<span style="color: green;">✓ <?php esc_html_e('Webhook is active', 'wpcusn'); ?></span>
					<p class="description">
						<?php esc_html_e('Webhook ID:', 'wpcusn'); ?> <code><?php echo esc_html($webhook_id); ?></code>
					</p>
				</td>
			</tr>
		<?php endif; ?>
	</table>

	<?php if (($is_connected || $api_key) && $space_id): ?>
		<?php if (!$webhook_id): ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
				<input type="hidden" name="action" value="wpcusn_save_settings" />
				<?php wp_nonce_field('wpcusn_save_settings', 'wpcusn_settings_nonce'); ?>
				<?php wp_nonce_field('wpcusn_create_webhook', '_wpnonce'); ?>
				<input type="hidden" name="wpcusn_action" value="create_webhook" />
				<button type="submit" class="button button-primary">
					<?php esc_html_e('Create Webhook Automatically', 'wpcusn'); ?>
				</button>
				<p class="description">
					<?php esc_html_e('This will create a webhook in ClickUp for "taskStatusUpdated" events in your space.', 'wpcusn'); ?>
				</p>
			</form>
		<?php else: ?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 10px;">
				<input type="hidden" name="action" value="wpcusn_save_settings" />
				<?php wp_nonce_field('wpcusn_save_settings', 'wpcusn_settings_nonce'); ?>
				<?php wp_nonce_field('wpcusn_delete_webhook', '_wpnonce'); ?>
				<input type="hidden" name="wpcusn_action" value="delete_webhook" />
				<button type="submit" class="button"
					onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this webhook?', 'wpcusn'); ?>');">
					<?php esc_html_e('Delete Webhook', 'wpcusn'); ?>
				</button>
			</form>
		<?php endif; ?>
	<?php else: ?>
		<p class="description">
			<?php esc_html_e('Please connect to ClickUp and configure your Space ID first, then click "Create Webhook Automatically" above.', 'wpcusn'); ?>
		</p>
	<?php endif; ?>

	<?php if ($is_connected): ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top: 20px;">
			<input type="hidden" name="action" value="wpcusn_disconnect" />
			<?php wp_nonce_field('wpcusn_disconnect', 'wpcusn_disconnect_nonce'); ?>
			<button type="submit" class="button"
				onclick="return confirm('<?php esc_attr_e('Are you sure you want to disconnect from ClickUp?', 'wpcusn'); ?>');">
				<?php esc_html_e('Disconnect from ClickUp', 'wpcusn'); ?>
			</button>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e('Sync Logs', 'wpcusn'); ?></h2>
	<p class="description">
		<?php esc_html_e('Production log book: All plugin activity is logged here for debugging. This replaces debug logs in production environments.', 'wpcusn'); ?>
	</p>

	<?php if (empty($logs)): ?>
		<p><?php esc_html_e('No sync events yet.', 'wpcusn'); ?></p>
	<?php else: ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Time', 'wpcusn'); ?></th>
					<th><?php esc_html_e('Event Type', 'wpcusn'); ?></th>
					<th><?php esc_html_e('Post', 'wpcusn'); ?></th>
					<th><?php esc_html_e('Task', 'wpcusn'); ?></th>
					<th><?php esc_html_e('Details', 'wpcusn'); ?></th>
					<th><?php esc_html_e('Result', 'wpcusn'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($logs as $log): ?>
					<?php
					$timestamp = $log['timestamp'] ?? $log['time'] ?? '';
					$direction = $log['direction'] ?? '';
					$post_id = $log['post_id'] ?? 0;
					$task_id = $log['task_id'] ?? '';
					$old_status = $log['old_status'] ?? '';
					$new_status = $log['new_status'] ?? '';
					$success = $log['success'] ?? null;
					$error = $log['error'] ?? '';

					// Format event type for display
					$event_type_labels = array(
						'auto_link_attempt' => __('Auto-Link Attempt', 'wpcusn'),
						'auto_link_success' => __('Auto-Link Success', 'wpcusn'),
						'auto_link_failed' => __('Auto-Link Failed', 'wpcusn'),
						'task_unlinked' => __('Task Unlinked', 'wpcusn'),
						'wp_to_clickup' => __('WP → ClickUp Sync', 'wpcusn'),
						'clickup_to_wp' => __('ClickUp → WP Sync', 'wpcusn'),
						'webhook_received' => __('Webhook Received', 'wpcusn'),
						'webhook_error' => __('Webhook Error', 'wpcusn'),
						'api_debug' => __('API Debug', 'wpcusn'),
					);
					$event_label = $event_type_labels[$direction] ?? $direction;
					?>
					<tr>
						<td>
							<?php
							if ($timestamp) {
								$time = strtotime($timestamp);
								if ($time) {
									echo esc_html(date_i18n('Y-m-d H:i:s', $time));
								} else {
									echo esc_html($timestamp);
								}
							}
							?>
						</td>
						<td>
							<strong><?php echo esc_html($event_label); ?></strong>
						</td>
						<td>
							<?php
							if ($post_id) {
								$post = get_post($post_id);
								if ($post) {
									echo '<a href="' . esc_url(get_edit_post_link($post_id)) . '">' . esc_html($post->post_title) . '</a>';
									echo '<br /><small>ID: ' . esc_html($post_id) . '</small>';
								} else {
									echo esc_html($post_id);
								}
							} else {
								echo '—';
							}
							?>
						</td>
						<td>
							<?php
							if ($task_id) {
								echo esc_html($task_id);
							} else {
								echo '—';
							}
							?>
						</td>
						<td>
							<?php
							// Format details based on event type
							if (in_array($direction, array('wp_to_clickup', 'clickup_to_wp'), true)) {
								// Sync events: show status change
								echo '<strong>' . esc_html__('Status:', 'wpcusn') . '</strong> ';
								echo esc_html($old_status) . ' → ' . esc_html($new_status);
							} elseif (in_array($direction, array('auto_link_attempt', 'auto_link_success', 'auto_link_failed'), true)) {
								// Auto-link events: show slug and search details
								echo esc_html($old_status);
								if ($new_status) {
									echo '<br />' . esc_html($new_status);
								}
							} elseif ('task_unlinked' === $direction) {
								// Unlink event
								echo esc_html($old_status);
								if ($new_status) {
									echo '<br />' . esc_html($new_status);
								}
							} else {
								// Generic: show both
								if ($old_status) {
									echo esc_html($old_status);
								}
								if ($new_status) {
									if ($old_status) {
										echo ' → ';
									}
									echo esc_html($new_status);
								}
							}

							// Show error if present
							if ($error) {
								echo '<br /><span style="color: red;"><small>' . esc_html($error) . '</small></span>';
							}
							?>
						</td>
						<td>
							<?php
							if (null === $success) {
								// In progress or info event
								echo '<span style="color: #666;">—</span>';
							} elseif ($success) {
								echo '<span style="color: green;">✓ ' . esc_html__('Success', 'wpcusn') . '</span>';
							} else {
								echo '<span style="color: red;">✗ ' . esc_html__('Failed', 'wpcusn') . '</span>';
								if ($new_status && false !== strpos($new_status, 'Failed:')) {
									$failure_msg = str_replace('Failed: ', '', $new_status);
									echo '<br /><small style="color: red;">' . esc_html($failure_msg) . '</small>';
								}
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<?php if ($is_connected || $api_key): ?>
	<script>
		jQuery(document).ready(function ($) {
			var loadingSpaces = false;

			// Ensure manual input starts disabled (since it's hidden by default)
			$('#wpcusn_space_id_manual').prop('disabled', true);

			// Auto-load spaces on page load if dropdown is visible
			var select = $('#wpcusn_space_id');
			if (select.is(':visible') && select.length) {
				// Small delay to ensure page is fully loaded
				setTimeout(function () {
					$('#wpcusn-load-spaces').trigger('click');
				}, 100);
			}

			$('#wpcusn-load-spaces').on('click', function () {
				if (loadingSpaces) return;

				var button = $(this);
				var select = $('#wpcusn_space_id');
				var loading = $('#wpcusn-spaces-loading');

				button.prop('disabled', true);
				loading.show();
				loadingSpaces = true;

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wpcusn_get_spaces',
						nonce: '<?php echo esc_js(wp_create_nonce('wpcusn_get_spaces')); ?>'
					},
					success: function (response) {
						if (response.success && response.data.spaces) {
							// Get current saved space ID from hidden input BEFORE clearing options
							var currentSpaceId = $('#wpcusn_current_space_id').val() || '';

							// Clear existing options except the first one
							select.find('option:not(:first)').remove();

							// Add spaces
							$.each(response.data.spaces, function (i, space) {
								// Check if this space matches the currently saved one
								var selected = (space.id === currentSpaceId) ? ' selected' : '';
								// Display format: "Space Name (Team Name)" - cleaner, no ID clutter
								var label = space.name + (space.team_name ? ' (' + space.team_name + ')' : '');
								select.append('<option value="' + space.id + '"' + selected + ' data-team-id="' + space.team_id + '">' + label + '</option>');
							});

							if (response.data.spaces.length === 0) {
								select.append('<option value=""><?php esc_html_e('No spaces found', 'wpcusn'); ?></option>');
							}

							// Set team id for currently selected option
							var teamInput = $('#wpcusn_team_id');
							var selectedOpt = select.find('option:selected');
							if (selectedOpt.length) {
								teamInput.val(selectedOpt.data('team-id') || '');
							}

							// Update team id when selection changes
							select.off('change.wpcusn').on('change.wpcusn', function () {
								var opt = $(this).find('option:selected');
								teamInput.val(opt.data('team-id') || '');
							});
						} else {
							alert(response.data.message || '<?php esc_html_e('Failed to load spaces', 'wpcusn'); ?>');
						}
					},
					error: function () {
						alert('<?php esc_html_e('Error loading spaces', 'wpcusn'); ?>');
					},
					complete: function () {
						button.prop('disabled', false);
						loading.hide();
						loadingSpaces = false;
					}
				});
			});

			$('#wpcusn-toggle-manual-space').on('click', function (e) {
				e.preventDefault();
				var select = $('#wpcusn_space_id');
				var manual = $('#wpcusn_space_id_manual');
				var link = $(this);
				var teamInput = $('#wpcusn_team_id');

				if (manual.is(':visible')) {
					// Switching to dropdown - give name to select, remove from manual, hide manual
					select.attr('name', 'wpcusn_space_id').prop('disabled', false).show();
					manual.removeAttr('name').hide();
					link.text('<?php esc_html_e('Enter Space ID manually', 'wpcusn'); ?>');
					// restore team id from selected option
					var opt = select.find('option:selected');
					teamInput.val(opt.data('team-id') || '');
				} else {
					// Switching to manual - give name to manual, remove from select, hide select
					manual.attr('name', 'wpcusn_space_id').show();
					select.removeAttr('name').prop('disabled', true).hide();
					link.text('<?php esc_html_e('Use dropdown instead', 'wpcusn'); ?>');
					// manual entry has no team context; clear team id to fallback later
					teamInput.val('');
				}
			});
		});
	</script>
<?php endif; ?>