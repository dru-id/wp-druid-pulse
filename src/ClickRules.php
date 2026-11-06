<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ViewCatalog.php';

final class ClickRules {
	public const OPTION = 'wp_druid_pulse_click_rules';
	private const DEFAULTS = array( 'include' => array(), 'exclude' => array() );

	public static function initialize(): ?\WP_Error {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::DEFAULTS, autoload: false );
		}
		$value = self::read();
		return is_wp_error( $value ) ? $value : null;
	}

	public static function read(): array|\WP_Error {
		$value = get_option( self::OPTION, null );
		return self::valid( $value ) ? $value : new \WP_Error( 'wp_druid_pulse_click_rules_data', __( 'The saved click rules are invalid. Check the installation data.', 'wp-druid-pulse' ) );
	}

	public static function save( array $value ): ?\WP_Error {
		if ( ! self::valid( $value ) ) {
			return self::invalid();
		}
		$current = self::read();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$existing_pages = array_merge( array(), ...array_column( array_merge( $current['include'], $current['exclude'] ), 'pages' ) );
		foreach ( $value as $rules ) {
			foreach ( $rules as $rule ) {
				foreach ( $rule['pages'] ?? array() as $key ) {
					if ( ! in_array( $key, $existing_pages, true ) && ! self::available( $key ) ) {
						return self::invalid();
					}
				}
			}
		}
		if ( $current === $value ) {
			return null;
		}
		update_option( self::OPTION, $value, false );
		return get_option( self::OPTION, null ) === $value ? null : new \WP_Error( 'wp_druid_pulse_click_rules_save', __( 'The click rules could not be saved. Try again.', 'wp-druid-pulse' ) );
	}

	public static function for_page( string $key ): array|\WP_Error {
		$rules = self::read();
		if ( is_wp_error( $rules ) ) {
			return $rules;
		}
		foreach ( $rules as &$list ) {
			$list = array_values( array_filter( $list, static fn( $rule ) => ! isset( $rule['pages'] ) || in_array( $key, $rule['pages'], true ) ) );
			foreach ( $list as &$rule ) {
				unset( $rule['pages'] );
			}
			unset( $rule );
		}
		unset( $list );
		return $rules;
	}

	public static function available( string $key ): bool {
		if ( 'home:posts' === $key ) {
			return ViewCatalog::has_posts_home();
		}
		if ( ! preg_match( '/^post:([1-9][0-9]*)$/D', $key, $match ) ) {
			return false;
		}
		$id = filter_var( $match[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		return false !== $id && ViewCatalog::is_eligible( $id ) && ! post_password_required( $id );
	}

	private static function valid( mixed $value ): bool {
		if ( ! is_array( $value ) || count( $value ) !== 2 || ! isset( $value['include'], $value['exclude'] ) ) {
			return false;
		}
		foreach ( $value as $list ) {
			if ( ! is_array( $list ) || ! array_is_list( $list ) ) {
				return false;
			}
			foreach ( $list as $rule ) {
				if ( ! is_array( $rule ) || array_diff( array_keys( $rule ), array( 'selector', 'classPrefix', 'pages' ) )
					|| ! isset( $rule['selector'] ) || ! is_string( $rule['selector'] ) || '' === trim( $rule['selector'] )
					|| preg_match( '/[\x00-\x1f\x7f]/', $rule['selector'] ) ) {
					return false;
				}
				if ( array_key_exists( 'classPrefix', $rule ) && ( ! is_string( $rule['classPrefix'] ) || '' === $rule['classPrefix'] || preg_match( '/[\s\x00-\x1f\x7f]/', $rule['classPrefix'] ) ) ) {
					return false;
				}
				if ( array_key_exists( 'pages', $rule ) ) {
					if ( ! is_array( $rule['pages'] ) || ! array_is_list( $rule['pages'] ) || ! $rule['pages'] ) {
						return false;
					}
					foreach ( $rule['pages'] as $key ) {
						if ( ! is_string( $key ) || ! preg_match( '/^(home:posts|post:[1-9][0-9]*)$/D', $key ) ) {
							return false;
						}
					}
				}
			}
		}
		return true;
	}

	public static function invalid(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_click_rules_invalid', __( 'Provide complete click rules and available content references. Nothing was saved.', 'wp-druid-pulse' ) );
	}
}
