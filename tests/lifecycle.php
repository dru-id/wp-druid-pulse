<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	fwrite( STDERR, "An isolated test installation is required.\n" );
	exit( 1 );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use WP_Druid_Pulse\ConnectionSettings;
use WP_Druid_Pulse\EventStore;
use WP_Druid_Pulse\Lifecycle;
use WP_Druid_Pulse\Scheduler;
use WP_Druid_Pulse\SiteContext;
use WP_Druid_Pulse\ViewSelection;

final class TestWpDie extends RuntimeException {}

$checks = 0;
function verify( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function lifecycle_dies( callable $operation ): bool {
	try {
		$operation();
	} catch ( TestWpDie $error ) {
		return true;
	}
	return false;
}
$die_handler = static fn() => static function () { throw new TestWpDie(); };
add_filter( 'wp_die_handler', $die_handler );
$plugin = 'wp-druid-pulse/wp-druid-pulse.php';
$options = array( ViewSelection::OPTION, ConnectionSettings::OPTION, SiteContext::OPTION, 'wp_druid_pulse_schema_version' );
$user_id = 0;
$foreign_actions = array();
$exit_code = 0;

try {
	global $wpdb;
	$store = new EventStore();
	$user_id = wp_insert_user( array( 'user_login' => 'pulse-lifecycle-' . wp_generate_uuid4(), 'user_pass' => wp_generate_password() ) );
	verify( is_int( $user_id ), 'Could not create the synthetic user.' );
	$event = $store->append( 'future_type', $user_id, array( 'value' => 'original' ), new DateTimeImmutable() );
	$expired = $store->append( 'future_type', $user_id, array( 'value' => 'expired' ), new DateTimeImmutable() );
	verify( is_string( $event ) && is_string( $expired ), 'Could not seed lifecycle events.' );
	$row = $store->get( $event );
	$wpdb->update( EventStore::table(), array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'event_id' => $expired ) );
	$foreign_actions[] = as_schedule_single_action( time() + DAY_IN_SECONDS, Scheduler::CLEANUP_HOOK, array(), 'pulse-test-foreign' );
	$foreign_actions[] = as_schedule_single_action( time() + DAY_IN_SECONDS, 'pulse_test_foreign_hook', array(), Scheduler::GROUP );
	verify( ! in_array( 0, $foreign_actions, true ), 'Could not seed unrelated tasks.' );
	$foreign_logs = array_map( static fn( $id ) => count( ActionScheduler::logger()->get_logs( $id ) ), $foreign_actions );
	$id = SiteContext::id();
	$before = array_map( 'get_option', $options );
	$fail_cancel = static fn( $query ) => str_starts_with( $query, 'UPDATE' ) && str_contains( $query, $wpdb->actionscheduler_actions ) ? 'SELECT 1 WHERE 1=0' : $query;
	add_filter( 'query', $fail_cancel );
	$failed = lifecycle_dies( array( Lifecycle::class, 'deactivate' ) );
	remove_filter( 'query', $fail_cancel );
	verify( $failed && as_has_scheduled_action( Scheduler::CLEANUP_HOOK, array(), Scheduler::GROUP ), 'Failed cancellation was reported as successful.' );
	deactivate_plugins( $plugin );
	verify( ! is_plugin_active( $plugin ) && $before === array_map( 'get_option', $options ), 'Deactivation changed saved data.' );
	verify( $store->get( $event ) === $row && ! as_has_scheduled_action( Scheduler::CLEANUP_HOOK, array(), Scheduler::GROUP ), 'Deactivation removed queued data or left maintenance pending.' );
	verify( null === activate_plugin( $plugin ) && $before === array_map( 'get_option', $options ), 'Reactivation changed saved data.' );
	verify( $store->get( $event ) === $row && ! in_array( $expired, $store->pending_ids(), true ), 'Reactivation altered context or made expired events eligible.' );
	$tasks = as_get_scheduled_actions( array( 'hook' => Scheduler::CLEANUP_HOOK, 'group' => Scheduler::GROUP, 'status' => 'pending' ), 'ids' );
	verify( 1 === count( $tasks ), 'Reactivation duplicated maintenance.' );
	Scheduler::cleanup();
	verify( 1 === EventStore::usage()['events'], 'Reactivated cleanup did not purge the expired record.' );
	Scheduler::stop();
	Scheduler::register_hooks();
	do_action( 'action_scheduler_ensure_recurring_actions' );
	verify( as_has_scheduled_action( Scheduler::CLEANUP_HOOK, array(), Scheduler::GROUP ), 'The native recurring-action recovery hook did not restore maintenance.' );
	$held_table = EventStore::table() . '_test_held';
	$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', EventStore::table(), $held_table ) );
	try {
		verify( lifecycle_dies( array( Lifecycle::class, 'activate' ) ), 'Missing current queue table was silently recreated.' );
		verify( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( EventStore::table() ) ) ), 'Activation replaced a missing installed table.' );
	} finally {
		$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i', $held_table, EventStore::table() ) );
	}
	delete_option( SiteContext::OPTION );
	$failed = false;
	try {
		Lifecycle::activate();
	} catch ( TestWpDie $error ) {
		$failed = true;
	}
	verify( $failed && null === get_option( SiteContext::OPTION, null ), 'A missing UUID in an installed schema was silently regenerated.' );
	add_option( SiteContext::OPTION, $id, autoload: false );
	update_option( 'wp_druid_pulse_foreign_marker', 'keep', false );
	set_transient( 'wp_druid_pulse_foreign_token', 'keep', 3600 );
	set_transient( ConnectionSettings::TOKEN_TRANSIENT, array( 'access_token' => 'synthetic.token' ), 3600 );
	deactivate_plugins( $plugin );
	$fail_log_read = static fn( $query ) => str_starts_with( $query, 'SELECT' ) && str_contains( $query, $wpdb->actionscheduler_logs ) ? 'EXPECTED SCHEDULER LOG READ FAILURE' : $query;
	add_filter( 'query', $fail_log_read );
	$suppressed = $wpdb->suppress_errors( true );
	$failed = lifecycle_dies( array( Lifecycle::class, 'uninstall' ) );
	$wpdb->suppress_errors( $suppressed );
	remove_filter( 'query', $fail_log_read );
	verify( $failed && as_get_scheduled_actions( array( 'hook' => Scheduler::CLEANUP_HOOK, 'group' => Scheduler::GROUP, 'status' => '' ), 'ids' ), 'Failed log read was mistaken for an empty log and lost its action.' );
	$fail_logs = static fn( $query ) => str_starts_with( $query, 'DELETE FROM' ) && str_contains( $query, $wpdb->actionscheduler_logs ) ? 'SELECT 1 WHERE 1=0' : $query;
	add_filter( 'query', $fail_logs );
	$failed = lifecycle_dies( array( Lifecycle::class, 'uninstall' ) );
	remove_filter( 'query', $fail_logs );
	verify( $failed && as_get_scheduled_actions( array( 'hook' => Scheduler::CLEANUP_HOOK, 'group' => Scheduler::GROUP, 'status' => '' ), 'ids' ), 'Failed log cleanup lost its action or reported success.' );
	verify( $store->get( $event ) === $row && $before === array_map( 'get_option', $options ), 'Failed scheduler cleanup erased the queue or configuration.' );
	$fail_action = static fn( $query ) => str_starts_with( $query, 'DELETE FROM' ) && str_contains( $query, $wpdb->actionscheduler_actions ) ? 'SELECT 1 WHERE 1=0' : $query;
	add_filter( 'query', $fail_action );
	$failed = lifecycle_dies( array( Lifecycle::class, 'uninstall' ) );
	remove_filter( 'query', $fail_action );
	verify( $failed, 'Failed task deletion was reported as successful.' );
	$fail_drop = static fn( $query ) => str_starts_with( $query, 'DROP TABLE' ) && str_contains( $query, EventStore::table() ) ? 'EXPECTED QUEUE DROP FAILURE' : $query;
	add_filter( 'query', $fail_drop );
	$failed = lifecycle_dies( array( Lifecycle::class, 'uninstall' ) );
	remove_filter( 'query', $fail_drop );
	verify( $failed && $store->get( $event ) === $row && $before === array_map( 'get_option', $options ), 'Failed table removal was hidden or erased configuration.' );
	$fail_delete = static fn( $query ) => str_starts_with( $query, 'DELETE ' ) && str_contains( $query, SiteContext::OPTION ) ? 'SELECT 1 WHERE 1=0' : $query;
	add_filter( 'query', $fail_delete );
	$failed = false;
	try {
		uninstall_plugin( $plugin );
	} catch ( TestWpDie $error ) {
		$failed = true;
	} finally {
		remove_filter( 'query', $fail_delete );
	}
	$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", SiteContext::OPTION ) );
	verify( $failed && $stored === $id && null !== get_option( 'wp_druid_pulse_schema_version', null ), 'Failed deletion was reported as a successful uninstall.' );
	update_option( '_transient_timeout_' . ConnectionSettings::TOKEN_TRANSIENT, time() - 10, false );
	$timeout_option = '_transient_timeout_' . ConnectionSettings::TOKEN_TRANSIENT;
	$fail_timeout = static fn( $query ) => str_starts_with( $query, 'DELETE ' ) && str_contains( $query, $timeout_option ) ? 'SELECT 1 WHERE 1=0' : $query;
	wp_cache_flush();
	add_filter( 'query', $fail_timeout );
	$failed = false;
	try {
		Lifecycle::uninstall();
	} catch ( TestWpDie $error ) {
		$failed = true;
	} finally {
		remove_filter( 'query', $fail_timeout );
	}
	verify( $failed, 'An expired token deletion failure was hidden.' );
	wp_cache_flush();
	Lifecycle::uninstall();
	foreach ( array_merge( $options, array( '_transient_' . ConnectionSettings::TOKEN_TRANSIENT, '_transient_timeout_' . ConnectionSettings::TOKEN_TRANSIENT ) ) as $option ) {
		verify( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ), 'Uninstall left one of its own records behind.' );
	}
	verify( 'keep' === get_option( 'wp_druid_pulse_foreign_marker' ) && 'keep' === get_transient( 'wp_druid_pulse_foreign_token' ), 'Uninstall removed unrelated data.' );
	verify( null === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( EventStore::table() ) ) ), 'Uninstall left the event table.' );
	verify( array() === as_get_scheduled_actions( array( 'hook' => Scheduler::CLEANUP_HOOK, 'group' => Scheduler::GROUP, 'status' => '' ), 'ids' ), 'Uninstall left its own tasks.' );
	foreach ( $foreign_actions as $index => $action_id ) {
		verify( 'pending' === ActionScheduler::store()->get_status( $action_id ) && $foreign_logs[ $index ] === count( ActionScheduler::logger()->get_logs( $action_id ) ), 'Cleanup changed unrelated tasks or logs.' );
	}
	$fail_create = static fn( $query ) => str_starts_with( $query, 'CREATE TABLE' ) && str_contains( $query, EventStore::table() ) ? 'EXPECTED QUEUE CREATE FAILURE' : $query;
	add_filter( 'query', $fail_create );
	$failed = lifecycle_dies( array( Lifecycle::class, 'activate' ) );
	remove_filter( 'query', $fail_create );
	verify( $failed && null === get_option( 'wp_druid_pulse_schema_version', null ), 'Failed table creation advanced the schema.' );
	verify( null === activate_plugin( $plugin ), 'Fresh activation after uninstall failed.' );
	verify( null === EventStore::verify() && 0 === EventStore::usage()['events'], 'Fresh activation did not recreate an empty valid queue.' );
	verify( SiteContext::id() !== $id && wp_is_uuid( SiteContext::id(), 4 ), 'Reinstallation did not create a new identity.' );
	verify( array( 'post_ids' => array(), 'posts_home' => false ) === ViewSelection::read(), 'Reinstallation retained the old selection.' );
	verify( is_wp_error( ConnectionSettings::ready() ), 'Reinstallation retained credentials.' );
	echo "Passed {$checks} lifecycle assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	remove_filter( 'wp_die_handler', $die_handler );
	foreach ( $foreign_actions as $action_id ) {
		ActionScheduler::store()->delete_action( $action_id );
	}
	if ( is_int( $user_id ) && $user_id > 0 ) {
		wp_delete_user( $user_id );
	}
}
exit( $exit_code );
