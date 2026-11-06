<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	fwrite( STDERR, "An isolated test installation is required.\n" );
	exit( 1 );
}

use WP_Druid_Pulse\EventMapper;
use WP_Druid_Pulse\EventPreparer;
use WP_Druid_Pulse\EventStore;

require_once WP_PLUGIN_DIR . '/wp-druid-pulse/src/EventPreparer.php';

$credentials = json_decode( file_get_contents( getenv( 'WP_TEST_CREDENTIALS' ) ?: '/test/private.json' ), true );
$base = home_url();
// Keep this endpoint valid while the view and click suites change permalinks.
$endpoint = add_query_arg( 'rest_route', '/wp-druid-pulse/v1/events', home_url( '/' ) );
$checks = 0;
$exit_code = 0;
$jars = array( tempnam( '/tmp', 'pulse-admin-' ), tempnam( '/tmp', 'pulse-subscriber-' ) );
$application_password = null;
$lock = 'druid_pulse_' . substr( hash( 'sha256', DB_NAME . ':' . EventStore::table() ), 0, 48 );
$event = array( 'type' => 'fixture_activity', 'published' => '2026-11-05T12:30:00Z', 'context' => array(
	'page' => array( 'key' => 'fixture:page', 'url' => home_url( '/' ), 'title' => 'Synthetic page' ), 'label' => '<b>Original</b>',
) );

function verify_receiver( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function receiver_request( string $url, ?string $jar = null, string $method = 'GET', ?string $body = null, array $headers = array(), ?string $basic = null ): array {
	$response_headers = array();
	$curl = curl_init( $url );
	$options = array( CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15,
		CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ( $curl, string $line ) use ( &$response_headers ): int {
			if ( str_contains( $line, ':' ) ) {
				list( $name, $value ) = explode( ':', $line, 2 );
				$response_headers[ strtolower( $name ) ] = trim( $value );
			}
			return strlen( $line );
		},
	);
	if ( null !== $jar ) {
		$options[ CURLOPT_COOKIEFILE ] = $jar;
		$options[ CURLOPT_COOKIEJAR ] = $jar;
	}
	if ( null !== $body ) {
		$options[ CURLOPT_POSTFIELDS ] = $body;
	}
	if ( null !== $basic ) {
		$options[ CURLOPT_USERPWD ] = $basic;
	}
	curl_setopt_array( $curl, $options );
	$body = curl_exec( $curl );
	$status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	curl_close( $curl );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'Local HTTP request failed.' );
	}
	return array( 'status' => $status, 'body' => $body, 'headers' => $response_headers, 'json' => json_decode( $body, true ) );
}
function browser_login( int $index ): array {
	global $credentials, $base, $jars;
	$role = 0 === $index ? 'admin' : 'subscriber';
	receiver_request( $base . '/wp-login.php', $jars[ $index ] );
	$login = receiver_request( $base . '/wp-login.php', $jars[ $index ], 'POST', http_build_query( array(
		'log' => $credentials[ $role . '_user' ], 'pwd' => $credentials[ $role . '_pass' ], 'testcookie' => '1', 'redirect_to' => $base,
	) ), array( 'Content-Type: application/x-www-form-urlencoded' ) );
	verify_receiver( 302 === $login['status'], 'The test user could not log in.' );
	$front = receiver_request( $base . '/', $jars[ $index ] );
	verify_receiver( 200 === $front['status'] && str_contains( $front['body'], 'assets/frontend-transport.js?ver=0.8.0' ), 'Authenticated frontend did not load the transport.' );
	verify_receiver( 1 === preg_match( '/window\.wpDruidPulseSettings = (\{[^\n]+\});/', $front['body'], $match ), 'Session-specific settings were not found.' );
	$settings = json_decode( $match[1], true );
	verify_receiver( array( 'endpoint', 'nonce', 'maxBytes' ) === array_keys( $settings ) && 16384 === $settings['maxBytes'], 'Unexpected browser settings or exposed configuration.' );
	return $settings;
}
function queue_count(): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(event_id) FROM %i', EventStore::table() ) );
}
function rejected_request( string $body, ?string $jar, array $headers, int $status, ?string $code = null, ?string $url = null, string $method = 'POST' ): array {
	global $endpoint;
	$before = queue_count();
	$result = receiver_request( $url ?? $endpoint, $jar, $method, $body, $headers );
	verify_receiver( $status === $result['status'] && ( null === $code || $code === ( $result['json']['code'] ?? null ) )
		&& $before === queue_count(), 'Rejected request was stored or had an unexpected response (' . $status . ' expected, ' . $result['status'] . ' received).' );
	return $result;
}
function login_cookie( string $jar ): string {
	foreach ( file( $jar, FILE_IGNORE_NEW_LINES ) as $line ) {
		$fields = explode( "\t", $line );
		if ( 7 === count( $fields ) && LOGGED_IN_COOKIE === $fields[5] ) {
			return urldecode( $fields[6] );
		}
	}
	throw new RuntimeException( 'The browser session cookie was not found.' );
}

