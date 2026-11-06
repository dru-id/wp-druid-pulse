<?php

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit;
}

use WP_Druid_Pulse\ClickRules;
use WP_Druid_Pulse\EventMapper;
use WP_Druid_Pulse\EventPreparer;
use WP_Druid_Pulse\EventStore;
use WP_Druid_Pulse\ViewSelection;

return static function ( array $jars, array $admin_headers, array $headers, int $user_id ): void {
	global $wpdb, $endpoint, $checks, $wp_rewrite;
	$initial_checks = $checks;
	$original = ClickRules::read();
	$views = get_option( ViewSelection::OPTION );
	$permalinks = get_option( 'permalink_structure' );
	$posts = array();
	$events = array();
	$page_url = admin_url( 'admin.php?page=wp-druid-pulse-clicks' );
	$save_url = admin_url( 'admin-post.php' );
	$store = new EventStore();
	$config = static function ( string $url ) use ( $jars ): ?array {
		$response = receiver_request( $url, $jars[1] );
		return preg_match( '/window\.wpDruidPulseTracking = (\{[^\n]+\});/', $response['body'], $match ) ? json_decode( $match[1], true ) : null;
	};
	$send = static fn( array $context ) => wp_json_encode( array( 'type' => 'click', 'published' => '2026-11-06T08:00:00Z', 'context' => $context ) );
	try {
		update_option( ViewSelection::OPTION, array( 'post_ids' => array(), 'posts_home' => false ) );
		update_option( ClickRules::OPTION, array( 'include' => array(), 'exclude' => array() ) );
		foreach ( array( 'Click source', 'Other source' ) as $title ) {
			$posts[] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title ) );
		}
		verify_receiver( null === $config( get_permalink( $posts[0] ) ), 'Empty click rules loaded automatic capture.' );
		verify_receiver( 403 === receiver_request( $page_url, $jars[1] )['status'], 'A subscriber opened the click settings.' );
		$form = receiver_request( $page_url, $jars[0] );
		verify_receiver( 200 === $form['status'] && str_contains( $form['body'], 'assets/admin-clicks.js' ), 'The click settings did not load their editor.' );
		preg_match( '/name="_wpnonce" value="([^"]+)"/', $form['body'], $nonce );
		verify_receiver( isset( $nonce[1] ), 'The click settings nonce is missing.' );
		$submission = array( 'action' => 'wp_druid_pulse_save_clicks', '_wpnonce' => $nonce[1], 'complete' => '1', 'rules' => array(
			array( 'mode' => 'include', 'selector' => 'a[href], button', 'prefix' => '', 'scope' => 'pages', 'pages' => (string) $posts[0] ),
			array( 'mode' => 'exclude', 'selector' => '.no-track', 'prefix' => '', 'scope' => 'all', 'pages' => '' ),
		) );
		$save = static fn( array $input, string $jar ) => receiver_request( $save_url, $jar, 'POST', http_build_query( $input ), array( 'Content-Type: application/x-www-form-urlencoded' ) );
		verify_receiver( 403 === $save( $submission, $jars[1] )['status'], 'A subscriber saved click rules.' );
		verify_receiver( 403 === $save( array_replace( $submission, array( '_wpnonce' => 'invalid' ) ), $jars[0] )['status'], 'Click save accepted an invalid nonce.' );
		verify_receiver( 400 === $save( array_replace( $submission, array( 'complete' => '0' ) ), $jars[0] )['status'], 'A truncated form was saved.' );
		verify_receiver( 302 === $save( $submission, $jars[0] )['status'], 'Valid click rules were not saved.' );
		wp_cache_delete( ClickRules::OPTION, 'options' );
		$rules = ClickRules::read();
		verify_receiver( array( 'post:' . $posts[0] ) === $rules['include'][0]['pages'] && '.no-track' === $rules['exclude'][0]['selector'], 'The editor changed rule semantics.' );
		verify_receiver( 'off' === $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, ClickRules::OPTION ) ), 'Click rules unexpectedly autoload.' );
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
		$tracking = $config( add_query_arg( 'campaign', 'discarded', get_permalink( $posts[0] ) ) );
		verify_receiver( null === $tracking['view'] && ! isset( $tracking['clicks']['include'][0]['pages'] ) && ! str_contains( $tracking['page']['url'], 'campaign' ), 'Clicks depend on views or leaked browsing parameters into source context.' );
		verify_receiver( null === $config( get_permalink( $posts[1] ) ), 'Plain permalinks expanded a content rule to another post sharing pathname.' );
		verify_receiver( ! str_contains( receiver_request( get_permalink( $posts[0] ) )['body'], 'wpDruidPulseTracking' ), 'Anonymous visitors received tracking configuration.' );
		$context = array( 'page' => $tracking['page'], 'link' => array( 'title' => '<b>Original label</b>' ) );
		foreach ( array( null, 'https://example.test/destination?item=1#details', 'tel:+34910000000', 'mailto:test@example.test?subject=Hello' ) as $destination ) {
			$input = $context;
			if ( null !== $destination ) {
				$input['target'] = array( 'objectType' => str_starts_with( $destination, 'https:' ) ? 'page' : 'link', 'url' => $destination );
			}
			$response = receiver_request( $endpoint, $jars[1], 'POST', $send( $input ), $headers );
			verify_receiver( 202 === $response['status'], 'A selected click was rejected: ' . wp_json_encode( $response['json'] ) );
			$events[] = $response['json']['event_id'];
			$row = $store->get( end( $events ) );
			verify_receiver( $user_id === $row['user_id'] && 'Original label' === $row['context']['link']['title'] && '2026-11-06 08:00:00' === $row['published'], 'Click identity, label or timestamp changed.' );
			$beat = ( new EventPreparer( $store, new EventMapper() ) )->prepare( end( $events ) );
			verify_receiver( ! is_wp_error( $beat ) && 'click' === $beat['verb'] && 'link' === $beat['object']['objectType'] && $tracking['page']['url'] === $beat['object']['url'] && $destination === ( $beat['target']['url'] ?? null ), 'The click did not prepare through the common pipeline.' );
		}
		foreach ( array( 'actor' => array(), 'user_id' => $user_id, 'html' => '<form>private</form>' ) as $key => $value ) {
			rejected_request( $send( $context + array( $key => $value ) ), $jars[1], $headers, 400 );
		}
		foreach ( array( 'javascript:alert(1)', 'data:text/html,hello', 'tel:', 'tel:+34 910', 'mailto:', 'https://user:pass@example.test/' ) as $url ) {
			rejected_request( $send( $context + array( 'target' => array( 'objectType' => 'link', 'url' => $url ) ) ), $jars[1], $headers, 400 );
		}
		$forged = $context;
		$forged['page']['url'] = 'https://other.example/';
		rejected_request( $send( $forged ), $jars[1], $headers, 400 );
		$forged = $context;
		$forged['page']['key'] = 'post:' . $posts[1];
		rejected_request( $send( $forged ), $jars[1], $headers, 400, 'wp_druid_pulse_click_unavailable' );
		$failed = static fn( $sql ) => str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, ClickRules::OPTION ) ? 'SELECT 1 WHERE 1=0' : $sql;
		add_filter( 'query', $failed );
		$error = ClickRules::save( array( 'include' => array(), 'exclude' => array() ) );
		remove_filter( 'query', $failed );
		verify_receiver( is_wp_error( $error ) && $rules === ClickRules::read(), 'Failed save lost the existing rules or claimed success.' );
		$all = array( 'include' => array( array( 'selector' => 'a[href]' ) ), 'exclude' => array() );
		verify_receiver( null === ClickRules::save( $all ), 'Global rules failed to save.' );
		$route = $config( home_url( '/?s=missing-click-source&private=discarded' ) );
		verify_receiver( str_starts_with( $route['page']['key'], 'route:' ) && ! str_contains( $route['page']['url'], '?' ) && $route['clicks'] === $all, 'Global click rules did not cover a non-catalog frontend page.' );
		wp_update_post( array( 'ID' => $posts[0], 'post_title' => 'Changed after capture', 'post_status' => 'draft' ) );
		rejected_request( $send( $context ), $jars[1], $headers, 400 );
		$stored = $store->get( $events[0] );
		verify_receiver( $stored['context']['page'] === $tracking['page'], 'Post changes rewrote an already accepted click.' );
		echo 'PASS: ' . ( $checks - $initial_checks ) . " click HTTP and persistence assertions.\n";
	} finally {
		update_option( ClickRules::OPTION, $original );
		update_option( ViewSelection::OPTION, $views );
		$wp_rewrite->set_permalink_structure( $permalinks );
		$wp_rewrite->flush_rules();
		foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
		foreach ( $events as $id ) { $wpdb->delete( EventStore::table(), array( 'event_id' => $id ), array( '%s' ) ); }
	}
};
