<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	fwrite( STDERR, "An isolated test installation is required.\n" );
	exit( 1 );
}

use WP_Druid_Pulse\ConnectionSettings;

$credentials = json_decode( file_get_contents( getenv( 'WP_TEST_CREDENTIALS' ) ?: '/test/private.json' ), true );
$base = home_url();
$page = $base . '/wp-admin/admin.php?page=wp-druid-pulse-connection';
$post = $base . '/wp-admin/admin-post.php';
$checks = 0;
$jars = array( tempnam( '/tmp', 'pulseadmin-' ), tempnam( '/tmp', 'pulsereader-' ) );

function check_http( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function request( string $url, string $jar, ?array $data = null, bool $follow = false ): array {
	$curl = curl_init( $url );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 15 ) );
	if ( null !== $data ) {
		curl_setopt_array( $curl, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query( $data ) ) );
	}
	$body = curl_exec( $curl );
	$status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	curl_close( $curl );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'Local HTTP request failed.' );
	}
	return array( 'status' => $status, 'body' => $body );
}
function nonce( string $html, string $action ): string {
	$dom = new DOMDocument();
	@$dom->loadHTML( $html );
	$xpath = new DOMXPath( $dom );
	$nodes = $xpath->query( '//form[input[@name="action" and @value="' . $action . '"]]//input[@name="_wpnonce"]/@value' );
	if ( 1 !== $nodes->length ) {
		throw new RuntimeException( 'Could not find the protected form.' );
	}
	return $nodes->item( 0 )->nodeValue;
}
function fresh_settings(): array|WP_Error {
	wp_cache_delete( ConnectionSettings::OPTION, 'options' );
	return ConnectionSettings::read();
}

