<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {
	public const GROUP = 'wp-druid-pulse';
	public const CLEANUP_HOOK = 'wp_druid_pulse_cleanup_events';
	public const WAKE_HOOK = 'wp_druid_pulse_check_delivery';
	public const DELIVERY_HOOK = 'wp_druid_pulse_deliver_events';

	public static function ready(): ?\WP_Error {
		return is_callable( array( '\ActionScheduler', 'is_initialized' ) ) && \ActionScheduler::is_initialized()
			&& function_exists( 'as_has_scheduled_action' ) ? null : self::error();
	}

	public static function register_hooks(): void {
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		add_action( self::WAKE_HOOK, array( self::class, 'wake' ) );
		add_action( self::DELIVERY_HOOK, array( self::class, 'deliver' ) );
		add_action( 'action_scheduler_completed_action', array( self::class, 'completed' ) );
		add_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring' ) );
	}

	public static function ensure_scheduled(): ?\WP_Error {
		return self::run( static function () {
			foreach ( array( self::CLEANUP_HOOK => HOUR_IN_SECONDS, self::WAKE_HOOK => MINUTE_IN_SECONDS ) as $hook => $interval ) {
				if ( as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
					continue;
				}
				$id = as_schedule_recurring_action( time(), $interval, $hook, array(), self::GROUP, true );
				if ( ! $id && ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
					return self::error();
				}
			}
			return null;
		} );
	}

	public static function wake(): void {
		if ( ! Lifecycle::is_current() ) {
			return;
		}
		$state = DeliveryState::read();
		if ( is_wp_error( $state ) ) {
			throw new \RuntimeException( 'Delivery state is unavailable. Check database access.' );
		}
		if ( $state['retry_at'] > time() ) {
			return;
		}
		$ids = ( new EventStore() )->pending_ids( 1 );
		if ( is_wp_error( $ids ) ) {
			throw new \RuntimeException( 'Delivery could not read pending events. Check database access.' );
		}
		if ( ! $ids || as_has_scheduled_action( self::DELIVERY_HOOK, array(), self::GROUP ) ) {
			return;
		}
		$id = as_enqueue_async_action( self::DELIVERY_HOOK, array(), self::GROUP, true );
		if ( ! $id && ! as_has_scheduled_action( self::DELIVERY_HOOK, array(), self::GROUP ) ) {
			throw new \RuntimeException( 'Delivery could not be scheduled. Check Action Scheduler.' );
		}
	}

	public static function deliver(): void {
		require_once __DIR__ . '/DeliveryWorker.php';
		DeliveryWorker::run();
	}

	public static function completed( int $id ): void {
		$action = \ActionScheduler::store()->fetch_action( $id );
		if ( self::DELIVERY_HOOK === $action->get_hook() && self::GROUP === $action->get_group()
			&& in_array( plugin_basename( dirname( __DIR__ ) . '/wp-druid-pulse.php' ), get_option( 'active_plugins', array() ), true ) ) {
			self::wake();
		}
	}

	public static function ensure_recurring(): void {
		if ( ! Lifecycle::is_current() ) {
			return;
		}
		$error = self::ensure_scheduled();
		if ( null !== $error ) {
			throw new \RuntimeException( $error->get_error_message() );
		}
	}

	public static function cleanup(): void {
		if ( ! Lifecycle::is_current() ) {
			throw new \RuntimeException( 'DruID Pulse maintenance requires a compatible installation.' );
		}
		$accepted = EventStore::purge_accepted();
		if ( is_wp_error( $accepted ) ) {
			throw new \RuntimeException( $accepted->get_error_message() );
		}
		for ( $batch = 0; $batch < EventStore::MAX_EVENTS / EventStore::CLEANUP_BATCH; ++$batch ) {
			$count = EventStore::purge_expired();
			if ( is_wp_error( $count ) ) {
				throw new \RuntimeException( $count->get_error_message() );
			}
			if ( $count < EventStore::CLEANUP_BATCH ) {
				break;
			}
		}
	}

	public static function stop(): ?\WP_Error {
		remove_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
		remove_action( self::WAKE_HOOK, array( self::class, 'wake' ) );
		remove_action( self::DELIVERY_HOOK, array( self::class, 'deliver' ) );
		remove_action( 'action_scheduler_completed_action', array( self::class, 'completed' ) );
		remove_action( 'action_scheduler_ensure_recurring_actions', array( self::class, 'ensure_recurring' ) );
		return self::run( static function () {
			$store = \ActionScheduler::store();
			do {
				$ids = self::action_ids( 'pending' );
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				foreach ( $ids as $id ) {
					$store->cancel_action( $id );
					if ( 'canceled' !== $store->get_status( $id ) ) {
						return self::error();
					}
				}
			} while ( $ids );
			return null;
		} );
	}

	public static function uninstall(): ?\WP_Error {
		$error = self::stop();
		if ( null !== $error ) {
			return $error;
		}
		return self::run( static function () {
			global $wpdb;
			$store = \ActionScheduler::store();
			$logger = \ActionScheduler::logger();
			do {
				$ids = self::action_ids();
				if ( is_wp_error( $ids ) ) {
					return $ids;
				}
				foreach ( $ids as $id ) {
					// Keep the action discoverable for a retry if its database logs cannot be removed.
					if ( is_callable( array( $logger, 'clear_deleted_action_logs' ) ) ) {
						$logger->clear_deleted_action_logs( $id );
						if ( $logger->get_logs( $id ) || '' !== $wpdb->last_error ) {
							return self::error();
						}
					}
					$store->delete_action( $id );
				}
			} while ( $ids );
			return null;
		} );
	}

	private static function action_ids( string $status = '' ): array|\WP_Error {
		global $wpdb;
		$ids = array();
		foreach ( array( self::CLEANUP_HOOK, self::WAKE_HOOK, self::DELIVERY_HOOK ) as $hook ) {
			$found = as_get_scheduled_actions( array( 'hook' => $hook, 'group' => self::GROUP, 'status' => $status, 'per_page' => 100, 'orderby' => 'none' ), 'ids' );
			if ( '' !== $wpdb->last_error ) {
				return self::error();
			}
			$ids = array_merge( $ids, $found );
		}
		return $ids;
	}

	private static function run( callable $operation ): ?\WP_Error {
		$error = self::ready();
		if ( null !== $error ) {
			return $error;
		}
		try {
			return $operation();
		} catch ( \Exception $error ) {
			return self::error();
		}
	}

	private static function error(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_scheduler', __( 'DruID Pulse could not manage its maintenance tasks. Check Action Scheduler and database access.', 'wp-druid-pulse' ) );
	}
}
