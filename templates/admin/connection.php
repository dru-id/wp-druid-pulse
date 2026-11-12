<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'DruID Pulse — Connection', 'wp-druid-pulse' ); ?></h1>
	<p><?php esc_html_e( 'Enter your Pulse connection details, save them and test authentication.', 'wp-druid-pulse' ); ?></p>
	<?php if ( is_wp_error( $delivery ) || '' !== $delivery['error'] ) : ?>
		<div class="notice notice-warning"><p><?php esc_html_e( 'Delivery needs attention. Review and save these settings, then test authentication.', 'wp-druid-pulse' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-druid-pulse-diagnostics' ) ); ?>"><?php esc_html_e( 'View delivery status', 'wp-druid-pulse' ); ?></a>
		</p></div>
	<?php endif; ?>
	<?php if ( '1' === ( $_GET['saved'] ?? null ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connection settings saved.', 'wp-druid-pulse' ); ?></p></div>
	<?php endif; ?>
	<?php if ( '1' === ( $_GET['tested'] ?? null ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'OAuth authentication succeeded. No identities or events were sent; this does not verify delivery to Pulse.', 'wp-druid-pulse' ); ?></p></div>
	<?php endif; ?>
	<p><strong><?php esc_html_e( 'Site shown in Pulse:', 'wp-druid-pulse' ); ?></strong> <?php echo esc_html( $application['displayName'] ?? '' ); ?> — <?php echo esc_html( $application['url'] ); ?>
		<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e( 'Edit site name and address', 'wp-druid-pulse' ); ?></a></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $save_action ); ?>">
		<?php wp_nonce_field( $save_action ); ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="pulse-api-url"><?php esc_html_e( 'Pulse API base URL', 'wp-druid-pulse' ); ?></label></th><td>
				<input class="regular-text" id="pulse-api-url" name="api_url" type="url" value="<?php echo esc_attr( $settings['api_url'] ); ?>">
				<p class="description"><?php esc_html_e( 'HTTPS address of the Pulse API, without /identities.', 'wp-druid-pulse' ); ?></p>
			</td></tr>
			<tr><th scope="row"><label for="pulse-oauth-url"><?php esc_html_e( 'Authorization server base URL', 'wp-druid-pulse' ); ?></label></th><td>
				<input class="regular-text" id="pulse-oauth-url" name="oauth_url" type="url" value="<?php echo esc_attr( $settings['oauth_url'] ); ?>">
				<p class="description"><?php esc_html_e( 'HTTPS address, without /oauth2/token.', 'wp-druid-pulse' ); ?></p>
			</td></tr>
			<tr><th scope="row"><label for="pulse-client-id"><?php esc_html_e( 'Client ID', 'wp-druid-pulse' ); ?></label></th><td>
				<input class="regular-text" id="pulse-client-id" name="client_id" type="text" value="<?php echo esc_attr( $settings['client_id'] ); ?>" autocomplete="off">
			</td></tr>
			<tr><th scope="row"><label for="pulse-client-secret"><?php esc_html_e( 'Client secret', 'wp-druid-pulse' ); ?></label></th><td>
				<input class="regular-text" id="pulse-client-secret" name="client_secret" type="password" value="" autocomplete="new-password">
				<p class="description"><?php echo esc_html( $has_secret ? __( 'A secret is saved. Leave this field empty to keep it, or enter a replacement.', 'wp-druid-pulse' ) : __( 'No secret is saved.', 'wp-druid-pulse' ) ); ?></p>
				<label><input type="checkbox" name="remove_secret" value="1"> <?php esc_html_e( 'Remove saved secret', 'wp-druid-pulse' ); ?></label>
			</td></tr>
			<tr><th scope="row"><label for="pulse-auth-method"><?php esc_html_e( 'Client authentication', 'wp-druid-pulse' ); ?></label></th><td>
				<select id="pulse-auth-method" name="auth_method">
					<option value="client_secret_basic" <?php selected( $settings['auth_method'], 'client_secret_basic' ); ?>>client_secret_basic</option>
					<option value="client_secret_post" <?php selected( $settings['auth_method'], 'client_secret_post' ); ?>>client_secret_post</option>
				</select>
				<p class="description"><?php esc_html_e( 'Use the method enabled for this client on the authorization server.', 'wp-druid-pulse' ); ?></p>
			</td></tr>
			<tr><th scope="row"><label for="pulse-scope"><?php esc_html_e( 'OAuth scopes (optional)', 'wp-druid-pulse' ); ?></label></th><td>
				<input class="regular-text" id="pulse-scope" name="scope" type="text" value="<?php echo esc_attr( $settings['scope'] ); ?>">
				<p class="description"><?php esc_html_e( 'Leave empty unless your authorization server requires specific scopes.', 'wp-druid-pulse' ); ?></p>
			</td></tr>
			<tr><th scope="row"><label for="pulse-tenant"><?php esc_html_e( 'Pulse tenant (optional)', 'wp-druid-pulse' ); ?></label></th><td>
				<input class="regular-text" id="pulse-tenant" name="tenant" type="text" value="<?php echo esc_attr( $settings['tenant'] ); ?>">
				<p class="description"><?php esc_html_e( 'Only fill in if your Pulse connection requires a tenant.', 'wp-druid-pulse' ); ?></p>
			</td></tr>
		</table>
		<?php submit_button( __( 'Save connection', 'wp-druid-pulse' ) ); ?>
	</form>
	<h2><?php esc_html_e( 'Test saved connection', 'wp-druid-pulse' ); ?></h2>
	<p><?php esc_html_e( 'Save changes first. This checks authentication with the saved settings; it does not send events.', 'wp-druid-pulse' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $test_action ); ?>">
		<?php wp_nonce_field( $test_action ); ?>
		<?php submit_button( __( 'Test OAuth authentication', 'wp-druid-pulse' ), 'secondary' ); ?>
	</form>
</div>
