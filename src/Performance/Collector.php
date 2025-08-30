<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Performance;

use AzureInsightsWonolog\Plugin;

class Collector {
	/** @var array<string,float> */
	private array $start_times = [];
	/** @var array<int,array{name:string,value:float|int,properties:array<string,string>}> */
	private array $metrics = [];
	private bool $enabled;
	private int $threshold_ms;
	private int $slow_query_threshold_ms;
	private ?float $cron_start = null;

	public function __construct( bool $enabled = true, int $threshold_ms = 150 ) {
		$this->enabled = $enabled;
		// Allow dynamic override via option if available
		if ( function_exists( 'get_option' ) ) {
			$opt = get_option( 'aiw_slow_hook_threshold_ms' );
			if ( $opt !== false ) {
				$threshold_ms = (int) $opt;
			}
			$sq                            = get_option( 'aiw_slow_query_threshold_ms', 500 );
			$this->slow_query_threshold_ms = max( 1, (int) $sq );
		} else {
			$this->slow_query_threshold_ms = 500;
		}
		$this->threshold_ms = $threshold_ms;
		// Cron context detection
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$this->cron_start = microtime( true );
		}
	}

	public function hook(): void {
		if ( ! $this->enabled || ! function_exists( 'add_action' ) ) {
			return;
		}
		$hooks = [ 'plugins_loaded', 'init', 'wp_loaded', 'parse_request', 'wp', 'template_redirect', 'send_headers' ];
		foreach ( $hooks as $h ) {
			$this->instrument( $h );
		}
		add_action( 'shutdown', [ $this, 'finalize' ], 5 );
	}

	private function instrument( string $hook ): void {
		if ( function_exists( 'add_action' ) ) {
			add_action( $hook, function () use ($hook) {
				$this->start_times[ $hook ] = microtime( true );
			}, -999 );
			add_action( $hook, function () use ($hook) {
				if ( isset( $this->start_times[ $hook ] ) ) {
					$dur = ( microtime( true ) - $this->start_times[ $hook ] ) * 1000;
					if ( $dur >= $this->threshold_ms ) {
						$this->metrics[] = [ 'name' => 'hook_duration_ms', 'value' => (int) $dur, 'properties' => [ 'hook' => $hook ] ];
					}
				}
			}, 999 );
		}
	}

	public function finalize(): void {
		if ( ! $this->enabled ) {
			return;
		}
		// Add memory peak metric
		$mem_peak        = memory_get_peak_usage( true );
		$this->metrics[] = [ 'name' => 'memory_peak_bytes', 'value' => (float) $mem_peak, 'properties' => [] ];
		// DB query stats if available + slow query detection
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && isset( $GLOBALS[ 'wpdb' ] ) && is_array( $GLOBALS[ 'wpdb' ]->queries ?? null ) ) {
			$total_time = 0.0;
			$count      = count( $GLOBALS[ 'wpdb' ]->queries );
			$slowCount  = 0;
			foreach ( $GLOBALS[ 'wpdb' ]->queries as $q ) {
				// Each entry: [ sql, time, caller ]
				if ( isset( $q[ 1 ] ) ) {
					$dur_ms     = (float) $q[ 1 ] * 1000;
					$total_time += (float) $q[ 1 ];
					if ( $dur_ms >= $this->slow_query_threshold_ms ) {
						$slowCount++;
						// Add a metric per slow query (capped to avoid explosion)
						if ( $slowCount <= 25 ) {
							$this->metrics[] = [ 'name' => 'db_slow_query_ms', 'value' => $dur_ms, 'properties' => [] ];
						}
					}
				}
			}
			$this->metrics[] = [ 'name' => 'db_query_count', 'value' => (float) $count, 'properties' => [] ];
			$this->metrics[] = [ 'name' => 'db_query_time_ms', 'value' => (float) ( $total_time * 1000 ), 'properties' => [] ];
			$this->metrics[] = [ 'name' => 'db_slow_query_count', 'value' => (float) $slowCount, 'properties' => [ 'threshold_ms' => (string) $this->slow_query_threshold_ms ] ];
		}

		// Cron run duration metric if executing in cron context
		if ( $this->cron_start ) {
			$cronDur         = ( microtime( true ) - $this->cron_start ) * 1000;
			$this->metrics[] = [ 'name' => 'cron_run_duration_ms', 'value' => (float) $cronDur, 'properties' => [] ];
		}
		if ( empty( $this->metrics ) ) {
			return;
		}
		$plugin    = Plugin::instance();
		$telemetry = $plugin->telemetry();
		$corr      = $plugin->correlation();
		foreach ( $this->metrics as $m ) {
			$item = $telemetry->build_metric_item( $m[ 'name' ], (float) $m[ 'value' ], $m[ 'properties' ], $corr->trace_id(), $corr->span_id() );
			$telemetry->add( $item );
		}
	}
}
