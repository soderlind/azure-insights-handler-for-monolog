<?php
declare(strict_types=1);
namespace AzureInsightsWonolog;

use AzureInsightsWonolog\Telemetry\Correlation;
use AzureInsightsWonolog\Telemetry\TelemetryClient;
use AzureInsightsWonolog\Telemetry\MockTelemetryClient;
use AzureInsightsWonolog\Handler\AzureInsightsHandler;
use AzureInsightsWonolog\Queue\RetryQueue;
use AzureInsightsWonolog\Admin\SettingsPage;
use AzureInsightsWonolog\Performance\Collector;
use AzureInsightsWonolog\Admin\MockViewer;
use AzureInsightsWonolog\Admin\RetryQueueViewer;
use AzureInsightsWonolog\Admin\StatusPanel;

/**
 * Core plugin bootstrap singleton.
 */
class Plugin {
	private static ?Plugin $instance = null; // @var Plugin

	private ?TelemetryClient $telemetry_client = null;
	private ?Correlation $correlation = null;
	private ?AzureInsightsHandler $handler = null;
	/** @var array<int,mixed> */
	private array $buffer = [];
	private float $request_start = 0.0;
	private ?RetryQueue $retry_queue = null;
	private ?Collector $performance_collector = null;

	/**
	 * Get singleton instance.
	 */
	public static function instance(): Plugin {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Activation hook */
	public static function activate(): void {
		// Schedule retry cron if not exists.
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'aiw_process_retry_queue' ) ) {
			if ( function_exists( 'wp_schedule_event' ) ) {
				wp_schedule_event( time() + 60, 'hourly', 'aiw_process_retry_queue' );
			}
		}
		// If there are pending retry items, schedule a near-immediate single run to drain without waiting for the hourly event.
		if ( function_exists( 'get_option' ) && function_exists( 'wp_schedule_single_event' ) ) {
			$pending_retry = get_option( 'aiw_retry_queue_v1', [] );
			if ( is_array( $pending_retry ) && ! empty( $pending_retry ) && ( ! ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'aiw_process_retry_queue' ) ) ) ) {
				wp_schedule_single_event( time() + 5, 'aiw_process_retry_queue' );
			}
			// If async batches were left over (e.g., site update), schedule a flush soon.
			$pending_batches = get_option( 'aiw_async_batches', [] );
			if ( is_array( $pending_batches ) && ! empty( $pending_batches ) && ! ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'aiw_async_flush' ) ) ) {
				wp_schedule_single_event( time() + 5, 'aiw_async_flush' );
			}
		}
	}

	/** Deactivation hook */
	public static function deactivate(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'aiw_process_retry_queue' );
		}
	}

	/** Bootstrap runtime */
	public function boot(): void {
		// Prepare correlation first.
		$this->correlation = new Correlation();
		$this->correlation->init();

		$this->request_start = microtime( true );
		$config              = $this->load_config();
		// Async flush hook
		if ( function_exists( 'add_action' ) ) {
			add_action( 'aiw_async_flush', function () {
				try {
					$batches = function_exists( 'get_option' ) ? get_option( 'aiw_async_batches', [] ) : [];
					if ( ! is_array( $batches ) || empty( $batches ) ) {
						return;
					}
					if ( function_exists( 'update_option' ) ) {
						update_option( 'aiw_async_batches', [] );
					}
					foreach ( $batches as $batch ) {
						$lines = $batch[ 'lines' ] ?? [];
						if ( $lines ) {
							// Reconstruct items minimally for retry if needed
							$items = array_map( function ($line) {
								return json_decode( $line, true );
							}, $lines );
							$this->telemetry_client->send_lines( $lines, $items );
						}
					}
				} catch (\Throwable $e) {
				}
			} );
		}
		$use_mock_option = function_exists( 'get_option' ) ? (bool) get_option( 'aiw_use_mock', false ) : false;
		$use_mock        = $use_mock_option;
		if ( function_exists( 'apply_filters' ) ) {
			$use_mock = apply_filters( 'aiw_use_mock_telemetry', $use_mock, $config );
		}
		$this->telemetry_client = $use_mock ? new MockTelemetryClient( $config ) : new TelemetryClient( $config );
		$this->retry_queue      = new RetryQueue( [ 60, 300, 900, 3600 ] );
		$this->telemetry_client->set_failure_callback( function (array $failed_batch) {
			$this->retry_queue->enqueue( $failed_batch );
		} );
		$level         = $this->convert_min_level_to_monolog( $config[ 'min_level' ] );
		$this->handler = new AzureInsightsHandler( $this->telemetry_client, $config, $level );

		// Admin settings UI handling (single-site vs multisite + network activation logic)
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$network_active = false;
			if ( defined( 'AIW_PLUGIN_FILE' ) && function_exists( 'plugin_basename' ) && function_exists( 'is_plugin_active_for_network' ) ) {
				$basename       = plugin_basename( AIW_PLUGIN_FILE );
				$network_active = is_plugin_active_for_network( $basename );
			}
			if ( $network_active ) {
				// When network-activated: suppress per-site page, expose only a network settings page in network admin.
				if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
					( new Admin\NetworkSettingsPage() )->register();
					if ( $use_mock ) {
						( new MockViewer() )->register();
					}
					( new RetryQueueViewer() )->register();
					( new StatusPanel() )->register();
				}
			} else {
				// Multisite but NOT network-activated: behave like normal per-site activation.
				if ( function_exists( 'is_admin' ) && is_admin() && ( ! function_exists( 'is_network_admin' ) || ! is_network_admin() ) ) {
					( new SettingsPage() )->register();
					if ( $use_mock ) {
						( new MockViewer() )->register();
					}
					( new RetryQueueViewer() )->register();
					( new StatusPanel() )->register();
				}
			}
		} elseif ( function_exists( 'is_admin' ) && is_admin() ) {
			// Single-site install: always show per-site settings page.
			( new SettingsPage() )->register();
			if ( $use_mock ) {
				( new MockViewer() )->register();
			}
			( new RetryQueueViewer() )->register();
			( new StatusPanel() )->register();
		}

		$enable_perf = function_exists( 'get_option' ) ? (bool) get_option( 'aiw_enable_performance', true ) : true;
		$enable_diag = function_exists( 'get_option' ) ? (bool) get_option( 'aiw_enable_internal_diagnostics', false ) : false;
		if ( $enable_perf ) {
			$this->performance_collector = new Collector( true );
			$this->performance_collector->hook();
		}
		if ( $enable_diag && function_exists( 'add_action' ) ) {
			add_action( 'aiw_internal_diagnostic', function ($code, $message, $context = []) {
				$line = 'AIW diagnostic [' . $code . '] ' . $message;
				if ( $context )
					$line .= ' ' . wp_json_encode( $context );
				if ( function_exists( 'error_log' ) )
					@error_log( $line );
			}, 10, 3 );
		}

		// Hook into Wonolog if present.
		if ( function_exists( 'add_action' ) ) {
			// Attempt Wonolog v3 integration: push handler onto the logger instance after Wonolog setup.
			// Wonolog v3 (bundled) integration: push our handler directly. Legacy hook fallback removed (dependency enforces v3+).
			if ( class_exists( '\\AzureInsightsWonolog\\Integration\\WonologIntegration' ) ) {
				$integration = new Integration\WonologIntegration( $this->handler );
				// Immediate attempt (may be a NullLogger early).
				$integration->attach();
				// Defer: once Wonolog finished setup ensure handler attached.
				add_action( 'wonolog.loaded', function () use ( $integration ) {
					$integration->attach();
				}, PHP_INT_MAX );
				// Late retry on init in case site loaded plugin before Wonolog fully bootstrapped (defensive).
				add_action( 'init', function () use ( $integration ) {
					$integration->attach();
				}, 20 );
			}
			// Request telemetry on shutdown.
			add_action( 'shutdown', [ $this, 'shutdown_flush' ], 9999 );
			add_action( 'aiw_process_retry_queue', [ $this, 'process_retry_queue' ] );
			// Inject correlation headers into outbound HTTP requests when enabled (filter allows control)
			if ( function_exists( 'add_filter' ) ) {
				add_filter( 'http_request_args', function ($args, $url) {
					$enable = true;
					if ( function_exists( 'apply_filters' ) ) {
						$enable = apply_filters( 'aiw_propagate_correlation', $enable, $url, $args );
					}
					if ( ! $enable ) {
						return $args;
					}
					if ( ! isset( $args[ 'headers' ] ) || ! is_array( $args[ 'headers' ] ) ) {
						$args[ 'headers' ] = [];
					}
					$args[ 'headers' ][ 'traceparent' ] = $this->correlation->traceparent_header();
					return $args;
				}, 10, 2 );
			}
		}
	}

	private function load_config(): array {
		$getOpt = function ($k, $d = null) {
			return function_exists( 'get_option' ) ? get_option( $k, $d ) : $d;
		};
		// Environment variable support (precedence: constant > env > option)
		$env_conn = getenv( 'AIW_CONNECTION_STRING' );
		if ( ! $env_conn ) {
			// Accept alternate common variable names if present
			$env_conn = getenv( 'APPLICATIONINSIGHTS_CONNECTION_STRING' ) ?: getenv( 'APPINSIGHTS_CONNECTION_STRING' );
		}
		$env_ikey = getenv( 'AIW_INSTRUMENTATION_KEY' );
		if ( ! $env_ikey ) {
			$env_ikey = getenv( 'APPLICATIONINSIGHTS_INSTRUMENTATION_KEY' );
		}
		// Helper to pull from site options if multisite
		$siteOpt  = function ($k, $d  = null) {
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site_option' ) ) {
				return get_site_option( $k, $d );
			}
			return $d;
		};
		$defaults = [ 
			'connection_string'    => defined( 'AIW_CONNECTION_STRING' ) ? AIW_CONNECTION_STRING : ( ( is_string( $env_conn ) && $env_conn !== '' ) ? $env_conn : ( $siteOpt( 'aiw_connection_string' ) ?: $getOpt( 'aiw_connection_string' ) ) ),
			'instrumentation_key'  => defined( 'AIW_INSTRUMENTATION_KEY' ) ? AIW_INSTRUMENTATION_KEY : ( ( is_string( $env_ikey ) && $env_ikey !== '' ) ? $env_ikey : ( $siteOpt( 'aiw_instrumentation_key' ) ?: $getOpt( 'aiw_instrumentation_key' ) ) ),
			'min_level'            => defined( 'AIW_MIN_LEVEL' ) ? AIW_MIN_LEVEL : ( $siteOpt( 'aiw_min_level' ) ?: ( $getOpt( 'aiw_min_level' ) ?: 'warning' ) ),
			'sampling_rate'        => defined( 'AIW_SAMPLING_RATE' ) ? (float) AIW_SAMPLING_RATE : (float) ( $siteOpt( 'aiw_sampling_rate', $getOpt( 'aiw_sampling_rate', 1 ) ) ),
			'batch_max_size'       => (int) ( $siteOpt( 'aiw_batch_max_size' ) ?? $getOpt( 'aiw_batch_max_size', 20 ) ),
			'batch_flush_interval' => (int) ( $siteOpt( 'aiw_batch_flush_interval' ) ?? $getOpt( 'aiw_batch_flush_interval', 5 ) ),
			'async_enabled'        => (bool) ( $siteOpt( 'aiw_async_enabled' ) ?? $getOpt( 'aiw_async_enabled', false ) ),
		];
		// Decrypt if encrypted
		if ( isset( $defaults[ 'connection_string' ] ) && \AzureInsightsWonolog\Security\Secrets::is_encrypted( $defaults[ 'connection_string' ] ) ) {
			$defaults[ 'connection_string' ] = \AzureInsightsWonolog\Security\Secrets::decrypt( $defaults[ 'connection_string' ] );
		}
		if ( isset( $defaults[ 'instrumentation_key' ] ) && \AzureInsightsWonolog\Security\Secrets::is_encrypted( $defaults[ 'instrumentation_key' ] ) ) {
			$defaults[ 'instrumentation_key' ] = \AzureInsightsWonolog\Security\Secrets::decrypt( $defaults[ 'instrumentation_key' ] );
		}
		// Parse connection string if provided: InstrumentationKey=...;IngestionEndpoint=...;
		if ( ! empty( $defaults[ 'connection_string' ] ) && is_string( $defaults[ 'connection_string' ] ) ) {
			$parts = array_filter( array_map( 'trim', explode( ';', $defaults[ 'connection_string' ] ) ) );
			foreach ( $parts as $p ) {
				if ( strpos( $p, '=' ) !== false ) {
					list( $k, $v ) = array_map( 'trim', explode( '=', $p, 2 ) );
					$k             = strtolower( $k );
					if ( $k === 'instrumentationkey' && empty( $defaults[ 'instrumentation_key' ] ) ) {
						$defaults[ 'instrumentation_key' ] = $v;
					} elseif ( $k === 'ingestionendpoint' ) {
						$defaults[ 'ingest_url' ] = rtrim( $v, '/' ) . '/v2/track';
					}
				}
			}
		}
		// Basic validation: ensure instrumentation key appears GUID-like.
		if ( ! empty( $defaults[ 'instrumentation_key' ] ) && ! preg_match( '/^[a-f0-9-]{32,36}$/i', $defaults[ 'instrumentation_key' ] ) ) {
			// Invalid key; prevent sending to random endpoint.
			$defaults[ 'instrumentation_key' ] = '';
		}
		return $defaults;
	}

	private function convert_min_level_to_monolog( string $level_slug ): int {
		$map        = [ 
			'debug'     => \Monolog\Logger::DEBUG,
			'info'      => \Monolog\Logger::INFO,
			'notice'    => \Monolog\Logger::NOTICE,
			'warning'   => \Monolog\Logger::WARNING,
			'error'     => \Monolog\Logger::ERROR,
			'critical'  => \Monolog\Logger::CRITICAL,
			'alert'     => \Monolog\Logger::ALERT,
			'emergency' => \Monolog\Logger::EMERGENCY,
		];
		$level_slug = strtolower( trim( $level_slug ) );
		return $map[ $level_slug ] ?? \Monolog\Logger::WARNING;
	}

	/** Flush on shutdown */
	public function shutdown_flush(): void {
		if ( $this->telemetry_client ) {
			$this->record_request_telemetry();
			$this->telemetry_client->flush();
		}
	}

	public function process_retry_queue(): void {
		if ( ! $this->retry_queue )
			return;
		$due = $this->retry_queue->due();
		if ( empty( $due ) )
			return;
		foreach ( $due as $index => $item ) {
			$success = $this->resend_batch( $item[ 'batch' ] );
			$this->retry_queue->mark_attempt( $index, $success );
		}
	}

	private function resend_batch( array $batch ): bool {
		if ( empty( $batch ) )
			return true;
		$payload_lines = [];
		foreach ( $batch as $i ) {
			$payload_lines[] = wp_json_encode( $i );
		}
		$args = [ 'body' => implode( "\n", $payload_lines ), 'timeout' => 3, 'blocking' => true, 'headers' => [ 'Content-Type' => 'application/x-json-stream' ] ];
		if ( function_exists( 'wp_remote_post' ) ) {
			$response = wp_remote_post( 'https://dc.services.visualstudio.com/v2/track', $args );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) )
				return false;
			$code = function_exists( 'wp_remote_retrieve_response_code' ) ? wp_remote_retrieve_response_code( $response ) : 0;
			return $code >= 200 && $code < 300;
		}
		return false;
	}

	private function record_request_telemetry(): void {
		try {
			$corr        = $this->correlation();
			$duration_ms = ( microtime( true ) - $this->request_start ) * 1000;
			$method      = isset( $_SERVER[ 'REQUEST_METHOD' ] ) ? ( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( wp_unslash( $_SERVER[ 'REQUEST_METHOD' ] ) ) : $_SERVER[ 'REQUEST_METHOD' ] ) : 'GET';
			$uri         = isset( $_SERVER[ 'REQUEST_URI' ] ) ? ( function_exists( 'esc_url_raw' ) ? esc_url_raw( wp_unslash( $_SERVER[ 'REQUEST_URI' ] ) ) : $_SERVER[ 'REQUEST_URI' ] ) : '/';
			$code        = function_exists( 'http_response_code' ) ? ( http_response_code() ?: 200 ) : 200;
			$full_url    = function_exists( 'home_url' ) ? home_url( $uri ) : ( ( isset( $_SERVER[ 'HTTP_HOST' ] ) ? 'https://' . $_SERVER[ 'HTTP_HOST' ] : '' ) . $uri );
			$item        = $this->telemetry_client->build_request_item( [ 
				'name' => $method . ' ' . $uri,
				'code' => (string) $code,
				'url'  => $full_url,
			], $duration_ms, $corr->trace_id(), $corr->span_id(), $corr->parent_span_id() );
			// Merge baseline & enrichment into request properties
			if ( isset( $item[ 'data' ][ 'baseData' ][ 'properties' ] ) && is_array( $item[ 'data' ][ 'baseData' ][ 'properties' ] ) ) {
				$base                                         = method_exists( $this->telemetry_client, 'baseline_properties' ) ? $this->telemetry_client->baseline_properties() : [];
				$item[ 'data' ][ 'baseData' ][ 'properties' ] = array_merge( $base, $item[ 'data' ][ 'baseData' ][ 'properties' ] );
				if ( function_exists( 'apply_filters' ) ) {
					$item[ 'data' ][ 'baseData' ][ 'properties' ] = apply_filters( 'aiw_enrich_dimensions', $item[ 'data' ][ 'baseData' ][ 'properties' ], [ 'type' => 'request' ] );
				}
			}
			$this->telemetry_client->add( $item );
		} catch (\Throwable $e) {
			// Silently ignore request telemetry failures (avoid impacting user facing response).
		}
	}

	public function telemetry(): TelemetryClient {
		return $this->telemetry_client ?? new TelemetryClient( [] ); // Fallback; should be initialized in boot()
	}
	public function correlation(): Correlation {
		return $this->correlation ?? new Correlation();
	}
}
