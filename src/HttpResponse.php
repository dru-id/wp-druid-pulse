<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class HttpResponse {
	public static function error_data( array $response ): array {
		$data = array( 'http_status' => wp_remote_retrieve_response_code( $response ) );
		$value = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $data;
		}
		$value = trim( $value );
		$now = time();
		if ( ctype_digit( $value ) ) {
			$seconds = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => PHP_INT_MAX - $now ) ) );
			$until = false === $seconds ? false : $now + $seconds;
		} else {
			$date = \DateTimeImmutable::createFromFormat( 'D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone( 'UTC' ) );
			$until = $date && $date->format( 'D, d M Y H:i:s \G\M\T' ) === $value ? $date->getTimestamp() : false;
		}
		if ( false !== $until && $until > $now ) {
			$data['retry_at'] = $until;
		}
		return $data;
	}
}
