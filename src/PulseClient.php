<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/OAuthClient.php';
require_once __DIR__ . '/HttpResponse.php';

final class PulseClient {
	public function send( array $beat ): array|\WP_Error {
		$body = wp_json_encode( $beat );
		if ( false === $body ) {
			return new \WP_Error( 'wp_druid_pulse_encoding', __( 'The event could not be encoded.', 'wp-druid-pulse' ) );
		}
		$settings = ConnectionSettings::ready();
		if ( is_wp_error( $settings ) ) {
			return $settings;
		}
		$token = ( new OAuthClient() )->token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$headers = array( 'Authorization' => 'Bearer ' . $token['access_token'], 'Content-Type' => 'application/json', 'Accept' => 'application/json' );
		if ( '' !== $settings['tenant'] ) {
			$headers['X-Tenant'] = $settings['tenant'];
		}
		$response = wp_safe_remote_post( $settings['api_url'] . '/identities', array(
			'headers' => $headers, 'body' => $body, 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 1048576,
		) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wp_druid_pulse_transport', __( 'The Pulse request failed. Its processing outcome is unknown.', 'wp-druid-pulse' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			delete_transient( ConnectionSettings::TOKEN_TRANSIENT );
		}
		if ( ! in_array( $status, array( 200, 201 ), true ) ) {
			return new \WP_Error( 'wp_druid_pulse_http', sprintf( __( 'Pulse returned HTTP %d.', 'wp-druid-pulse' ), $status ), HttpResponse::error_data( $response ) );
		}
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $result ) || ( $result['status'] ?? null ) !== $status ) {
			return new \WP_Error( 'wp_druid_pulse_response', __( 'Pulse returned an unexpected response. Its processing outcome is unknown.', 'wp-druid-pulse' ), HttpResponse::error_data( $response ) );
		}
		return array( 'status' => $status );
	}
}
