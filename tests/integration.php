<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require getenv( 'WP_TEST_BOOTSTRAP' ) ?: '/var/www/html/wp-load.php';
if ( ! defined( 'WP_DRUID_PULSE_TESTING' ) || true !== WP_DRUID_PULSE_TESTING ) {
	fwrite( STDERR, "An isolated WordPress with WP_DRUID_PULSE_TESTING=true is required.\n" );
	exit( 1 );
}

use WP_Druid_Pulse\ActorResolver;
use WP_Druid_Pulse\ConnectionSettings;
use WP_Druid_Pulse\EventMapper;
use WP_Druid_Pulse\Lifecycle;
use WP_Druid_Pulse\OAuthClient;
use WP_Druid_Pulse\PulseClient;
use WP_Druid_Pulse\SiteContext;
use WP_Druid_Pulse\ViewSelection;

$plugin = WP_PLUGIN_DIR . '/wp-druid-pulse/';
require_once $plugin . 'src/ActorResolver.php';
require_once $plugin . 'src/EventMapper.php';
require_once $plugin . 'src/PulseClient.php';

$checks = 0;
function check( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
	++$checks;
}
function error_is( mixed $value, string $code ): bool {
	return is_wp_error( $value ) && $code === $value->get_error_code();
}
function response( int $status, mixed $body ): array {
	return array( 'headers' => array(), 'body' => is_string( $body ) ? $body : wp_json_encode( $body ), 'response' => array( 'code' => $status, 'message' => '' ), 'cookies' => array() );
}

$requests = array();
$responses = array();
$http = static function ( $pre, $args, $url ) use ( &$requests, &$responses ) {
	$requests[] = array( 'url' => $url, 'args' => $args );
	if ( ! $responses ) {
		throw new RuntimeException( 'Unexpected outbound HTTP request.' );
	}
	return array_shift( $responses );
};
add_filter( 'pre_http_request', $http, 999, 3 );
add_filter( 'pre_wp_mail', '__return_true' );

