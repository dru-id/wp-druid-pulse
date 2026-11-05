<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit( "An isolated test installation is required.\n" );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once WP_PLUGIN_DIR . '/wp-druid-pulse/src/EventPreparer.php';
require_once WP_PLUGIN_DIR . '/wp-druid-pulse/src/PulseClient.php';

use WP_Druid_Pulse\ConnectionSettings;
use WP_Druid_Pulse\EventMapper;
use WP_Druid_Pulse\EventPreparer;
use WP_Druid_Pulse\EventStore;
use WP_Druid_Pulse\Lifecycle;
use WP_Druid_Pulse\PulseClient;
use WP_Druid_Pulse\Scheduler;
use WP_Druid_Pulse\SiteContext;

$checks = 0;
function queue_check( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function queue_error( mixed $value, string $code ): bool {
	return is_wp_error( $value ) && $code === $value->get_error_code();
}
function queue_http( int $status, array $body ): array {
	return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array() );
}

global $wpdb;
$store = new EventStore();
$mapper = new EventMapper();
$preparer = new EventPreparer( $store, $mapper );
$saved = array_map( 'get_option', array( 'blogname', 'home', SiteContext::OPTION, ConnectionSettings::OPTION ) );
$user_id = 0;
$calls = array();
$replies = array();
$exit_code = 0;
$http = static function ( $pre, $args, $url ) use ( &$calls, &$replies ) {
	$calls[] = array( 'url' => $url, 'args' => $args );
	if ( ! $replies ) {
		throw new RuntimeException( 'Unexpected HTTP request.' );
	}
	return array_shift( $replies );
};
add_filter( 'pre_http_request', $http, 999, 3 );

