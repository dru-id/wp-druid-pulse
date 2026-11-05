<?php
/**
 * Plugin Name: DruID Pulse for WordPress
 * Description: Independent DruID Pulse integration with page-view selection and OAuth connection settings. Event capture and scheduled delivery are not available yet.
 * Version: 0.4.0
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Author: Genetsis
 * Text Domain: wp-druid-pulse
 * Update URI: false
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/ViewSelection.php';
require_once __DIR__ . '/src/ConnectionSettings.php';
require_once __DIR__ . '/src/SiteContext.php';
require_once __DIR__ . '/src/Lifecycle.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook( __FILE__, array( \WP_Druid_Pulse\Lifecycle::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		( new \WP_Druid_Pulse\Plugin( __FILE__ ) )->register_hooks();
	}
);
