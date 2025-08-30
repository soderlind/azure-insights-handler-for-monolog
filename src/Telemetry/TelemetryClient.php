<?php
namespace AzureInsightsWonolog\Telemetry;

/**
 * Lightweight telemetry client handling buffering & sending to Azure.
 */
class TelemetryClient {
	private $config;
	private $buffer = [];
	private $last_flush_time;
	private $on_failure; // callable|null
	private $ingest_url; // resolved ingest endpoint
	private $last_payload_lines = []; // debug/testing
	private $transport; // BatchTransport

	const DEFAULT_INGEST_URL = 'https://dc.services.visualstudio.com/v2/track';

	public function __construct( array $config ) {
		$this->config          = $config;
		$this->last_flush_time = microtime( true );
		$this->ingest_url      = isset( $config[ 'ingest_url' ] ) && $config[ 'ingest_url' ] ? $config[ 'ingest_url' ] : self::DEFAULT_INGEST_URL;
		$this->transport       = new BatchTransport( $this->ingest_url );
	}

	public function set_failure_callback( callable $cb ) {
		$this->on_failure = $cb;
	}

	public function add( array $item ) {
		$this->buffer[] = $item;
		if ( count( $this->buffer ) >= (int) $this->config[ 'batch_max_size' ] ) {
			$this->flush();
			return;
		}
		$elapsed = microtime( true ) - $this->last_flush_time;
		if ( $elapsed >= (int) $this->config[ 'batch_flush_interval' ] ) {
			$this->flush();
		}
	}

	public function flush() {
		if ( empty( $this->buffer ) ) {
			return;
		}
		$payload_lines = [];
		foreach ( $this->buffer as $item ) {
			$payload_lines[] = wp_json_encode( $item );
		}
		// Allow last-minute mutation / enrichment of raw payload lines.
		if ( function_exists( 'apply_filters' ) ) {
			$payload_lines = apply_filters( 'aiw_before_send_batch', $payload_lines, $this->config );
		}
		$this->last_payload_lines = $payload_lines;
		$async                    = ! empty( $this->config[ 'async_enabled' ] );
		$in_background            = ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( $async && function_exists( 'wp_schedule_single_event' ) && ! $in_background ) {
			// Queue batch for async cron handling.
			$batches = function_exists( 'get_option' ) ? get_option( 'aiw_async_batches', [] ) : [];
			if ( ! is_array( $batches ) ) {
				$batches = [];
			}
			// Cap stored batches to avoid unbounded growth
			if ( count( $batches ) > 10 ) {
				$batches = array_slice( $batches, -10 );
			}
			$batches[] = [ 'lines' => $payload_lines, 'time' => time() ];
			if ( function_exists( 'update_option' ) ) {
				update_option( 'aiw_async_batches', $batches );
			}
			// Schedule a single event shortly to process batches.
			if ( ! function_exists( 'wp_next_scheduled' ) || ! wp_next_scheduled( 'aiw_async_flush' ) ) {
				wp_schedule_single_event( time() + 1, 'aiw_async_flush' );
			}
			$this->buffer          = [];
			$this->last_flush_time = microtime( true );
			return; // Defer actual send.
		}
		// Synchronous send path.
		$this->send_lines( $payload_lines, $this->buffer );
		$this->buffer = [];
	}

	/**
	 * Internal: send already-encoded payload lines now.
	 * @param array $payload_lines Array of JSON strings (one per telemetry item)
	 * @param array $original_items Original item array (used for retry callback)
	 */
	public function send_lines( array $payload_lines, array $original_items ): void {
		if ( empty( $payload_lines ) ) {
			return;
		}
		$this->transport->send( $payload_lines, $original_items, $this->on_failure );
		$this->last_flush_time = microtime( true );
	}

	/**
	 * Test helper: returns current unsent buffer (do not use in production logic).
	 */
	public function debug_get_buffer(): array {
		return $this->buffer;
	}

	/** Debug helper: last payload lines passed to send (after filter) */
	public function debug_last_payload_lines(): array {
		return $this->last_payload_lines;
	}

