<?php

namespace WP_Druid_Pulse\Rest;

use WP_Druid_Pulse\EventMapper;
use WP_Druid_Pulse\EventStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventsController {
	public const ROUTE = '/wp-druid-pulse/v1/events';
	public const MAX_BODY_BYTES = 16384;

	public function __construct( private EventMapper $types, private EventStore $store ) {}

	public function register_hooks(): void {
		register_rest_route( 'wp-druid-pulse/v1', '/events', array(
			'methods' => 'POST',
			'permission_callback' => array( $this, 'authorize' ),
			'callback' => array( $this, 'receive' ),
		) );
		add_filter( 'rest_pre_dispatch', array( $this, 'limit_body' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'response_headers' ), 20, 4 );
	}

	public function authorize( \WP_REST_Request $request ): true|\WP_Error {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || rest_get_authenticated_app_password()
			|| (int) wp_validate_auth_cookie( '', 'logged_in' ) !== $user_id ) {
			return self::error( 'session_required', __( 'An authenticated WordPress browser session is required.', 'wp-druid-pulse' ), 401 );
		}
		if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return self::error( 'session_invalid', __( 'The session nonce is invalid. Reload the page before sending new events.', 'wp-druid-pulse' ), 403 );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? $request->get_method() ) ) {
			return self::error( 'method_not_allowed', __( 'Events require an HTTP POST request.', 'wp-druid-pulse' ), 405 );
		}
		$origin = $request->get_header( 'Origin' ) ?? '';
		if ( '' !== $origin && ( null === self::origin( $origin ) || self::origin( $origin ) !== self::origin( home_url( '/' ) ) ) ) {
			return self::error( 'origin_not_allowed', __( 'Events must originate from this WordPress site.', 'wp-druid-pulse' ), 403 );
		}
		return true;
	}

	public function receive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $request->is_json_content_type() ) {
			return self::error( 'content_type', __( 'A JSON request body is required.', 'wp-druid-pulse' ), 415 );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || count( $input ) !== 3
			|| array_diff( array_keys( $input ), array( 'type', 'published', 'context' ) )
			|| ! is_string( $input['type'] ?? null ) || ! preg_match( '/^[a-z][a-z0-9_:-]{0,63}$/D', $input['type'] )
			|| ! is_string( $input['published'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $input['published'] )
			|| ! is_array( $input['context'] ?? null ) || ( $input['context'] && array_is_list( $input['context'] ) ) ) {
			return self::invalid_input();
		}
		$published = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $input['published'], new \DateTimeZone( 'UTC' ) );
		if ( false === $published || $published->format( 'Y-m-d\TH:i:s\Z' ) !== $input['published'] || (int) $published->format( 'Y' ) < 1000 ) {
			return self::invalid_input();
		}
		$context = $this->types->validate_context( $input['type'], $input['context'] );
		if ( is_wp_error( $context ) ) {
			$data = $context->get_error_data();
			$status = is_array( $data ) ? ( $data['status'] ?? 400 ) : 400;
			$status = is_int( $status ) && $status >= 400 && $status <= 599 ? $status : 400;
			return new \WP_Error( $context->get_error_code(), $context->get_error_message(), array( 'status' => $status ) );
		}
		$id = $this->store->append( $input['type'], get_current_user_id(), $context, $published );
		if ( is_wp_error( $id ) ) {
			$status = match ( $id->get_error_code() ) {
				'wp_druid_pulse_queue_full', 'wp_druid_pulse_queue_busy' => 503,
				'wp_druid_pulse_event_size' => 413,
				default => 500,
			};
			return new \WP_Error( $id->get_error_code(), $id->get_error_message(), array( 'status' => $status ) );
		}
		return new \WP_REST_Response( array( 'accepted' => true, 'event_id' => $id ), 202 );
	}

	public function limit_body( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null === $result && self::is_route( $request ) && strlen( $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return self::error( 'request_size', __( 'The event request exceeds 16 KiB.', 'wp-druid-pulse' ), 413 );
		}
		return $result;
	}

	public function response_headers( bool $served, \WP_HTTP_Response $response, \WP_REST_Request $request, \WP_REST_Server $server ): bool {
		if ( self::is_route( $request ) ) {
			$server->remove_header( 'Access-Control-Allow-Origin' );
			$server->remove_header( 'Access-Control-Allow-Credentials' );
			$server->send_header( 'Cache-Control', 'no-store, private' );
		}
		return $served;
	}

	private static function is_route( \WP_REST_Request $request ): bool {
		return 0 === strcasecmp( self::ROUTE, $request->get_route() );
	}

	private static function origin( string $url ): ?string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		return strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ':' . ( $parts['port'] ?? ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 ) );
	}

	private static function invalid_input(): \WP_Error {
		return self::error( 'request_invalid', __( 'Provide only an event type, a UTC date in YYYY-MM-DDTHH:MM:SSZ format and its context.', 'wp-druid-pulse' ), 400 );
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_' . $code, $message, array( 'status' => $status ) );
	}
}
