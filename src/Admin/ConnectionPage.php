<?php

namespace WP_Druid_Pulse\Admin;

use WP_Druid_Pulse\ConnectionSettings;
use WP_Druid_Pulse\OAuthClient;
use WP_Druid_Pulse\SiteContext;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/OAuthClient.php';

final class ConnectionPage {
	private const SLUG = 'wp-druid-pulse-connection';
	private const SAVE_ACTION = 'wp_druid_pulse_save_connection';
	private const TEST_ACTION = 'wp_druid_pulse_test_connection';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'test' ) );
	}

	public function add_menu(): void {
		add_submenu_page( 'wp-druid-pulse', __( 'DruID Pulse — Connection', 'wp-druid-pulse' ), __( 'Connection', 'wp-druid-pulse' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function render(): void {
		$this->require_permission();
		$settings = ConnectionSettings::read();
		$application = SiteContext::application();
		if ( is_wp_error( $settings ) || is_wp_error( $application ) ) {
			$this->fail( is_wp_error( $settings ) ? $settings : $application, 500 );
		}
		$has_secret = '' !== $settings['client_secret'];
		unset( $settings['client_secret'] );
		$save_action = self::SAVE_ACTION;
		$test_action = self::TEST_ACTION;
		require dirname( __DIR__, 2 ) . '/templates/admin/connection.php';
	}

	public function save(): void {
		$this->authorize( self::SAVE_ACTION );
		$error = ConnectionSettings::save( wp_unslash( $_POST ) );
		if ( null !== $error ) {
			$this->fail( $error, 'wp_druid_pulse_connection_input' === $error->get_error_code() ? 400 : 500 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function test(): void {
		$this->authorize( self::TEST_ACTION );
		$settings = ConnectionSettings::ready();
		if ( is_wp_error( $settings ) ) {
			$this->fail( $settings, 400 );
		}
		$result = ( new OAuthClient() )->token( $settings, force: true );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result, 502 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tested' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function require_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage DruID Pulse settings.', 'wp-druid-pulse' ), '', array( 'response' => 403 ) );
		}
	}

	private function authorize( string $action ): void {
		$this->require_permission();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? null ) ) {
			wp_die( esc_html__( 'Use the connection form to perform this action.', 'wp-druid-pulse' ), '', array( 'response' => 405 ) );
		}
		$nonce = $_POST['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), $action ) ) {
			wp_die( esc_html__( 'The security check failed. Reload the connection page and try again.', 'wp-druid-pulse' ), '', array( 'response' => 403 ) );
		}
	}

	private function fail( \WP_Error $error, int $status ): never {
		wp_die( esc_html( $error->get_error_message() ), esc_html__( 'DruID Pulse connection', 'wp-druid-pulse' ), array( 'response' => $status, 'back_link' => true ) );
		exit;
	}
}
