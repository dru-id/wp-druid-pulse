<?php

if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	return;
}

add_action( 'init', static function (): void {
	register_post_type( 'pulse_public', array( 'public' => true, 'supports' => array( 'title', 'editor' ) ) );
	register_post_type( 'pulse_hidden', array( 'public' => false ) );
} );
add_action( 'template_redirect', static function (): void {
	if ( isset( $_GET['pulse_fixture_error'] ) ) {
		status_header( 503 );
	}
} );
