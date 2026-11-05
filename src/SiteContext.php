<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteContext {
	public const OPTION = 'wp_druid_pulse_installation_id';
	public const PROVIDER = array( 'id' => 'wp-druid-pulse', 'objectType' => 'application', 'displayName' => 'DruID Pulse WordPress Connector' );

	public static function initialize(): ?\WP_Error {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, wp_generate_uuid4(), autoload: false );
		}
		$id = self::id();
		return is_wp_error( $id ) ? $id : null;
	}

	public static function id(): string|\WP_Error {
		$id = get_option( self::OPTION, null );
		if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) ) {
			return new \WP_Error( 'wp_druid_pulse_installation_data', __( 'The installation identifier is missing or invalid. Existing data has been preserved.', 'wp-druid-pulse' ) );
		}
		return $id;
	}

	public static function application(): array|\WP_Error {
		$id = self::id();
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$app = array( 'id' => 'wp-druid-pulse:' . $id, 'objectType' => 'application', 'url' => home_url( '/' ) );
		$name = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' !== $name ) {
			$app['displayName'] = $name;
		}
		return $app;
	}
}
