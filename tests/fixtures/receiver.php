<?php

if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	return;
}

add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_action( 'wp_druid_pulse_register_event_types', static function ( $types ) {
	$mapper = static fn( array $context ): array => array( 'verb' => 'test', 'object' => array( 'objectType' => 'fixture', 'displayName' => $context['label'] ) );
	$types->register( 'fixture_disabled', $mapper );
	$types->register( 'fixture_activity', $mapper, static function ( array $context ): array|WP_Error {
		$page = array( 'key' => 'fixture:page', 'url' => home_url( '/' ), 'title' => 'Synthetic page' );
		if ( array_diff( array_keys( $context ), array( 'page', 'label', 'padding' ) )
			|| ( $context['page'] ?? null ) !== $page || ! is_string( $context['label'] ?? null )
			|| ( isset( $context['padding'] ) && ! is_string( $context['padding'] ) ) ) {
			return new WP_Error( 'fixture_context_invalid', 'The synthetic context is invalid.' );
		}
		$context['label'] = sanitize_text_field( $context['label'] );
		return $context;
	} );
} );

add_filter( 'query', static function ( string $query ): string {
	global $wpdb;
	$table = '`' . $wpdb->prefix . 'druid_pulse_events`';
	if ( ! str_contains( $query, $table ) ) {
		return $query;
	}
	$mode = get_option( 'pulse_phase6_receiver_mode' );
	if ( 'insert_failure' === $mode && str_starts_with( $query, 'INSERT INTO ' . $table ) ) {
		return 'INTENTIONAL RECEIVER TEST FAILURE';
	}
	if ( 'queue_full' === $mode && str_starts_with( $query, 'SELECT COUNT(*) AS events,' ) ) {
		return 'SELECT 10000 AS events, 0 AS bytes';
	}
	return $query;
} );
