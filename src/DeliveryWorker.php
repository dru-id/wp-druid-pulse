<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/EventPreparer.php';
require_once __DIR__ . '/PulseClient.php';
require_once __DIR__ . '/DeliveryPolicy.php';

final class DeliveryWorker {
	public const BATCH_SIZE = 25;
	private const RUN_SECONDS = 25;
	private const REQUEST_SECONDS = 20;

	public static function run(): void {
		if ( ! Lifecycle::is_current() ) {
			throw new \RuntimeException( 'Delivery requires a compatible installation.' );
		}
		global $wpdb;
		$lock = 'druid_pulse_delivery_' . substr( hash( 'sha256', DB_NAME . ':' . EventStore::table() ), 0, 40 );
		$previous = $wpdb->suppress_errors( true );
		try {
			$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
			if ( '0' === (string) $acquired ) {
				return;
			}
			if ( '1' !== (string) $acquired ) {
				throw new \RuntimeException( 'Delivery could not acquire its database lock.' );
			}
			try {
				self::deliver( $lock );
			} catch ( \Throwable $error ) {
				// Action Scheduler logs exception messages; extensions may include private event data.
				throw new \RuntimeException( 'DruID Pulse delivery stopped unexpectedly. Check its queue, database and registered event mappers.' );
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	private static function deliver( string $lock ): void {
		$started = microtime( true );
		Scheduler::cleanup();
		$store = new EventStore();
		$preparer = new EventPreparer( $store, new EventMapper() );
		$client = new PulseClient();
		$ids = self::checked( $store->pending_ids( self::BATCH_SIZE ) );
		foreach ( $ids as $index => $id ) {
			if ( $index > 0 && microtime( true ) - $started + self::REQUEST_SECONDS > self::RUN_SECONDS ) {
				break;
			}
			$state = self::checked( DeliveryState::read() );
			if ( $state['retry_at'] > time() || ! self::owns_lock( $lock ) ) {
				break;
			}
			if ( ! self::checked( $store->begin_attempt( $id, $lock ) ) ) {
				continue;
			}
			$beat = $preparer->prepare( $id );
			if ( is_wp_error( $beat ) && in_array( $beat->get_error_code(), array( 'wp_druid_pulse_event_missing', 'wp_druid_pulse_event_expired' ), true ) ) {
				continue;
			}
			if ( ! self::owns_lock( $lock ) ) {
				break;
			}
			if ( is_wp_error( $beat ) && 'wp_druid_pulse_event_store' === $beat->get_error_code() ) {
				self::checked( $beat );
			}
			$result = is_wp_error( $beat ) ? $beat : $client->send( $beat );
			if ( ! self::owns_lock( $lock ) ) {
				throw new \RuntimeException( 'Delivery lost its database lock; the remote outcome may be unknown.' );
			}
			if ( ! is_wp_error( $result ) ) {
				self::checked( $store->finish_attempt( $id, 'accepted', '', 0, $lock ) );
				self::checked( $store->remove( $id ) );
				if ( ! self::checked( DeliveryState::save( $state, DeliveryState::cleared( $state ) ) ) ) {
					break;
				}
				continue;
			}
			$failure = DeliveryPolicy::failure( $result, $state );
			$next_state = $state;
			if ( $failure['shared'] ) {
				$next_state = array_replace( $state, array( 'retry_at' => $failure['retry_at'], 'failures' => min( 4, $state['failures'] + 1 ), 'error' => $failure['error'], 'auth_retry' => $failure['auth_retry'] ) );
			} elseif ( 'wp_druid_pulse_http' === $result->get_error_code() ) {
				$next_state = DeliveryState::cleared( $state );
			}
			self::checked( $store->finish_attempt( $id, $failure['status'], $failure['error'], $failure['shared'] ? 0 : $failure['retry_at'], $lock ) );
			$saved = self::checked( DeliveryState::save( $state, $next_state ) );
			if ( $failure['shared'] || ! $saved ) {
				break;
			}
		}
	}

	private static function owns_lock( string $lock ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $lock ) );
	}

	private static function checked( mixed $value ): mixed {
		if ( is_wp_error( $value ) ) {
			throw new \RuntimeException( 'DruID Pulse delivery could not persist or read its state. Check database access.' );
		}
		return $value;
	}
}
