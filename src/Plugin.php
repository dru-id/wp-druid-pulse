<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private string $plugin_basename;
	private ?\WP_Error $initialization_error = null;

	public function __construct( string $plugin_file ) {
		$this->plugin_basename = plugin_basename( $plugin_file );
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'initialize' ), 0 );

		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_status' ), 10, 2 );
	}

	public function initialize(): void {
		$this->initialization_error = Lifecycle::initialize();

		if ( null !== $this->initialization_error && is_admin() ) {
			add_action( 'admin_notices', array( $this, 'show_initialization_error' ) );
		}
	}

	public function show_initialization_error(): void {
		if ( null === $this->initialization_error || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html( $this->initialization_error->get_error_message() );
		echo ' ' . esc_html__( 'Plugin functions are paused. Initialization will be retried on subsequent requests.', 'wp-druid-pulse' );
		echo '</p></div>';
	}

	public function add_plugin_status( array $plugin_meta, string $plugin_file ): array {
		if ( $this->plugin_basename !== $plugin_file ) {
			return $plugin_meta;
		}

		if ( null !== $this->initialization_error ) {
			$plugin_meta[] = esc_html__( 'Initialization failed. Plugin functions are paused.', 'wp-druid-pulse' );

			return $plugin_meta;
		}

		$plugin_meta[] = esc_html__(
			'Installable foundation. Event tracking and delivery are not available yet.',
			'wp-druid-pulse'
		);

		return $plugin_meta;
	}
}
