<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ViewCatalog.php';

final class PageViews {
	public static function current_context(): ?array {
		if ( is_admin() || ! is_user_logged_in() || wp_doing_ajax() || wp_doing_cron()
			|| wp_is_serving_rest_request() || is_feed() || is_preview() || is_customize_preview()
			|| is_embed() || is_404() || is_search() || is_trackback() ) {
			return null;
		}
		$status = http_response_code();
		if ( is_int( $status ) && ( $status < 200 || $status >= 300 ) ) {
			return null;
		}
		if ( is_home() ) {
			$key = ViewCatalog::has_posts_home() ? 'home:posts' : 'post:' . get_queried_object_id();
		} elseif ( is_singular() ) {
			$key = 'post:' . get_queried_object_id();
		} else {
			return null;
		}
		return is_wp_error( self::selected_post( $key ) ) ? null : array( 'page' => array( 'key' => $key ) );
	}

	public static function validate_context( array $context ): array|\WP_Error {
		if ( array_keys( $context ) !== array( 'page' ) || ! is_array( $context['page'] )
			|| array_keys( $context['page'] ) !== array( 'key' ) || ! is_string( $context['page']['key'] ) ) {
			return self::invalid_context();
		}
		$key = $context['page']['key'];
		$post = self::selected_post( $key );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$url = $post ? get_permalink( $post ) : home_url( '/' );
		$url = is_string( $url ) ? esc_url_raw( preg_replace( '/#.*$/s', '', $url ), array( 'http', 'https' ) ) : '';
		if ( '' === $url ) {
			return new \WP_Error( 'wp_druid_pulse_view_url', __( 'WordPress could not resolve the selected content URL.', 'wp-druid-pulse' ), array( 'status' => 503 ) );
		}
		$title = $post ? get_the_title( $post ) : get_bloginfo( 'name' );
		return array( 'page' => array( 'key' => $key, 'url' => $url, 'title' => sanitize_text_field( wp_specialchars_decode( $title, ENT_QUOTES ) ) ) );
	}

	private static function selected_post( string $key ): \WP_Post|\WP_Error|null {
		$selection = ViewSelection::read();
		if ( is_wp_error( $selection ) ) {
			$selection->add_data( array( 'status' => 503 ) );
			return $selection;
		}
		if ( 'home:posts' === $key ) {
			// The selected posts homepage has no corresponding post.
			return $selection['posts_home'] && ViewCatalog::has_posts_home() ? null : self::unavailable();
		}
		if ( ! preg_match( '/^post:([1-9][0-9]*)$/D', $key, $match ) ) {
			return self::invalid_context();
		}
		$id = filter_var( $match[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( false === $id ) {
			return self::invalid_context();
		}
		if ( ! in_array( $id, $selection['post_ids'], true ) || ! ViewCatalog::is_eligible( $id ) ) {
			return self::unavailable();
		}
		$post = get_post( $id );
		return ! $post || post_password_required( $post ) ? self::unavailable() : $post;
	}

	private static function invalid_context(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_view_context', __( 'Provide only the selected content reference for a page view.', 'wp-druid-pulse' ) );
	}

	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_view_unavailable', __( 'This content is not currently selected and available for page views.', 'wp-druid-pulse' ) );
	}
}
