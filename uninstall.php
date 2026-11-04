<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Lifecycle.php';

\WP_Druid_Pulse\Lifecycle::uninstall();
