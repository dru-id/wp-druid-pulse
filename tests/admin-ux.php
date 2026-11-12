<?php
if ( 'cli' !== PHP_SAPI ) {
	exit;
}
require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	fwrite( STDERR, "An isolated test installation is required.\n" );
	exit( 1 );
}
use WP_Druid_Pulse\ClickRules;
$credentials = json_decode( file_get_contents( getenv( 'WP_TEST_CREDENTIALS' ) ?: '/test/private.json' ), true );
$checks = 0;
function ux_check( bool $ok, string $message ): void {
	global $checks;
	if ( ! $ok ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function ux_request( string $url, string $jar, ?array $data = null ): array {
	$curl = curl_init( $url );
	curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 15 ) );
	if ( null !== $data ) {
		curl_setopt_array( $curl, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query( $data ) ) );
	}
	$body = curl_exec( $curl );
	$status = curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
	$error = curl_error( $curl );
	curl_close( $curl );
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'Local HTTP request failed: ' . $error );
	}
	return array( 'status' => $status, 'body' => $body );
}
function ux_rules(): array {
	wp_cache_delete( ClickRules::OPTION, 'options' );
	return ClickRules::read();
}
$base = home_url();
$page = $base . '/wp-admin/admin.php?page=wp-druid-pulse-clicks';
$jars = array( tempnam( '/tmp', 'pulse-ux-admin-' ), tempnam( '/tmp', 'pulse-ux-reader-' ) );
$saved = ClickRules::read();
$home = get_option( 'show_on_front' );
$posts = array();
$exit = 0;
try {
	for ( $i = 1; $i <= 27; ++$i ) {
		$posts[] = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => sprintf( 'UX choice %02d', $i ) ) );
	}
	foreach ( array( array( 'post_status' => 'draft' ), array( 'post_status' => 'private' ), array( 'post_password' => 'test-password' ) ) as $extra ) {
		$posts[] = wp_insert_post( array_merge( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'UX choice hidden' ), $extra ) );
	}
	foreach ( array( 'admin', 'subscriber' ) as $index => $role ) {
		ux_request( $base . '/wp-login.php', $jars[ $index ] );
		$login = ux_request( $base . '/wp-login.php', $jars[ $index ], array( 'log' => $credentials[ $role . '_user' ], 'pwd' => $credentials[ $role . '_pass' ], 'testcookie' => '1', 'redirect_to' => $page ) );
		ux_check( 302 === $login['status'], 'Could not log in.' );
	}
	$html = ux_request( $page, $jars[0] );
	ux_check( 200 === $html['status'], 'Click editor failed to load.' );
	preg_match( '/data-nonce="([^"]+)"/', $html['body'], $nonce );
	preg_match( '/name="_wpnonce" value="([^"]+)"/', $html['body'], $save_nonce );
	ux_check( isset( $nonce[1], $save_nonce[1] ), 'Protected editor forms are missing.' );
	$search = array( 'action' => 'wp_druid_pulse_search_click_pages', 'nonce' => $nonce[1], 'search' => 'UX choice', 'paged' => 1 );
	$lookup = static fn( array $args, string $jar ) => ux_request( $base . '/wp-admin/admin-ajax.php?' . http_build_query( $args ), $jar );
	$first = $lookup( $search, $jars[0] );
	$first_data = json_decode( $first['body'], true )['data'];
	ux_check( 200 === $first['status'] && 27 === $first_data['total'] && 20 === count( $first_data['rows'] ) && 2 === $first_data['pages'], 'Page search is not bounded or exposes unavailable content.' );
	$second = json_decode( $lookup( array_replace( $search, array( 'paged' => 2 ) ), $jars[0] )['body'], true )['data'];
	ux_check( 7 === count( $second['rows'] ) && ! array_intersect( array_column( $first_data['rows'], 'key' ), array_column( $second['rows'], 'key' ) ), 'Search pagination repeats or loses results.' );
	ux_check( array_keys( $first_data['rows'][0] ) === array( 'key', 'title', 'type', 'url' ) && 'UX choice 01' === $first_data['rows'][0]['title'], 'Page choices lack usable labels or include unnecessary data.' );
	foreach ( array( array( 'nonce' => 'wrong' ), array( 'nonce' => array( 'wrong' ) ) ) as $invalid ) {
		ux_check( 403 === $lookup( array_replace( $search, $invalid ), $jars[0] )['status'], 'Search allowed an invalid nonce.' );
	}
	ux_check( 403 === $lookup( $search, $jars[1] )['status'], 'Subscriber could search admin content.' );
	ux_check( 400 === $lookup( $search, '' )['status'], 'Anonymous content search was registered.' );
	ux_check( 400 === $lookup( array_replace( $search, array( 'paged' => '0' ) ), $jars[0] )['status'], 'Invalid page accepted.' );
	ux_check( 400 === $lookup( array_replace( $search, array( 'search' => array( 'bad' ) ) ), $jars[0] )['status'], 'Malformed search accepted.' );
	update_option( 'show_on_front', 'posts' );
	$home_data = json_decode( $lookup( array_replace( $search, array( 'search' => 'Front page' ) ), $jars[0] )['body'], true )['data'];
	ux_check( in_array( 'home:posts', array_column( $home_data['rows'], 'key' ), true ), 'Posts homepage cannot be selected by title.' );
	update_option( 'show_on_front', $home );
	$rules = array();
	for ( $i = 0; $i < 220; ++$i ) {
		$rules[] = array( 'mode' => $i % 2 ? 'include' : 'exclude', 'selector' => '#rule-' . $i, 'prefix' => $i % 3 ? '' : 'promo-', 'scope' => 'pages', 'pages' => 'post:' . $posts[0] );
	}
	$form = array( 'action' => 'wp_druid_pulse_save_clicks', '_wpnonce' => $save_nonce[1], 'complete' => '1', 'rules_json' => wp_json_encode( $rules ) );
	$submit = static fn( array $value, string $jar ) => ux_request( $base . '/wp-admin/admin-post.php', $jar, $value );
	ux_check( 302 === $submit( $form, $jars[0] )['status'], 'Large paginated form was truncated by max_input_vars.' );
	$snapshot = ux_rules();
	ux_check( 110 === count( $snapshot['include'] ) && 110 === count( $snapshot['exclude'] ), 'Save lost rules outside the displayed page.' );
	ux_check( '#rule-219' === $snapshot['include'][109]['selector'] && '#rule-218' === $snapshot['exclude'][109]['selector'], 'Save reordered or changed selectors.' );
	ux_check( 'promo-' === $snapshot['exclude'][0]['classPrefix'] && array( 'post:' . $posts[0] ) === $snapshot['exclude'][0]['pages'], 'Existing optional filters changed.' );
	foreach ( array( array( 'complete' => '0' ), array( 'rules_json' => '[' ), array( 'rules_json' => '{}' ), array( 'rules_json' => array( 'invalid' ) ) ) as $invalid ) {
		ux_check( 400 === $submit( array_replace( $form, $invalid ), $jars[0] )['status'] && $snapshot === ux_rules(), 'Invalid JSON form changed saved rules.' );
	}
	ux_check( 403 === $submit( $form, $jars[1] )['status'] && $snapshot === ux_rules(), 'Subscriber saved JSON rules.' );
	ux_check( 403 === $submit( array_replace( $form, array( '_wpnonce' => 'wrong' ) ), $jars[0] )['status'], 'JSON form bypassed nonce.' );
	wp_update_post( array( 'ID' => $posts[0], 'post_status' => 'draft' ) );
	ux_check( 302 === $submit( $form, $jars[0] )['status'] && $snapshot === ux_rules(), 'Unchanged unavailable selections were discarded.' );
	$html = ux_request( $page, $jars[0] );
	ux_check( str_contains( $html['body'], 'UX choice 01' ) && str_contains( $html['body'], 'Unavailable' ) && ! str_contains( $html['body'], 'Content IDs, separated by commas' ), 'Existing unavailable content has no readable label.' );
	$rules[0]['pages'] = 'post:' . $posts[28];
	ux_check( 400 === $submit( array_replace( $form, array( 'rules_json' => wp_json_encode( $rules ) ) ), $jars[0] )['status'] && $snapshot === ux_rules(), 'New private content was accepted.' );
	$connection = ux_request( $base . '/wp-admin/admin.php?page=wp-druid-pulse-connection', $jars[0] );
	ux_check( ! str_contains( $connection['body'], 'Installation ID:' ) && str_contains( $connection['body'], 'Site shown in Pulse:' ), 'Connection exposes internal installation data instead of site identity.' );
	echo "PASS: $checks admin UX HTTP checks.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	$exit = 1;
} finally {
	update_option( ClickRules::OPTION, $saved, false );
	update_option( 'show_on_front', $home );
	foreach ( $posts as $id ) { wp_delete_post( $id, true ); }
	foreach ( $jars as $jar ) { unlink( $jar ); }
}
exit( $exit );