	public function build_trace_item( array $record, string $trace_id, string $span_id ): array {
		$time                     = gmdate( 'c' );
		$severity                 = $this->map_level( $record[ 'level' ] ?? 200 );
		$message                  = $record[ 'message' ] ?? '';
		$dimensions               = $record[ 'context' ] ?? [];
		$dimensions[ 'trace_id' ] = $trace_id;
		$dimensions[ 'span_id' ]  = $span_id;

		return [ 
			'name' => 'Microsoft.ApplicationInsights.Message',
			'time' => $time,
			'iKey' => $this->config[ 'instrumentation_key' ] ?? '',
			'tags' => [ 
				'ai.operation.id'       => $trace_id,
				'ai.operation.parentId' => $span_id,
			],
			'data' => [ 
				'baseType' => 'MessageData',
				'baseData' => [ 
					'message'       => $message,
					'severityLevel' => $severity,
					'properties'    => $dimensions,
				],
			],
		];
	}

	/** Build an Event telemetry item */
	public function build_event_item( string $name, array $properties, array $measurements, string $trace_id, string $span_id ): array {
		$time                     = gmdate( 'c' );
		$properties[ 'trace_id' ] = $trace_id;
		$properties[ 'span_id' ]  = $span_id;
		return [ 
			'name' => 'Microsoft.ApplicationInsights.Event',
			'time' => $time,
			'iKey' => $this->config[ 'instrumentation_key' ] ?? '',
			'tags' => [ 
				'ai.operation.id'       => $trace_id,
				'ai.operation.parentId' => $span_id,
			],
			'data' => [ 
				'baseType' => 'EventData',
				'baseData' => [ 
					'name'         => $name,
					'properties'   => $properties,
					'measurements' => $measurements,
				],
			],
		];
	}

