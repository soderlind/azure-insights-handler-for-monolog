<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Telemetry;

/**
 * Mock telemetry client: stores items in memory, never sends HTTP.
 * Enable via filter: add_filter('aiw_use_mock_telemetry', '__return_true');
 */
class MockTelemetryClient extends TelemetryClient {
	/** @var array<int,array<string,mixed>> */
	private array $sent = [];

	public function __construct( array $config ) {
		parent::__construct( $config );
		// Load previously persisted mock items (development convenience) if in WP context.
		if ( function_exists( 'get_option' ) ) {
			$stored = get_option( 'aiw_mock_telemetry_items', [] );
			if ( is_array( $stored ) ) {
				$this->sent = $stored;
			}
		}
	}

	/** In mock mode we want immediate visibility; flush after each add. */
	public function add( array $item ): void {
		// Use parent buffering logic then force flush so viewer reflects latest.
		parent::add( $item );
		$this->flush();
	}

	public function flush(): void {
		if ( empty( $this->buffer_snapshot() ) )
			return;
		$this->sent = array_merge( $this->sent, $this->buffer_snapshot() );
		$this->clear_buffer();
		// Persist a bounded history so admin viewer can display items across requests.
		if ( function_exists( 'update_option' ) ) {
			$max     = 200; // retain last 200 items
			$trimmed = array_slice( $this->sent, -$max );
			update_option( 'aiw_mock_telemetry_items', $trimmed );
			$this->sent = $trimmed;
		}
	}

	// Expose buffer utilities (wrap protected behavior using reflection since original properties private)
	/** @return array<int,array<string,mixed>> */
	private function buffer_snapshot(): array {
		$ref  = new \ReflectionClass( TelemetryClient::class);
		$prop = $ref->getProperty( 'buffer' );
		$prop->setAccessible( true );
		return $prop->getValue( $this );
	}
	private function clear_buffer(): void {
		$ref  = new \ReflectionClass( TelemetryClient::class);
		$prop = $ref->getProperty( 'buffer' );
		$prop->setAccessible( true );
		$prop->setValue( $this, [] );
	}

	/** @return array<int,array<string,mixed>> */
	public function sent_items(): array {
		return $this->sent;
	}

	/** Return all items (flushed so far). */
	public function all_items(): array {
		return $this->sent;
	}
}
