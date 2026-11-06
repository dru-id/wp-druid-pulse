<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryState {
	public const OPTION = 'wp_druid_pulse_delivery';

	public static function initialize(): ?\WP_Error {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::defaults(), autoload: false );
		}
		$state = self::read();
		return is_wp_error( $state ) ? $state : null;
	}

	public static function read(): array|\WP_Error {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || count( $state ) !== 5
			|| ! is_string( $state['revision'] ?? null ) || ! wp_is_uuid( $state['revision'], 4 )
			|| ! is_int( $state['retry_at'] ?? null ) || $state['retry_at'] < 0
			|| ! is_int( $state['failures'] ?? null ) || $state['failures'] < 0
			|| ! is_bool( $state['auth_retry'] ?? null ) || ! is_string( $state['error'] ?? null )
			|| ! preg_match( '/^[a-z0-9_]{0,64}$/D', $state['error'] ) ) {
			return self::error();
		}
		return $state;
	}

	public static function reset(): ?\WP_Error {
		$state = self::defaults();
		update_option( self::OPTION, $state, false );
		return get_option( self::OPTION ) === $state ? null : self::error();
	}

	public static function save( array $before, array $after ): bool|\WP_Error {
		if ( $before === $after ) {
			return true;
		}
		global $wpdb;
		$previous = $wpdb->suppress_errors( true );
		try {
			// Do not restore an old pause over a connection change made during an HTTP request.
			$updated = $wpdb->query( $wpdb->prepare(
				'UPDATE %i SET option_value=%s WHERE option_name=%s AND option_value=%s',
				$wpdb->options, maybe_serialize( $after ), self::OPTION, maybe_serialize( $before )
			) );
			wp_cache_delete( self::OPTION, 'options' );
			return false === $updated ? self::error() : 1 === $updated;
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	public static function cleared( array $state ): array {
		return array_replace( $state, array( 'retry_at' => 0, 'failures' => 0, 'auth_retry' => false, 'error' => '' ) );
	}

	private static function defaults(): array {
		return array( 'revision' => wp_generate_uuid4(), 'retry_at' => 0, 'failures' => 0, 'auth_retry' => false, 'error' => '' );
	}

	private static function error(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_delivery_state', __( 'Delivery state could not be read or saved. Check database access before retrying.', 'wp-druid-pulse' ) );
	}
}
