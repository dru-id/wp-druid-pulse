<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryPolicy {
	public static function failure( \WP_Error $error, array $state ): array {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$http = is_array( $data ) ? ( $data['http_status'] ?? 0 ) : 0;
		$http = is_int( $http ) && $http >= 100 && $http <= 599 ? $http : 0;
		$oauth = in_array( $code, array( 'wp_druid_pulse_oauth_rejected', 'wp_druid_pulse_oauth_transport', 'wp_druid_pulse_oauth_response' ), true );
		$shared = $oauth || in_array( $code, array( 'wp_druid_pulse_http', 'wp_druid_pulse_transport', 'wp_druid_pulse_response', 'wp_druid_pulse_connection_data', 'wp_druid_pulse_connection_incomplete', 'wp_druid_pulse_installation_data' ), true );
		$status = 'pending';
		$delay = HOUR_IN_SECONDS;
		$auth_retry = $state['auth_retry'];

		if ( 'wp_druid_pulse_http' === $code && in_array( $http, array( 400, 409, 413, 422 ), true ) ) {
			$shared = false;
			$status = 409 === $http ? 'conflict' : ( 422 === $http ? 'pending' : 'failed' );
		} elseif ( 'wp_druid_pulse_http' === $code && 401 === $http ) {
			$delay = $auth_retry ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS;
			$auth_retry = true;
		} elseif ( in_array( $code, array( 'wp_druid_pulse_transport', 'wp_druid_pulse_response', 'wp_druid_pulse_oauth_transport', 'wp_druid_pulse_oauth_response' ), true )
			|| ( $shared && ( in_array( $http, array( 408, 429 ), true ) || $http >= 500 ) ) ) {
			$delays = array( 60, 300, 900, 3600 );
			$delay = $delays[ min( 3, $state['failures'] ) ];
		} elseif ( in_array( $code, array( 'wp_druid_pulse_invalid_event', 'wp_druid_pulse_encoding', 'wp_druid_pulse_event_data', 'wp_druid_pulse_event_date' ), true ) ) {
			$status = 'failed';
		}

		$known = $shared || 'wp_druid_pulse_http' === $code || in_array( $code, array(
			'wp_druid_pulse_invalid_event', 'wp_druid_pulse_encoding', 'wp_druid_pulse_event_data', 'wp_druid_pulse_event_date',
			'wp_druid_pulse_no_user', 'wp_druid_pulse_identity_api', 'wp_druid_pulse_no_object_id', 'wp_druid_pulse_no_real_email',
			'wp_druid_pulse_unknown_event', 'wp_druid_pulse_event_version',
		), true );
		$reason = $known ? $code . ( $http ? '_' . $http : '' ) : 'wp_druid_pulse_preparation_failed';
		$retry_at = max( time() + $delay, is_array( $data ) && is_int( $data['retry_at'] ?? null ) ? $data['retry_at'] : 0 );
		return array( 'status' => $status, 'shared' => $shared, 'retry_at' => $retry_at, 'error' => $reason, 'auth_retry' => $auth_retry );
	}
}
