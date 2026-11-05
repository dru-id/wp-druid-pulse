<?php

if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	return;
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	if ( 'https://auth.example/oauth2/token' !== $url ) {
		return new WP_Error( 'test_network_disabled', 'No external requests are allowed in this fixture.' );
	}
	update_option( 'pulse_phase4_oauth_calls', (int) get_option( 'pulse_phase4_oauth_calls', 0 ) + 1 );
	$mode = get_option( 'pulse_phase4_oauth_mode', 'success' );
	$status = 'rejected' === $mode ? 401 : 200;
	$body = 'rejected' === $mode ? array( 'error_description' => 'http-fixture-secret' ) : array( 'access_token' => 'http.fixture.token', 'token_type' => 'Bearer', 'expires_in' => 3600 );
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array() );
}, 999, 3 );
