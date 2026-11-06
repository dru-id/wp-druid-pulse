<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/ActorResolver.php';
require_once __DIR__ . '/EventMapper.php';

final class EventPreparer {
	public function __construct( private EventStore $store, private EventMapper $mapper ) {}

	public function prepare( string $event_id ): array|\WP_Error {
		$event = $this->store->get( $event_id );
		if ( is_wp_error( $event ) ) {
			return $event;
		}
		if ( EventStore::FORMAT_VERSION !== $event['format_version'] ) {
			return new \WP_Error( 'wp_druid_pulse_event_version', __( 'The queued event format is not supported by this plugin version.', 'wp-druid-pulse' ) );
		}
		$published = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $event['published'], new \DateTimeZone( 'UTC' ) );
		if ( false === $published || $published->format( 'Y-m-d H:i:s' ) !== $event['published'] ) {
			return new \WP_Error( 'wp_druid_pulse_event_date', __( 'The queued event date is invalid.', 'wp-druid-pulse' ) );
		}
		$actor = ( new ActorResolver() )->resolve( $event['user_id'] );
		if ( is_wp_error( $actor ) ) {
			return $actor;
		}
		$beat = $this->mapper->map( $event['event_type'], $event['context'], $actor, $published );
		if ( ! is_wp_error( $beat ) ) {
			$beat['externalId'] = 'wp-druid-pulse:event:' . $event_id;
		}
		return $beat;
	}
}
