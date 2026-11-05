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

use WP_Druid_Pulse\ConnectionSettings;
use WP_Druid_Pulse\Lifecycle;
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
$die_handler = static fn() => static function () { throw new TestWpDie(); };
add_filter( 'wp_die_handler', $die_handler );
$plugin = 'wp-druid-pulse/wp-druid-pulse.php';
$options = array( ViewSelection::OPTION, ConnectionSettings::OPTION, SiteContext::OPTION, 'wp_druid_pulse_schema_version' );

try {
	$id = SiteContext::id();
	$before = array_map( 'get_option', $options );
	deactivate_plugins( $plugin );
	verify( ! is_plugin_active( $plugin ) && $before === array_map( 'get_option', $options ), 'Deactivation changed saved data.' );
	verify( null === activate_plugin( $plugin ) && $before === array_map( 'get_option', $options ), 'Reactivation changed saved data.' );
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
	global $wpdb;
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
	verify( null === activate_plugin( $plugin ), 'Fresh activation after uninstall failed.' );
	verify( SiteContext::id() !== $id && wp_is_uuid( SiteContext::id(), 4 ), 'Reinstallation did not create a new identity.' );
	verify( array( 'post_ids' => array(), 'posts_home' => false ) === ViewSelection::read(), 'Reinstallation retained the old selection.' );
	verify( is_wp_error( ConnectionSettings::ready() ), 'Reinstallation retained credentials.' );
	echo "Passed {$checks} lifecycle assertions.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	exit( 1 );
} finally {
	remove_filter( 'wp_die_handler', $die_handler );
}
