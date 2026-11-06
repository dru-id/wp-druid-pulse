<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING || ! in_array( $argv[1] ?? '', array( 'crash', 'send' ), true ) ) {
	exit( 1 );
}

add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $argv ) {
	if ( str_ends_with( $url, '/oauth2/token' ) ) {
		$body = array( 'access_token' => 'synthetic.worker.token', 'token_type' => 'Bearer', 'expires_in' => 3600 );
	} else {
		file_put_contents( $argv[2], "attempt\n", FILE_APPEND | LOCK_EX );
		if ( 'crash' === $argv[1] ) {
			exit( 42 );
		}
		usleep( 250000 );
		$body = array( 'status' => 200 );
	}
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => 200 ), 'cookies' => array() );
}, 1000, 3 );

WP_Druid_Pulse\Scheduler::deliver();