try {
	foreach ( array( 'admin', 'subscriber' ) as $i => $role ) {
		request( $base . '/wp-login.php', $jars[ $i ] );
		$login = request( $base . '/wp-login.php', $jars[ $i ], array( 'log' => $credentials[ $role . '_user' ], 'pwd' => $credentials[ $role . '_pass' ], 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => $page ) );
		check_http( 302 === $login['status'], 'Test user could not log in.' );
	}
	$admin = request( $page, $jars[0] );
	check_http( 200 === $admin['status'], 'Administrator could not open Connection.' );
	check_http( 403 === request( $page, $jars[1] )['status'], 'Subscriber could open Connection.' );
	$save_nonce = nonce( $admin['body'], 'wp_druid_pulse_save_connection' );
	$test_nonce = nonce( $admin['body'], 'wp_druid_pulse_test_connection' );
	$settings = array( 'api_url' => 'https://pulse.example/api', 'oauth_url' => 'https://auth.example', 'client_id' => '"><script>unsafe()</script>', 'client_secret' => 'http-fixture-secret', 'auth_method' => 'client_secret_basic', 'scope' => '', 'tenant' => '' );
	$form = array_merge( $settings, array( 'action' => 'wp_druid_pulse_save_connection', '_wpnonce' => $save_nonce ) );
	check_http( 302 === request( $post, $jars[0], $form )['status'], 'Protected save failed.' );
	check_http( $settings === fresh_settings(), 'HTTP save did not persist the submitted values.' );
	$admin = request( $page, $jars[0] );
	check_http( ! str_contains( $admin['body'], $settings['client_secret'] ) && str_contains( $admin['body'], 'name="client_secret" type="password" value=""' ), 'Stored secret was returned to the browser.' );
	check_http( ! str_contains( $admin['body'], '<script>unsafe()</script>' ) && str_contains( $admin['body'], '&lt;script&gt;unsafe()&lt;/script&gt;' ), 'Connection values were not escaped.' );
	$empty = array_replace( $form, array( 'client_secret' => '' ) );
	check_http( 302 === request( $post, $jars[0], $empty )['status'] && $settings === fresh_settings(), 'Blank secret overwrote the existing one.' );
	foreach ( array( '', 'invalid', array( 'invalid' ) ) as $invalid_nonce ) {
		check_http( 403 === request( $post, $jars[0], array_replace( $form, array( '_wpnonce' => $invalid_nonce, 'client_id' => 'must-not-save' ) ) )['status'], 'Invalid nonce allowed saving.' );
	}
	check_http( 403 === request( $post, $jars[1], $form )['status'] && $settings === fresh_settings(), 'Subscriber changed the saved settings.' );
	check_http( 405 === request( $post . '?action=wp_druid_pulse_save_connection', $jars[0] )['status'], 'GET was allowed to save.' );
	check_http( 400 === request( $post, $jars[0], array_replace( $form, array( 'client_secret' => array( 'invalid' ) ) ) )['status'], 'Malformed secret input was accepted.' );
	$test = array( 'action' => 'wp_druid_pulse_test_connection', '_wpnonce' => $test_nonce );
	update_option( 'pulse_phase4_oauth_calls', 0 );
	update_option( 'pulse_phase4_oauth_mode', 'success' );
	check_http( 403 === request( $post, $jars[1], $test )['status'], 'Subscriber could test OAuth.' );
	check_http( 403 === request( $post, $jars[0], array_replace( $test, array( '_wpnonce' => 'invalid' ) ) )['status'], 'Invalid nonce allowed OAuth.' );
	wp_cache_delete( 'pulse_phase4_oauth_calls', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	check_http( 0 === (int) get_option( 'pulse_phase4_oauth_calls' ), 'Unauthorized test caused an outbound call.' );
	check_http( 302 === request( $post, $jars[0], $test )['status'], 'OAuth test failed.' );
	wp_cache_delete( 'pulse_phase4_oauth_calls', 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	check_http( 1 === (int) get_option( 'pulse_phase4_oauth_calls' ), 'OAuth test did not use saved credentials exactly once.' );
	$success = request( $page . '&tested=1', $jars[0] );
	check_http( str_contains( $success['body'], 'No identities or events were sent' ) && ! str_contains( $success['body'], 'http.fixture.token' ), 'OAuth result leaked a token or claimed event delivery.' );
	update_option( 'pulse_phase4_oauth_mode', 'rejected' );
	$failure = request( $post, $jars[0], $test );
	check_http( 502 === $failure['status'] && str_contains( $failure['body'], 'HTTP 401' ) && ! str_contains( $failure['body'], $settings['client_secret'] ), 'OAuth error exposed the server response or reported success.' );
	check_http( 302 === request( $post, $jars[0], array_replace( $empty, array( 'remove_secret' => '1' ) ) )['status'] && '' === fresh_settings()['client_secret'], 'Explicit secret removal failed.' );
	check_http( 400 === request( $post, $jars[0], $test )['status'], 'OAuth test accepted incomplete settings.' );
	$tracking = request( $base . '/wp-admin/admin.php?page=wp-druid-pulse', $jars[0] );
	check_http( 200 === $tracking['status'] && str_contains( $tracking['body'], 'Selected before upgrade' ), 'The existing tracking page regressed.' );
	check_http( 200 === request( $base . '/', $jars[0] )['status'], 'Frontend failed.' );
	$delivery = \WP_Druid_Pulse\DeliveryState::read();
	$delivery['retry_at'] = time() + 3600;
	$delivery['error'] = 'wp_druid_pulse_http_403';
	update_option( \WP_Druid_Pulse\DeliveryState::OPTION, $delivery, false );
	$notice = request( $page, $jars[0] );
	check_http( str_contains( $notice['body'], 'wp_druid_pulse_http_403' ) && ! str_contains( $notice['body'], 'http.fixture.token' ), 'Connection recovery notice is absent or leaks a token.' );
	check_http( 302 === request( $post, $jars[0], $form )['status'], 'Saving connection while delivery is paused failed.' );
	wp_cache_delete( \WP_Druid_Pulse\DeliveryState::OPTION, 'options' );
	check_http( 0 === \WP_Druid_Pulse\DeliveryState::read()['retry_at'], 'Connection save did not clear delivery pause.' );
	echo "Passed {$checks} HTTP administration assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	exit( 1 );
} finally {
	foreach ( $jars as $jar ) {
		unlink( $jar );
	}
}
