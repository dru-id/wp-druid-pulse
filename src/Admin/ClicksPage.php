<?php

namespace WP_Druid_Pulse\Admin;

use WP_Druid_Pulse\ClickRules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClicksPage {
	private const SLUG = 'wp-druid-pulse-clicks';
	private const SAVE_ACTION = 'wp_druid_pulse_save_clicks';
	private string $hook_suffix = '';

	public function __construct( private string $plugin_file ) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = add_submenu_page( 'wp-druid-pulse', __( 'Clicks', 'wp-druid-pulse' ), __( 'Clicks', 'wp-druid-pulse' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue( string $hook ): void {
		if ( $hook === $this->hook_suffix ) {
			wp_enqueue_script( 'wp-druid-pulse-click-rules', plugins_url( 'assets/admin-clicks.js', $this->plugin_file ), array(), '0.8.0', array( 'in_footer' => true ) );
		}
	}

	public function render(): void {
		$this->require_permission();
		$rules = ClickRules::read();
		if ( is_wp_error( $rules ) ) {
			echo '<div class="wrap"><h1>DruID Pulse</h1><div class="notice notice-error"><p>' . esc_html( $rules->get_error_message() ) . '</p></div></div>';
			return;
		}
		$rows = array();
		foreach ( $rules as $mode => $list ) {
			foreach ( $list as $rule ) {
				$rows[] = array_merge( $rule, array( 'mode' => $mode ) );
			}
		}
		$save_action = self::SAVE_ACTION;
		require dirname( __DIR__, 2 ) . '/templates/admin/clicks.php';
	}

	public function save(): void {
		$this->require_permission();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Use the click rules form.', 'wp-druid-pulse' ), '', array( 'response' => 405 ) );
		}
		$nonce = $_POST['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'The security check failed. Reload the page.', 'wp-druid-pulse' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		$input = wp_unslash( $_POST );
		$value = $this->parse( $input );
		$error = is_wp_error( $value ) ? $value : ClickRules::save( $value );
		if ( null !== $error ) {
			$status = 'wp_druid_pulse_click_rules_invalid' === $error->get_error_code() ? 400 : 500;
			wp_die( esc_html( $error->get_error_message() ), '', array( 'response' => $status, 'back_link' => true ) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function parse( array $input ): array|\WP_Error {
		$rows = $input['rules'] ?? array();
		if ( '1' !== ( $input['complete'] ?? null ) || ! is_array( $rows ) || ! array_is_list( $rows ) ) {
			return ClickRules::invalid();
		}
		$result = array( 'include' => array(), 'exclude' => array() );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== 5 || ! isset( $row['mode'], $row['selector'], $row['prefix'], $row['scope'], $row['pages'] ) ) {
				return ClickRules::invalid();
			}
			foreach ( $row as $value ) {
				if ( ! is_string( $value ) ) {
					return ClickRules::invalid();
				}
			}
			if ( ! isset( $result[ $row['mode'] ] ) || ! in_array( $row['scope'], array( 'all', 'pages' ), true ) ) {
				return ClickRules::invalid();
			}
			$rule = array( 'selector' => trim( $row['selector'] ) );
			if ( '' !== trim( $row['prefix'] ) ) {
				$rule['classPrefix'] = trim( $row['prefix'] );
			}
			if ( 'pages' === $row['scope'] ) {
				$keys = array_map( 'trim', explode( ',', $row['pages'] ) );
				$rule['pages'] = array_values( array_unique( array_map( static fn( $key ) => ctype_digit( $key ) ? 'post:' . $key : $key, $keys ) ) );
			}
			$result[ $row['mode'] ][] = $rule;
		}
		return $result;
	}

	private function require_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage DruID Pulse settings.', 'wp-druid-pulse' ), '', array( 'response' => 403 ) );
		}
	}
}
