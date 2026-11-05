<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Lifecycle {
	private const SCHEMA_OPTION = 'wp_druid_pulse_schema_version';
	private const SCHEMA_VERSION = 4;

	public static function is_current(): bool {
		return in_array( get_option( self::SCHEMA_OPTION, null ), array( self::SCHEMA_VERSION, (string) self::SCHEMA_VERSION ), true );
	}

	public static function initialize(): ?\WP_Error {
		$version = get_option( self::SCHEMA_OPTION, null );

		if ( in_array( $version, array( self::SCHEMA_VERSION, (string) self::SCHEMA_VERSION ), true ) ) {
			return Scheduler::ready();
		}

		if ( ! in_array( $version, array( null, 1, '1', 2, '2', 3, '3' ), true ) ) {
			return new \WP_Error(
				'wp_druid_pulse_incompatible_schema',
				__( 'DruID Pulse installation data is incompatible with this plugin version. Existing data has been preserved. Restore a compatible plugin version or check the installation metadata.', 'wp-druid-pulse' )
			);
		}

		$error = ViewSelection::initialize();

		if ( null !== $error ) {
			return $error;
		}
		foreach ( array( SiteContext::class, ConnectionSettings::class ) as $resource ) {
			$error = $resource::initialize();
			if ( null !== $error ) {
				return $error;
			}
		}
		$error = EventStore::initialize();
		if ( null === $error ) {
			$error = Scheduler::ensure_scheduled();
		}
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
		if ( null === $error ) {
			$id = SiteContext::id();
			$connection = ConnectionSettings::read();
			$error = is_wp_error( $id ) ? $id : ( is_wp_error( $connection ) ? $connection : null );
		}
		if ( null === $error ) {
			$error = EventStore::verify();
		}
		if ( null === $error ) {
			$error = Scheduler::ensure_scheduled();
		}

		if ( null !== $error ) {
			wp_die(
				esc_html( $error->get_error_message() ),
				esc_html__( 'DruID Pulse activation failed.', 'wp-druid-pulse' ),
				array( 'response' => 500, 'back_link' => true )
			);
		}
		Scheduler::register_hooks();
	}

	public static function deactivate(): void {
		$error = Scheduler::stop();
		if ( null !== $error ) {
			wp_die( esc_html( $error->get_error_message() ), esc_html__( 'DruID Pulse deactivation failed.', 'wp-druid-pulse' ), array( 'response' => 500, 'back_link' => true ) );
		}
	}

	public static function uninstall(): void {
		if ( null !== Scheduler::uninstall() || null !== EventStore::uninstall() ) {
			self::uninstall_error();
		}
		if ( wp_using_ext_object_cache() ) {
			$token = get_transient( ConnectionSettings::TOKEN_TRANSIENT );
			if ( ! delete_transient( ConnectionSettings::TOKEN_TRANSIENT ) && false !== $token ) {
				self::uninstall_error();
			}
		}
		// Read database records directly: get_transient() can expire them without reporting deletion failures.
		foreach ( array( ViewSelection::OPTION, SiteContext::OPTION, ConnectionSettings::OPTION, '_transient_' . ConnectionSettings::TOKEN_TRANSIENT, '_transient_timeout_' . ConnectionSettings::TOKEN_TRANSIENT, self::SCHEMA_OPTION ) as $option ) {
			$value = get_option( $option, null );

			// WordPress can cache absence even when the database deletion fails.
			if ( ! delete_option( $option ) && null !== $value ) {
				self::uninstall_error();
			}
		}
	}

	private static function uninstall_error(): never {
		wp_die(
			esc_html__( 'DruID Pulse could not remove its installation data. Check database access and try deleting the plugin again.', 'wp-druid-pulse' ),
			esc_html__( 'DruID Pulse uninstall failed.', 'wp-druid-pulse' ),
			array( 'response' => 500, 'back_link' => true )
		);
		exit;
	}
}