try {
	queue_check( Lifecycle::is_current() && null === EventStore::verify(), 'Queue schema is not ready.' );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
	queue_check( array( 'events' => 0, 'bytes' => 0 ) === EventStore::usage(), 'Empty queue reports usage.' );
	$user_id = wp_insert_user( array( 'user_login' => 'pulse-queue-' . wp_generate_uuid4(), 'user_email' => 'before@example.test', 'user_pass' => wp_generate_password(), 'first_name' => 'Before', 'last_name' => 'Example', 'role' => 'subscriber' ) );
	queue_check( is_int( $user_id ), 'Could not create the synthetic user.' );
	$data = array( 'page' => array( 'key' => 'post:42', 'url' => 'https://shop.example/original/', 'title' => 'Original page' ), 'link' => array( 'key' => 'menu:3', 'title' => 'Original link' ), 'target' => array( 'objectType' => 'page', 'url' => 'https://outside.example/then/', 'title' => 'Original destination' ) );
	$published = new DateTimeImmutable( '2026-10-02T12:30:00+02:00' );
	$id = $store->append( 'click', $user_id, $data, $published );
	queue_check( is_string( $id ) && wp_is_uuid( $id, 4 ), 'Appending did not return a stable local UUID.' );
	$row = $store->get( $id );
	queue_check( $row['context'] === $data && $row['user_id'] === $user_id && 1 === $row['format_version'], 'Compact context did not round-trip.' );
	queue_check( '2026-10-02 10:30:00' === $row['published'], 'Original event date or timezone changed.' );
	queue_check( strtotime( $row['expires_at'] . ' UTC' ) - strtotime( $row['received_at'] . ' UTC' ) === EventStore::RETENTION_SECONDS, 'Expiry is not based on local acceptance.' );
	$json = $wpdb->get_var( $wpdb->prepare( 'SELECT context FROM %i WHERE event_id=%s', EventStore::table(), $id ) );
	queue_check( ! str_contains( $json, 'before@example.test' ) && ! isset( $row['actor'], $row['provider'], $row['installation_id'], $row['api_url'] ), 'Queue copied profile or connection data.' );
	queue_check( EventStore::usage() === array( 'events' => 1, 'bytes' => strlen( $json ) ), 'Stored JSON size is incorrect.' );
	wp_set_current_user( 0 );
	wp_update_user( array( 'ID' => $user_id, 'user_email' => 'after@example.test', 'first_name' => 'After' ) );
	update_option( 'blogname', 'Current shop' );
	update_option( 'home', 'https://moved.example' );
	$new_installation = wp_generate_uuid4();
	update_option( SiteContext::OPTION, $new_installation, false );
	$beat = $preparer->prepare( $id );
	queue_check( ! is_wp_error( $beat ) && 'after@example.test' === $beat['actor']['schemaOrg']['email']['value'] && 'After' === $beat['actor']['schemaOrg']['givenName'], 'Preparation did not read the current profile without a session.' );
	queue_check( 'wp-druid-pulse:' . $new_installation === $beat['origin']['application']['id'] && 'Current shop' === $beat['origin']['application']['displayName'], 'An installation change blocked the pending event or retained old application data.' );
	queue_check( 'https://shop.example/original/' === $beat['object']['url'] && 'Original page' === $beat['origin']['displayName'] && 'https://outside.example/then/' === $beat['target']['url'], 'Preparation changed the historical page or click destination.' );
	queue_check( '2026-10-02T10:30:00Z' === $beat['published'], 'Preparation replaced event time with send time.' );
	queue_check( $store->get( $id ) === $row && ! $calls, 'Preparation mutated the queue or performed HTTP.' );
	$settings = array( 'api_url' => 'https://old.example/api', 'oauth_url' => 'https://old-auth.example', 'client_id' => 'old-client', 'client_secret' => 'synthetic-old-secret', 'auth_method' => 'client_secret_basic', 'scope' => '', 'tenant' => 'old' );
	queue_check( null === ConnectionSettings::save( $settings ), 'Could not set the old connection.' );
	$new = array_replace( $settings, array( 'api_url' => 'https://current.example/api', 'oauth_url' => 'https://current-auth.example', 'client_id' => 'current-client', 'client_secret' => 'synthetic-new-secret', 'tenant' => 'current' ) );
	queue_check( null === ConnectionSettings::save( $new ), 'Pending events prevented a connection change.' );
	$replies[] = queue_http( 200, array( 'access_token' => 'queue.test.token', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	$replies[] = queue_http( 200, array( 'status' => 200 ) );
	queue_check( array( 'status' => 200 ) === ( new PulseClient() )->send( $preparer->prepare( $id ) ), 'The pending event could not use the new connection.' );
	queue_check( 2 === count( $calls ) && 'https://current-auth.example/oauth2/token' === $calls[0]['url'] && 'https://current.example/api/identities' === $calls[1]['url'] && 'current' === $calls[1]['args']['headers']['X-Tenant'], 'Delivery used a frozen destination or wrong endpoint.' );
	queue_check( ! is_wp_error( $store->get( $id ) ), 'Calling the HTTP client silently removed a queued event.' );
	$mapper->register( 'future_type', static fn( $data, $app ) => array( 'verb' => 'share', 'object' => array( 'objectType' => 'article', 'url' => $data['page']['url'] ) ) );
	$extra = $store->append( 'future_type', $user_id, $data, $published );
	queue_check( 'share' === $preparer->prepare( $extra )['verb'], 'Queue restricts the extensible event registry.' );
	$unknown = $store->append( 'unknown_type', $user_id, $data, $published );
	queue_check( queue_error( $preparer->prepare( $unknown ), 'wp_druid_pulse_unknown_event' ), 'Unknown type was silently reinterpreted.' );
	$wpdb->update( EventStore::table(), array( 'format_version' => 99 ), array( 'event_id' => $unknown ) );
	queue_check( queue_error( $preparer->prepare( $unknown ), 'wp_druid_pulse_event_version' ), 'Unknown format was silently reinterpreted.' );
	queue_check( queue_error( $store->append( 'click', 0, $data, $published ), 'wp_druid_pulse_event_input' ), 'Anonymous attribution was stored.' );
	queue_check( queue_error( $store->append( 'bad type', $user_id, $data, $published ), 'wp_druid_pulse_event_input' ), 'Invalid type was stored.' );
	queue_check( queue_error( $store->append( 'click', $user_id, array( 'invalid' => NAN ), $published ), 'wp_druid_pulse_event_size' ), 'Invalid JSON was accepted.' );
	queue_check( queue_error( $store->append( 'click', $user_id, array( 'large' => str_repeat( 'x', EventStore::MAX_EVENT_BYTES ) ), $published ), 'wp_druid_pulse_event_size' ), 'Oversized event was accepted.' );
	$unicode = array( 'text' => 'Descripción / 東京', 'zero' => 0.0, 'empty' => array(), 'missing' => null, 'flag' => false );
	$unicode_id = $store->append( 'future_type', $user_id, $unicode, $published );
	queue_check( $store->get( $unicode_id )['context'] === $unicode, 'Context serialization changed values.' );
	queue_check( queue_error( $store->get( wp_generate_uuid4() ), 'wp_druid_pulse_event_missing' ), 'Missing event was reported as available.' );
	$before = EventStore::usage();
	$fail_insert = static fn( $query ) => str_starts_with( $query, 'INSERT INTO' ) && str_contains( $query, EventStore::table() ) ? 'EXPECTED QUEUE INSERT FAILURE' : $query;
	add_filter( 'query', $fail_insert );
	$failed = $store->append( 'click', $user_id, $data, $published );
	remove_filter( 'query', $fail_insert );
	queue_check( queue_error( $failed, 'wp_druid_pulse_event_store' ) && EventStore::usage() === $before, 'Failed insert was accepted or changed queue usage.' );
	$lock = 'druid_pulse_' . substr( hash( 'sha256', DB_NAME . ':' . EventStore::table() ), 0, 48 );
	$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );
	queue_check( queue_error( $store->append( 'click', $user_id, $data, $published ), 'wp_druid_pulse_queue_busy' ), 'Concurrent ownership was ignored.' );
	$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	$other->close();
	$lose_lock = static function ( $query ) use ( $lock, $wpdb ) {
		if ( str_starts_with( $query, 'INSERT INTO' ) && str_contains( $query, EventStore::table() ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return $query;
	};
	add_filter( 'query', $lose_lock );
	$failed = $store->append( 'click', $user_id, $data, $published );
	remove_filter( 'query', $lose_lock );
	queue_check( queue_error( $failed, 'wp_druid_pulse_event_store' ) && EventStore::usage() === $before, 'Insert proceeded after losing the capacity lock.' );
	$wpdb->update( EventStore::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'event_id' => $unknown ) );
	queue_check( queue_error( $store->get( $unknown ), 'wp_druid_pulse_event_expired' ) && ! in_array( $unknown, $store->pending_ids(), true ), 'Expired event remained eligible before physical cleanup.' );
	$fail_delete = static fn( $query ) => str_starts_with( $query, 'DELETE FROM' ) && str_contains( $query, EventStore::table() ) ? 'EXPECTED QUEUE DELETE FAILURE' : $query;
	add_filter( 'query', $fail_delete );
	queue_check( queue_error( EventStore::purge_expired(), 'wp_druid_pulse_event_store' ), 'Failed purge was reported as successful.' );
	remove_filter( 'query', $fail_delete );
	Scheduler::ensure_scheduled();
	$task = as_next_scheduled_action( Scheduler::CLEANUP_HOOK, array(), Scheduler::GROUP );
	$tasks = as_get_scheduled_actions( array( 'hook' => Scheduler::CLEANUP_HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' );
	queue_check( 1 === count( $tasks ) && false !== $task, 'Repeated initialization created duplicate maintenance tasks.' );
	ActionScheduler::runner()->process_action( $tasks[0], 'isolated queue test' );
	queue_check( 'complete' === ActionScheduler::store()->get_status( $tasks[0] ) && queue_error( $store->get( $unknown ), 'wp_druid_pulse_event_missing' ) && ! is_wp_error( $store->get( $id ) ), 'Scheduled maintenance did not remove only expired events.' );
	queue_check( null === $store->remove( $unicode_id ) && null === $store->remove( $unicode_id ), 'Removal is not idempotent.' );
	Scheduler::stop();
	update_option( 'wp_druid_pulse_schema_version', 3 );
	add_filter( 'pre_as_schedule_recurring_action', '__return_zero' );
	$failed = Lifecycle::initialize();
	remove_filter( 'pre_as_schedule_recurring_action', '__return_zero' );
	queue_check( queue_error( $failed, 'wp_druid_pulse_scheduler' ) && 3 === get_option( 'wp_druid_pulse_schema_version' ), 'Scheduling failure advanced the schema.' );
	queue_check( queue_error( $store->append( 'click', $user_id, $data, $published ), 'wp_druid_pulse_event_store' ), 'Incomplete initialization accepted an event.' );
	queue_check( null === Lifecycle::initialize() && Lifecycle::is_current(), 'Interrupted migration did not recover.' );
	Scheduler::register_hooks();
	queue_check( $store->get( $id )['context'] === $data, 'Migration recovery changed queued data.' );
	wp_delete_user( $user_id );
	queue_check( queue_error( $preparer->prepare( $id ), 'wp_druid_pulse_no_user' ), 'Deleted user was invented or replaced during preparation.' );
	$user_id = wp_insert_user( array( 'user_login' => 'pulse-identity-' . wp_generate_uuid4(), 'user_email' => 'identity@example.test', 'user_pass' => wp_generate_password() ) );
	$identity_event = $store->append( 'click', $user_id, $data, $published );
	define( 'WP_DRUID', 'druid' );
	queue_check( queue_error( $preparer->prepare( $identity_event ), 'wp_druid_pulse_identity_api' ), 'Active missing API fell back to a profile.' );
	require __DIR__ . '/fixtures/identity.php';
	\WP_Druid\Services\Users::$ids[ $user_id ] = 'current-synthetic-object-id';
	queue_check( $preparer->prepare( $identity_event )['actor'] === array( 'objectType' => 'person', 'externalIds' => array( 'druid:objectid' => 'current-synthetic-object-id' ) ), 'Identity mode at preparation did not use only the current objectId.' );
	\WP_Druid\Services\Users::$ids = array();
	queue_check( queue_error( $preparer->prepare( $identity_event ), 'wp_druid_pulse_no_object_id' ), 'Missing current objectId fell back to profile data.' );
	queue_check( 2 === count( $calls ) && ! $replies, 'Queue operations performed extra HTTP.' );
	echo "Passed {$checks} queue assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	foreach ( array( 'blogname', 'home', SiteContext::OPTION, ConnectionSettings::OPTION ) as $i => $option ) {
		update_option( $option, $saved[ $i ] );
	}
	delete_transient( ConnectionSettings::TOKEN_TRANSIENT );
	remove_filter( 'pre_http_request', $http, 999 );
	if ( is_int( $user_id ) && $user_id > 0 ) {
		wp_delete_user( $user_id );
	}
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', EventStore::table() ) );
}
exit( $exit_code );
