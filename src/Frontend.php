<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Frontend {
	public function __construct( private string $plugin_file ) {}

	public function enqueue(): void {
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}
		require_once __DIR__ . '/Rest/EventsController.php';
		$settings = array(
			'endpoint' => rest_url( ltrim( Rest\EventsController::ROUTE, '/' ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'maxBytes' => Rest\EventsController::MAX_BODY_BYTES,
		);
		wp_enqueue_script( 'wp-druid-pulse-transport', plugins_url( 'assets/frontend-transport.js', $this->plugin_file ), array(), '0.6.0', array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script( 'wp-druid-pulse-transport', 'window.wpDruidPulseSettings = ' . wp_json_encode( $settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}
}
