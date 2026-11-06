<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventUrl {
	public static function valid( mixed $value, bool $destination = false ): bool {
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 1024 || preg_match( '/[\x00-\x20\x7f]|%(?![a-f0-9]{2})/i', $value ) ) {
			return false;
		}
		$url = wp_parse_url( $value );
		if ( ! is_array( $url ) || isset( $url['user'] ) || isset( $url['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $url['scheme'] ?? '' );
		if ( in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return ! empty( $url['host'] );
		}
		return $destination && in_array( $scheme, array( 'tel', 'mailto' ), true ) && ! empty( $url['path'] )
			&& ! str_starts_with( $url['path'], '/' ) && ! isset( $url['host'] ) && ! isset( $url['fragment'] );
	}
}
