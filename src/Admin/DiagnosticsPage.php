<?php

namespace WP_Druid_Pulse\Admin;

use WP_Druid_Pulse\{ClickRules, ConnectionSettings, DeliveryState, EventStore, Lifecycle, Scheduler, ViewSelection};

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiagnosticsPage {
	private const SLUG = 'wp-druid-pulse-diagnostics';
	private const ACTION = 'wp_druid_pulse_diagnostics_action';
	private array $screens = array();

	public function __construct( private string $plugin_file, private ?\WP_Error $initialization_error = null ) {}

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function add_menu(): void {
		if ( null !== $this->initialization_error ) {
			$this->screens[] = add_menu_page( 'DruID Pulse', 'DruID Pulse', 'manage_options', 'wp-druid-pulse', array( $this, 'render' ), 'dashicons-chart-area' );
		}
		$this->screens[] = add_submenu_page( 'wp-druid-pulse', __( 'DruID Pulse — Diagnostics', 'wp-druid-pulse' ), __( 'Diagnostics', 'wp-druid-pulse' ), 'manage_options', self::SLUG, array( $this, 'render' ) );
		foreach ( $this->screens as $screen ) {
			add_action( 'load-' . $screen, array( $this, 'validate_filters' ) );
		}
	}

	public function enqueue( string $screen ): void {
		if ( in_array( $screen, $this->screens, true ) ) {
			wp_enqueue_style( 'wp-druid-pulse-diagnostics', plugins_url( 'assets/diagnostics.css', $this->plugin_file ), array(), '0.10.0' );
		}
	}

	public function render(): void {
		$this->require_permission();
		$error = $this->readiness();
		$delivery = DeliveryState::read();
		$views = ViewSelection::read();
		$clicks = ClickRules::read();
		$connection = ConnectionSettings::ready();
		[ $state, $type, $page ] = $this->validate_filters();
		$queue = null;
		if ( Lifecycle::is_current() && null === EventStore::verify() && ! is_wp_error( $delivery ) ) {
			$queue = EventStore::diagnostics( $delivery['retry_at'], $state, $type, (int) $page );
			if ( is_wp_error( $queue ) ) {
				$error = $queue;
				$queue = null;
			}
		}
		$labels = $this->state_labels();
		$header = get_file_data( $this->plugin_file, array( 'version' => 'Version' ) );
		$schema = get_option( 'wp_druid_pulse_schema_version', null );
		$schema = is_scalar( $schema ) && ctype_digit( (string) $schema ) ? (string) $schema : __( 'Unavailable', 'wp-druid-pulse' );
		$tasks = $this->tasks();
		$scheduler_version = is_callable( array( '\ActionScheduler_Versions', 'instance' ) ) ? \ActionScheduler_Versions::instance()->latest_version() : false;
		$feedback = $this->feedback();
		$action = self::ACTION;
		$slug = self::SLUG;
		require dirname( __DIR__, 2 ) . '/templates/admin/diagnostics.php';
	}

	public function validate_filters(): array {
		$this->require_permission();
		$state = $this->query_string( 'state' );
		$type = $this->query_string( 'type' );
		$page = $this->query_string( 'paged' );
		if ( ( '' !== $state && ! in_array( $state, EventStore::DIAGNOSTIC_STATES, true ) )
			|| ( '' !== $type && ! preg_match( '/^[a-z][a-z0-9_:-]{0,63}$/D', $type ) )
			|| ( '' !== $page && ! ctype_digit( $page ) ) ) {
			$this->fail( __( 'Invalid diagnostic filters.', 'wp-druid-pulse' ), 400 );
		}
		return array( $state, $type, (int) $page );
	}

	public function handle(): void {
		$this->require_permission();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? null ) ) {
			$this->fail( __( 'Use the diagnostic forms to perform this action.', 'wp-druid-pulse' ), 405 );
		}
		$nonce = $_POST['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), self::ACTION ) ) {
			$this->fail( __( 'The security check failed. Reload Diagnostics and try again.', 'wp-druid-pulse' ), 403 );
		}
		if ( null !== $this->readiness() ) {
			$this->fail( __( 'Diagnostics is read-only until the installation, queue and scheduler are available.', 'wp-druid-pulse' ), 503 );
		}
		$operation = $_POST['operation'] ?? null;
		$count = 0;
		if ( 'cleanup' === $operation ) {
			try {
				Scheduler::cleanup();
			} catch ( \Exception $exception ) {
				$this->fail( __( 'Maintenance could not finish. Check database access and try again; some eligible records may already have been removed.', 'wp-druid-pulse' ), 500 );
			}
			$result = 'cleaned';
		} elseif ( 'process' === $operation || 'retry' === $operation ) {
			if ( 'retry' === $operation ) {
				$count = EventStore::retry_failed( $this->selected_attempts() );
				if ( is_wp_error( $count ) ) {
					$this->fail( __( 'Failed events could not be requeued. Reload Diagnostics and check database access.', 'wp-druid-pulse' ), 500 );
				}
			}
			$result = 'retry' === $operation && 0 === $count ? 'unchanged' : Scheduler::request_delivery();
			if ( is_wp_error( $result ) ) {
				if ( $count > 0 ) {
					$result = 'retry_unscheduled';
				} else {
					$this->fail( __( 'Delivery could not be scheduled. Check the queue, delivery state and Action Scheduler.', 'wp-druid-pulse' ), 500 );
				}
			}
		} else {
			$this->fail( __( 'Unknown diagnostic action.', 'wp-druid-pulse' ), 400 );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'result' => $result, 'retried' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function selected_attempts(): array {
		$ids = $_POST['event_ids'] ?? null;
		$attempts = $_POST['attempts'] ?? null;
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || ! $ids || count( $ids ) > EventStore::DIAGNOSTIC_PAGE_SIZE || ! is_array( $attempts ) ) {
			$this->fail( __( 'Select up to 25 failed events from the current page.', 'wp-druid-pulse' ), 400 );
		}
		$selected = array();
		foreach ( $ids as $id ) {
			if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || ! is_string( $attempts[ $id ] ?? null )
				|| ! ctype_digit( $attempts[ $id ] ) || strlen( $attempts[ $id ] ) > 10 || (int) $attempts[ $id ] > 4294967295 ) {
				$this->fail( __( 'Invalid selection. Reload Diagnostics before retrying.', 'wp-druid-pulse' ), 400 );
			}
			$selected[ $id ] = (int) $attempts[ $id ];
		}
		return $selected;
	}

	private function readiness(): ?\WP_Error {
		if ( null !== $this->initialization_error ) {
			return $this->initialization_error;
		}
		if ( ! Lifecycle::is_current() ) {
			return new \WP_Error( 'wp_druid_pulse_schema', __( 'The installation schema is incompatible.', 'wp-druid-pulse' ) );
		}
		$error = EventStore::verify() ?? Scheduler::ready();
		$delivery = DeliveryState::read();
		return $error ?? ( is_wp_error( $delivery ) ? $delivery : null );
	}

	private function tasks(): array|\WP_Error {
		$error = Scheduler::ready();
		if ( null !== $error ) {
			return $error;
		}
		try {
			$tasks = array();
			foreach ( array( Scheduler::WAKE_HOOK => __( 'Check delivery (every minute)', 'wp-druid-pulse' ), Scheduler::CLEANUP_HOOK => __( 'Maintenance (hourly)', 'wp-druid-pulse' ), Scheduler::DELIVERY_HOOK => __( 'Delivery worker', 'wp-druid-pulse' ) ) as $hook => $label ) {
				$next = as_next_scheduled_action( $hook, array(), Scheduler::GROUP );
				$tasks[ $label ] = true === $next ? __( 'Pending or running', 'wp-druid-pulse' ) : ( false === $next ? __( 'Not scheduled', 'wp-druid-pulse' ) : wp_date( 'Y-m-d H:i:s', $next ) );
			}
			return $tasks;
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'wp_druid_pulse_scheduler', __( 'Task status is unavailable. Check Action Scheduler.', 'wp-druid-pulse' ) );
		}
	}

	private function feedback(): string {
		$message = match ( $this->query_string( 'result' ) ) {
			'scheduled' => __( 'Delivery was scheduled in the background. This does not confirm that events were sent.', 'wp-druid-pulse' ),
			'already_scheduled' => __( 'A delivery task is already pending or running.', 'wp-druid-pulse' ),
			'paused' => __( 'The common delivery pause is active. The periodic check will resume delivery when due.', 'wp-druid-pulse' ),
			'empty' => __( 'No events are eligible for delivery now. Waiting events keep their retry dates.', 'wp-druid-pulse' ),
			'cleaned' => __( 'Maintenance completed. Expired records and a batch of accepted records were removed; live pending events were preserved.', 'wp-druid-pulse' ),
			'unchanged' => __( 'No events were requeued. The selection is stale, expired or no longer failed.', 'wp-druid-pulse' ),
			'retry_unscheduled' => __( 'Events were requeued, but delivery could not be scheduled. Check Action Scheduler; the periodic check can retry.', 'wp-druid-pulse' ),
			default => '',
		};
		$count = $this->query_string( 'retried' );
		if ( '' !== $message && ctype_digit( $count ) && (int) $count > 0 && (int) $count <= EventStore::DIAGNOSTIC_PAGE_SIZE ) {
			$message = sprintf( __( '%d events returned to pending. ', 'wp-druid-pulse' ), (int) $count ) . $message;
		}
		return $message;
	}

	private function state_labels(): array {
		return array(
			'ready' => __( 'Pending · eligible', 'wp-druid-pulse' ),
			'waiting' => __( 'Pending · waiting', 'wp-druid-pulse' ),
			'failed' => __( 'Failed', 'wp-druid-pulse' ),
			'conflict' => __( 'Conflict · 409', 'wp-druid-pulse' ),
			'expired' => __( 'Expired', 'wp-druid-pulse' ),
			'accepted' => __( 'Accepted · awaiting deletion', 'wp-druid-pulse' ),
			'unknown' => __( 'Unknown state', 'wp-druid-pulse' ),
		);
	}

	private function error_description( string $code ): string {
		if ( '' === $code ) {
			return __( 'No recorded error.', 'wp-druid-pulse' );
		}
		if ( preg_match( '/^wp_druid_pulse_http_([1-5][0-9]{2})$/D', $code, $match ) ) {
			return match ( (int) $match[1] ) {
				400, 413 => __( 'Pulse rejected the event data or size. Check the mapper before retrying.', 'wp-druid-pulse' ),
				409 => __( 'Pulse reported a conflict. Acceptance is unconfirmed; this event cannot be manually retried.', 'wp-druid-pulse' ),
				401, 403 => __( 'Pulse denied authentication or access. Check the connection credentials and permissions.', 'wp-druid-pulse' ),
				422 => __( 'Pulse could not process the identity or event. Check identification and mapping; retry is automatic.', 'wp-druid-pulse' ),
				408, 429 => __( 'Pulse timed out or limited requests. Delivery will retry after the waiting period.', 'wp-druid-pulse' ),
				default => sprintf( __( 'Pulse returned HTTP %d. Check service availability and the connection settings.', 'wp-druid-pulse' ), (int) $match[1] ),
			};
		}
		if ( preg_match( '/^wp_druid_pulse_oauth_(rejected|transport|response)(_[1-5][0-9]{2})?$/D', $code ) ) {
			return __( 'OAuth authentication failed or returned an invalid response. Check credentials, authorization service and connectivity.', 'wp-druid-pulse' );
		}
		return match ( $code ) {
			'wp_druid_pulse_schema', 'wp_druid_pulse_incompatible_schema' => __( 'The saved schema is incompatible. Restore a compatible plugin version or review the installation metadata.', 'wp-druid-pulse' ),
			'wp_druid_pulse_event_store' => __( 'The event table is unavailable or incompatible. Check its schema and database access.', 'wp-druid-pulse' ),
			'wp_druid_pulse_delivery_state' => __( 'Delivery state is invalid or unavailable. Check the saved installation data and database access.', 'wp-druid-pulse' ),
			'wp_druid_pulse_scheduler' => __( 'Action Scheduler is unavailable. Check that it initializes and can access its tables.', 'wp-druid-pulse' ),
			'wp_druid_pulse_transport', 'wp_druid_pulse_response' => __( 'Pulse was unreachable or its response did not confirm acceptance. Delivery will retry automatically.', 'wp-druid-pulse' ),
			'wp_druid_pulse_connection_data', 'wp_druid_pulse_connection_incomplete', 'wp_druid_pulse_installation_data' => __( 'Connection or installation settings are unavailable. Check the configuration.', 'wp-druid-pulse' ),
			'wp_druid_pulse_no_user' => __( 'The WordPress user is unavailable. The event will wait until it can be prepared or expires.', 'wp-druid-pulse' ),
			'wp_druid_pulse_identity_api', 'wp_druid_pulse_no_object_id', 'wp_druid_pulse_no_real_email' => __( 'User identification is unavailable. Check the identity integration and required user data.', 'wp-druid-pulse' ),
			'wp_druid_pulse_unknown_event', 'wp_druid_pulse_event_version' => __( 'The event type or format is unsupported. Check the installed mapper and plugin version.', 'wp-druid-pulse' ),
			'wp_druid_pulse_invalid_event', 'wp_druid_pulse_encoding', 'wp_druid_pulse_event_data', 'wp_druid_pulse_event_date' => __( 'The event could not be prepared from its stored data. Check the mapper before retrying.', 'wp-druid-pulse' ),
			default => __( 'Preparation or storage failed. Check the installation and event mapper; raw error details are not displayed.', 'wp-druid-pulse' ),
		};
	}

	private function date( string $utc ): string {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $utc, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $utc ? wp_date( 'Y-m-d H:i:s', $date->getTimestamp() ) : __( 'Unavailable', 'wp-druid-pulse' );
	}

	private function query_string( string $key ): string {
		return is_string( $_GET[ $key ] ?? null ) ? wp_unslash( $_GET[ $key ] ) : '';
	}

	private function require_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->fail( __( 'You are not allowed to manage DruID Pulse diagnostics.', 'wp-druid-pulse' ), 403 );
		}
	}

	private function fail( string $message, int $status ): never {
		wp_die( esc_html( $message ), esc_html__( 'DruID Pulse diagnostics', 'wp-druid-pulse' ), array( 'response' => $status, 'back_link' => true ) );
		exit;
	}
}
