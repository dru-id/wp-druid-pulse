<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EventStore {
	public const FORMAT_VERSION = 1;
	public const MAX_EVENTS = 10000;
	public const MAX_BYTES = 104857600;
	public const MAX_EVENT_BYTES = 65536;
	public const RETENTION_SECONDS = 604800;
	public const CLEANUP_BATCH = 500;
	public const DIAGNOSTIC_PAGE_SIZE = 25;
	public const DIAGNOSTIC_STATES = array( 'ready', 'waiting', 'failed', 'conflict', 'expired', 'accepted', 'unknown' );

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'druid_pulse_events';
	}

	public static function initialize(): ?\WP_Error {
		return self::with_database( static function () {
			global $wpdb;
			$table = self::table();
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error();
			}
			if ( null === $exists || ! Lifecycle::is_current() ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				dbDelta( "CREATE TABLE $table (
					event_id char(36) NOT NULL,
					format_version smallint unsigned NOT NULL,
					event_type varchar(64) NOT NULL,
					user_id bigint unsigned NOT NULL,
					published datetime NOT NULL,
					received_at datetime NOT NULL,
					expires_at datetime NOT NULL,
					context longtext NOT NULL,
					context_bytes int unsigned NOT NULL,
					delivery_status varchar(16) NOT NULL DEFAULT 'pending',
					attempts int unsigned NOT NULL DEFAULT 0,
					next_attempt_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
					last_error varchar(64) NOT NULL DEFAULT '',
					PRIMARY KEY  (event_id),
					KEY expires (expires_at),
					KEY received (received_at,event_id),
					KEY capacity (context_bytes),
					KEY delivery (delivery_status,next_attempt_at,received_at,event_id)
				) " . $wpdb->get_charset_collate() . ';' );
			}
			return self::verify();
		} );
	}

	public static function verify(): ?\WP_Error {
		return self::with_database( static function () {
			global $wpdb;
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::table() ), ARRAY_A );
			$expected = array( 'event_id' => 'char(36)', 'format_version' => 'smallint unsigned', 'event_type' => 'varchar(64)', 'user_id' => 'bigint unsigned', 'published' => 'datetime', 'received_at' => 'datetime', 'expires_at' => 'datetime', 'context' => 'longtext', 'context_bytes' => 'int unsigned' );
			$expected += array( 'delivery_status' => 'varchar(16)', 'attempts' => 'int unsigned', 'next_attempt_at' => 'datetime', 'last_error' => 'varchar(64)' );
			if ( '' !== $wpdb->last_error || ! is_array( $columns ) || count( $columns ) !== count( $expected ) ) {
				return self::database_error();
			}
			foreach ( $columns as $column ) {
				$type = preg_replace( '/\b(smallint|bigint|int)\(\d+\)/', '$1', $column['Type'] );
				if ( ( $expected[ $column['Field'] ] ?? null ) !== $type || 'NO' !== $column['Null'] ) {
					return self::database_error();
				}
			}
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', self::table() ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error();
			}
			$keys = array();
			foreach ( $indexes as $index ) {
				$keys[ $index['Key_name'] ][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
			foreach ( array( 'PRIMARY' => array( 'event_id' ), 'expires' => array( 'expires_at' ), 'received' => array( 'received_at', 'event_id' ), 'capacity' => array( 'context_bytes' ), 'delivery' => array( 'delivery_status', 'next_attempt_at', 'received_at', 'event_id' ) ) as $name => $fields ) {
				$columns = $keys[ $name ] ?? array();
				ksort( $columns );
				if ( array_values( $columns ) !== $fields ) {
					return self::database_error();
				}
			}
			return null;
		} );
	}

	public function append( string $type, int $user_id, array $context, \DateTimeImmutable $published ): string|\WP_Error {
		if ( ! Lifecycle::is_current() ) {
			return self::database_error();
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_:-]{0,63}$/D', $type ) || $user_id < 1 || ! get_user_by( 'id', $user_id ) ) {
			return new \WP_Error( 'wp_druid_pulse_event_input', __( 'A valid event type and WordPress user are required.', 'wp-druid-pulse' ) );
		}
		$json = json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
		if ( false === $json || strlen( $json ) > self::MAX_EVENT_BYTES ) {
			return new \WP_Error( 'wp_druid_pulse_event_size', __( 'The event context is invalid or exceeds 64 KiB.', 'wp-druid-pulse' ) );
		}
		return self::with_lock( static function ( string $lock ) use ( $type, $user_id, $json, $published ) {
			global $wpdb;
			$usage = self::usage();
			if ( is_wp_error( $usage ) ) {
				return $usage;
			}
			if ( $usage['events'] >= self::MAX_EVENTS || $usage['bytes'] + strlen( $json ) > self::MAX_BYTES ) {
				return new \WP_Error( 'wp_druid_pulse_queue_full', __( 'The event queue has reached its storage limit. The new event was not saved.', 'wp-druid-pulse' ) );
			}
			$id = wp_generate_uuid4();
			$now = time();
			// Recheck lock ownership in the INSERT in case wpdb reconnected after acquiring it.
			$inserted = $wpdb->query( $wpdb->prepare(
				'INSERT INTO %i (event_id,format_version,event_type,user_id,published,received_at,expires_at,context,context_bytes)
				 SELECT %s,%d,%s,%d,%s,%s,%s,%s,%d FROM DUAL WHERE IS_USED_LOCK(%s) = CONNECTION_ID()',
				self::table(), $id, self::FORMAT_VERSION, $type, $user_id,
				$published->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', $now ), gmdate( 'Y-m-d H:i:s', $now + self::RETENTION_SECONDS ), $json, strlen( $json ), $lock
			) );
			return 1 === $inserted ? $id : self::database_error();
		} );
	}

	public static function usage(): array|\WP_Error {
		return self::with_database( static function () {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS events, COALESCE(SUM(context_bytes),0) AS bytes FROM %i', self::table() ), ARRAY_A );
			return '' === $wpdb->last_error && is_array( $row ) ? array_map( 'intval', $row ) : self::database_error();
		} );
	}

	public static function diagnostics( int $retry_at, string $state = '', string $type = '', int $page = 1 ): array|\WP_Error {
		if ( ! Lifecycle::is_current() || ( '' !== $state && ! in_array( $state, self::DIAGNOSTIC_STATES, true ) )
			|| ( '' !== $type && ! preg_match( '/^[a-z][a-z0-9_:-]{0,63}$/D', $type ) ) ) {
			return self::database_error();
		}
		return self::with_database( static function () use ( $retry_at, $state, $type, $page ) {
			global $wpdb;
			$now = time();
			$date = gmdate( 'Y-m-d H:i:s', $now );
			$bucket = $wpdb->prepare( "CASE WHEN expires_at <= %s THEN 'expired'
				WHEN delivery_status='pending' THEN IF(next_attempt_at <= %s AND %d <= %d, 'ready', 'waiting')
				WHEN delivery_status IN ('failed','conflict','accepted') THEN delivery_status ELSE 'unknown' END", $date, $date, $retry_at, $now );
			$groups = $wpdb->get_results( $wpdb->prepare( "SELECT $bucket AS state, COUNT(*) AS events, SUM(context_bytes) AS bytes,
				MIN(IF(delivery_status='pending' AND expires_at > %s, received_at, NULL)) AS oldest
				FROM %i GROUP BY state", $date, self::table() ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error();
			}
			$result = array( 'counts' => array_fill_keys( self::DIAGNOSTIC_STATES, 0 ), 'events' => 0, 'bytes' => 0, 'oldest' => null );
			foreach ( $groups as $group ) {
				$result['counts'][ $group['state'] ] = (int) $group['events'];
				$result['events'] += (int) $group['events'];
				$result['bytes'] += (int) $group['bytes'];
				if ( null !== $group['oldest'] && ( null === $result['oldest'] || $group['oldest'] < $result['oldest'] ) ) {
					$result['oldest'] = $group['oldest'];
				}
			}
			$result['types'] = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_type FROM %i ORDER BY event_type', self::table() ) );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error();
			}
			$where = '1=1';
			if ( '' !== $state ) {
				$where .= $wpdb->prepare( " AND ($bucket)=%s", $state );
			}
			if ( '' !== $type ) {
				$where .= $wpdb->prepare( ' AND event_type=%s', $type );
			}
			$result['total'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $where", self::table() ) );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error();
			}
			$result['pages'] = max( 1, (int) ceil( $result['total'] / self::DIAGNOSTIC_PAGE_SIZE ) );
			$result['page'] = max( 1, min( $result['pages'], $page ) );
			$result['rows'] = $wpdb->get_results( $wpdb->prepare( "SELECT event_id,event_type,published,received_at,expires_at,
				delivery_status,attempts,next_attempt_at,last_error,$bucket AS state FROM %i WHERE $where
				ORDER BY received_at,event_id LIMIT %d OFFSET %d", self::table(), self::DIAGNOSTIC_PAGE_SIZE, ( $result['page'] - 1 ) * self::DIAGNOSTIC_PAGE_SIZE ), ARRAY_A );
			return '' === $wpdb->last_error ? $result : self::database_error();
		} );
	}

	public static function retry_failed( array $expected_attempts ): int|\WP_Error {
		if ( ! Lifecycle::is_current() || ! $expected_attempts || count( $expected_attempts ) > self::DIAGNOSTIC_PAGE_SIZE ) {
			return self::database_error();
		}
		foreach ( $expected_attempts as $id => $attempts ) {
			if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || ! is_int( $attempts ) || $attempts < 0 || $attempts > 4294967295 ) {
				return self::database_error();
			}
		}
		return self::with_lock( static function ( string $lock ) use ( $expected_attempts ) {
			global $wpdb;
			$matches = array();
			foreach ( $expected_attempts as $id => $attempts ) {
				$matches[] = $wpdb->prepare( '(event_id=%s AND attempts=%d)', $id, $attempts );
			}
			// The displayed attempt count fences stale forms after another retry has already failed.
			$where = implode( ' OR ', $matches );
			$count = $wpdb->query( $wpdb->prepare( "UPDATE %i SET delivery_status='pending',next_attempt_at='1970-01-01 00:00:00'
				WHERE delivery_status='failed' AND expires_at > %s AND ($where) AND IS_USED_LOCK(%s)=CONNECTION_ID()",
				self::table(), gmdate( 'Y-m-d H:i:s' ), $lock ) );
			if ( false === $count || ( 0 === $count && '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)=CONNECTION_ID()', $lock ) ) ) ) {
				return self::database_error();
			}
			return $count;
		} );
	}

	public function pending_ids( int $limit = 100 ): array|\WP_Error {
		return self::with_database( static function () use ( $limit ) {
			global $wpdb;
			$now = gmdate( 'Y-m-d H:i:s' );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM %i WHERE delivery_status='pending' AND next_attempt_at <= %s AND expires_at > %s ORDER BY received_at,event_id LIMIT %d", self::table(), $now, $now, max( 1, min( 1000, $limit ) ) ) );
			return '' === $wpdb->last_error ? $ids : self::database_error();
		} );
	}

	public function get( string $id ): array|\WP_Error {
		return self::with_database( static function () use ( $id ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id = %s', self::table(), $id ), ARRAY_A );
			if ( '' !== $wpdb->last_error ) {
				return self::database_error();
			}
			if ( ! $row ) {
				return new \WP_Error( 'wp_druid_pulse_event_missing', __( 'The queued event no longer exists.', 'wp-druid-pulse' ) );
			}
			if ( $row['expires_at'] <= gmdate( 'Y-m-d H:i:s' ) ) {
				return new \WP_Error( 'wp_druid_pulse_event_expired', __( 'The queued event has expired and will not be sent.', 'wp-druid-pulse' ) );
			}
			$context = json_decode( $row['context'], true );
			if ( ! is_array( $context ) || JSON_ERROR_NONE !== json_last_error() || strlen( $row['context'] ) !== (int) $row['context_bytes'] ) {
				return new \WP_Error( 'wp_druid_pulse_event_data', __( 'The queued event context is invalid.', 'wp-druid-pulse' ) );
			}
			$row['context'] = $context;
			foreach ( array( 'format_version', 'user_id', 'context_bytes', 'attempts' ) as $field ) {
				$row[ $field ] = (int) $row[ $field ];
			}
			return $row;
		} );
	}

	public function remove( string $id ): ?\WP_Error {
		return self::with_lock( static function () use ( $id ) {
			global $wpdb;
			return false === $wpdb->delete( self::table(), array( 'event_id' => $id ), array( '%s' ) ) ? self::database_error() : null;
		} );
	}

	public function begin_attempt( string $id, string $worker_lock ): bool|\WP_Error {
		return self::with_database( static function () use ( $id, $worker_lock ) {
			global $wpdb;
			$now = gmdate( 'Y-m-d H:i:s' );
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE %i SET attempts=attempts+1,next_attempt_at=%s WHERE event_id=%s AND delivery_status='pending'
				 AND next_attempt_at <= %s AND expires_at > %s AND IS_USED_LOCK(%s)=CONNECTION_ID()",
				self::table(), gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ), $id, $now, $now, $worker_lock
			) );
			return false === $changed ? self::database_error() : 1 === $changed;
		} );
	}

	public function finish_attempt( string $id, string $status, string $reason, int $retry_at, string $worker_lock ): ?\WP_Error {
		return self::with_database( static function () use ( $id, $status, $reason, $retry_at, $worker_lock ) {
			global $wpdb;
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE %i SET delivery_status=%s,last_error=%s,next_attempt_at=%s WHERE event_id=%s
				 AND delivery_status='pending' AND IS_USED_LOCK(%s)=CONNECTION_ID()",
				self::table(), $status, $reason, gmdate( 'Y-m-d H:i:s', min( $retry_at, time() + self::RETENTION_SECONDS ) ), $id, $worker_lock
			) );
			if ( 1 === $changed ) {
				return null;
			}
			if ( false === $changed ) {
				return self::database_error();
			}
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT event_id FROM %i WHERE event_id=%s', self::table(), $id ) );
			return null === $exists && '' === $wpdb->last_error ? null : self::database_error();
		} );
	}

	public static function purge_accepted(): int|\WP_Error {
		return self::with_lock( static function () {
			global $wpdb;
			$count = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE delivery_status='accepted' LIMIT %d", self::table(), self::CLEANUP_BATCH ) );
			return false === $count ? self::database_error() : $count;
		} );
	}

	public static function purge_expired(): int|\WP_Error {
		return self::with_lock( static function () {
			global $wpdb;
			$count = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s ORDER BY expires_at LIMIT %d', self::table(), gmdate( 'Y-m-d H:i:s' ), self::CLEANUP_BATCH ) );
			return false === $count ? self::database_error() : $count;
		} );
	}

	public static function uninstall(): ?\WP_Error {
		return self::with_lock( static function () {
			global $wpdb;
			if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ) ) {
				return self::database_error();
			}
			$remaining = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
			return null === $remaining && '' === $wpdb->last_error ? null : self::database_error();
		} );
	}

	private static function with_lock( callable $operation ): mixed {
		return self::with_database( static function () use ( $operation ) {
			global $wpdb;
			$lock = 'druid_pulse_' . substr( hash( 'sha256', DB_NAME . ':' . self::table() ), 0, 48 );
			if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
				return new \WP_Error( 'wp_druid_pulse_queue_busy', __( 'The event store could not obtain exclusive access. No change was accepted.', 'wp-druid-pulse' ) );
			}
			try {
				return $operation( $lock );
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		} );
	}

	private static function with_database( callable $operation ): mixed {
		global $wpdb;
		// Database errors may contain the event SQL. Return controlled errors instead of logging payloads.
		$previous = $wpdb->suppress_errors( true );
		try {
			return $operation();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}

	private static function database_error(): \WP_Error {
		return new \WP_Error( 'wp_druid_pulse_event_store', __( 'The event store could not complete the operation. Check its database schema and access.', 'wp-druid-pulse' ) );
	}
}
