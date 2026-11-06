<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit( "An isolated test installation is required.\n" );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once WP_PLUGIN_DIR . '/wp-druid-pulse/src/DeliveryWorker.php';

use WP_Druid_Pulse\ConnectionSettings;
use WP_Druid_Pulse\DeliveryState;
use WP_Druid_Pulse\DeliveryWorker;
use WP_Druid_Pulse\EventStore;
use WP_Druid_Pulse\Scheduler;

$checks = 0;
$store = new EventStore();
$calls = array();
$replies = array();
$oauth_replies = array();
$user_id = 0;
$exit_code = 0;
$saved_settings = ConnectionSettings::read();
$settings = array( 'api_url' => 'https://pulse.example/api', 'oauth_url' => 'https://auth.example', 'client_id' => 'synthetic-client', 'client_secret' => 'synthetic-secret', 'auth_method' => 'client_secret_basic', 'scope' => '', 'tenant' => 'synthetic' );

function delivery_check( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function delivery_response( int $status, mixed $body = null, array $headers = array() ): array {
	return array( 'headers' => $headers, 'body' => wp_json_encode( $body ?? array( 'status' => $status ) ), 'response' => array( 'code' => $status ), 'cookies' => array() );
}
function delivery_seed( string $type = 'page_view', ?int $user = null ): string {
	global $store, $user_id;
	$id = $store->append( $type, $user ?? $user_id, array( 'page' => array( 'key' => 'post:42', 'url' => 'https://shop.example/original/', 'title' => 'Original' ), 'link' => array( 'title' => 'Link' ) ), new DateTimeImmutable( '2026-01-02T03:04:05Z' ) );
	if ( ! is_string( $id ) ) {
		throw new RuntimeException( 'Could not seed event.' );
	}
	return $id;
}
function delivery_reset(): void {
	global $wpdb, $calls, $replies, $oauth_replies;
	delivery_check( ! $replies && ! $oauth_replies, 'A planned HTTP response was not consumed.' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	DeliveryState::reset();
	delete_transient( ConnectionSettings::TOKEN_TRANSIENT );
	$calls = array();
}
function delivery_due(): void {
	$state = DeliveryState::read();
	$state['retry_at'] = 0;
	update_option( DeliveryState::OPTION, $state, false );
}
function delivery_event_due( string $id ): void {
	global $wpdb;
	$wpdb->update( EventStore::table(), array( 'next_attempt_at' => '1970-01-01 00:00:00' ), array( 'event_id' => $id ) );
}
function delivery_throws( callable $operation ): bool {
	try {
		$operation();
	} catch ( RuntimeException $error ) {
		return true;
	}
	return false;
}

$http = static function ( $pre, $args, $url ) use ( &$calls, &$replies, &$oauth_replies ) {
	if ( str_ends_with( $url, '/oauth2/token' ) ) {
		$calls[] = array( 'oauth' => $url );
		if ( $oauth_replies ) {
			return array_shift( $oauth_replies );
		}
		return delivery_response( 200, array( 'access_token' => 'synthetic.delivery.token', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	}
	$calls[] = array( 'url' => $url, 'beat' => json_decode( $args['body'], true ), 'headers' => $args['headers'] );
	if ( ! $replies ) {
		throw new RuntimeException( 'Unexpected event HTTP request.' );
	}
	$reply = array_shift( $replies );
	return is_callable( $reply ) ? $reply() : $reply;
};
add_filter( 'pre_http_request', $http, 1000, 3 );

try {
	global $wpdb;
	delivery_check( null === ConnectionSettings::save( $settings ), 'Connection could not be configured.' );
	$user_id = wp_insert_user( array( 'user_login' => 'pulse-delivery-' . wp_generate_uuid4(), 'user_email' => 'delivery@example.test', 'user_pass' => wp_generate_password() ) );
	delivery_check( is_int( $user_id ), 'Synthetic user creation failed.' );
	delivery_reset();
	foreach ( array( 'page_view' => 200, 'click' => 201 ) as $type => $status ) {
		$id = delivery_seed( $type );
		$replies[] = delivery_response( $status );
		Scheduler::wake();
		Scheduler::wake();
		$tasks = as_get_scheduled_actions( array( 'hook' => Scheduler::DELIVERY_HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' );
		delivery_check( count( $tasks ) === 1, 'Delivery wake duplicated an action.' );
		$action = ActionScheduler::store()->fetch_action( $tasks[0] );
		delivery_check( array() === $action->get_args(), 'Action Scheduler contains event or profile data.' );
		ActionScheduler::runner()->process_action( $tasks[0], 'delivery-test' );
		delivery_check( 'complete' === ActionScheduler::store()->get_status( $tasks[0] ) && is_wp_error( $store->get( $id ) ), 'Scheduled delivery failed to acknowledge and remove the event.' );
		$sent = end( $calls );
		delivery_check( 'https://pulse.example/api/identities' === $sent['url'] && 'wp-druid-pulse:event:' . $id === $sent['beat']['externalId'], 'Wrong endpoint or unstable event identifier.' );
		delivery_check( '2026-01-02T03:04:05Z' === $sent['beat']['published'] && 'https://shop.example/original/' === $sent['beat']['object']['url'], 'Delivery changed the original fact.' );
	}
	delivery_reset();
	$id = delivery_seed();
	$expires = $store->get( $id )['expires_at'];
	foreach ( array( 60, 300, 900, 3600, 3600 ) as $expected ) {
		$replies[] = delivery_response( 503, array( 'secret' => 'never store remote content' ) );
		Scheduler::deliver();
		$state = DeliveryState::read();
		delivery_check( abs( $state['retry_at'] - time() - $expected ) <= 2, 'Transient backoff changed.' );
		delivery_check( $store->get( $id )['expires_at'] === $expires && ! str_contains( wp_json_encode( $state ), 'never store' ), 'A retry extended retention or copied remote data.' );
		$count = count( $calls );
		Scheduler::deliver();
		delivery_check( count( $calls ) === $count, 'Common cooldown was ignored.' );
		delivery_due();
	}
	delivery_check( 5 === $store->get( $id )['attempts'], 'Attempts were not persisted.' );
	$replies[] = delivery_response( 200 );
	Scheduler::deliver();
	delivery_check( '' === DeliveryState::read()['error'] && 0 === DeliveryState::read()['failures'], 'Successful recovery did not clear common failure state.' );

	foreach ( array( new WP_Error( 'network', 'secret-path' ), delivery_response( 200, 'invalid response' ), delivery_response( 408 ), delivery_response( 429 ), delivery_response( 500 ) ) as $reply ) {
		delivery_reset();
		$id = delivery_seed();
		$replies[] = $reply;
		Scheduler::deliver();
		delivery_check( 'pending' === $store->get( $id )['delivery_status'] && DeliveryState::read()['retry_at'] > time(), 'Transient or ambiguous outcome was treated as terminal.' );
		delivery_due();
		$replies[] = delivery_response( 200 );
		Scheduler::deliver();
		$sent = array_values( array_filter( $calls, static fn( $call ) => isset( $call['beat'] ) ) );
		delivery_check( $sent[0]['beat']['externalId'] === $sent[1]['beat']['externalId'], 'Retry generated another externalId.' );
	}
	foreach ( array( '7200', gmdate( 'D, d M Y H:i:s \G\M\T', time() + 7200 ) ) as $header ) {
		delivery_reset();
		delivery_seed();
		$replies[] = delivery_response( 429, null, array( 'retry-after' => $header ) );
		Scheduler::deliver();
		delivery_check( abs( DeliveryState::read()['retry_at'] - time() - 7200 ) <= 2, 'Retry-After was not respected.' );
	}
	foreach ( array( '-1', 'next Tuesday', '999999999999999999999999', array( '60', '120' ) ) as $header ) {
		$data = \WP_Druid_Pulse\HttpResponse::error_data( delivery_response( 429, null, array( 'retry-after' => $header ) ) );
		delivery_check( ! isset( $data['retry_at'] ), 'Invalid Retry-After was accepted.' );
	}
	delivery_reset();
	$id = delivery_seed();
	foreach ( array( 60, 3600 ) as $expected ) {
		$replies[] = delivery_response( 401 );
		Scheduler::deliver();
		delivery_check( abs( DeliveryState::read()['retry_at'] - time() - $expected ) <= 2 && false === get_transient( ConnectionSettings::TOKEN_TRANSIENT ), '401 did not renew once then back off.' );
		delivery_due();
	}
	delivery_check( 2 === count( array_filter( $calls, static fn( $call ) => isset( $call['oauth'] ) ) ), 'Rejected token was reused.' );
	$replies[] = delivery_response( 200 );
	Scheduler::deliver();
	delivery_check( ! DeliveryState::read()['auth_retry'], 'Authorization recovery retained the refresh flag.' );
	foreach ( array( 400 => 3600, 403 => 3600, 429 => 60, 503 => 60, 200 => 60 ) as $status => $delay ) {
		delivery_reset();
		delivery_seed();
		delivery_seed();
		$oauth_replies[] = delivery_response( $status, array( 'error_description' => 'private server detail' ) );
		Scheduler::deliver();
		delivery_check( count( $calls ) === 1 && isset( $calls[0]['oauth'] ) && abs( DeliveryState::read()['retry_at'] - time() - $delay ) <= 2, 'OAuth failure did not pause all events without calling Pulse.' );
		delivery_check( ! str_contains( wp_json_encode( DeliveryState::read() ), 'private server' ), 'OAuth failure persisted a remote response.' );
		ConnectionSettings::save( $settings );
		$replies = array( delivery_response( 200 ), delivery_response( 200 ) );
		Scheduler::deliver();
		delivery_check( 0 === EventStore::usage()['events'], 'Saving connection did not resume paused pending events.' );
	}
	foreach ( array( 302, 403, 404, 405, 415 ) as $status ) {
		delivery_reset();
		delivery_seed();
		$replies[] = delivery_response( $status );
		Scheduler::deliver();
		delivery_check( abs( DeliveryState::read()['retry_at'] - time() - 3600 ) <= 2, 'Pulse configuration rejection was discarded or retried too often.' );
	}

	foreach ( array( 400 => 'failed', 413 => 'failed', 409 => 'conflict', 422 => 'pending' ) as $status => $expected ) {
		delivery_reset();
		$id = delivery_seed();
		$replies[] = delivery_response( $status );
		Scheduler::deliver();
		$row = $store->get( $id );
		delivery_check( $expected === $row['delivery_status'] && $expires <= $row['expires_at'], 'A rejection was acknowledged or misclassified.' );
		$good = delivery_seed();
		$replies[] = delivery_response( 200 );
		Scheduler::deliver();
		delivery_check( is_wp_error( $store->get( $good ) ) && 1 === $store->get( $id )['attempts'], 'A rejected event blocked others or retried too soon.' );
	}
	delivery_reset();
	$id = delivery_seed();
	wp_update_user( array( 'ID' => $user_id, 'user_email' => 'technical@dru-id.internal' ) );
	Scheduler::deliver();
	delivery_check( ! $calls && 'pending' === $store->get( $id )['delivery_status'] && abs( strtotime( $store->get( $id )['next_attempt_at'] . ' UTC' ) - time() - 3600 ) <= 2, 'Missing identity was sent or discarded.' );
	wp_update_user( array( 'ID' => $user_id, 'user_email' => 'corrected@example.test' ) );
	delivery_event_due( $id );
	$replies[] = delivery_response( 200 );
	Scheduler::deliver();
	delivery_check( 'corrected@example.test' === end( $calls )['beat']['actor']['schemaOrg']['email']['value'], 'Identity was frozen before recovery.' );

	delivery_reset();
	$id = delivery_seed();
	$lock = 'druid_pulse_delivery_' . substr( hash( 'sha256', DB_NAME . ':' . EventStore::table() ), 0, 40 );
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) );
	Scheduler::deliver();
	delivery_check( ! $calls && 0 === $store->get( $id )['attempts'], 'Concurrent worker ignored exclusive delivery ownership.' );
	$extra = delivery_seed();
	delivery_check( is_string( $extra ), 'Delivery ownership blocked capture.' );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	$other->close();
	$store->remove( $extra );
	$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) );
	delivery_check( true === $store->begin_attempt( $id, $lock ), 'Could not persist an interrupted attempt.' );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	Scheduler::deliver();
	delivery_check( ! $calls, 'Interrupted attempt retried before its recovery delay.' );
	delivery_event_due( $id );
	$replies[] = delivery_response( 200 );
	Scheduler::deliver();
	delivery_check( is_wp_error( $store->get( $id ) ), 'Interrupted attempt did not recover.' );

	delivery_reset();
	$id = delivery_seed();
	$replies[] = static function () use ( $wpdb, $lock ) {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		return delivery_response( 200 );
	};
	delivery_check( delivery_throws( array( Scheduler::class, 'deliver' ) ) && 'pending' === $store->get( $id )['delivery_status'], 'Lost worker ownership still acknowledged a response.' );

	delivery_reset();
	$id = delivery_seed();
	$fail_delete = static fn( $query ) => str_starts_with( $query, 'DELETE FROM' ) && str_contains( $query, EventStore::table() ) && str_contains( $query, $id ) ? 'EXPECTED DELIVERY DELETE FAILURE' : $query;
	add_filter( 'query', $fail_delete );
	$replies[] = delivery_response( 200 );
	delivery_check( delivery_throws( array( Scheduler::class, 'deliver' ) ), 'Delete failure was hidden.' );
	remove_filter( 'query', $fail_delete );
	delivery_check( 'accepted' === $store->get( $id )['delivery_status'], 'Acknowledgement was forgotten after delete failure.' );
	$count = count( $calls );
	Scheduler::deliver();
	delivery_check( count( $calls ) === $count && is_wp_error( $store->get( $id ) ), 'Accepted event was resent instead of only removed.' );

	delivery_reset();
	$id = delivery_seed();
	$new = array_replace( $settings, array( 'api_url' => 'https://new.example/api', 'tenant' => 'new' ) );
	$replies[] = static function () use ( $new ) {
		ConnectionSettings::save( $new );
		return delivery_response( 503 );
	};
	Scheduler::deliver();
	delivery_check( 0 === DeliveryState::read()['retry_at'], 'Old request overwrote a newly saved connection pause.' );
	$replies[] = delivery_response( 200 );
	Scheduler::deliver();
	delivery_check( 'https://new.example/api/identities' === end( $calls )['url'] && 'new' === end( $calls )['headers']['X-Tenant'], 'A pending event retained the previous destination.' );
	ConnectionSettings::save( $settings );

	delivery_reset();
	$id = delivery_seed();
	$receipt_file = tempnam( sys_get_temp_dir(), 'pulse-worker-' );
	$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/delivery-worker.php', 'crash', $receipt_file ), array(), $pipes );
	delivery_check( 42 === proc_close( $process ) && 1 === $store->get( $id )['attempts'], 'A real interrupted process did not leave a recoverable attempt.' );
	delivery_event_due( $id );
	$workers = array();
	for ( $i = 0; $i < 4; ++$i ) {
		$workers[] = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/delivery-worker.php', 'send', $receipt_file ), array(), $pipes );
	}
	foreach ( $workers as $worker ) {
		delivery_check( 0 === proc_close( $worker ), 'A concurrent worker failed.' );
	}
	delivery_check( 2 === count( file( $receipt_file ) ) && is_wp_error( $store->get( $id ) ), 'Process recovery or concurrent delivery duplicated a send.' );
	unlink( $receipt_file );

	delivery_reset();
	$register = static fn( $mapper ) => $mapper->register( 'future_event', static fn() => array( 'verb' => 'share', 'object' => array( 'objectType' => 'article' ) ) );
	add_action( 'wp_druid_pulse_register_event_types', $register );
	delivery_seed( 'future_event' );
	$replies[] = delivery_response( 201 );
	Scheduler::deliver();
	remove_action( 'wp_druid_pulse_register_event_types', $register );
	delivery_check( 'share' === end( $calls )['beat']['verb'] && 0 === EventStore::usage()['events'], 'Delivery was restricted to page views and clicks.' );
	delivery_reset();
	$register = static fn( $mapper ) => $mapper->register( 'broken_extension', static function () { throw new RuntimeException( 'private-event-detail' ); } );
	add_action( 'wp_druid_pulse_register_event_types', $register );
	$id = delivery_seed( 'broken_extension' );
	Scheduler::wake();
	$task = as_get_scheduled_actions( array( 'hook' => Scheduler::DELIVERY_HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' )[0];
	ActionScheduler::runner()->process_action( $task, 'delivery-test' );
	remove_action( 'wp_druid_pulse_register_event_types', $register );
	$logs = array_map( static fn( $entry ) => $entry->get_message(), ActionScheduler::logger()->get_logs( $task ) );
	delivery_check( 'failed' === ActionScheduler::store()->get_status( $task ) && ! str_contains( implode( ' ', $logs ), 'private-event-detail' ) && 1 === $store->get( $id )['attempts'], 'Extension failure leaked details or lost recovery state.' );

	delivery_reset();
	$id = delivery_seed();
	$fail_begin = static fn( $query ) => str_contains( $query, 'SET attempts=attempts+1' ) ? 'EXPECTED DELIVERY UPDATE FAILURE' : $query;
	add_filter( 'query', $fail_begin );
	delivery_check( delivery_throws( array( Scheduler::class, 'deliver' ) ) && ! $calls, 'HTTP ran before an attempt could be persisted.' );
	remove_filter( 'query', $fail_begin );
	delivery_check( 0 === $store->get( $id )['attempts'], 'Failed attempt persistence changed the record.' );

	delivery_reset();
	$id = delivery_seed();
	$wpdb->update( EventStore::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'event_id' => $id ) );
	Scheduler::deliver();
	delivery_check( ! $calls, 'Expired event was sent.' );
	Scheduler::cleanup();
	delivery_check( 0 === EventStore::usage()['events'], 'Expired event was not purged.' );

	delivery_reset();
	delivery_seed();
	delivery_seed();
	$replies[] = static function () {
		usleep( 5100000 );
		return delivery_response( 200 );
	};
	Scheduler::deliver();
	delivery_check( 1 === EventStore::usage()['events'] && ! $replies, 'A slow response did not stop the batch before another HTTP budget.' );
	$replies[] = delivery_response( 200 );
	Scheduler::deliver();
	delivery_check( 0 === EventStore::usage()['events'], 'The next pass did not resume after the time budget.' );
	delivery_reset();
	for ( $i = 0; $i < 26; ++$i ) {
		delivery_seed();
		$replies[] = delivery_response( 200 );
	}
	Scheduler::wake();
	$task = as_get_scheduled_actions( array( 'hook' => Scheduler::DELIVERY_HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' )[0];
	ActionScheduler::runner()->process_action( $task, 'delivery-test' );
	delivery_check( 1 === EventStore::usage()['events'], 'Delivery did not respect its batch size.' );
	$tasks = as_get_scheduled_actions( array( 'hook' => Scheduler::DELIVERY_HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' );
	delivery_check( 1 === count( $tasks ) && $task !== $tasks[0], 'Remaining events did not schedule a continuation.' );
	ActionScheduler::runner()->process_action( $tasks[0], 'delivery-test' );
	delivery_check( 0 === EventStore::usage()['events'] && ! $replies, 'Continuation did not drain the queue.' );

	echo "Passed {$checks} delivery assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	remove_filter( 'pre_http_request', $http, 1000 );
	ConnectionSettings::save( $saved_settings );
	DeliveryState::reset();
	delete_transient( ConnectionSettings::TOKEN_TRANSIENT );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	if ( is_int( $user_id ) && $user_id > 0 ) {
		wp_delete_user( $user_id );
	}
}
exit( $exit_code );
