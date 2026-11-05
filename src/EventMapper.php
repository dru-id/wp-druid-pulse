<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventMapper {
	private array $mappers;

	public function __construct() {
		$this->mappers = array( 'page_view' => array( $this, 'view' ), 'click' => array( $this, 'click' ) );
	}

	public function register( string $type, callable $mapper ): void {
		$this->mappers[ $type ] = $mapper;
	}

	public function map( string $type, array $data, array $actor, \DateTimeImmutable $published ): array|\WP_Error {
		if ( ! isset( $this->mappers[ $type ] ) ) {
			return new \WP_Error( 'wp_druid_pulse_unknown_event', __( 'No mapper is registered for this event type.', 'wp-druid-pulse' ) );
		}
		$application = SiteContext::application();
		if ( is_wp_error( $application ) ) {
			return $application;
		}
		$page = $data['page'] ?? null;
		if ( ! is_array( $page ) || ! isset( $page['key'], $page['url'] ) || ! is_string( $page['key'] )
			|| ! preg_match( '/^[a-zA-Z0-9:_-]+$/D', $page['key'] ) || ! self::valid_url( $page['url'] )
			|| 'person' !== ( $actor['objectType'] ?? null ) ) {
			return self::invalid_event();
		}
		$mapped = ( $this->mappers[ $type ] )( $data, $application['id'] );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		if ( ! is_array( $mapped ) || ! isset( $mapped['verb'], $mapped['object'] )
			|| ! is_string( $mapped['verb'] ) || '' === $mapped['verb']
			|| ! is_array( $mapped['object'] ) || empty( $mapped['object']['objectType'] ) ) {
			return self::invalid_event();
		}
		$origin = self::resource( $page, 'entrypoint', $application['id'] . ':entrypoint:' . $page['key'] );
		$origin['application'] = $application;
		return array_merge( $mapped, array(
			'published' => $published->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'actor' => $actor, 'origin' => $origin, 'provider' => SiteContext::PROVIDER,
		) );
	}

	private function view( array $data, string $application_id ): array {
		return array( 'verb' => 'watch', 'object' => self::resource( $data['page'], 'page', $application_id . ':' . $data['page']['key'] ) );
	}

	private function click( array $data, string $application_id ): array|\WP_Error {
		$link = $data['link'] ?? null;
		if ( ! is_array( $link ) ) {
			return self::invalid_event();
		}
		$link['url'] = $data['page']['url'];
		$id = null;
		if ( isset( $link['key'] ) ) {
			if ( ! is_string( $link['key'] ) || ! preg_match( '/^[a-zA-Z0-9:_-]+$/D', $link['key'] ) ) {
				return self::invalid_event();
			}
			$id = $application_id . ':link:' . $link['key'];
		}
		$mapped = array( 'verb' => 'click', 'object' => self::resource( $link, 'link', $id ) );
		if ( isset( $data['target'] ) ) {
			$target = $data['target'];
			if ( ! is_array( $target ) || ! isset( $target['objectType'], $target['url'] )
				|| ! is_string( $target['objectType'] ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9]*$/D', $target['objectType'] )
				|| ! self::valid_url( $target['url'] ) ) {
				return self::invalid_event();
			}
			$id = null;
			if ( isset( $target['key'] ) ) {
				if ( ! is_string( $target['key'] ) || ! preg_match( '/^[a-zA-Z0-9:_-]+$/D', $target['key'] ) ) {
					return self::invalid_event();
				}
				$id = $application_id . ':' . $target['key'];
			}
			$mapped['target'] = self::resource( $target, $target['objectType'], $id );
		}
		return $mapped;
	}

	private static function resource( array $data, string $type, ?string $id ): array {
		$resource = array( 'objectType' => $type, 'url' => $data['url'] );
		if ( null !== $id ) {
			$resource['id'] = $id;
		}
		$title = isset( $data['title'] ) && is_string( $data['title'] ) ? sanitize_text_field( wp_specialchars_decode( $data['title'], ENT_QUOTES ) ) : '';
		if ( '' !== $title ) {
			$resource['displayName'] = $title;
		}
		return $resource;
	}

	private static function valid_url( mixed $value ): bool {
		if ( ! is_string( $value ) || preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			return false;
		}
		$url = wp_parse_url( $value );
		return is_array( $url ) && in_array( $url['scheme'] ?? null, array( 'http', 'https' ), true )
			&& ! empty( $url['host'] ) && ! isset( $url['user'] ) && ! isset( $url['pass'] );
	}

	private static function invalid_event(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_invalid_event', __( 'The event context is incomplete or invalid.', 'wp-druid-pulse' ) );
	}
}
