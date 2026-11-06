<?php

if ( 'cli' !== PHP_SAPI || ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	exit;
}

use WP_Druid_Pulse\EventMapper;
use WP_Druid_Pulse\EventPreparer;
use WP_Druid_Pulse\EventStore;
use WP_Druid_Pulse\ViewSelection;

return static function ( string $jar, array $headers, int $user_id ): void {
	global $wpdb, $wp_rewrite, $endpoint, $checks;
	$initial_checks = $checks;
	$options = array();
	foreach ( array( ViewSelection::OPTION, \WP_Druid_Pulse\ClickRules::OPTION, 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure' ) as $name ) {
		$options[ $name ] = get_option( $name );
	}
	$posts = array();
	$events = array();
	$store = new EventStore();
	$selection = static function ( array $ids, bool $home = false ): void {
		update_option( ViewSelection::OPTION, array( 'post_ids' => $ids, 'posts_home' => $home ), false );
	};
	$context = static function ( string $url, ?string $expected ) use ( $jar ): array {
		$response = receiver_request( $url, $jar );
		preg_match_all( '/window\.wpDruidPulseView = (\{[^\n]+\});/', $response['body'], $matches );
		if ( null === $expected ) {
			verify_receiver( empty( $matches[1] ) && ! str_contains( $response['body'], 'assets/frontend-views.js' ), 'An excluded route loaded view capture: ' . $url );
		} else {
			verify_receiver( 200 === $response['status'] && 1 === count( $matches[1] )
				&& array( 'page' => array( 'key' => $expected ) ) === json_decode( $matches[1][0], true ), 'Selected content did not provide exactly one minimal reference: ' . $url );
			verify_receiver( 1 === substr_count( $response['body'], 'assets/tracker.js?ver=0.2.0' )
				&& str_contains( $response['body'], 'assets/frontend-views.js?ver=0.8.0' )
				&& strpos( $response['body'], 'assets/tracker.js' ) < strpos( $response['body'], 'assets/frontend-views.js' ), 'SDK and adapter assets were missing, duplicated or unordered.' );
		}
		return $response;
	};
	$body = static fn( string $key ): string => wp_json_encode( array( 'type' => 'page_view', 'published' => '2026-11-05T12:30:00Z', 'context' => array( 'page' => array( 'key' => $key ) ) ) );
	$accept = static function ( string $key ) use ( $jar, $headers, $user_id, $body, $store, &$events, $endpoint ): array {
		$response = receiver_request( $endpoint, $jar, 'POST', $body( $key ), $headers );
		verify_receiver( 202 === $response['status'], 'Selected view was not accepted: ' . wp_json_encode( $response['json'] ) );
		$events[] = $response['json']['event_id'];
		$saved = $store->get( end( $events ) );
		verify_receiver( ! is_wp_error( $saved ) && $user_id === $saved['user_id'] && '2026-11-05 12:30:00' === $saved['published']
			&& $key === $saved['context']['page']['key'], 'View attribution, reference or original time changed.' );
		return $saved;
	};
	try {
		update_option( \WP_Druid_Pulse\ClickRules::OPTION, array( 'include' => array(), 'exclude' => array() ) );
		verify_receiver( post_type_exists( 'pulse_public' ), 'Install the views MU fixture before running this suite.' );
		foreach ( array( 'post', 'page', 'page', 'post', 'pulse_public', 'pulse_hidden' ) as $i => $type ) {
			$posts[] = wp_insert_post( array( 'post_title' => 'View fixture ' . $i, 'post_status' => 'publish', 'post_type' => $type,
				'post_author' => $user_id, 'post_content' => 'First page<!--nextpage-->Second page' ) );
		}
		list( $post, $front, $blog, $protected, $public, $hidden ) = $posts;
		$key = 'post:' . $post;
		$wp_rewrite->set_permalink_structure( '' );
		$wp_rewrite->flush_rules();
		update_option( 'show_on_front', 'posts' );
		$selection( array() );
		$unselected = $context( get_permalink( $post ), null );
		verify_receiver( str_contains( $unselected['body'], 'assets/frontend-transport.js' ), 'View selection incorrectly disabled the common transport.' );
		$selection( $posts, true );
		$context( add_query_arg( array( 'utm_source' => 'fixture', 'filter' => 'x' ), get_permalink( $post ) ) . '#section', $key );
		$anonymous = receiver_request( get_permalink( $post ) );
		verify_receiver( ! str_contains( $anonymous['body'], 'wpDruidPulseView' ), 'An anonymous page activated views.' );
		foreach ( array( add_query_arg( 'preview', 'true', get_permalink( $post ) ), add_query_arg( 'embed', 'true', get_permalink( $post ) ),
			add_query_arg( 'pulse_fixture_error', '1', get_permalink( $post ) ), home_url( '/?s=View' ), home_url( '/?p=2147483647' ),
			home_url( '/?feed=rss2' ), get_author_posts_url( (int) get_post( $post )->post_author ) ) as $url ) {
			$context( $url, null );
		}
		$context( get_permalink( $public ), 'post:' . $public );
		rejected_request( $body( 'post:' . $hidden ), $jar, $headers, 400, 'wp_druid_pulse_view_unavailable' );
		$context( add_query_arg( 'page', '2', get_permalink( $post ) ), $key );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'At receipt & changed' ) );
		$saved = $accept( $key );
		verify_receiver( get_permalink( $post ) === $saved['context']['page']['url'] && str_contains( $saved['context']['page']['url'], '?p=' )
			&& 'At receipt & changed' === $saved['context']['page']['title'], 'Reception did not resolve the current title and structural permalink.' );
		wp_update_post( array( 'ID' => $post, 'post_title' => 'After acceptance' ) );
		$prepared = ( new EventPreparer( $store, new EventMapper() ) )->prepare( $saved['event_id'] );
		verify_receiver( ! is_wp_error( $prepared ) && 'watch' === $prepared['verb'] && 'page' === $prepared['object']['objectType']
			&& 'At receipt & changed' === $prepared['object']['displayName'] && $saved['context']['page']['url'] === $prepared['object']['url'], 'Preparation re-resolved accepted metadata or changed the view mapping.' );
		foreach ( array( array( 'key' => $key, 'url' => 'https://forged.example/' ), array( 'key' => $key, 'title' => 'Forged' ),
			array( 'key' => 'post:0' ), array( 'key' => 'post:01' ), array( 'key' => 'post:999999999999999999999999999' ), array( 'key' => array( $key ) ) ) as $page ) {
			$invalid = json_decode( $body( $key ), true );
			$invalid['context']['page'] = $page;
			rejected_request( wp_json_encode( $invalid ), $jar, $headers, 400, 'wp_druid_pulse_view_context' );
		}
		$selection( array() );
		rejected_request( $body( $key ), $jar, $headers, 400, 'wp_druid_pulse_view_unavailable' );
		$selection( $posts, true );
		foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
			wp_update_post( array( 'ID' => $post, 'post_status' => $status ) );
			rejected_request( $body( $key ), $jar, $headers, 400, 'wp_druid_pulse_view_unavailable' );
		}
		wp_update_post( array( 'ID' => $post, 'post_status' => 'publish' ) );
		update_option( ViewSelection::OPTION, 'corrupt-test-data', false );
		rejected_request( $body( $key ), $jar, $headers, 503, 'wp_druid_pulse_invalid_selection_data' );
		$selection( $posts, true );
		wp_update_post( array( 'ID' => $protected, 'post_password' => 'synthetic-view-password' ) );
		$context( get_permalink( $protected ), null );
		rejected_request( $body( 'post:' . $protected ), $jar, $headers, 400, 'wp_druid_pulse_view_unavailable' );
		receiver_request( home_url( '/wp-login.php?action=postpass' ), $jar, 'POST', http_build_query( array( 'post_password' => 'synthetic-view-password' ) ), array( 'Content-Type: application/x-www-form-urlencoded' ) );
		$context( get_permalink( $protected ), 'post:' . $protected );
		$accept( 'post:' . $protected );
		$context( home_url( '/' ), 'home:posts' );
		$home = $accept( 'home:posts' );
		verify_receiver( home_url( '/' ) === $home['context']['page']['url'] && get_bloginfo( 'name' ) === $home['context']['page']['title'], 'Posts homepage metadata was not resolved by WordPress.' );
		update_option( 'posts_per_page', 1 );
		$context( home_url( '/?paged=2' ), 'home:posts' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $blog );
		$context( home_url( '/' ), 'post:' . $front );
		$context( get_permalink( $blog ), 'post:' . $blog );
		$context( add_query_arg( 'paged', '2', get_permalink( $blog ) ), 'post:' . $blog );
		$accept( 'post:' . $front );
		$accept( 'post:' . $blog );
		rejected_request( $body( 'home:posts' ), $jar, $headers, 400, 'wp_druid_pulse_view_unavailable' );
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		$wp_rewrite->flush_rules();
		$context( add_query_arg( 'utm_source', 'fixture', get_permalink( $post ) ), $key );
		$pretty = $accept( $key );
		verify_receiver( get_permalink( $post ) === $pretty['context']['page']['url'] && ! str_contains( $pretty['context']['page']['url'], '?' ), 'Pretty permalink copied browsing parameters.' );
		wp_delete_post( $post, true );
		rejected_request( $body( $key ), $jar, $headers, 400, 'wp_druid_pulse_view_unavailable' );
		verify_receiver( $saved === $store->get( $saved['event_id'] ), 'Changes to selection or content changed an accepted event.' );
		echo 'PASS: ' . ( $checks - $initial_checks ) . " page view HTTP assertions.\n";
	} finally {
		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}
		$wp_rewrite->set_permalink_structure( $options['permalink_structure'] );
		$wp_rewrite->flush_rules();
		foreach ( $posts as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $events as $id ) {
			$wpdb->delete( EventStore::table(), array( 'event_id' => $id ), array( '%s' ) );
		}
	}
};
