<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ViewSelection {
	public const OPTION = 'wp_druid_pulse_view_selection';
	private const DEFAULTS = array( 'post_ids' => array(), 'posts_home' => false );

	public static function initialize(): ?\WP_Error {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::DEFAULTS, autoload: false );
		}

		$value = self::read();

		return is_wp_error( $value ) ? $value : null;
	}

	public static function read(): array|\WP_Error {
		$value = get_option( self::OPTION, null );

		if ( ! is_array( $value ) || count( $value ) !== 2
			|| ! isset( $value['post_ids'], $value['posts_home'] )
			|| ! is_array( $value['post_ids'] ) || ! array_is_list( $value['post_ids'] )
			|| ! is_bool( $value['posts_home'] ) ) {
			return self::invalid_data();
		}

		foreach ( $value['post_ids'] as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return self::invalid_data();
			}
		}

		return $value;
	}

	public static function save_page( array $visible_ids, array $selected_ids, ?bool $posts_home ): ?\WP_Error {
		$current = self::read();

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		if ( array_diff( $selected_ids, $visible_ids ) ) {
			return self::invalid_submission();
		}

		foreach ( $selected_ids as $id ) {
			if ( ! in_array( $id, $current['post_ids'], true ) && ! ViewCatalog::is_eligible( $id ) ) {
				return new \WP_Error( 'wp_druid_pulse_unavailable_selection', __( 'Some content is no longer available. Nothing was saved. Reload the list and try again.', 'wp-druid-pulse' ) );
			}
		}

		if ( true === $posts_home && ! $current['posts_home'] && ! ViewCatalog::has_posts_home() ) {
			return new \WP_Error( 'wp_druid_pulse_unavailable_selection', __( 'The front page has changed. Nothing was saved. Reload the list and try again.', 'wp-druid-pulse' ) );
		}

		$ids = array_values( array_unique( array_merge( array_diff( $current['post_ids'], $visible_ids ), $selected_ids ) ) );
		sort( $ids, SORT_NUMERIC );
		$next = array( 'post_ids' => $ids, 'posts_home' => $posts_home ?? $current['posts_home'] );

		if ( $current === $next ) {
			return null;
		}

		update_option( self::OPTION, $next, false );

		if ( get_option( self::OPTION, null ) !== $next ) {
			return new \WP_Error( 'wp_druid_pulse_save_failed', __( 'The selection could not be saved. Check database access and try again.', 'wp-druid-pulse' ) );
		}

		return null;
	}

	public static function invalid_submission(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_invalid_selection', __( 'The submitted selection is incomplete or invalid. Nothing was saved. Reload the list and try again.', 'wp-druid-pulse' ) );
	}

	private static function invalid_data(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_invalid_selection_data', __( 'DruID Pulse could not load its saved selection. Existing data has been preserved. Check the installation data and try again.', 'wp-druid-pulse' ) );
	}
}
