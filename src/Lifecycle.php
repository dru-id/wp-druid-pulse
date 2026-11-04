<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lifecycle {
	private const SCHEMA_OPTION = 'wp_druid_pulse_schema_version';
	private const SCHEMA_VERSION = 2;

	public static function initialize(): ?\WP_Error {
		$version = get_option( self::SCHEMA_OPTION, null );

		if ( in_array( $version, array( self::SCHEMA_VERSION, (string) self::SCHEMA_VERSION ), true ) ) {
			return null;
		}

		if ( ! in_array( $version, array( null, 1, '1' ), true ) ) {
			return new \WP_Error(
				'wp_druid_pulse_incompatible_schema',
				__( 'DruID Pulse installation data is incompatible with this plugin version. Existing data has been preserved. Restore a compatible plugin version or check the installation metadata.', 'wp-druid-pulse' )
			);
		}

		$error = ViewSelection::initialize();

		if ( null !== $error ) {
			return $error;
		}

		if ( null === $version ) {
			add_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, autoload: true );
		} else {
			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, true );
		}

		if ( ! in_array( get_option( self::SCHEMA_OPTION, null ), array( self::SCHEMA_VERSION, (string) self::SCHEMA_VERSION ), true ) ) {
			return new \WP_Error(
				'wp_druid_pulse_initialization_failed',
				__( 'DruID Pulse could not save its installation data. Check database access and try again.', 'wp-druid-pulse' )
			);
		}

		return null;
	}

	public static function activate(): void {
		$error = self::initialize();
		if ( null === $error ) {
			$selection = ViewSelection::read();
			$error = is_wp_error( $selection ) ? $selection : null;
		}

		if ( null !== $error ) {
			wp_die(
				esc_html( $error->get_error_message() ),
				esc_html__( 'DruID Pulse activation failed.', 'wp-druid-pulse' ),
				array( 'response' => 500, 'back_link' => true )
			);
		}
	}

	public static function uninstall(): void {
		foreach ( array( ViewSelection::OPTION, self::SCHEMA_OPTION ) as $option ) {
			$value = get_option( $option, null );

			// WordPress can cache absence even when the database deletion fails.
			if ( ! delete_option( $option ) && null !== $value ) {
				wp_die(
					esc_html__( 'DruID Pulse could not remove its installation data. Check database access and try deleting the plugin again.', 'wp-druid-pulse' ),
					esc_html__( 'DruID Pulse uninstall failed.', 'wp-druid-pulse' ),
					array( 'response' => 500, 'back_link' => true )
				);
			}
		}
	}
}
