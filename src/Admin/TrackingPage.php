<?php

namespace WP_Druid_Pulse\Admin;

use WP_Druid_Pulse\ViewCatalog;
use WP_Druid_Pulse\ViewSelection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackingPage {
	private const SLUG = 'wp-druid-pulse';
	private const SAVE_ACTION = 'wp_druid_pulse_save_views';
	private string $hook_suffix = '';

	public function __construct( private string $plugin_file ) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = add_menu_page( __( 'DruID Pulse — Tracking', 'wp-druid-pulse' ), 'DruID Pulse', 'manage_options', self::SLUG, array( $this, 'render' ), 'dashicons-chart-area' );
		add_submenu_page( self::SLUG, __( 'Tracking', 'wp-druid-pulse' ), __( 'Tracking', 'wp-druid-pulse' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook === $this->hook_suffix ) {
			wp_enqueue_script( 'wp-druid-pulse-tracking', plugins_url( 'assets/admin-tracking.js', $this->plugin_file ), array(), '0.3.0', array( 'in_footer' => true ) );
		}
	}

	public function render(): void {
		$this->require_permission();
		$selection = ViewSelection::read();

		if ( is_wp_error( $selection ) ) {
			echo '<div class="wrap"><h1>DruID Pulse</h1><div class="notice notice-error"><p>' . esc_html( $selection->get_error_message() ) . '</p></div></div>';
			return;
		}

		$filters = $this->filters( $_GET );
		$catalog_page = ( new ViewCatalog() )->page( $selection, $filters );
		$filters['paged'] = $catalog_page['paged'];
		$types = ViewCatalog::types();
		$views = $this->views();
		$page_url = $this->page_url( $filters );
		$save_action = self::SAVE_ACTION;
		$slug = self::SLUG;
		require dirname( __DIR__, 2 ) . '/templates/admin/tracking.php';
	}

	public function save(): void {
		$this->require_permission();

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Use the tracking form to save a selection.', 'wp-druid-pulse' ), '', array( 'response' => 405 ) );
		}

		$nonce = $_POST['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::SAVE_ACTION ) ) {
			wp_die( esc_html__( 'The security check failed. Reload the tracking page and try again.', 'wp-druid-pulse' ), '', array( 'response' => 403, 'back_link' => true ) );
		}

		$input = wp_unslash( $_POST );
		$visible = $this->ids( $input['visible_ids'] ?? array() );
		$selected = $this->ids( $input['selected_ids'] ?? array() );
		$home_visible = $input['posts_home_visible'] ?? null;
		$home = $input['posts_home'] ?? null;
		$valid_home = ( null === $home_visible && null === $home )
			|| ( '1' === $home_visible && in_array( $home, array( null, '1' ), true ) );

		if ( '1' !== ( $input['complete'] ?? null ) || null === $visible || null === $selected || ! $valid_home ) {
			$error = ViewSelection::invalid_submission();
		} else {
			$error = ViewSelection::save_page( $visible, $selected, '1' === $home_visible ? '1' === $home : null );
		}

		if ( null !== $error ) {
			$server_error = in_array( $error->get_error_code(), array( 'wp_druid_pulse_save_failed', 'wp_druid_pulse_invalid_selection_data' ), true );
			wp_die( esc_html( $error->get_error_message() ), esc_html__( 'Selection not saved', 'wp-druid-pulse' ), array( 'response' => $server_error ? 500 : 400, 'back_link' => true ) );
		}

		wp_safe_redirect( add_query_arg( 'saved', '1', $this->page_url( $this->filters( $_POST ) ) ) );
		exit;
	}

	private function require_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage DruID Pulse settings.', 'wp-druid-pulse' ), '', array( 'response' => 403 ) );
		}
	}

	private function ids( mixed $input ): ?array {
		if ( ! is_array( $input ) || ! array_is_list( $input ) ) {
			return null;
		}

		$ids = array();
		foreach ( $input as $value ) {
			if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) {
				return null;
			}
			$id = filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $id ) {
				return null;
			}
			$ids[] = $id;
		}

		return array_values( array_unique( $ids ) );
	}

	private function filters( array $input ): array {
		$text = static fn( $key ) => isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
		$type = $text( 'post_type' );
		$view = $text( 'selection' );
		$page = filter_var( $text( 'paged' ), FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => intdiv( PHP_INT_MAX, ViewCatalog::PAGE_SIZE ) ) ) );

		return array(
			'search' => $text( 'search' ),
			'post_type' => isset( ViewCatalog::types()[ $type ] ) ? $type : '',
			'selection' => isset( $this->views()[ $view ] ) ? $view : 'all',
			'paged' => false === $page ? 1 : $page,
		);
	}

	private function views(): array {
		return array(
			'all' => __( 'All available content', 'wp-druid-pulse' ),
			'selected' => __( 'Selected available content', 'wp-druid-pulse' ),
			'unselected' => __( 'Unselected available content', 'wp-druid-pulse' ),
			'unavailable' => __( 'Unavailable selections', 'wp-druid-pulse' ),
		);
	}

	private function page_url( array $filters ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $filters ), admin_url( 'admin.php' ) );
	}
}
