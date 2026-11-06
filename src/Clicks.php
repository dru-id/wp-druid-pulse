<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ClickRules.php';
require_once __DIR__ . '/EventUrl.php';

final class Clicks {
	public static function current_context(): ?array {
		if ( is_admin() || ! is_user_logged_in() || wp_doing_ajax() || wp_doing_cron()
			|| wp_is_serving_rest_request() || is_feed() || is_preview() || is_customize_preview() || is_embed() || is_trackback() ) {
			return null;
		}
		if ( is_home() || is_singular() ) {
			$key = is_home() && ViewCatalog::has_posts_home() ? 'home:posts' : 'post:' . get_queried_object_id();
			if ( ! ClickRules::available( $key ) ) {
				return null;
			}
			$url = 'home:posts' === $key ? home_url( '/' ) : get_permalink( get_queried_object_id() );
			$title = 'home:posts' === $key ? get_bloginfo( 'name' ) : get_the_title( get_queried_object_id() );
		} else {
			$path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
			$home = wp_parse_url( home_url( '/' ) );
			if ( ! is_string( $path ) || ! is_array( $home ) ) {
				return null;
			}
			$url = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . '/' . ltrim( $path, '/' );
			$key = 'route:' . hash( 'sha256', $url );
			$title = is_search() ? get_bloginfo( 'name' ) : wp_get_document_title();
		}
		$url = is_string( $url ) ? preg_replace( '/#.*$/s', '', $url ) : '';
		if ( ! EventUrl::valid( $url ) ) {
			return null;
		}
		return array( 'key' => $key, 'url' => $url, 'title' => sanitize_text_field( wp_specialchars_decode( $title, ENT_QUOTES ) ) );
	}

	public static function validate_context( array $context ): array|\WP_Error {
		if ( array_diff( array_keys( $context ), array( 'page', 'link', 'target' ) ) || ! isset( $context['page'], $context['link'] ) ) {
			return self::invalid();
		}
		$page = $context['page'];
		$link = $context['link'];
		if ( ! is_array( $page ) || count( $page ) !== 3 || ! isset( $page['key'], $page['url'], $page['title'] )
			|| ! is_string( $page['key'] ) || ! is_string( $page['title'] ) || ! EventUrl::valid( $page['url'] )
			|| ! is_array( $link ) || array_keys( $link ) !== array( 'title' ) || ! is_string( $link['title'] ) ) {
			return self::invalid();
		}
		$home = wp_parse_url( home_url( '/' ) );
		$source = wp_parse_url( $page['url'] );
		foreach ( array( 'scheme', 'host', 'port' ) as $part ) {
			if ( ( $home[ $part ] ?? null ) !== ( $source[ $part ] ?? null ) ) {
				return self::invalid();
			}
		}
		if ( str_starts_with( $page['key'], 'route:' ) ) {
			if ( $page['key'] !== 'route:' . hash( 'sha256', $page['url'] ) || isset( $source['query'] ) || isset( $source['fragment'] ) ) {
				return self::invalid();
			}
		} elseif ( ! ClickRules::available( $page['key'] ) ) {
			return self::invalid();
		}
		$rules = ClickRules::for_page( $page['key'] );
		if ( is_wp_error( $rules ) ) {
			$rules->add_data( array( 'status' => 503 ) );
			return $rules;
		}
		if ( ! $rules['include'] ) {
			return new \WP_Error( 'wp_druid_pulse_click_unavailable', __( 'Click capture is not enabled for this page.', 'wp-druid-pulse' ) );
		}
		$page['title'] = sanitize_text_field( $page['title'] );
		$result = array( 'page' => $page, 'link' => array( 'title' => sanitize_text_field( $link['title'] ) ) );
		if ( array_key_exists( 'target', $context ) ) {
			$target = $context['target'];
			if ( ! is_array( $target ) || count( $target ) !== 2 || ! isset( $target['objectType'], $target['url'] )
				|| ! in_array( $target['objectType'], array( 'page', 'link' ), true ) || ! EventUrl::valid( $target['url'], true ) ) {
				return self::invalid();
			}
			$result['target'] = $target;
		}
		return $result;
	}

	private static function invalid(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_click_context', __( 'The click context is incomplete or invalid.', 'wp-druid-pulse' ) );
	}
}
