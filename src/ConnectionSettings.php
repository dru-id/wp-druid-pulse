<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ConnectionSettings {
	public const OPTION = 'wp_druid_pulse_connection';
	public const TOKEN_TRANSIENT = 'wp_druid_pulse_oauth_token';
	private const DEFAULTS = array(
		'api_url' => '', 'oauth_url' => '', 'client_id' => '', 'client_secret' => '',
		'auth_method' => 'client_secret_basic', 'scope' => '', 'tenant' => '',
	);

	public static function initialize(): ?\WP_Error {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::DEFAULTS, autoload: false );
		}
		$value = self::read();
		return is_wp_error( $value ) ? $value : null;
	}

	public static function read(): array|\WP_Error {
		$value = get_option( self::OPTION, null );
		if ( ! is_array( $value ) || ! self::valid( $value ) ) {
			return new \WP_Error( 'wp_druid_pulse_connection_data', __( 'The saved connection settings are invalid. Existing data has been preserved.', 'wp-druid-pulse' ) );
		}
		return $value;
	}

	public static function ready(): array|\WP_Error {
		$value = self::read();
		if ( is_wp_error( $value ) ) {
			return $value;
		}
		foreach ( array( 'api_url', 'oauth_url', 'client_id', 'client_secret' ) as $field ) {
			if ( '' === $value[ $field ] ) {
				return new \WP_Error( 'wp_druid_pulse_connection_incomplete', __( 'Save the Pulse URL, authorization server URL, client ID and secret before testing the connection.', 'wp-druid-pulse' ) );
			}
		}
		return $value;
	}

	public static function save( array $input ): ?\WP_Error {
		$current = self::read();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$next = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! isset( $input[ $key ] ) || ! is_string( $input[ $key ] ) ) {
				return self::invalid_input();
			}
			$next[ $key ] = 'client_secret' === $key ? $input[ $key ] : trim( $input[ $key ] );
		}
		$remove = $input['remove_secret'] ?? null;
		if ( ! in_array( $remove, array( null, '1' ), true ) || ( '1' === $remove && '' !== $next['client_secret'] ) ) {
			return self::invalid_input();
		}
		if ( '' === $next['client_secret'] && '1' !== $remove ) {
			$next['client_secret'] = $current['client_secret'];
		}
		foreach ( array( 'api_url', 'oauth_url' ) as $key ) {
			$next[ $key ] = untrailingslashit( $next[ $key ] );
		}
		if ( ! self::valid( $next ) ) {
			return self::invalid_input();
		}
		if ( $current === $next ) {
			return DeliveryState::reset();
		}
		update_option( self::OPTION, $next, false );
		if ( get_option( self::OPTION, null ) !== $next ) {
			return new \WP_Error( 'wp_druid_pulse_connection_save', __( 'The connection settings could not be saved. Check database access and try again.', 'wp-druid-pulse' ) );
		}
		delete_transient( self::TOKEN_TRANSIENT );
		return DeliveryState::reset();
	}

	private static function valid( array $value ): bool {
		if ( count( $value ) !== count( self::DEFAULTS ) || array_diff_key( self::DEFAULTS, $value ) ) {
			return false;
		}
		foreach ( $value as $field ) {
			if ( ! is_string( $field ) || preg_match( '/[\x00-\x1f\x7f]/', $field ) ) {
				return false;
			}
		}
		foreach ( array( 'api_url', 'oauth_url' ) as $key ) {
			if ( '' === $value[ $key ] ) {
				continue;
			}
			$url = wp_parse_url( $value[ $key ] );
			if ( ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? '' ) || empty( $url['host'] )
				|| array_intersect( array( 'user', 'pass', 'query', 'fragment' ), array_keys( $url ) )
				|| preg_match( '/\s/', $value[ $key ] ) || esc_url_raw( $value[ $key ], array( 'https' ) ) !== $value[ $key ] ) {
				return false;
			}
		}
		return in_array( $value['auth_method'], array( 'client_secret_basic', 'client_secret_post' ), true )
			&& ( '' === $value['scope'] || 1 === preg_match( '/^[\x21\x23-\x5b\x5d-\x7e]+(?: [\x21\x23-\x5b\x5d-\x7e]+)*$/D', $value['scope'] ) );
	}

	private static function invalid_input(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_connection_input', __( 'Invalid connection settings. Use HTTPS base URLs without credentials, query parameters or fragments. To remove the secret, leave its field empty and select Remove saved secret.', 'wp-druid-pulse' ) );
	}
}
