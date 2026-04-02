<?php
/**
 * Settings Page View (V2 - Clean SaaS)
 *
 * @package WPCUSN
 * @since 1.3.0
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
// PHASE 2 (02/04/2026): Read logs from dedicated DB table via WPCUSN_Sync_Logger.
$logs = WPCUSN_Sync_Logger::get_logs( 50 ); // Last 50 entries, newest first
$log_count = WPCUSN_Sync_Logger::count();
?>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
	// Webhook Actions Proxies
	function triggerCreateWebhook() {
		var form = document.getElementById('wpcusn-create-webhook-form');
		if (!form) {
			console.error('WPCUSN: Create webhook form not found');
			alert('<?php esc_html_e("Error: Webhook form not found. Please refresh the page.", "wpcusn"); ?>');
			return;
		}
		form.submit();
	}

	function triggerDeleteWebhook() {
		var form = document.getElementById('wpcusn-delete-webhook-form');
		if (!form) {
			console.error('WPCUSN: Delete webhook form not found');
			alert('<?php esc_html_e("Error: Webhook form not found. Please refresh the page.", "wpcusn"); ?>');
			return;
		}
		Swal.fire({
			title: '<?php esc_html_e("Delete Webhook?", "wpcusn"); ?>',
			text: "<?php esc_html_e("This will stop ClickUp from syncing updates to WordPress.", "wpcusn"); ?>",
			icon: 'warning',
			showCancelButton: true,
			confirmButtonColor: '#ef4444',
			cancelButtonColor: '#6b7280',
			confirmButtonText: '<?php esc_html_e("Yes, delete it", "wpcusn"); ?>',
			cancelButtonText: '<?php esc_html_e("Cancel", "wpcusn"); ?>'
		}).then((result) => {
			if (result.isConfirmed) {
				form.submit();
			}
		});
	}
</script>

<div class="wpcusn-wrap">

	<!-- HEADER -->
	<header class="wpcusn-header">
		<div class="wpcusn-brand-lockup">
			<div class="wpcusn-brand-logo">
				<?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/logo.svg'); ?>
			</div>
			<div class="wpcusn-brand-text">
				<div class="wpcusn-brand-title">
					WP ClickUp Sync-nator
					<span class="wpcusn-version-pill">v<?php echo esc_html(WPCUSN_VERSION); ?></span>
				</div>
				<div class="wpcusn-brand-subtitle"><?php esc_html_e('Integration Settings & Logs', 'wpcusn'); ?></div>
			</div>
		</div>
		<div class="wpcusn-header-actions">
			<?php if ($is_connected || ($api_key && $space_id)): ?>
				<div class="wpcusn-status-badge active">
					<span class="wpcusn-dot"></span> <?php esc_html_e('Connected', 'wpcusn'); ?>
				</div>
				<?php if ($is_connected): ?>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<input type="hidden" name="action" value="wpcusn_disconnect" />
						<?php wp_nonce_field('wpcusn_disconnect', 'wpcusn_disconnect_nonce'); ?>
						<button type="submit" class="wpcusn-btn wpcusn-btn-destructive"
							onclick="return confirm('<?php esc_attr_e('Are you sure you want to disconnect?', 'wpcusn'); ?>');">
							<span
								class="wpcusn-btn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/disconnect.svg'); ?></span>
							<?php esc_html_e('Disconnect', 'wpcusn'); ?>
						</button>
					</form>
				<?php endif; ?>
			<?php else: ?>
				<div class="wpcusn-status-badge">
					<span class="wpcusn-dot" style="background: #ccc;"></span>
					<?php esc_html_e('Not Connected', 'wpcusn'); ?>
				</div>
			<?php endif; ?>
		</div>
	</header>

	<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
		<input type="hidden" name="action" value="wpcusn_save_settings" />
		<?php wp_nonce_field('wpcusn_save_settings', 'wpcusn_settings_nonce'); ?>

		<!-- GRID LAYOUT -->
		<div class="wpcusn-grid">

			<!-- LEFT COLUMN -->
			<div class="wpcusn-col-main">

				<!-- AUTH PANEL -->
				<section class="wpcusn-panel" style="border-left: 4px solid var(--wpcusn-brand);">
					<div class="wpcusn-panel-header">
						<span class="wpcusn-panel-title">
							<span
								class="wpcusn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/auth.svg'); ?></span>
							<?php esc_html_e('Authentication', 'wpcusn'); ?>
						</span>
					</div>
					<div class="wpcusn-panel-body">

						<?php if ($is_connected): ?>
							<div
								style="margin-bottom: 20px; padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; display: flex; align-items: center; gap: 10px;">
								<span
									style="color: #16a34a; font-weight: 600; display: flex; align-items: center; gap: 6px;">
									<span
										style="width: 18px;"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/check.svg'); ?></span>
									<?php esc_html_e('Authenticated', 'wpcusn'); ?></span>
								<span style="font-size: 13px; color: #666;">( Credentials hidden for security )</span>
								<button type="button" class="wpcusn-btn wpcusn-btn-white"
									style="font-size: 12px; padding: 4px 8px; margin-left: auto;"
									onclick="jQuery('#wpcusn-auth-inputs').slideToggle();">
									<?php esc_html_e('Show Credentials', 'wpcusn'); ?>
								</button>
							</div>
						<?php endif; ?>

						<div id="wpcusn-auth-inputs" style="<?php echo $is_connected ? 'display: none;' : ''; ?>">
							<p class="wpcusn-helper-text" style="font-size: 14px; margin-bottom: 20px;">
								<?php esc_html_e('Link your workspace to enable two-way status syncing.', 'wpcusn'); ?>
							</p>

							<div class="wpcusn-field-group">
								<label class="wpcusn-label"><?php esc_html_e('OAuth Client ID', 'wpcusn'); ?></label>
								<input type="text" name="wpcusn_oauth_client_id"
									value="<?php echo esc_attr($client_id); ?>" class="wpcusn-input"
									placeholder="Client ID">
							</div>
							<div class="wpcusn-field-group">
								<label
									class="wpcusn-label"><?php esc_html_e('OAuth Client Secret', 'wpcusn'); ?></label>
								<input type="password" name="wpcusn_oauth_client_secret"
									value="<?php echo esc_attr($client_secret); ?>" class="wpcusn-input"
									placeholder="Client Secret">
							</div>

							<div style="background: #f9f9f9; padding: 12px; border-radius: 6px; margin-top: 15px;">
								<label class="wpcusn-label"><?php esc_html_e('Redirect URI', 'wpcusn'); ?></label>
								<div style="display:flex; gap:8px;">
									<input type="text" id="wpcusn-redirect-uri" readonly
										value="<?php echo esc_url(admin_url('options-general.php?page=wpcusn&action=oauth_callback')); ?>"
										class="wpcusn-input" style="background:#fff; color:#666;">
									<button type="button" class="wpcusn-btn wpcusn-btn-white wpcusn-copy-btn"
										data-target="#wpcusn-redirect-uri">
										<span
											class="wpcusn-btn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/copy.svg'); ?></span>
										<?php esc_html_e('Copy', 'wpcusn'); ?>
									</button>
								</div>
								<p class="wpcusn-helper-text">
									<?php esc_html_e('Add this to your ClickUp App settings.', 'wpcusn'); ?>
								</p>
							</div>

							<?php if ($client_id && $client_secret && !$is_connected): ?>
								<?php $auth_url = $oauth->get_authorization_url(); ?>
								<a href="<?php echo esc_url($auth_url); ?>" class="wpcusn-btn wpcusn-btn-primary"
									style="margin-top: 20px; width: 100%;">
									<?php esc_html_e('Authenticate with ClickUp', 'wpcusn'); ?>
								</a>
							<?php elseif (!$is_connected): ?>
								<button type="submit" class="wpcusn-btn wpcusn-btn-primary" style="margin-top: 20px;">
									<?php esc_html_e('Save Credentials', 'wpcusn'); ?>
								</button>
							<?php endif; ?>

							<div style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 15px;">
								<a href="#" id="wpcusn-toggle-advanced-auth"
									style="font-size: 12px; text-decoration: none; color: #666;"><?php esc_html_e('Or use API Key (Legacy)', 'wpcusn'); ?>
									↓</a>
								<div id="wpcusn-advanced-auth-group"
									style="<?php echo $api_key ? '' : 'display: none;'; ?> margin-top: 15px;">
									<label
										class="wpcusn-label"><?php esc_html_e('Personal API Key', 'wpcusn'); ?></label>
									<input type="password" name="wpcusn_api_key"
										value="<?php echo esc_attr($api_key); ?>" class="wpcusn-input">
									<?php if (!$is_connected): ?>
										<button type="submit" class="wpcusn-btn wpcusn-btn-primary"
											style="margin-top: 10px;">
											<?php esc_html_e('Save & Connect via Key', 'wpcusn'); ?>
										</button>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				</section>

				<!-- CONTEXT PANEL -->
				<section class="wpcusn-panel">
					<div class="wpcusn-panel-header">
						<span class="wpcusn-panel-title">
							<span
								class="wpcusn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/space.svg'); ?></span>
							<?php esc_html_e('Workspace Context', 'wpcusn'); ?>
						</span>
						<?php if ($is_connected || $api_key): ?>
							<button id="wpcusn-load-spaces" class="wpcusn-btn wpcusn-btn-white"
								style="font-size: 12px; padding: 4px 8px;">
								<?php esc_html_e('Refresh Spaces', 'wpcusn'); ?>
							</button>
						<?php endif; ?>
					</div>
					<div class="wpcusn-panel-body">

						<div class="wpcusn-field-group">
							<label class="wpcusn-label"><?php esc_html_e('ClickUp Space', 'wpcusn'); ?></label>

							<?php if ($is_connected || $api_key): ?>
								<div class="input-select-wrapper">
									<select id="wpcusn_space_id" name="wpcusn_space_id" class="wpcusn-select">
										<option value=""><?php esc_html_e('-- Select a Space --', 'wpcusn'); ?></option>
										<?php if ($space_id): ?>
											<option value="<?php echo esc_attr($space_id); ?>"
												data-team-id="<?php echo esc_attr($team_id); ?>" selected>
												<?php echo esc_html($space_id); ?> (Current)
											</option>
										<?php endif; ?>
									</select>
								</div>

								<input type="hidden" id="wpcusn_current_space_id"
									value="<?php echo esc_attr($space_id); ?>" />
								<div style="margin-top: 8px;">
									<a href="#" id="wpcusn-toggle-manual-space"
										style="font-size: 12px; color: var(--wpcusn-brand); text-decoration: none; display: inline-block;">
										<?php esc_html_e('Enter Space ID manually', 'wpcusn'); ?>
									</a>
								</div>

								<div class="wpcusn-field-group" style="margin-top: 15px;">
									<label
										class="wpcusn-label"><?php esc_html_e('Team ID (auto-filled)', 'wpcusn'); ?></label>
									<input type="text" id="wpcusn_team_id" name="wpcusn_team_id"
										value="<?php echo esc_attr($team_id); ?>" class="wpcusn-input" readonly
										style="background-color: #f3f4f6; color: #6b7280; cursor: not-allowed;" />
								</div>

								<!-- Manual Fallback -->
								<input type="text" id="wpcusn_space_id_manual" value="<?php echo esc_attr($space_id); ?>"
									class="wpcusn-input" style="display: none;" placeholder="Enter Space ID">

							<?php else: ?>
								<input type="text" name="wpcusn_space_id" value="<?php echo esc_attr($space_id); ?>"
									class="wpcusn-input" placeholder="Connect to load spaces...">
							<?php endif; ?>

							<p class="wpcusn-helper-text">
								<?php esc_html_e('Tasks will be synced to this space. Search is limited to this scope.', 'wpcusn'); ?>
							</p>
						</div>

						<div class="wpcusn-field-group">
							<label
								class="wpcusn-label"><?php esc_html_e('Default List ID (Optional)', 'wpcusn'); ?></label>
							<input type="text" name="wpcusn_list_id" value="<?php echo esc_attr($list_id); ?>"
								class="wpcusn-input" placeholder="e.g. 12345678">
							<p class="wpcusn-helper-text">
								<?php esc_html_e('Limit search to a specific list. Leave empty to search the whole space.', 'wpcusn'); ?>
							</p>
						</div>
					</div>
				</section>

				<!-- SYNC RULES PANEL -->
				<section class="wpcusn-panel">
					<div class="wpcusn-panel-header">
						<span class="wpcusn-panel-title">
							<span
								class="wpcusn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/sync.svg'); ?></span>
							<?php esc_html_e('Sync Direction', 'wpcusn'); ?>
						</span>
					</div>
					<div class="wpcusn-panel-body">

						<div class="wpcusn-toggle-row">
							<div class="wpcusn-toggle-info">
								<span
									class="wpcusn-toggle-title"><?php esc_html_e('WordPress → ClickUp', 'wpcusn'); ?></span>
								<span
									class="wpcusn-toggle-desc"><?php esc_html_e('Update ClickUp status when WP Post status changes.', 'wpcusn'); ?></span>
							</div>
							<label class="wpcusn-switch">
								<input type="checkbox" name="wpcusn_sync_wp_to_clickup" value="1" <?php checked(get_option('wpcusn_sync_wp_to_clickup', true)); ?>>
								<span class="wpcusn-slider"></span>
							</label>
						</div>

						<div class="wpcusn-toggle-row">
							<div class="wpcusn-toggle-info">
								<span
									class="wpcusn-toggle-title"><?php esc_html_e('ClickUp → WordPress', 'wpcusn'); ?></span>
								<span
									class="wpcusn-toggle-desc"><?php esc_html_e('Update WP Post status via Webhook events.', 'wpcusn'); ?></span>
							</div>
							<label class="wpcusn-switch">
								<input type="checkbox" name="wpcusn_sync_clickup_to_wp" value="1" <?php checked(get_option('wpcusn_sync_clickup_to_wp', true)); ?>>
								<span class="wpcusn-slider"></span>
							</label>
						</div>

						<div
							style="margin-top: 15px; padding: 12px; background: #f9f9f9; border-radius: 6px; font-size: 13px;">
							<strong><?php esc_html_e('Status Mapping:', 'wpcusn'); ?></strong>
							<div
								style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 8px; color: #555;">
								<!-- WP -> ClickUp -->
								<div>
									<div
										style="font-weight: 600; margin-bottom: 6px; color: #333; text-decoration: underline;">
										WordPress → ClickUp</div>
									<div><code>draft</code> <svg class="wpcusn-map-arrow" viewBox="0 0 24 24">
											<path
												d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z" />
										</svg> <code>IN PROGRESS</code></div>
									<div><code>ready</code> <svg class="wpcusn-map-arrow" viewBox="0 0 24 24">
											<path
												d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z" />
										</svg> <code>READY</code></div>
									<div><code>schedulable</code> <svg class="wpcusn-map-arrow" viewBox="0 0 24 24">
											<path
												d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z" />
										</svg> <code>PENDING</code></div>
									<div><code>scheduled</code> <svg class="wpcusn-map-arrow" viewBox="0 0 24 24">
											<path
												d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z" />
										</svg> <code>PENDING</code></div>
									<div><code>publish</code> <svg class="wpcusn-map-arrow" viewBox="0 0 24 24">
											<path
												d="M6.99 11L3 15l3.99 4v-3H14v-2H6.99v-3zM21 9l-3.99-4v3H10v2h7.01v3L21 9z" />
										</svg> <code>PUBLISHED</code></div>
								</div>

								<!-- ClickUp -> WP -->
								<div>
									<div
										style="font-weight: 600; margin-bottom: 6px; color: #333; text-decoration: underline;">
										ClickUp → WordPress</div>
									<div><code>TO DO</code> ↔ <code>draft</code></div>
									<div><code>IN PROGRESS</code> ↔ <code>draft</code></div>
									<div><code>READY</code> ↔ <code>ready</code></div>
									<div><code>PENDING</code> ↔ <code>schedulable</code></div>
									<div><code>PUBLISHED</code> ↔ <code>publish</code></div>
								</div>
							</div>
						</div>

					</div>
				</section>
			</div>

			<!-- RIGHT COLUMN -->
			<div class="wpcusn-col-side">

				<!-- WEBHOOK PANEL -->
				<section class="wpcusn-panel">
					<div class="wpcusn-panel-header">
						<span class="wpcusn-panel-title">
							<span
								class="wpcusn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/webhook.svg'); ?></span>
							<?php esc_html_e('Webhook Health', 'wpcusn'); ?>
						</span>
					</div>
					<div class="wpcusn-panel-body" style="text-align: center;">
						<?php if ($webhook_id): ?>
							<div
								style="width: 48px; height: 48px; background: var(--wpcusn-success-bg); border-radius: 50%; color: var(--wpcusn-success-fg); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
								<span
									style="width: 24px; height: 24px;"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/check.svg'); ?></span>
							</div>
							<div style="font-weight: 600; margin-bottom: 4px; color: var(--wpcusn-success-fg);">
								<?php esc_html_e('Active', 'wpcusn'); ?>
							</div>
							<div style="font-size: 12px; color: var(--wpcusn-text-sec); margin-bottom: 16px;">
								ID: <?php echo esc_html($webhook_id); ?>
							</div>
						<?php else: ?>
							<div
								style="width: 48px; height: 48px; background: #f3f4f6; border-radius: 50%; color: #9ca3af; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
								<span
									style="width: 24px; height: 24px;"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/close.svg'); ?></span>
							</div>
							<div style="font-weight: 600; margin-bottom: 16px; color: var(--wpcusn-text-sec);">
								<?php esc_html_e('Not Configured', 'wpcusn'); ?>
							</div>
						<?php endif; ?>

						<input type="text" readonly value="<?php echo esc_url($webhook_url); ?>" class="wpcusn-input"
							style="font-size: 11px; margin-bottom: 12px; text-align: center; background: #f9f9f9;">
						<p class="wpcusn-helper-text" style="text-align: center; margin-top: -8px;">
							<?php esc_html_e('This is the URL that ClickUp will send webhook events to.', 'wpcusn'); ?>
						</p>


						<!-- PROXY ACTIONS -->
						<?php if (($is_connected || $api_key) && $space_id): ?>
							<?php if (!$webhook_id): ?>
								<button type="button" class="wpcusn-btn wpcusn-btn-white"
									style="border-style: dashed; width: 100%; margin-top: 8px;"
									onclick="triggerCreateWebhook()">
									+ <?php esc_html_e('Create Webhook Automatically', 'wpcusn'); ?>
								</button>
							<?php else: ?>
								<button type="button" class="wpcusn-btn wpcusn-btn-destructive"
									style="width: 100%; margin-top: 8px;" onclick="triggerDeleteWebhook()">
									<?php esc_html_e('Delete Webhook', 'wpcusn'); ?>
								</button>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				</section>

				<!-- MAINTENANCE PANEL -->
				<section class="wpcusn-panel">
					<div class="wpcusn-panel-header">
						<span class="wpcusn-panel-title">
							<span
								class="wpcusn-icon"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/maintenance.svg'); ?></span>
							<?php esc_html_e('Maintenance', 'wpcusn'); ?>
						</span>
					</div>
					<div class="wpcusn-panel-body">
						<div class="wpcusn-field-group">
							<label class="wpcusn-label"><?php esc_html_e('Log Limit', 'wpcusn'); ?></label>
							<?php $log_limit = get_option('wpcusn_log_limit', 200); ?>
							<select name="wpcusn_log_limit" class="wpcusn-select">
								<option value="50" <?php selected($log_limit, 50); ?>>50 Entries</option>
								<option value="100" <?php selected($log_limit, 100); ?>>100 Entries</option>
								<option value="200" <?php selected($log_limit, 200); ?>>200 Entries</option>
								<option value="500" <?php selected($log_limit, 500); ?>>500 Entries</option>
							</select>
						</div>

						<div class="wpcusn-field-group">
							<label class="wpcusn-label"><?php esc_html_e('Retention (Days)', 'wpcusn'); ?></label>
							<input type="number" name="wpcusn_log_retention_days"
								value="<?php echo esc_attr(get_option('wpcusn_log_retention_days', 7)); ?>"
								class="wpcusn-input" min="1" max="90">
						</div>

						<div class="wpcusn-toggle-row" style="background: transparent; border: none; padding: 0;">
							<div class="wpcusn-toggle-info">
								<span class="wpcusn-toggle-title"
									style="font-size: 13px;"><?php esc_html_e('Include Closed Tasks', 'wpcusn'); ?></span>
								<p
									style="font-size: 11px; color: #d97706; margin: 4px 0 0 0; display: flex; align-items: center; gap: 4px;">
									<span
										style="display: inline-flex; align-items: center; justify-content: center; width: 14px; height: 14px; flex-shrink: 0; position: relative; top: -1px;"><?php echo file_get_contents(WPCUSN_PLUGIN_DIR . 'admin/assets/icons/warning.svg'); ?></span>
									<?php esc_html_e('Warning: Enable only if needed. With 10K+ closed tasks, this slows search significantly.', 'wpcusn'); ?>
								</p>
							</div>
							<label class="wpcusn-switch" style="transform: scale(0.8);">
								<input type="checkbox" name="wpcusn_include_closed_tasks" value="1" <?php checked(get_option('wpcusn_include_closed_tasks', false)); ?>>
								<span class="wpcusn-slider"></span>
							</label>
						</div>
					</div>
				</section>

			</div>

		</div>

		<!-- FORM FOOTER WITH SAVE BUTTON -->
		<div class="wpcusn-form-footer">
			<button type="submit" class="wpcusn-btn wpcusn-btn-primary" style="min-width: 120px;">
				<?php esc_html_e('Save Settings', 'wpcusn'); ?>
			</button>
		</div>
	</form>

	<!-- WEBHOOK ACTIONS (Self-contained forms) -->
	<?php if (($is_connected || $api_key) && $space_id): ?>
		<div style="display: none;">
			<?php if (!$webhook_id): ?>
				<form id="wpcusn-create-webhook-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
					<input type="hidden" name="action" value="wpcusn_save_settings" />
					<?php wp_nonce_field('wpcusn_save_settings', 'wpcusn_settings_nonce'); ?>
					<?php wp_nonce_field('wpcusn_create_webhook', '_wpnonce'); ?>
					<input type="hidden" name="wpcusn_action" value="create_webhook" />
					<!-- Button removed for proxy -->
				</form>
			<?php else: ?>
				<form id="wpcusn-delete-webhook-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
					<input type="hidden" name="action" value="wpcusn_save_settings" />
					<?php wp_nonce_field('wpcusn_save_settings', 'wpcusn_settings_nonce'); ?>
					<?php wp_nonce_field('wpcusn_delete_webhook', '_wpnonce'); ?>
					<input type="hidden" name="wpcusn_action" value="delete_webhook" />
					<!-- Button removed for proxy -->
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>


	<!-- TERMINAL LOGS -->
	<div class="wpcusn-terminal-container">
		<div class="wpcusn-terminal-header">
			<div class="wpcusn-term-dot r"></div>
			<div class="wpcusn-term-dot y"></div>
			<div class="wpcusn-term-dot g"></div>
			<span class="wpcusn-term-title">wpcusn-sync.log — <?php echo (int) $log_count; ?> entries</span>
		</div>
		<div class="wpcusn-terminal-body">
			<?php if (empty($logs)): ?>
				<div class="wpcusn-log-line"
					style="border:none; display: block; text-align: center; color: #666; margin-top: 20px;">
					<span class="wpcusn-msg">-- No logs available --</span>
				</div>
			<?php else: ?>
				<?php foreach ($logs as $log): ?>
					<?php
					// DB rows are stdClass objects. Map to the same local vars the template uses.
					$message = '';
					$timestamp = $log->created_at ?? '';
					$direction = $log->direction ?? '';
					$new_status = $log->new_status ?? '';
					$error = $log->message ?? '';   // 'message' column holds error details
					$success = isset( $log->success ) ? (int) $log->success : null; // 1=success, 0=fail, NULL=info

					// Determine Badge
					$badge_class = 'info';
					$badge_label = 'INFO';

					if (strpos($direction, 'wp_to_clickup') !== false) {
						$badge_class = 'out';
						$badge_label = 'SYNC OUT';
					} elseif (strpos($direction, 'clickup_to_wp') !== false || strpos($direction, 'webhook') !== false) {
						$badge_class = 'in';
						$badge_label = 'SYNC IN';
					}

					if ($error || $success === 0) {
						$badge_class = 'err';
						$badge_label = 'ERROR';
					} elseif ($success === 1) {
						// $badge_class = 'in'; // Keep direction color but maybe add checkmark?
					}

					// Format message
					if ($log->post_id ?? false)
						$message .= "Post #" . $log->post_id . " ";
					if ($log->task_id ?? false)
						$message .= "Task " . $log->task_id . " ";
					if ($new_status)
						$message .= "→ " . $new_status;
					if ($error)
						$message .= " [" . $error . "]";
					if (isset($log->old_status))
						$message = "Status: " . $log->old_status . " " . $message;
					if ($direction === 'auto_link_success')
						$message = "Linked Post #" . ($log->post_id ?? '') . " to " . ($log->task_id ?? '');

					?>
					<div class="wpcusn-log-line">
						<span class="wpcusn-ts">[<?php echo esc_html($timestamp); ?>]</span>
						<span><span class="wpcusn-badge <?php echo $badge_class; ?>"><?php echo $badge_label; ?></span></span>
						<span class="wpcusn-msg">
							<?php echo esc_html($message ?: $direction); ?>
						</span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

</div>