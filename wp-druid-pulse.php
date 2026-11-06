<?php
/**
 * Plugin Name: DruID Pulse for WordPress
 * Description: Independent DruID Pulse integration with authenticated page views and clicks, OAuth, an extensible browser tracker and scheduled event delivery.
 * Version: 0.9.0
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Author: Genetsis
 * Text Domain: wp-druid-pulse
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/libraries/action-scheduler/action-scheduler.php';
require_once __DIR__ . '/src/ViewSelection.php';
require_once __DIR__ . '/src/ClickRules.php';
require_once __DIR__ . '/src/ConnectionSettings.php';
require_once __DIR__ . '/src/DeliveryState.php';
require_once __DIR__ . '/src/SiteContext.php';
require_once __DIR__ . '/src/EventStore.php';
require_once __DIR__ . '/src/Scheduler.php';
require_once __DIR__ . '/src/Lifecycle.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( \WP_Druid_Pulse\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WP_Druid_Pulse\Lifecycle::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new \WP_Druid_Pulse\Plugin( __FILE__ ) )->register_hooks();
	}
);
