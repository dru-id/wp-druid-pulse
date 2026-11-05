<?php

namespace WP_Druid_Pulse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	private string $plugin_file;
	private ?\WP_Error $initialization_error = null;

	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'initialize' ), 2 );

		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_status' ), 10, 2 );
	}

	public function initialize(): void {
		$this->initialization_error = Lifecycle::initialize();

		if ( null !== $this->initialization_error ) {
			if ( is_admin() ) {
				add_action( 'admin_notices', array( $this, 'show_initialization_error' ) );
			}
			return;
		}

		Scheduler::register_hooks();
		add_action( 'rest_api_init', array( $this, 'register_receiver' ) );
		if ( ! is_admin() ) {
			require_once __DIR__ . '/Frontend.php';
			add_action( 'wp_enqueue_scripts', array( new Frontend( $this->plugin_file ), 'enqueue' ) );
		}

		if ( is_admin() ) {
			require_once __DIR__ . '/ViewCatalog.php';
			require_once __DIR__ . '/Admin/TrackingPage.php';
			require_once __DIR__ . '/Admin/ConnectionPage.php';
			( new Admin\TrackingPage( $this->plugin_file ) )->register_hooks();
			( new Admin\ConnectionPage() )->register_hooks();
		}
	}

	public function register_receiver(): void {
		require_once __DIR__ . '/EventMapper.php';
		require_once __DIR__ . '/Rest/EventsController.php';
		( new Rest\EventsController( new EventMapper(), new EventStore() ) )->register_hooks();
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
		if ( plugin_basename( $this->plugin_file ) !== $plugin_file ) {
			return $plugin_meta;
		}

		if ( null !== $this->initialization_error ) {
			$plugin_meta[] = esc_html__( 'Initialization failed. Plugin functions are paused.', 'wp-druid-pulse' );

			return $plugin_meta;
		}

		$plugin_meta[] = esc_html__(
			'Page-view selection and OAuth settings are available under DruID Pulse. Authenticated event reception and browser transport are available. Automatic capture and scheduled delivery are not available yet.',
			'wp-druid-pulse'
		);

		return $plugin_meta;
	}
}
