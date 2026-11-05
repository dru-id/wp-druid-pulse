<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit( "An isolated test installation is required.\n" );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

use WP_Druid_Pulse\EventStore;

function capacity_context( int $bytes ): array {
	return array( 'blob' => str_repeat( 'x', $bytes - 11 ) );
}

if ( 'worker' === ( $argv[1] ?? '' ) ) {
	$barrier = $argv[4];
	touch( $barrier . '/ready-' . getmypid() );
	$deadline = microtime( true ) + 20;
	while ( ! file_exists( $barrier . '/go' ) ) {
		if ( microtime( true ) > $deadline ) {
			exit( 2 );
		}
		usleep( 10000 );
	}
	$result = ( new EventStore() )->append( 'capacity_test', (int) $argv[2], capacity_context( (int) $argv[3] ), new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
	echo json_encode( array( 'result' => is_wp_error( $result ) ? $result->get_error_code() : $result ) );
	exit;
}

$checks = 0;
function capacity_check( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}

function capacity_seed( int $count, int $bytes, int $user_id ): void {
	global $wpdb;
	$json = wp_json_encode( capacity_context( $bytes ) );
	capacity_check( strlen( $json ) === $bytes, 'Invalid fixture byte count.' );
	$now = gmdate( 'Y-m-d H:i:s' );
	$expires = gmdate( 'Y-m-d H:i:s', time() + EventStore::RETENTION_SECONDS );
	for ( $offset = 0; $offset < $count; $offset += 100 ) {
		$rows = array();
		$batch = min( 100, $count - $offset );
		for ( $index = 0; $index < $batch; ++$index ) {
			$id = sprintf( '00000000-0000-4000-8000-%012d', $offset + $index );
			$rows[] = $wpdb->prepare( '(%s,%d,%s,%d,%s,%s,%s,%s,%d)', $id, EventStore::FORMAT_VERSION, 'capacity_test', $user_id, $now, $now, $expires, $json, $bytes );
		}
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (event_id,format_version,event_type,user_id,published,received_at,expires_at,context,context_bytes) VALUES ', EventStore::table() ) . implode( ',', $rows ) );
		if ( $inserted !== $batch ) {
			throw new RuntimeException( 'Could not seed the isolated capacity test.' );
		}
	}
}

function capacity_race( int $user_id, int $bytes ): array {
	$barrier = sys_get_temp_dir() . '/pulse-capacity-' . wp_generate_uuid4();
	mkdir( $barrier, 0700 );
	$workers = array();
	try {
		for ( $index = 0; $index < 4; ++$index ) {
			$process = proc_open( array( PHP_BINARY, __FILE__, 'worker', (string) $user_id, (string) $bytes, $barrier ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			if ( ! is_resource( $process ) ) {
				throw new RuntimeException( 'Could not start a concurrent worker.' );
			}
			fclose( $pipes[0] );
			$workers[] = array( $process, $pipes );
		}
		$deadline = microtime( true ) + 20;
		while ( count( glob( $barrier . '/ready-*' ) ) < count( $workers ) ) {
			if ( microtime( true ) > $deadline ) {
				throw new RuntimeException( 'Concurrent workers did not become ready.' );
			}
			usleep( 10000 );
		}
		touch( $barrier . '/go' );
		$results = array();
		foreach ( $workers as [ $process, $pipes ] ) {
			$deadline = microtime( true ) + 20;
			do {
				$status = proc_get_status( $process );
				if ( microtime( true ) > $deadline ) {
					throw new RuntimeException( 'Concurrent insertion did not finish.' );
				}
				usleep( 10000 );
			} while ( $status['running'] );
			$result = json_decode( stream_get_contents( $pipes[1] ), true );
			$errors = stream_get_contents( $pipes[2] );
			if ( 0 !== $status['exitcode'] || '' !== $errors || ! isset( $result['result'] ) ) {
				throw new RuntimeException( 'A concurrent worker failed.' );
			}
			$results[] = $result['result'];
		}
		return $results;
	} finally {
		foreach ( $workers as [ $process, $pipes ] ) {
			if ( proc_get_status( $process )['running'] ) {
				proc_terminate( $process );
			}
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $process );
		}
		foreach ( glob( $barrier . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $barrier );
	}
}

function capacity_results( array $results ): void {
	$accepted = array_filter( $results, static fn( $result ) => wp_is_uuid( $result, 4 ) );
	$rejected = array_filter( $results, static fn( $result ) => in_array( $result, array( 'wp_druid_pulse_queue_full', 'wp_druid_pulse_queue_busy' ), true ) );
	capacity_check( 1 === count( $accepted ) && 3 === count( $rejected ), 'Concurrent inserts crossed the hard limit or failed unexpectedly.' );
}

global $wpdb;
$store = new EventStore();
$user_id = 0;
$exit_code = 0;
try {
	capacity_check( null === EventStore::verify(), 'Queue schema is not ready.' );
	$user_id = wp_insert_user( array( 'user_login' => 'pulse-capacity-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password() ) );
	capacity_check( is_int( $user_id ), 'Could not create the synthetic user.' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	capacity_seed( EventStore::MAX_EVENTS - 1, 32, $user_id );
	capacity_results( capacity_race( $user_id, 32 ) );
	capacity_check( array( 'events' => EventStore::MAX_EVENTS, 'bytes' => EventStore::MAX_EVENTS * 32 ) === EventStore::usage(), 'Count limit is inaccurate.' );
	$result = $store->append( 'capacity_test', $user_id, capacity_context( 32 ), new DateTimeImmutable() );
	capacity_check( is_wp_error( $result ) && 'wp_druid_pulse_queue_full' === $result->get_error_code(), 'Full queue accepted another record.' );
	capacity_check( ! is_wp_error( $store->get( '00000000-0000-4000-8000-000000000000' ) ), 'Capacity rejection evicted an accepted event.' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	$count = intdiv( EventStore::MAX_BYTES, EventStore::MAX_EVENT_BYTES );
	capacity_seed( $count - 1, EventStore::MAX_EVENT_BYTES, $user_id );
	capacity_results( capacity_race( $user_id, EventStore::MAX_EVENT_BYTES ) );
	capacity_check( array( 'events' => $count, 'bytes' => EventStore::MAX_BYTES ) === EventStore::usage(), 'Byte limit is inaccurate.' );
	$result = $store->append( 'capacity_test', $user_id, capacity_context( 32 ), new DateTimeImmutable() );
	capacity_check( is_wp_error( $result ) && 'wp_druid_pulse_queue_full' === $result->get_error_code(), 'Full byte budget accepted another record.' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	$result = $store->append( 'capacity_test', $user_id, capacity_context( EventStore::MAX_EVENT_BYTES + 1 ), new DateTimeImmutable() );
	capacity_check( is_wp_error( $result ) && 'wp_druid_pulse_event_size' === $result->get_error_code() && 0 === EventStore::usage()['events'], 'Event limit accepted 64 KiB plus one byte.' );
	echo "Passed {$checks} capacity assertions with concurrent PHP processes at 10,000 records and 100 MiB.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	if ( is_int( $user_id ) && $user_id > 0 ) {
		wp_delete_user( $user_id );
	}
}
exit( $exit_code );
