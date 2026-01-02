<?php
/**
 * Settings Page View
 *
 * @package WPCUSN
 * @author Krafty Sprouts Media, LLC
 * @since 1.0.0
 * @version 1.0.0
 * @last_modified 2024-01-01
 *
 * HTML template for the settings page.
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$oauth = WPCUSN_ClickUp_OAuth::get_instance();
$api = WPCUSN_ClickUp_API::get_instance();
$is_connected = $oauth->is_connected();
$client_id = get_option( 'wpcusn_oauth_client_id' );
$client_secret = get_option( 'wpcusn_oauth_client_secret' );
$api_key = get_option( 'wpcusn_api_key' );
$list_id = get_option( 'wpcusn_list_id' );
$logs = get_option( 'wpcusn_sync_logs', array() );
$webhook_url = rest_url( 'clickup/v1/webhook' );
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'wpcusn_settings' ); ?>

		<h2><?php esc_html_e( 'Authentication', 'wpcusn' ); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Connection Status', 'wpcusn' ); ?></th>
				<td>
					<?php if ( $is_connected ) : ?>
						<span style="color: green;">✓ <?php esc_html_e( 'Connected', 'wpcusn' ); ?></span>
						<form method="post" style="display: inline-block; margin-left: 20px;">
							<?php wp_nonce_field( 'wpcusn_disconnect' ); ?>
							<input type="submit" name="wpcusn_disconnect" class="button" value="<?php esc_attr_e( 'Disconnect', 'wpcusn' ); ?>" />
						</form>
					<?php else : ?>
						<span style="color: red;">✗ <?php esc_html_e( 'Not Connected', 'wpcusn' ); ?></span>
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
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'API Key (Alternative)', 'wpcusn' ); ?></th>
				<td>
					<input type="text" id="wpcusn_api_key" name="wpcusn_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Use this if you prefer not to use OAuth2. Get your API key from ClickUp Settings → Apps → API Token.', 'wpcusn' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Configuration', 'wpcusn' ); ?></h2>

		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="wpcusn_list_id"><?php esc_html_e( 'List ID', 'wpcusn' ); ?></label>
				</th>
				<td>
					<input type="text" id="wpcusn_list_id" name="wpcusn_list_id" value="<?php echo esc_attr( $list_id ); ?>" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'The ClickUp List ID where your tasks are located. Found in the list URL: app.clickup.com/.../{list_id}', 'wpcusn' ); ?>
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

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Webhook URL', 'wpcusn' ); ?></th>
				<td>
					<input type="text" readonly value="<?php echo esc_url( $webhook_url ); ?>" class="regular-text" onclick="this.select();" />
					<button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>'); alert('Copied!');">
						<?php esc_html_e( 'Copy', 'wpcusn' ); ?>
					</button>
					<p class="description">
						<?php esc_html_e( 'Configure this URL in ClickUp: Space Settings → Integrations → Webhooks. Event: Task Status Updated.', 'wpcusn' ); ?>
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
					<th><?php esc_html_e( 'Post ID', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Direction', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Status Change', 'wpcusn' ); ?></th>
					<th><?php esc_html_e( 'Result', 'wpcusn' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $logs ) as $log ) : ?>
					<tr>
						<td><?php echo esc_html( $log['timestamp'] ); ?></td>
						<td><?php echo esc_html( $log['post_id'] ); ?></td>
						<td><?php echo esc_html( $log['direction'] ); ?></td>
						<td>
							<?php echo esc_html( $log['old_status'] ); ?> → <?php echo esc_html( $log['new_status'] ); ?>
						</td>
						<td>
							<?php if ( $log['success'] ) : ?>
								<span style="color: green;">✓</span>
							<?php else : ?>
								<span style="color: red;">✗</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

