<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit( "An isolated test installation is required.\n" );
}

use WP_Druid_Pulse\{ConnectionSettings, DeliveryState, EventStore, Scheduler};

$checks = 0;
function diagnostic_check( bool $ok, string $message ): void {
	global $checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function request( string $url, string $jar, ?array $data = null, bool $follow = false ): array {
	$curl = curl_init( $url );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => 15 ) );
	if ( null !== $data ) {
		curl_setopt_array( $curl, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query( $data ) ) );
	}
	$body = curl_exec( $curl );
	$status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	curl_close( $curl );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'Local HTTP request failed.' );
	}
	return array( 'status' => $status, 'body' => $body );
}
function event_row( string $id ): array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event_id=%s', EventStore::table(), $id ), ARRAY_A );
}
function set_event( string $id, array $values ): void {
	global $wpdb;
	diagnostic_check( false !== $wpdb->update( EventStore::table(), $values, array( 'event_id' => $id ) ), 'Fixture update failed.' );
}
function parsed( string $html ): DOMXPath {
	$dom = new DOMDocument();
	@$dom->loadHTML( $html );
	return new DOMXPath( $dom );
}

$credentials = json_decode( file_get_contents( getenv( 'WP_TEST_CREDENTIALS' ) ?: '/test/private.json' ), true );
$base = home_url();
$page = $base . '/wp-admin/admin.php?page=wp-druid-pulse-diagnostics';
$post = $base . '/wp-admin/admin-post.php';
$jars = array( tempnam( '/tmp', 'pulse-diagnostic-admin-' ), tempnam( '/tmp', 'pulse-diagnostic-reader-' ) );
$saved_delivery = get_option( DeliveryState::OPTION );
$saved_connection = get_option( ConnectionSettings::OPTION );
$saved_timezone = get_option( 'timezone_string' );
$exit_code = 0;
global $wpdb;
$table = EventStore::table();
$store = new EventStore();
try {
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
	DeliveryState::reset();
	as_unschedule_all_actions( Scheduler::DELIVERY_HOOK, array(), Scheduler::GROUP );
	diagnostic_check( 'empty' === Scheduler::request_delivery(), 'Empty queue scheduled delivery.' );
	update_option( 'timezone_string', 'Europe/Madrid' );
	$settings = array( 'api_url' => 'https://pulse.example/api', 'oauth_url' => 'https://auth.example', 'client_id' => 'diagnostic-private-client', 'client_secret' => 'diagnostic-private-secret', 'auth_method' => 'client_secret_basic', 'scope' => '', 'tenant' => '' );
	diagnostic_check( null === ConnectionSettings::save( $settings ), 'Connection fixture failed.' );
	$ids = array();
	foreach ( array( 'ready', 'waiting', 'failed', 'conflict', 'expired', 'accepted', 'unknown' ) as $index => $state ) {
		$id = $store->append( 'unknown' === $state ? 'extension:custom' : 'click', 1, array( 'private' => 'diagnostic-pii@example.test', 'url' => 'https://private.example/secret-page', 'title' => 'Diagnostic private title' ), new DateTimeImmutable( '2026-01-02T03:04:05Z' ) );
		diagnostic_check( is_string( $id ), 'Fixture append failed.' );
		$ids[ $state ] = $id;
		set_event( $id, array( 'received_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 + $index ), 'delivery_status' => match ( $state ) { 'ready', 'waiting' => 'pending', 'expired' => 'failed', 'unknown' => 'unexpected', default => $state }, 'attempts' => 2, 'last_error' => 'failed' === $state ? 'wp_druid_pulse_http_400' : ( 'conflict' === $state ? 'wp_druid_pulse_http_409' : 'diagnostic-raw-secret' ) ) );
	}
	set_event( $ids['waiting'], array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
	set_event( $ids['expired'], array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
	$before = array_map( 'event_row', $ids );
	$queue = EventStore::diagnostics( 0 );
	diagnostic_check( ! is_wp_error( $queue ) && 7 === $queue['events'] && 7 === $queue['total'], 'Diagnostic totals are incorrect.' );
	diagnostic_check( array_fill_keys( EventStore::DIAGNOSTIC_STATES, 1 ) === $queue['counts'], 'States overlap or are missing.' );
	diagnostic_check( $queue['bytes'] === EventStore::usage()['bytes'] && $queue['oldest'] === $before['ready']['received_at'], 'Usage or oldest pending differs from the store.' );
	diagnostic_check( 1 === EventStore::diagnostics( 0, 'unknown', 'extension:custom' )['total'], 'Extension types cannot be filtered.' );
	diagnostic_check( array( 'click', 'extension:custom' ) === $queue['types'], 'Type filter is hardcoded.' );
	foreach ( $queue['rows'] as $row ) {
		diagnostic_check( array_keys( $row ) === array( 'event_id', 'event_type', 'published', 'received_at', 'expires_at', 'delivery_status', 'attempts', 'next_attempt_at', 'last_error', 'state' ), 'Diagnostic query exposes event data.' );
	}
	diagnostic_check( 2 === EventStore::diagnostics( time() + 60 )['counts']['waiting'] && 0 === EventStore::diagnostics( time() + 60 )['counts']['ready'], 'Shared pause ignored in counts.' );
	diagnostic_check( is_wp_error( EventStore::diagnostics( 0, 'invalid' ) ) && is_wp_error( EventStore::diagnostics( 0, '', "' OR 1=1" ) ), 'Invalid filters accepted.' );
	for ( $i = 0; $i < 25; ++$i ) {
		$store->append( 'fixture', 1, array(), new DateTimeImmutable() );
	}
	$first = EventStore::diagnostics( 0 );
	$last = EventStore::diagnostics( 0, '', '', 999 );
	diagnostic_check( 25 === count( $first['rows'] ) && 7 === count( $last['rows'] ) && 2 === $last['page'], 'Pagination does not clamp or bound results.' );
	diagnostic_check( ! array_intersect( array_column( $first['rows'], 'event_id' ), array_column( $last['rows'], 'event_id' ) ), 'Pagination duplicates records.' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE event_type='fixture'", $table ) );
	foreach ( array( 'admin', 'subscriber' ) as $i => $role ) {
		request( $base . '/wp-login.php', $jars[ $i ] );
		$login = request( $base . '/wp-login.php', $jars[ $i ], array( 'log' => $credentials[ $role . '_user' ], 'pwd' => $credentials[ $role . '_pass' ], 'wp-submit' => 'Log In', 'testcookie' => '1', 'redirect_to' => $page ) );
		diagnostic_check( 302 === $login['status'], 'Fixture login failed.' );
	}
	update_option( 'pulse_phase4_oauth_calls', 0 );
	$admin = request( $page, $jars[0] );
	diagnostic_check( 200 === $admin['status'], 'Diagnostics page unavailable.' );
	foreach ( array( 'diagnostic-pii@example.test', 'https://private.example/secret-page', 'Diagnostic private title', 'diagnostic-private-secret', 'diagnostic-private-client', 'diagnostic-raw-secret' ) as $sensitive ) {
		diagnostic_check( ! str_contains( $admin['body'], $sensitive ), 'Diagnostics exposed sensitive values.' );
	}
	foreach ( array( '2026-01-02 04:04:05', 'Europe/Madrid', 'external cron', 'Remote access and delivery are not verified here', 'action-scheduler', 'Acceptance is unconfirmed' ) as $expected ) {
		diagnostic_check( str_contains( $admin['body'], $expected ), 'Diagnostic explanation or timezone missing: ' . $expected );
	}
	$dom = parsed( $admin['body'] );
	$nonce = $dom->query( '//form[input[@name="action" and @value="wp_druid_pulse_diagnostics_action"]]//input[@name="_wpnonce"]/@value' )->item( 0 )->nodeValue;
	$form = array( 'action' => 'wp_druid_pulse_diagnostics_action', '_wpnonce' => $nonce, 'operation' => 'process' );
	diagnostic_check( 1 === $dom->query( '//input[@name="event_ids[]"]' )->length && $ids['failed'] === $dom->query( '//input[@name="event_ids[]"]/@value' )->item( 0 )->nodeValue, 'Nonfailed events are selectable.' );
	for ( $i = 0; $i < 26; ++$i ) {
		$store->append( 'fixture', 1, array(), new DateTimeImmutable() );
	}
	$listing = request( $page . '&state=ready&type=fixture', $jars[0] );
	$next_page = parsed( $listing['body'] )->query( '//nav[contains(@class,"tablenav")]//a[contains(@class,"next")]/@href' )->item( 0 );
	diagnostic_check( null !== $next_page && str_contains( $next_page->nodeValue, 'paged=2' ), 'Pagination URL is not usable.' );
	$listing = request( $next_page->nodeValue, $jars[0] );
	diagnostic_check( 200 === $listing['status'] && str_contains( $listing['body'], '26 records match' ) && str_contains( $listing['body'], 'page 2 of 2' ), 'Pagination did not preserve the selected filters.' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE event_type='fixture'", $table ) );
	diagnostic_check( 403 === request( $page, $jars[1] )['status'], 'Subscriber can view diagnostics.' );
	diagnostic_check( 302 === request( $page, '' )['status'], 'Anonymous diagnostics did not require login.' );
	foreach ( array( 'process', 'cleanup', 'retry' ) as $operation ) {
		$payload = array_replace( $form, array( 'operation' => $operation ) );
		diagnostic_check( 403 === request( $post, $jars[1], $payload )['status'], 'Subscriber can mutate diagnostics.' );
		foreach ( array( '', 'invalid', array( 'invalid' ) ) as $bad ) {
			diagnostic_check( 403 === request( $post, $jars[0], array_replace( $payload, array( '_wpnonce' => $bad ) ) )['status'], 'Invalid nonce accepted.' );
		}
	}
	diagnostic_check( 405 === request( $post . '?action=' . $form['action'], $jars[0] )['status'], 'GET could mutate diagnostics.' );
	diagnostic_check( 400 === request( $post, $jars[0], array_replace( $form, array( 'operation' => 'wipe' ) ) )['status'], 'Unknown operation accepted.' );
	diagnostic_check( 400 === request( $page . '&state=invalid', $jars[0] )['status'], 'Invalid HTTP filter accepted.' );
	diagnostic_check( 302 === request( $post, $jars[0], $form )['status'] && as_has_scheduled_action( Scheduler::DELIVERY_HOOK, array(), Scheduler::GROUP ), 'Process did not schedule eligible work.' );
	diagnostic_check( $before === array_map( 'event_row', $ids ), 'Viewing or scheduling mutated retained event data.' );
	diagnostic_check( 'already_scheduled' === Scheduler::request_delivery(), 'Repeated scheduling is not idempotent.' );
	as_unschedule_all_actions( Scheduler::DELIVERY_HOOK, array(), Scheduler::GROUP );
	$pause = DeliveryState::read();
	$pause['retry_at'] = time() + 3600;
	update_option( DeliveryState::OPTION, $pause, false );
	diagnostic_check( 'paused' === Scheduler::request_delivery(), 'Common pause was bypassed.' );
	$retry = array_replace( $form, array( 'operation' => 'retry', 'event_ids' => array( $ids['failed'], $ids['conflict'], $ids['expired'] ), 'attempts' => array_fill_keys( array( $ids['failed'], $ids['conflict'], $ids['expired'] ), '2' ) ) );
	$response = request( $post, $jars[0], $retry, true );
	diagnostic_check( 200 === $response['status'] && str_contains( $response['body'], '1 events returned to pending' ), 'Retry count or response incorrect.' );
	$after = event_row( $ids['failed'] );
	$expected = array_replace( $before['failed'], array( 'delivery_status' => 'pending', 'next_attempt_at' => '1970-01-01 00:00:00' ) );
	diagnostic_check( $after === $expected, 'Retry changed the ID, payload, timestamps, expiry or attempts.' );
	diagnostic_check( $before['conflict'] === event_row( $ids['conflict'] ) && $before['expired'] === event_row( $ids['expired'] ), 'Conflict or expiry protection failed.' );
	diagnostic_check( ! as_has_scheduled_action( Scheduler::DELIVERY_HOOK, array(), Scheduler::GROUP ), 'Manual retry bypassed common pause.' );
	diagnostic_check( str_contains( request( $post, $jars[0], $retry, true )['body'], 'No events were requeued' ), 'Retry replay changed pending event.' );
	set_event( $ids['failed'], array( 'delivery_status' => 'failed', 'attempts' => 3 ) );
	diagnostic_check( str_contains( request( $post, $jars[0], $retry, true )['body'], 'No events were requeued' ) && 'failed' === event_row( $ids['failed'] )['delivery_status'], 'Stale form requeued a later failed attempt.' );
	foreach ( array( array(), array( 'event_ids' => 'invalid' ), array( 'event_ids' => array( 'invalid' ) ), array( 'attempts' => array( $ids['failed'] => '-1' ) ), array( 'event_ids' => array_fill( 0, 26, $ids['failed'] ) ) ) as $invalid ) {
		$payload = $invalid ? array_replace( $retry, $invalid ) : array_replace( $form, array( 'operation' => 'retry' ) );
		diagnostic_check( 400 === request( $post, $jars[0], $payload )['status'], 'Malformed selection accepted.' );
	}
	$retry['attempts'][ $ids['failed'] ] = '3';
	$handles = array();
	$multi = curl_multi_init();
	foreach ( array( 0, 1 ) as $i ) {
		$handle = curl_init( $post );
		curl_setopt_array( $handle, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jars[0], CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query( $retry ), CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true ) );
		curl_multi_add_handle( $multi, $handle );
		$handles[] = $handle;
	}
	do {
		curl_multi_exec( $multi, $active );
		if ( $active ) { curl_multi_select( $multi, 1 ); }
	} while ( $active );
	$changed = 0;
	foreach ( $handles as $handle ) {
		$response = curl_multi_getcontent( $handle );
		$changed += (int) str_contains( $response, 'retried=1' );
		diagnostic_check( in_array( curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ), array( 302, 500 ), true ), 'Unexpected concurrent response.' );
		curl_multi_remove_handle( $multi, $handle );
		curl_close( $handle );
	}
	curl_multi_close( $multi );
	diagnostic_check( 1 === $changed && 'pending' === event_row( $ids['failed'] )['delivery_status'] && '3' === event_row( $ids['failed'] )['attempts'], 'Concurrent retry changed the event twice.' );
	DeliveryState::reset();
	set_event( $ids['failed'], array( 'delivery_status' => 'failed', 'attempts' => 4 ) );
	$retry['attempts'][ $ids['failed'] ] = '4';
	update_option( 'pulse_test_diagnostic_schedule_failure', true, false );
	$response = request( $post, $jars[0], $retry, true );
	diagnostic_check( 200 === $response['status'] && str_contains( $response['body'], 'Events were requeued, but delivery could not be scheduled' ) && 'pending' === event_row( $ids['failed'] )['delivery_status'], 'Partial scheduling failure hides the successful requeue.' );
	diagnostic_check( 500 === request( $post, $jars[0], $form )['status'], 'Scheduling failure was reported as success.' );
	delete_option( 'pulse_test_diagnostic_schedule_failure' );
	$live = array_map( 'event_row', array_intersect_key( $ids, array_flip( array( 'ready', 'waiting', 'failed', 'conflict', 'unknown' ) ) ) );
	diagnostic_check( 302 === request( $post, $jars[0], array_replace( $form, array( 'operation' => 'cleanup' ) ) )['status'], 'Cleanup form failed.' );
	diagnostic_check( 5 === EventStore::usage()['events'] && $live === array_map( 'event_row', array_intersect_key( $ids, $live ) ), 'Cleanup deleted live records or left eligible expired/accepted records.' );
	update_option( 'wp_druid_pulse_schema_version', 99 );
	$readonly = request( $page, $jars[0] );
	diagnostic_check( 200 === $readonly['status'] && str_contains( $readonly['body'], 'Read-only diagnostics' ) && ! str_contains( $readonly['body'], 'name="operation"' ), 'Incompatible initialization hides diagnostics or exposes mutation controls.' );
	diagnostic_check( 200 === request( $base . '/wp-admin/admin.php?page=wp-druid-pulse', $jars[0] )['status'], 'Failed initialization has no accessible root menu.' );
	diagnostic_check( 503 === request( $post, $jars[0], $form )['status'], 'Incompatible installation accepts manual action.' );
	update_option( 'wp_druid_pulse_schema_version', 6 );
	update_option( DeliveryState::OPTION, array( 'invalid' => true ), false );
	diagnostic_check( 200 === request( $page, $jars[0] )['status'] && 503 === request( $post, $jars[0], $form )['status'], 'Invalid delivery state is not read-only.' );
	update_option( DeliveryState::OPTION, $pause, false );
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $table . '_diagnostic_backup' ) );
	try {
		diagnostic_check( 200 === request( $page, $jars[0] )['status'] && 503 === request( $post, $jars[0], $form )['status'], 'Missing event table breaks read-only diagnostics.' );
	} finally {
		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $table . '_diagnostic_backup', $table ) );
	}
	wp_cache_delete( 'pulse_phase4_oauth_calls', 'options' );
	diagnostic_check( 0 === (int) get_option( 'pulse_phase4_oauth_calls' ), 'Diagnostics performed remote OAuth.' );
	$filters = static function ( $sql ) { return str_starts_with( $sql, 'UPDATE `' . EventStore::table() . '` SET delivery_status=' ) ? 'DIAGNOSTIC_INVALID_SQL' : $sql; };
	add_filter( 'query', $filters );
	diagnostic_check( is_wp_error( EventStore::retry_failed( array( $ids['failed'] => 3 ) ) ), 'SQL failure was reported as a successful retry.' );
	remove_filter( 'query', $filters );
	$lost_lock = static function ( $sql ) use ( $wpdb ) {
		if ( str_starts_with( $sql, 'UPDATE `' . EventStore::table() . '` SET delivery_status=' ) ) {
			$wpdb->get_var( 'SELECT RELEASE_ALL_LOCKS()' );
		}
		return $sql;
	};
	add_filter( 'query', $lost_lock );
	diagnostic_check( is_wp_error( EventStore::retry_failed( array( $ids['failed'] => 4 ) ) ), 'Lost queue lock was reported as a successful retry.' );
	remove_filter( 'query', $lost_lock );

	echo "Passed $checks diagnostic and administration checks.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	delete_option( 'pulse_test_diagnostic_schedule_failure' );
	update_option( 'wp_druid_pulse_schema_version', 6 );
	update_option( DeliveryState::OPTION, $saved_delivery, false );
	update_option( ConnectionSettings::OPTION, $saved_connection, false );
	update_option( 'timezone_string', $saved_timezone );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
	foreach ( $jars as $jar ) { unlink( $jar ); }
}
exit( $exit_code );