	private function format_duration_timespan( float $duration_ms ): string {
		$total_ms      = (int) round( $duration_ms );
		$ms            = $total_ms % 1000;
		$total_seconds = intdiv( $total_ms, 1000 );
		$seconds       = $total_seconds % 60;
		$total_minutes = intdiv( $total_seconds, 60 );
		$minutes       = $total_minutes % 60;
		$hours         = intdiv( $total_minutes, 60 );
		return sprintf( '%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $ms );
	}

	public function build_request_item( array $data, float $duration_ms, string $trace_id, string $span_id, ?string $parent_span_id ): array {
		$time = gmdate( 'c' );
		return [ 
			'name' => 'Microsoft.ApplicationInsights.Request',
			'time' => $time,
			'iKey' => $this->config[ 'instrumentation_key' ] ?? '',
			'tags' => [ 
				'ai.operation.id'       => $trace_id,
				'ai.operation.parentId' => $parent_span_id ?: $span_id,
			],
			'data' => [ 
				'baseType' => 'RequestData',
				'baseData' => [ 
					'id'           => $span_id,
					'name'         => $data[ 'name' ] ?? 'request',
					'url'          => $data[ 'url' ] ?? '',
					'success'      => ( isset( $data[ 'code' ] ) && (int) $data[ 'code' ] < 500 ),
					'responseCode' => (string) ( $data[ 'code' ] ?? '200' ),
					'duration'     => $this->format_duration_timespan( $duration_ms ),
					'properties'   => [ 
						'trace_id'       => $trace_id,
						'span_id'        => $span_id,
						'parent_span_id' => $parent_span_id,
					],
				],
			],
		];
	}

	public function build_exception_item( \Throwable $exception, array $record, string $trace_id, string $span_id ): array {
		$time     = gmdate( 'c' );
		$severity = $this->map_level( $record[ 'level' ] ?? 400 );
		$stack    = $exception->getTrace();
		$frames   = [];
		foreach ( $stack as $frame ) {
			$frames[] = [ 
				'fileName' => isset( $frame[ 'file' ] ) ? $frame[ 'file' ] : 'unknown',
				'line'     => isset( $frame[ 'line' ] ) ? (int) $frame[ 'line' ] : 0,
				'method'   => ( $frame[ 'class' ] ?? '' ) . ( isset( $frame[ 'type' ] ) ? $frame[ 'type' ] : '' ) . ( $frame[ 'function' ] ?? '' ),
			];
		}
		return [ 
			'name' => 'Microsoft.ApplicationInsights.Exception',
			'time' => $time,
			'iKey' => $this->config[ 'instrumentation_key' ] ?? '',
			'tags' => [ 
				'ai.operation.id'       => $trace_id,
				'ai.operation.parentId' => $span_id,
			],
			'data' => [ 
				'baseType' => 'ExceptionData',
				'baseData' => [ 
					'exceptions'    => [ 
						[ 
							'typeName'     => get_class( $exception ),
							'message'      => $exception->getMessage(),
							'hasFullStack' => true,
							'parsedStack'  => $frames,
						],
					],
					'severityLevel' => $severity,
					'properties'    => [ 
						'trace_id' => $trace_id,
						'span_id'  => $span_id,
					],
				],
			],
		];
	}

	private function map_level( int $level ): int {
		if ( $level >= 550 )
			return 4; // emergency/alert
		if ( $level >= 500 )
			return 4; // critical
		if ( $level >= 400 )
			return 3; // error
		if ( $level >= 300 )
			return 2; // warning
		if ( $level >= 200 )
			return 1; // info/notice
		return 0; // debug
	}

	/** Build a metric telemetry item */
	public function build_metric_item( string $name, float $value, array $properties, string $trace_id, string $span_id ): array {
		$time                     = gmdate( 'c' );
		$properties[ 'trace_id' ] = $trace_id;
		$properties[ 'span_id' ]  = $span_id;
		return [ 
			'name' => 'Microsoft.ApplicationInsights.Metric',
			'time' => $time,
			'iKey' => $this->config[ 'instrumentation_key' ] ?? '',
			'tags' => [ 
				'ai.operation.id'       => $trace_id,
				'ai.operation.parentId' => $span_id,
			],
			'data' => [ 
				'baseType' => 'MetricData',
				'baseData' => [ 
					'metrics'    => [ [ 'name' => $name, 'value' => $value ] ],
					'properties' => $properties,
				],
			],
		];
	}

	public function ingest_url(): string {
		return $this->ingest_url;
	}

	/** Build baseline properties (site/env info). */
	public function baseline_properties(): array {
		$props = [];
		if ( function_exists( 'home_url' ) ) {
			$props[ 'site_url' ] = (string) home_url();
		}
		if ( defined( 'WP_ENV' ) ) {
			$props[ 'environment' ] = WP_ENV;
		}
		$props[ 'php_version' ] = PHP_VERSION;
		if ( defined( 'WP_VERSION' ) ) {
			$props[ 'wp_version' ] = WP_VERSION;
		}
		if ( defined( 'AIW_PLUGIN_VERSION' ) ) {
			$props[ 'plugin_version' ] = AIW_PLUGIN_VERSION;
		}
		// Multisite blog id
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_current_blog_id' ) ) {
			$props[ 'blog_id' ] = (string) get_current_blog_id();
		}
		// Request method / URI (avoid query string PII by trimming length & removing sensitive parameters basic heuristic)
		if ( isset( $_SERVER[ 'REQUEST_METHOD' ] ) ) {
			$props[ 'request_method' ] = substr( (string) $_SERVER[ 'REQUEST_METHOD' ], 0, 10 );
		}
		if ( isset( $_SERVER[ 'REQUEST_URI' ] ) ) {
			$uri = (string) $_SERVER[ 'REQUEST_URI' ];
			// Basic scrubbing: replace potential password/email param values
			$uri                    = preg_replace( '/((?:pass|pwd|token|email)=[^&#]+)/i', '$1[REDACTED]', $uri );
			$props[ 'request_uri' ] = substr( $uri, 0, 300 );
		}
		if ( isset( $this->config[ 'sampling_rate' ] ) ) {
			$props[ 'sampling_rate' ] = (string) $this->config[ 'sampling_rate' ];
		}
		// Add hashed user id if logged in (privacy preservation)
		if ( function_exists( 'is_user_logged_in' ) && function_exists( 'get_current_user_id' ) && function_exists( 'wp_salt' ) ) {
			if ( is_user_logged_in() ) {
				$uid                  = (string) get_current_user_id();
				$props[ 'user_hash' ] = substr( hash( 'sha256', $uid . '|' . wp_salt( 'auth' ) ), 0, 32 );
			}
		}
		return $props;
	}
}
