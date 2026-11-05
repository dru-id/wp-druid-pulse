<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OAuthClient {
	public function token( array $settings, bool $force = false ): array|\WP_Error {
		$fingerprint = hash_hmac( 'sha256', wp_json_encode( $settings ), wp_salt( 'auth' ) );
		$cached = get_transient( ConnectionSettings::TOKEN_TRANSIENT );
		if ( ! $force && is_array( $cached ) && ( $cached['fingerprint'] ?? null ) === $fingerprint
			&& isset( $cached['access_token'], $cached['expires_at'] ) && is_int( $cached['expires_at'] )
			&& $cached['expires_at'] > time() + 30 && self::valid_token( $cached['access_token'] ) ) {
			return array( 'access_token' => $cached['access_token'], 'expires_at' => $cached['expires_at'] );
		}
		delete_transient( ConnectionSettings::TOKEN_TRANSIENT );
		$body = array( 'grant_type' => 'client_credentials' );
		$headers = array( 'Accept' => 'application/json' );
		if ( 'client_secret_basic' === $settings['auth_method'] ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( urlencode( $settings['client_id'] ) . ':' . urlencode( $settings['client_secret'] ) );
		} else {
			$body['client_id'] = $settings['client_id'];
			$body['client_secret'] = $settings['client_secret'];
		}
		if ( '' !== $settings['scope'] ) {
			$body['scope'] = $settings['scope'];
		}
		$started = time();
		$response = wp_safe_remote_post( $settings['oauth_url'] . '/oauth2/token', array(
			'headers' => $headers, 'body' => $body, 'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 65536,
		) );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'wp_druid_pulse_oauth_transport', __( 'The authorization server could not be reached securely. Check its URL and network access.', 'wp-druid-pulse' ) );
		}
		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error( 'wp_druid_pulse_oauth_rejected', sprintf( __( 'OAuth authentication failed (HTTP %d). Check the client credentials, authentication method and scopes.', 'wp-druid-pulse' ), $status ), array( 'http_status' => $status ) );
		}
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		$expires = is_array( $result ) ? ( $result['expires_in'] ?? null ) : null;
		if ( is_string( $expires ) && ctype_digit( $expires ) && strlen( $expires ) < 10 ) {
			$expires = (int) $expires;
		}
		if ( ! is_array( $result ) || ! self::valid_token( $result['access_token'] ?? null )
			|| ! is_string( $result['token_type'] ?? null ) || 0 !== strcasecmp( $result['token_type'], 'Bearer' )
			|| ! is_int( $expires ) || $expires <= 0 || $expires > PHP_INT_MAX - $started || $started + $expires <= time() ) {
			return new \WP_Error( 'wp_druid_pulse_oauth_response', __( 'The authorization server returned an invalid or expired token response.', 'wp-druid-pulse' ) );
		}
		$token = array( 'access_token' => $result['access_token'], 'expires_at' => $started + $expires );
		$lifetime = $token['expires_at'] - time() - 30;
		if ( $lifetime > 0 ) {
			set_transient( ConnectionSettings::TOKEN_TRANSIENT, array_merge( $token, array( 'fingerprint' => $fingerprint ) ), $lifetime );
		}
		return $token;
	}

	private static function valid_token( mixed $token ): bool {
		return is_string( $token ) && 1 === preg_match( '/^[a-zA-Z0-9._~+\/-]+=*$/D', $token );
	}
}