try {
	check( in_array( get_option( 'wp_druid_pulse_schema_version' ), array( 6, '6' ), true ), 'Schema migration did not complete.' );
	$id = SiteContext::id();
	check( is_string( $id ) && wp_is_uuid( $id, 4 ), 'Missing installation UUID.' );
	check( null === Lifecycle::initialize() && $id === SiteContext::id(), 'Initialization replaced the UUID.' );
	global $wpdb;
	foreach ( array( SiteContext::OPTION, ConnectionSettings::OPTION ) as $option ) {
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		check( ! in_array( $autoload, wp_autoload_values_to_autoload(), true ), 'New option was autoloaded.' );
	}
	delete_option( ConnectionSettings::OPTION );
	ConnectionSettings::initialize();
	delete_transient( ConnectionSettings::TOKEN_TRANSIENT );
	check( error_is( ConnectionSettings::ready(), 'wp_druid_pulse_connection_incomplete' ), 'Empty settings were considered ready.' );
	$settings = array( 'api_url' => 'https://pulse.example/api/', 'oauth_url' => 'https://auth.example/', 'client_id' => 'client:one', 'client_secret' => 'test secret:+&', 'auth_method' => 'client_secret_basic', 'scope' => '', 'tenant' => 'test' );
	check( null === ConnectionSettings::save( $settings ), 'Could not save connection.' );
	$settings = ConnectionSettings::read();
	check( 'https://pulse.example/api' === $settings['api_url'], 'Base URL was not normalized.' );
	check( null === ConnectionSettings::save( array_replace( $settings, array( 'client_secret' => '' ) ) ) && $settings === ConnectionSettings::read(), 'An empty secret changed existing credentials.' );
	foreach ( array( 'http://pulse.example', 'https://user:pass@pulse.example', 'https://pulse.example/?token=x', 'https://pulse.example/#x' ) as $bad_url ) {
		check( error_is( ConnectionSettings::save( array_replace( $settings, array( 'api_url' => $bad_url ) ) ), 'wp_druid_pulse_connection_input' ), 'Unsafe base URL accepted.' );
	}
	check( error_is( ConnectionSettings::save( array_replace( $settings, array( 'tenant' => "bad\r\nInjected: x" ) ) ), 'wp_druid_pulse_connection_input' ), 'Header injection accepted.' );
	check( error_is( ConnectionSettings::save( array_replace( $settings, array( 'remove_secret' => '1' ) ) ), 'wp_druid_pulse_connection_input' ), 'Conflicting secret instructions accepted.' );
	check( null === ConnectionSettings::save( array_replace( $settings, array( 'client_secret' => '', 'remove_secret' => '1' ) ) ) && '' === ConnectionSettings::read()['client_secret'], 'Secret removal failed.' );
	check( null === ConnectionSettings::save( $settings ), 'Secret restoration failed.' );

	$user_id = wp_insert_user( array( 'user_login' => 'pulseactor', 'user_email' => 'actor@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'first_name' => 'Ada', 'last_name' => 'Example' ) );
	if ( is_wp_error( $user_id ) ) {
		$user_id = get_user_by( 'login', 'pulseactor' )->ID;
	}
	$resolver = new ActorResolver();
	check( error_is( $resolver->resolve( 0 ), 'wp_druid_pulse_no_user' ), 'Anonymous user accepted.' );
	$actor = $resolver->resolve( $user_id );
	check( $actor === array( 'objectType' => 'person', 'schemaOrg' => array( 'email' => array( 'value' => 'actor@example.test' ), 'givenName' => 'Ada', 'familyName' => 'Example' ) ), 'Profile actor differs from the agreed contract.' );
	foreach ( array( 'technical@dru-id.internal', str_repeat( 'a', 32 ) . '@' . str_repeat( 'b', 32 ) . '.com' ) as $email ) {
		wp_update_user( array( 'ID' => $user_id, 'user_email' => $email ) );
		check( error_is( $resolver->resolve( $user_id ), 'wp_druid_pulse_no_real_email' ), 'Technical email was emitted.' );
	}
	wp_update_user( array( 'ID' => $user_id, 'user_email' => 'actor@example.test' ) );
	$mapper = new EventMapper();
	$data = array( 'page' => array( 'key' => 'post:42', 'url' => 'https://shop.example/catalogue/?p=42#section', 'title' => 'Catalogue &amp; Guides' ) );
	$time = new DateTimeImmutable( '2026-11-05T12:30:00+02:00' );
	$view = $mapper->map( 'page_view', $data, $actor, $time );
	check( 'watch' === $view['verb'] && 'page' === $view['object']['objectType'] && ! isset( $view['target'] ), 'View mapping is incorrect.' );
	check( '2026-11-05T10:30:00Z' === $view['published'], 'Event time was not preserved in UTC.' );
	check( $data['page']['url'] === $view['object']['url'] && 'Catalogue & Guides' === $view['object']['displayName'], 'Page context was changed unexpectedly.' );
	$data['link'] = array( 'key' => 'menu:8', 'title' => 'View racket', 'url' => 'https://untrusted.example/ignored' );
	$data['target'] = array( 'objectType' => 'page', 'key' => 'post:43', 'url' => 'https://shop.example/racket/', 'title' => 'Racket' );
	$click = $mapper->map( 'click', $data, $actor, $time );
	check( 'click' === $click['verb'] && 'link' === $click['object']['objectType'], 'Click interaction is incorrect.' );
	check( $view['object']['url'] === $click['object']['url'] && $data['target']['url'] === $click['target']['url'], 'Click source and destination were confused.' );
	check( $view['origin'] === $click['origin'] && $view['actor'] === $click['actor'], 'Common context differs between types.' );
	foreach ( array( 'tel:+34910000000', 'mailto:test@example.test?subject=Hello' ) as $destination ) {
		$contact = $data;
		$contact['target'] = array( 'objectType' => 'link', 'url' => $destination );
		$mapped = $mapper->map( 'click', $contact, $actor, $time );
		check( ! is_wp_error( $mapped ) && $destination === $mapped['target']['url'] && $data['page']['url'] === $mapped['object']['url'], 'Contact destination was dropped or replaced the source.' );
	}
	foreach ( array( 'javascript:alert(1)', 'tel:', 'tel:/invalid', 'mailto:', 'data:text/plain,hello', 'tel:+34 910', 'https://user:pass@example.test/' ) as $destination ) {
		$invalid = $data;
		$invalid['target']['url'] = $destination;
		check( is_wp_error( $mapper->map( 'click', $invalid, $actor, $time ) ), 'An invalid destination was accepted.' );
	}
	unset( $data['target'], $data['link']['key'] );
	$without_target = $mapper->map( 'click', $data, $actor, $time );
	check( ! isset( $without_target['target'] ) && ! isset( $without_target['object']['id'] ), 'Unknown target or link ID was invented.' );
	check( error_is( $mapper->map( 'unknown', $data, $actor, $time ), 'wp_druid_pulse_unknown_event' ), 'Unregistered event was accepted.' );
	$mapper->register( 'extra_test_type', static fn( $data, $app ) => array( 'verb' => 'share', 'object' => array( 'objectType' => 'article', 'url' => $data['page']['url'] ) ) );
	$extra = $mapper->map( 'extra_test_type', $data, $actor, $time );
	check( 'share' === $extra['verb'] && $extra['origin'] === $view['origin'], 'An additional event required changes to the common mapper.' );
	check( error_is( $mapper->validate_context( 'page_view', $data ), 'wp_druid_pulse_view_context' ), 'A page view accepted client-provided URL/title instead of a reference.' );
	foreach ( array( 'extra_test_type', 'unknown' ) as $type ) {
		check( error_is( $mapper->validate_context( $type, $data ), 'wp_druid_pulse_event_type_unavailable' ), 'An event without a receiver validator was enabled.' );
	}
	check( error_is( $mapper->validate_context( 'click', $data ), 'wp_druid_pulse_click_context' ), 'Clicks accepted unsupported context fields.' );
	$register_type = static function ( EventMapper $registry ) {
		$registry->register(
			'receiver_test',
			static fn( $context, $app ) => array( 'verb' => 'share', 'object' => array( 'objectType' => 'article', 'url' => $context['page']['url'] ) ),
			static function ( array $context ) {
				if ( array_diff( array_keys( $context ), array( 'page', 'label' ) ) || ! isset( $context['page'] ) || ! is_string( $context['label'] ?? null ) ) {
					return new WP_Error( 'fixture_context_invalid', 'Invalid test context.' );
				}
				$context['label'] = sanitize_text_field( $context['label'] );
				return $context;
			}
		);
	};
	add_action( 'wp_druid_pulse_register_event_types', $register_type );
	$receiving_mapper = new EventMapper();
	$preparing_mapper = new EventMapper();
	remove_action( 'wp_druid_pulse_register_event_types', $register_type );
	$context = array( 'page' => $data['page'], 'label' => '<b>Shared</b>' );
	$validated = $receiving_mapper->validate_context( 'receiver_test', $context );
	check( $validated === array( 'page' => $data['page'], 'label' => 'Shared' ), 'The registered input validator was not applied.' );
	check( error_is( $receiving_mapper->validate_context( 'receiver_test', array_merge( $context, array( 'actor' => array() ) ) ), 'fixture_context_invalid' ), 'A validator rejection was hidden.' );
	check( 'share' === $preparing_mapper->map( 'receiver_test', $validated, $actor, $time )['verb'], 'Reception and preparation could not use the same type registration.' );
	$receiving_mapper->register( 'broken_validator', static fn() => array(), static fn() => true );
	check( error_is( $receiving_mapper->validate_context( 'broken_validator', array() ), 'wp_druid_pulse_invalid_event' ), 'A broken validator was treated as valid context.' );
	$receiving_mapper->register( 'receiver_test', static fn() => array() );
	check( error_is( $receiving_mapper->validate_context( 'receiver_test', $context ), 'wp_druid_pulse_event_type_unavailable' ), 'Replacing a definition retained its previous receiver policy.' );
	$old_name = get_option( 'blogname' );
	$old_home = get_option( 'home' );
	update_option( 'blogname', 'Renamed shop' );
	update_option( 'home', 'https://moved.example' );
	check( SiteContext::application()['id'] === $view['origin']['application']['id'] && 'Renamed shop' === SiteContext::application()['displayName'], 'Renaming or moving the site changed its identity.' );
	update_option( 'blogname', $old_name );
	update_option( 'home', $old_home );

	$oauth = new OAuthClient();
	$responses[] = response( 200, array( 'access_token' => 'synthetic.token', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	$token = $oauth->token( $settings );
	check( 'synthetic.token' === $token['access_token'] && 1 === count( $requests ), 'OAuth request failed.' );
	check( 'https://auth.example/oauth2/token' === $requests[0]['url'] && 'client_credentials' === $requests[0]['args']['body']['grant_type'], 'OAuth endpoint or grant is incorrect.' );
	check( $requests[0]['args']['headers']['Authorization'] === 'Basic ' . base64_encode( urlencode( $settings['client_id'] ) . ':' . urlencode( $settings['client_secret'] ) ), 'Basic credentials were incorrectly encoded.' );
	check( ! isset( $requests[0]['args']['body']['client_secret'], $requests[0]['args']['body']['scope'] ), 'Optional fields or duplicate credentials were sent.' );
	check( $token === $oauth->token( $settings ) && 1 === count( $requests ), 'Cached token was not reused.' );
	$client = new PulseClient();
	$responses[] = response( 201, array( 'status' => 201, 'content' => array( 'id' => 'synthetic-identity' ) ) );
	check( array( 'status' => 201 ) === $client->send( $extra ), 'Generic event delivery failed.' );
	$sent = end( $requests );
	check( 'https://pulse.example/api/identities' === $sent['url'] && $extra === json_decode( $sent['args']['body'], true ), 'The client changed the event or used the wrong endpoint.' );
	check( 'Bearer synthetic.token' === $sent['args']['headers']['Authorization'] && 'test' === $sent['args']['headers']['X-Tenant'], 'Delivery headers are incorrect.' );
	check( 0 === $sent['args']['redirection'] && true === $sent['args']['reject_unsafe_urls'], 'Unsafe HTTP options.' );
	foreach ( array( 400, 403, 409, 422, 429, 500, 503, 302 ) as $status ) {
		$responses[] = response( $status, array( 'secret' => $settings['client_secret'], 'email' => 'do-not-expose@example.test' ) );
		$error = $client->send( $view );
		check( error_is( $error, 'wp_druid_pulse_http' ) && $error->get_error_data()['http_status'] === $status && ! str_contains( $error->get_error_message(), $settings['client_secret'] ), 'HTTP failure was misclassified or exposed data.' );
	}
	$responses[] = response( 200, 'not-json' );
	check( error_is( $client->send( $view ), 'wp_druid_pulse_response' ), 'Malformed success was accepted.' );
	$responses[] = new WP_Error( 'http_request_failed', 'private path and secret' );
	check( error_is( $client->send( $view ), 'wp_druid_pulse_transport' ), 'Transport failure was hidden.' );
	$responses[] = response( 401, array() );
	check( error_is( $client->send( $view ), 'wp_druid_pulse_http' ) && false === get_transient( ConnectionSettings::TOKEN_TRANSIENT ), 'Rejected token was retained.' );
	foreach ( array( array( 'access_token' => "bad\r\ntoken", 'token_type' => 'Bearer', 'expires_in' => 3600 ), array( 'access_token' => 'token', 'token_type' => 'Bearer' ), array( 'access_token' => 'token', 'token_type' => 'Bearer', 'expires_in' => 0 ), array( 'access_token' => 'token', 'token_type' => 'MAC', 'expires_in' => 100 ) ) as $invalid ) {
		$responses[] = response( 200, $invalid );
		check( error_is( $oauth->token( $settings ), 'wp_druid_pulse_oauth_response' ), 'Invalid OAuth response accepted.' );
	}
	$responses[] = response( 400, array( 'error_description' => $settings['client_secret'] ) );
	check( error_is( $oauth->token( $settings ), 'wp_druid_pulse_oauth_rejected' ), 'OAuth rejection was not handled.' );
	$responses[] = new WP_Error( 'http_request_failed', 'private transport details' );
	check( error_is( $oauth->token( $settings ), 'wp_druid_pulse_oauth_transport' ), 'OAuth transport error was not handled.' );
	$changed = array_replace( $settings, array( 'auth_method' => 'client_secret_post', 'scope' => 'write_identities' ) );
	check( null === ConnectionSettings::save( $changed ), 'Could not change authentication method.' );
	$responses[] = response( 200, array( 'access_token' => 'post.token', 'token_type' => 'bearer', 'expires_in' => '3600' ) );
	$oauth->token( $changed );
	$sent = end( $requests );
	check( ! isset( $sent['args']['headers']['Authorization'] ) && $settings['client_secret'] === $sent['args']['body']['client_secret'] && 'write_identities' === $sent['args']['body']['scope'], 'POST authentication is incorrect.' );
	$old_cache = get_transient( ConnectionSettings::TOKEN_TRANSIENT );
	check( null === ConnectionSettings::save( $settings ) && false === get_transient( ConnectionSettings::TOKEN_TRANSIENT ), 'Settings change retained a cached token.' );
	set_transient( ConnectionSettings::TOKEN_TRANSIENT, $old_cache, 3600 );
	$responses[] = response( 200, array( 'access_token' => 'fresh.token', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	check( 'fresh.token' === $oauth->token( $settings )['access_token'], 'Token from a different configuration was reused.' );
	$expired = get_transient( ConnectionSettings::TOKEN_TRANSIENT );
	$expired['expires_at'] = time() - 1;
	set_transient( ConnectionSettings::TOKEN_TRANSIENT, $expired, 3600 );
	$responses[] = response( 200, array( 'access_token' => 'renewed.token', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
	check( 'renewed.token' === $oauth->token( $settings )['access_token'], 'Expired token was reused.' );
	check( ! $responses, 'Some expected requests did not happen.' );

	$fail_write = static fn( $query ) => str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, ConnectionSettings::OPTION ) ? 'SELECT 1 WHERE 1=0' : $query;
	add_filter( 'query', $fail_write );
	$error = ConnectionSettings::save( array_replace( $settings, array( 'client_id' => 'replacement' ) ) );
	remove_filter( 'query', $fail_write );
	check( error_is( $error, 'wp_druid_pulse_connection_save' ) && $settings === ConnectionSettings::read(), 'Failed save changed credentials or reported success.' );
	$selection = get_option( ViewSelection::OPTION );
	update_option( 'wp_druid_pulse_schema_version', 2 );
	delete_option( ConnectionSettings::OPTION );
	$fail_add = static fn( $query ) => str_starts_with( $query, 'INSERT ' ) && str_contains( $query, ConnectionSettings::OPTION ) ? 'SELECT 1 WHERE 1=0' : $query;
	add_filter( 'query', $fail_add );
	$error = Lifecycle::initialize();
	remove_filter( 'query', $fail_add );
	check( is_wp_error( $error ) && 2 === get_option( 'wp_druid_pulse_schema_version' ) && $id === SiteContext::id() && $selection === get_option( ViewSelection::OPTION ), 'Partial migration advanced or overwrote existing state.' );
	check( null === Lifecycle::initialize() && 6 === get_option( 'wp_druid_pulse_schema_version' ) && $id === SiteContext::id(), 'Migration did not recover idempotently.' );
	ConnectionSettings::save( $settings );
	update_option( 'wp_druid_pulse_schema_version', 999 );
	check( error_is( Lifecycle::initialize(), 'wp_druid_pulse_incompatible_schema' ) && 999 === get_option( 'wp_druid_pulse_schema_version' ), 'A newer schema was downgraded.' );
	update_option( 'wp_druid_pulse_schema_version', 6 );

	define( 'WP_DRUID', 'druid' );
	check( error_is( $resolver->resolve( $user_id ), 'wp_druid_pulse_identity_api' ), 'An active but unavailable identity API fell back to profile data.' );
	require __DIR__ . '/fixtures/identity.php';
	\WP_Druid\Services\Users::$ids[ $user_id ] = 'synthetic-object-id';
	check( $resolver->resolve( $user_id ) === array( 'objectType' => 'person', 'externalIds' => array( 'druid:objectid' => 'synthetic-object-id' ) ), 'Identity actor contains profile data or the wrong identifier.' );
	\WP_Druid\Services\Users::$ids = array();
	check( error_is( $resolver->resolve( $user_id ), 'wp_druid_pulse_no_object_id' ), 'Missing objectId fell back to the WordPress profile.' );
	echo "Passed {$checks} integration assertions on WordPress " . get_bloginfo( 'version' ) . ', PHP ' . PHP_VERSION . ".\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAILED: ' . $error->getMessage() . "\n" );
	exit( 1 );
} finally {
	remove_filter( 'pre_http_request', $http, 999 );
	remove_filter( 'pre_wp_mail', '__return_true' );
}
