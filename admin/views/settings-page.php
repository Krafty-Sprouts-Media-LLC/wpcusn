<?php
/**
 * Settings Page View
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$oauth = WPCUSN_ClickUp_OAuth::get_instance();
$is_connected = $oauth->is_connected();
$client_id = get_option( 'wpcusn_oauth_client_id' );
$client_secret = get_option( 'wpcusn_oauth_client_secret' );
$api_key = get_option( 'wpcusn_api_key' );
$list_id = get_option( 'wpcusn_list_id' );
$webhook_url = rest_url( 'clickup/v1/webhook' );
$logs = get_option( 'wpcusn_sync_logs', array() );
$logs = array_slice( array_reverse( $logs ), 0, 50 ); // Last 50 entries
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'wpcusn_settings' ); ?>

		<h2><?php esc_html_e( 'Authentication', 'wpcusn' ); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'API Key (Alternative)', 'wpcusn' ); ?></th>
				<td>
					<input type="password" id="wpcusn_api_key" name="wpcusn_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Get your API key from ClickUp Settings → Apps → API Token', 'wpcusn' ); ?>
					</p>
					<?php if ( $api_key && ! $is_connected ) : ?>
						<p>
							<span style="color: green;">✓ <?php esc_html_e( 'API Key configured', 'wpcusn' ); ?></span>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'OAuth2 (Recommended)', 'wpcusn' ); ?></th>
				<td>
					<p>
						<label for="wpcusn_oauth_client_id"><?php esc_html_e( 'Client ID:', 'wpcusn' ); ?></label><br />
						<input type="text" id="wpcusn_oauth_client_id" name="wpcusn_oauth_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" />
					</p>
					<p>
						<label for="wpcusn_oauth_client_secret"><?php esc_html_e( 'Client Secret:', 'wpcusn' ); ?></label><br />
						<input type="password" id="wpcusn_oauth_client_secret" name="wpcusn_oauth_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" />
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Redirect URI:', 'wpcusn' ); ?></strong><br />
						<code style="display: block; padding: 8px; background: #f5f5f5; margin-top: 5px;"><?php echo esc_url( admin_url( 'options-general.php?page=wpcusn&action=oauth_callback' ) ); ?></code>
						<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js( admin_url( 'options-general.php?page=wpcusn&action=oauth_callback' ) ); ?>'); alert('<?php esc_html_e( 'Redirect URI copied to clipboard!', 'wpcusn' ); ?>');" style="margin-top: 5px;">
							<?php esc_html_e( 'Copy', 'wpcusn' ); ?>
						</button>
						<br />
						<small><?php esc_html_e( 'Add this URL to your ClickUp app\'s Redirect URL(s) field.', 'wpcusn' ); ?></small>
					</p>
					<?php if ( $client_id && $client_secret && ! $is_connected ) : ?>
						<p>
							<?php
							$auth_url = $oauth->get_authorization_url();
							if ( $auth_url ) :
								?>
								<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">
									<?php esc_html_e( 'Connect to ClickUp', 'wpcusn' ); ?>
								</a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
					<?php if ( $is_connected ) : ?>
						<p>
							<span style="color: green;">✓ <?php esc_html_e( 'Connected to ClickUp', 'wpcusn' ); ?></span>
							<form method="post" action="" style="display: inline;">
								<?php wp_nonce_field( 'wpcusn_disconnect' ); ?>
								<input type="hidden" name="wpcusn_disconnect" value="1" />
								<button type="submit" class="button" style="margin-left: 10px;">
									<?php esc_html_e( 'Disconnect', 'wpcusn' ); ?>
								</button>
							</form>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Configuration', 'wpcusn' ); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="wpcusn_space_id"><?php esc_html_e( 'Space', 'wpcusn' ); ?></label>
				</th>
				<td>
					<?php $space_id = get_option( 'wpcusn_space_id' ); ?>
					<?php if ( $is_connected ) : ?>
						<select id="wpcusn_space_id" name="wpcusn_space_id" class="regular-text">
							<option value=""><?php esc_html_e( '-- Select a Space --', 'wpcusn' ); ?></option>
							<?php if ( $space_id ) : ?>
								<option value="<?php echo esc_attr( $space_id ); ?>" selected><?php echo esc_html( $space_id ); ?> (<?php esc_html_e( 'Current', 'wpcusn' ); ?>)</option>
							<?php endif; ?>
						</select>
						<button type="button" id="wpcusn-load-spaces" class="button" style="margin-left: 5px;">
							<?php esc_html_e( 'Load Spaces', 'wpcusn' ); ?>
						</button>
						<span id="wpcusn-spaces-loading" style="display: none; margin-left: 10px;"><?php esc_html_e( 'Loading...', 'wpcusn' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Click "Load Spaces" to fetch your ClickUp spaces. The plugin will search across all lists in the selected space.', 'wpcusn' ); ?>
						</p>
						<input type="text" id="wpcusn_space_id_manual" name="wpcusn_space_id" value="<?php echo esc_attr( $space_id ); ?>" class="regular-text" style="display: none; margin-top: 5px;" placeholder="<?php esc_attr_e( 'Or enter Space ID manually', 'wpcusn' ); ?>" />
						<p class="description" style="margin-top: 5px;">
							<a href="#" id="wpcusn-toggle-manual-space" style="text-decoration: none;"><?php esc_html_e( 'Enter Space ID manually', 'wpcusn' ); ?></a>
						</p>
					<?php else : ?>
						<input type="text" id="wpcusn_space_id" name="wpcusn_space_id" value="<?php echo esc_attr( $space_id ); ?>" class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'Connect to ClickUp first to load spaces automatically, or enter the Space ID manually. Found in the space URL: app.clickup.com/{space_id}/...', 'wpcusn' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="wpcusn_list_id"><?php esc_html_e( 'List ID (Optional)', 'wpcusn' ); ?></label>
				</th>
				<td>
					<input type="text" id="wpcusn_list_id" name="wpcusn_list_id" value="<?php echo esc_attr( $list_id ); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Optional: Limit search to a specific list. If not provided, the plugin will search across all lists in the space.', 'wpcusn' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Status Mapping', 'wpcusn' ); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'WordPress → ClickUp', 'wpcusn' ); ?></th>
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
				<th scope="row"><?php esc_html_e( 'ClickUp → WordPress', 'wpcusn' ); ?></th>
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

		<h2><?php esc_html_e( 'Webhook Configuration', 'wpcusn' ); ?></h2>

		<?php
		$webhook_id = get_option( 'wpcusn_webhook_id' );
		$webhook_secret = get_option( 'wpcusn_webhook_secret' );
		$space_id = get_option( 'wpcusn_space_id' );
		$is_connected = WPCUSN_ClickUp_OAuth::get_instance()->is_connected();
		?>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook URL', 'wpcusn' ); ?></th>
				<td>
					<input type="text" readonly value="<?php echo esc_url( $webhook_url ); ?>" class="regular-text" onclick="this.select();" />
					<button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>'); alert('Copied!');">
						<?php esc_html_e( 'Copy', 'wpcusn' ); ?>
					</button>
				</td>
			</tr>
			<?php if ( $webhook_id ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Webhook Status', 'wpcusn' ); ?></th>
					<td>
						<span style="color: green;">✓ <?php esc_html_e( 'Webhook is active', 'wpcusn' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'Webhook ID:', 'wpcusn' ); ?> <code><?php echo esc_html( $webhook_id ); ?></code>
						</p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook Setup', 'wpcusn' ); ?></th>
				<td>
					<?php if ( $is_connected && $space_id ) : ?>
						<?php if ( ! $webhook_id ) : ?>
							<form method="post" action="">
								<?php wp_nonce_field( 'wpcusn_create_webhook' ); ?>
								<input type="hidden" name="wpcusn_action" value="create_webhook" />
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Create Webhook Automatically', 'wpcusn' ); ?>
								</button>
								<p class="description">
									<?php esc_html_e( 'This will create a webhook in ClickUp for "taskStatusUpdated" events in your space.', 'wpcusn' ); ?>
								</p>
							</form>
						<?php else : ?>
							<form method="post" action="">
								<?php wp_nonce_field( 'wpcusn_delete_webhook' ); ?>
								<input type="hidden" name="wpcusn_action" value="delete_webhook" />
								<button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this webhook?', 'wpcusn' ); ?>');">
									<?php esc_html_e( 'Delete Webhook', 'wpcusn' ); ?>
								</button>
							</form>
						<?php endif; ?>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Please connect to ClickUp and configure your Space ID first.', 'wpcusn' ); ?>
						</p>
					<?php endif; ?>
					<p class="description" style="margin-top: 10px;">
						<strong><?php esc_html_e( 'Manual Setup (Alternative):', 'wpcusn' ); ?></strong><br />
						<?php esc_html_e( 'Webhooks must be created via the ClickUp API. Use the Create Webhook endpoint with:', 'wpcusn' ); ?><br />
						• <?php esc_html_e( 'Endpoint:', 'wpcusn' ); ?> <code><?php echo esc_url( $webhook_url ); ?></code><br />
						• <?php esc_html_e( 'Event:', 'wpcusn' ); ?> <code>taskStatusUpdated</code><br />
						• <?php esc_html_e( 'Space ID:', 'wpcusn' ); ?> <code><?php echo esc_html( $space_id ?: 'Your Space ID' ); ?></code>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<h2><?php esc_html_e( 'Sync Logs', 'wpcusn' ); ?></h2>

	<?php if ( empty( $logs ) ) : ?>
		<p><?php esc_html_e( 'No sync events yet.', 'wpcusn' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Post', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Task', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Direction', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Status Change', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Result', 'wpcusn' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['time'] ?? '' ); ?></td>
						<td>
							<?php
							$post_id = $log['post_id'] ?? 0;
							if ( $post_id ) {
								$post = get_post( $post_id );
								if ( $post ) {
									echo '<a href="' . esc_url( get_edit_post_link( $post_id ) ) . '">' . esc_html( $post->post_title ) . '</a>';
								} else {
									echo esc_html( $post_id );
								}
							}
							?>
						</td>
						<td><?php echo esc_html( $log['task_id'] ?? '' ); ?></td>
						<td><?php echo esc_html( $log['direction'] ?? '' ); ?></td>
						<td>
							<?php
							$old_status = $log['old_status'] ?? '';
							$new_status = $log['new_status'] ?? '';
							echo esc_html( $old_status ) . ' → ' . esc_html( $new_status );
							?>
						</td>
						<td>
							<?php
							$success = $log['success'] ?? false;
							if ( $success ) {
								echo '<span style="color: green;">✓ ' . esc_html__( 'Success', 'wpcusn' ) . '</span>';
							} else {
								echo '<span style="color: red;">✗ ' . esc_html__( 'Failed', 'wpcusn' ) . '</span>';
								if ( isset( $log['error'] ) ) {
									echo '<br /><small>' . esc_html( $log['error'] ) . '</small>';
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

<?php if ( $is_connected ) : ?>
<script>
jQuery(document).ready(function($) {
	var loadingSpaces = false;

	$('#wpcusn-load-spaces').on('click', function() {
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
				nonce: '<?php echo esc_js( wp_create_nonce( 'wpcusn_get_spaces' ) ); ?>'
			},
			success: function(response) {
				if (response.success && response.data.spaces) {
					// Clear existing options except the first one
					select.find('option:not(:first)').remove();
					
					// Add spaces
					$.each(response.data.spaces, function(i, space) {
						var selected = space.id === '<?php echo esc_js( $space_id ); ?>' ? ' selected' : '';
						var label = space.name + ' (' + space.team_name + ') - ID: ' + space.id;
						select.append('<option value="' + space.id + '"' + selected + '>' + label + '</option>');
					});
					
					if (response.data.spaces.length === 0) {
						select.append('<option value=""><?php esc_html_e( 'No spaces found', 'wpcusn' ); ?></option>');
					}
				} else {
					alert(response.data.message || '<?php esc_html_e( 'Failed to load spaces', 'wpcusn' ); ?>');
				}
			},
			error: function() {
				alert('<?php esc_html_e( 'Error loading spaces', 'wpcusn' ); ?>');
			},
			complete: function() {
				button.prop('disabled', false);
				loading.hide();
				loadingSpaces = false;
			}
		});
	});

	$('#wpcusn-toggle-manual-space').on('click', function(e) {
		e.preventDefault();
		var select = $('#wpcusn_space_id');
		var manual = $('#wpcusn_space_id_manual');
		var link = $(this);
		
		if (manual.is(':visible')) {
			manual.hide();
			select.show();
			link.text('<?php esc_html_e( 'Enter Space ID manually', 'wpcusn' ); ?>');
		} else {
			select.hide();
			manual.show();
			link.text('<?php esc_html_e( 'Use dropdown instead', 'wpcusn' ); ?>');
		}
	});
});
</script>
<?php endif; ?>