try {
	delete_option( 'pulse_phase6_receiver_mode' );
	$before = queue_count();
	$settings = array( browser_login( 0 ), browser_login( 1 ) );
	$users = array( get_user_by( 'login', $credentials['admin_user'] ), get_user_by( 'login', $credentials['subscriber_user'] ) );
	$body = wp_json_encode( $event );
	$headers = array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $settings[0]['nonce'] );
	$subscriber_headers = array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $settings[1]['nonce'] );
	$anonymous = receiver_request( $base . '/' );
	$admin = receiver_request( $base . '/wp-admin/', $jars[0] );
	verify_receiver( ! str_contains( $anonymous['body'], 'wpDruidPulseSettings' ) && ! str_contains( $anonymous['body'], 'frontend-transport.js' ), 'Anonymous frontend exposed transport settings.' );
	verify_receiver( 200 === $admin['status'] && ! str_contains( $admin['body'], 'frontend-transport.js' ) && ! str_contains( $admin['body'], 'wpDruidPulseSettings' ), 'Transport was loaded in administration.' );
	verify_receiver( $before === queue_count(), 'Requests without JavaScript unexpectedly captured events.' );

	$store = new EventStore();
	foreach ( array( $headers, $subscriber_headers ) as $i => $auth ) {
		$result = receiver_request( $endpoint, $jars[ $i ], 'POST', $body, $auth );
		verify_receiver( 202 === $result['status'] && true === ( $result['json']['accepted'] ?? null ) && wp_is_uuid( $result['json']['event_id'] ?? '' )
			&& array( 'accepted', 'event_id' ) === array_keys( $result['json'] ), 'Valid browser session did not receive a local acknowledgement.' );
		$saved = $store->get( $result['json']['event_id'] );
		verify_receiver( ! is_wp_error( $saved ) && (int) $users[ $i ]->ID === $saved['user_id'] && '2026-11-05 12:30:00' === $saved['published']
			&& 'Original' === $saved['context']['label'] && 1 === $saved['format_version'], 'Attribution, validation or original timestamp was not persisted.' );
		verify_receiver( 'no-store, private' === ( $result['headers']['cache-control'] ?? null ) && ! isset( $result['headers']['access-control-allow-origin'] ) && ! isset( $result['headers']['access-control-allow-credentials'] )
			&& ! empty( $result['headers']['x-wp-nonce'] ), 'Receipt must be private and provide the native nonce refresh.' );
		$prepared = ( new EventPreparer( $store, new EventMapper() ) )->prepare( $result['json']['event_id'] );
		verify_receiver( ! is_wp_error( $prepared ) && 'test' === $prepared['verb'] && 'fixture' === $prepared['object']['objectType']
			&& $users[ $i ]->user_email === $prepared['actor']['schemaOrg']['email']['value'], 'The registered type did not prepare through the existing mapper and actor resolver.' );
	}
	$refreshed = array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $result['headers']['x-wp-nonce'], 'Origin: ' . $base );
	verify_receiver( 202 === receiver_request( $endpoint, $jars[1], 'POST', $body, $refreshed )['status'], 'Refreshed nonce or same-origin request failed.' );
	$spoof_query = receiver_request( add_query_arg( 'user_id', $users[1]->ID, $endpoint ), $jars[0], 'POST', $body, $headers );
	verify_receiver( 202 === $spoof_query['status'] && (int) $users[0]->ID === $store->get( $spoof_query['json']['event_id'] )['user_id'], 'Query parameters changed the authenticated actor.' );

	rejected_request( $body, null, $headers, 403, 'rest_cookie_invalid_nonce' );
	rejected_request( $body, null, array( 'Content-Type: application/json' ), 401, 'wp_druid_pulse_session_required' );
	rejected_request( $body, $jars[0], array( 'Content-Type: application/json' ), 401, 'wp_druid_pulse_session_required' );
	rejected_request( $body, $jars[0], array( 'Content-Type: application/json', 'X-WP-Nonce: invalid' ), 403, 'rest_cookie_invalid_nonce' );
	rejected_request( $body, $jars[1], $headers, 403, 'rest_cookie_invalid_nonce' );
	foreach ( array( 'https://other.example', 'null' ) as $origin ) {
		$rejected = rejected_request( $body, $jars[0], array_merge( $headers, array( 'Origin: ' . $origin ) ), 403, 'wp_druid_pulse_origin_not_allowed' );
		verify_receiver( ! isset( $rejected['headers']['access-control-allow-origin'] ) && ! isset( $rejected['headers']['access-control-allow-credentials'] ), 'Rejected origin retained CORS access.' );
	}
	$other = receiver_request( rest_url(), null, 'GET', null, array( 'Origin: https://other.example' ) );
	verify_receiver( 'https://other.example' === ( $other['headers']['access-control-allow-origin'] ?? null ), 'The receiver changed unrelated REST CORS behavior.' );
	$case_route = str_replace( 'wp-druid-pulse/v1/events', 'WP-DRUID-PULSE/v1/EVENTS', $endpoint );
	$rejected = rejected_request( $body, $jars[0], array_merge( $headers, array( 'Origin: https://other.example' ) ), 403, null, $case_route );
	verify_receiver( ! isset( $rejected['headers']['access-control-allow-origin'] ), 'Case-insensitive route matching bypassed CORS protection.' );
	rejected_request( $body, $jars[0], $headers, 404, 'rest_no_route', null, 'GET' );
	rejected_request( $body, $jars[0], $headers, 405, 'wp_druid_pulse_method_not_allowed', add_query_arg( '_method', 'POST', $endpoint ), 'GET' );
	rejected_request( $body, $jars[0], array( 'Content-Type: text/plain', 'X-WP-Nonce: ' . $settings[0]['nonce'] ), 415, 'wp_druid_pulse_content_type' );

	foreach ( array( 'null', 'false', '7', '"string"', '[]', '[{}]', '{}', '{broken' ) as $invalid ) {
		rejected_request( $invalid, $jars[0], $headers, 400 );
	}
	foreach ( array( 'user_id' => $users[1]->ID, 'actor' => array( 'objectType' => 'person' ), 'connection' => array(), 'extra' => true ) as $key => $value ) {
		rejected_request( wp_json_encode( $event + array( $key => $value ) ), $jars[0], $headers, 400, 'wp_druid_pulse_request_invalid' );
	}
	rejected_request( wp_json_encode( array_replace( $event, array( 'type' => 'page_view' ) ) ), $jars[0], $headers, 400, 'wp_druid_pulse_view_context' );
	rejected_request( wp_json_encode( array_replace( $event, array( 'type' => 'click' ) ) ), $jars[0], $headers, 400, 'wp_druid_pulse_click_context' );
	foreach ( array( 'fixture_disabled', 'unknown' ) as $type ) {
		rejected_request( wp_json_encode( array_replace( $event, array( 'type' => $type ) ) ), $jars[0], $headers, 400, 'wp_druid_pulse_event_type_unavailable' );
	}
	foreach ( array( '', 'Bad type', array( 'fixture_activity' ) ) as $type ) {
		rejected_request( wp_json_encode( array_replace( $event, array( 'type' => $type ) ) ), $jars[0], $headers, 400, 'wp_druid_pulse_request_invalid' );
	}
	foreach ( array( '2026-02-30T12:30:00Z', '2026-11-05T12:30:00+00:00', '2026-11-05T12:30:00.000Z', "2026-11-05T12:30:00Z\0", '0999-11-05T12:30:00Z', array() ) as $date ) {
		rejected_request( wp_json_encode( array_replace( $event, array( 'published' => $date ) ) ), $jars[0], $headers, 400, 'wp_druid_pulse_request_invalid' );
	}
	foreach ( array( array(), array( 'actor' => array( 'id' => 'forged' ) ), array( 'page' => array(), 'label' => 'invalid' ) ) as $context ) {
		rejected_request( wp_json_encode( array_replace( $event, array( 'context' => $context ) ) ), $jars[0], $headers, 400, 'fixture_context_invalid' );
	}
	$large = $event;
	$large['context']['padding'] = '';
	$large['context']['padding'] = str_repeat( 'x', 16384 - strlen( wp_json_encode( $large ) ) );
	verify_receiver( 16384 === strlen( wp_json_encode( $large ) ) && 202 === receiver_request( $endpoint, $jars[0], 'POST', wp_json_encode( $large ), $headers )['status'], 'An exact 16 KiB request was rejected.' );
	$large['context']['padding'] .= 'x';
	rejected_request( wp_json_encode( $large ), $jars[0], $headers, 413, 'wp_druid_pulse_request_size' );
	rejected_request( str_repeat( '{', 16385 ), $jars[0], $headers, 413, 'wp_druid_pulse_request_size' );
	$large['context']['padding'] = str_repeat( 'á', 8192 );
	rejected_request( json_encode( $large, JSON_UNESCAPED_UNICODE ), $jars[0], $headers, 413, 'wp_druid_pulse_request_size' );

	update_option( 'pulse_phase6_receiver_mode', 'insert_failure' );
	rejected_request( $body, $jars[0], $headers, 500, 'wp_druid_pulse_event_store' );
	update_option( 'pulse_phase6_receiver_mode', 'queue_full' );
	rejected_request( $body, $jars[0], $headers, 503, 'wp_druid_pulse_queue_full' );
	delete_option( 'pulse_phase6_receiver_mode' );
	verify_receiver( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ), 'Could not hold the synthetic queue lock.' );
	rejected_request( $body, $jars[0], $headers, 503, 'wp_druid_pulse_queue_busy' );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	verify_receiver( 202 === receiver_request( $endpoint, $jars[0], 'POST', $body, $headers )['status'], 'Storage did not recover after the controlled failures.' );

	$views = require __DIR__ . '/page-views-http.php';
	$views( $jars[1], $subscriber_headers, (int) $users[1]->ID );
	$clicks = require __DIR__ . '/clicks-http.php';
	$clicks( $jars, $headers, $subscriber_headers, (int) $users[1]->ID );
	$application_password = WP_Application_Passwords::create_new_application_password( $users[1]->ID, array( 'name' => 'Pulse receiver isolated test' ) );
	verify_receiver( ! is_wp_error( $application_password ), 'Could not create the synthetic application password.' );
	$basic = $credentials['subscriber_user'] . ':' . $application_password[0];
	verify_receiver( 200 === receiver_request( rest_url( 'wp/v2/users/me' ), null, 'GET', null, array(), $basic )['status'], 'The control route did not authenticate the application password.' );
	$before = queue_count();
	$app = receiver_request( $endpoint, null, 'POST', $body, array( 'Content-Type: application/json' ), $basic );
	verify_receiver( 401 === $app['status'] && 'wp_druid_pulse_session_required' === ( $app['json']['code'] ?? null ) && $before === queue_count(), 'Application-password authentication bypassed the browser-session requirement.' );

	$cookie = login_cookie( $jars[1] );
	$token = wp_parse_auth_cookie( $cookie, 'logged_in' )['token'];
	$expired = substr( wp_hash( ( wp_nonce_tick( 'wp_rest' ) - 2 ) . '|wp_rest|' . $users[1]->ID . '|' . $token, 'nonce' ), -12, 10 );
	rejected_request( $body, $jars[1], array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $expired ), 403, 'rest_cookie_invalid_nonce' );
	WP_Session_Tokens::get_instance( $users[1]->ID )->destroy( $token );
	rejected_request( $body, $jars[1], $subscriber_headers, 403, 'rest_cookie_invalid_nonce' );
	$new_settings = browser_login( 1 );
	verify_receiver( $new_settings['nonce'] !== $settings[1]['nonce'], 'The new session reused the old session nonce.' );
	rejected_request( $body, $jars[1], $subscriber_headers, 403, 'rest_cookie_invalid_nonce' );
	verify_receiver( 202 === receiver_request( $endpoint, $jars[1], 'POST', $body, array( 'Content-Type: application/json', 'X-WP-Nonce: ' . $new_settings['nonce'] ) )['status'], 'The new page could not use its own session.' );
	echo "PASS: $checks receiver HTTP assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n" );
	$exit_code = 1;
} finally {
	delete_option( 'pulse_phase6_receiver_mode' );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	$wpdb->delete( EventStore::table(), array( 'event_type' => 'fixture_activity' ), array( '%s' ) );
	if ( is_array( $application_password ) ) {
		WP_Application_Passwords::delete_application_password( $users[1]->ID, $application_password[1]['uuid'] );
	}
	foreach ( $jars as $jar ) {
		unlink( $jar );
	}
}
exit( $exit_code );
