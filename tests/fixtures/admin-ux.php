<?php

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	fwrite( STDERR, "An isolated installation is required.\n" );
	exit( 1 );
}

use WP_Druid_Pulse\ClickRules;

$option = 'pulse_admin_ux_fixture';
$fixture = get_option( $option );
$mode = $argv[1] ?? '';
if ( 'seed' === $mode ) {
	if ( $fixture ) {
		throw new RuntimeException( 'Restore the previous UX fixture first.' );
	}
	$fixture = array( 'rules' => ClickRules::read(), 'posts' => array() );
	for ( $i = 1; $i <= 25; ++$i ) {
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => sprintf( 'UX browser page %02d', $i ), 'post_content' => '<button id="ux-test-button" type="button">Test click</button>' ), true );
		if ( is_wp_error( $id ) ) {
			throw new RuntimeException( 'Could not create fixture page.' );
		}
		$fixture['posts'][] = $id;
	}
	update_option( $option, $fixture, false );
	$rules = array( 'include' => array(), 'exclude' => array( array( 'selector' => '.ux-excluded' ) ) );
	for ( $i = 0; $i < 21; ++$i ) {
		$rules['include'][] = array( 'selector' => '#ux-' . $i, 'classPrefix' => 'legacy-', 'pages' => array( 'post:' . $fixture['posts'][0] ) );
	}
	$error = ClickRules::save( $rules );
	if ( is_wp_error( $error ) ) {
		throw new RuntimeException( $error->get_error_message() );
	}
	echo "UX browser fixture ready: 22 rules and 25 pages.\n";
} elseif ( 'verify' === $mode && $fixture ) {
	$rules = ClickRules::read();
	if ( count( $rules['include'] ) !== 21 || count( $rules['exclude'] ) !== 1 ) {
		throw new RuntimeException( 'Pagination lost rules.' );
	}
	if ( $rules['include'][0] !== array( 'selector' => 'a[href], button', 'pages' => array( 'post:' . $fixture['posts'][24] ) ) ) {
		throw new RuntimeException( 'First rule did not preserve the selected page and preset.' );
	}
	for ( $i = 1; $i < 20; ++$i ) {
		if ( $rules['include'][$i] !== array( 'selector' => '#ux-' . $i, 'classPrefix' => 'legacy-', 'pages' => array( 'post:' . $fixture['posts'][0] ) ) ) {
			throw new RuntimeException( 'An untouched rule changed.' );
		}
	}
	if ( $rules['include'][20] !== array( 'selector' => 'a[href], button', 'pages' => array( 'post:' . $fixture['posts'][1] ) ) ) {
		throw new RuntimeException( 'Last-page addition was not saved.' );
	}
	if ( $rules['exclude'] !== array( array( 'selector' => '.ux-excluded' ) ) ) {
		throw new RuntimeException( 'Exclusion changed.' );
	}
	echo "Saved rules from both editor pages verified; untouched rules preserved.\n";
} elseif ( 'restore' === $mode && $fixture ) {
	update_option( ClickRules::OPTION, $fixture['rules'], false );
	foreach ( $fixture['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	delete_option( $option );
	echo "UX browser fixture restored.\n";
} else {
	throw new RuntimeException( 'Unknown fixture mode or missing seed.' );
}
