<?php
namespace AzureInsightsWonolog\Tests;

use PHPUnit\Framework\TestCase;
use AzureInsightsWonolog\Plugin;
use AzureInsightsWonolog\Performance\Collector;

// Provide WP option shims if not present.
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS[ 'aiw_test_option_store' ] = [];
	function get_option( $k, $d = null ) {
		return $GLOBALS[ 'aiw_test_option_store' ][ $k ] ?? $d;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $k, $v ) {
		$GLOBALS[ 'aiw_test_option_store' ][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '/' ) {
		return 'https://example.test' . $p;
	}
}
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

class PerformanceMetricsTest extends TestCase {
	private function bootPlugin(): Plugin {
		$plugin = Plugin::instance();
		// Only boot once (avoid re-registering handlers). Use reflection to see if telemetry exists.
		$ref  = new \ReflectionClass( $plugin );
		$prop = $ref->getProperty( 'telemetry_client' );
		$prop->setAccessible( true );
		if ( ! $prop->getValue( $plugin ) ) {
			$plugin->boot();
		}
		return $plugin;
	}

	public function testSlowQueryMetricsEmitted() {
		// Ensure clean buffer
		$plugin    = $this->bootPlugin();
		$telemetry = $plugin->telemetry();
		// Clear existing buffer via reflection
		$refT = new \ReflectionClass( get_class( $telemetry ) );
		if ( $refT->hasProperty( 'buffer' ) ) {
			$p = $refT->getProperty( 'buffer' );
			$p->setAccessible( true );
			$p->setValue( $telemetry, [] );
		}
		// Configure slow query threshold low so our test query qualifies.
		update_option( 'aiw_slow_query_threshold_ms', 15 ); // threshold 15ms so only first query qualifies (20ms)
		// Simulate queries in $wpdb->queries
		global $wpdb;
		$wpdb = new \stdClass();
		// Each entry: [sql, seconds, caller]; second query is below threshold
		$wpdb->queries = [ 
			[ 'SELECT SLEEP(0.02)', 0.02, 'caller1' ], // 20ms slow
			[ 'SELECT 1', 0.001, 'caller2' ],           // 1ms fast
		];

		$collector = new Collector( true );
		$collector->finalize();

		$buffer = $telemetry->debug_get_buffer();
		$names  = [];
		foreach ( $buffer as $item ) {
			if ( isset( $item[ 'data' ][ 'baseData' ][ 'metrics' ] ) && is_array( $item[ 'data' ][ 'baseData' ][ 'metrics' ] ) ) {
				foreach ( $item[ 'data' ][ 'baseData' ][ 'metrics' ] as $m ) {
					if ( isset( $m[ 'name' ] ) ) {
						$names[] = $m[ 'name' ];
					}
				}
			}
		}
		$this->assertContains( 'db_query_count', $names );
		$this->assertContains( 'db_query_time_ms', $names );
		$this->assertContains( 'db_slow_query_count', $names );
		// Ensure slow query count metric value > 0 rather than relying on per-query metric presence
		$slowCountValue = null;
		foreach ( $buffer as $item ) {
			if ( isset( $item[ 'data' ][ 'baseData' ][ 'metrics' ] ) ) {
				foreach ( $item[ 'data' ][ 'baseData' ][ 'metrics' ] as $m ) {
					if ( isset( $m[ 'name' ] ) && $m[ 'name' ] === 'db_slow_query_count' ) {
						$slowCountValue = $m[ 'value' ] ?? null;
					}
				}
			}
		}
		$this->assertNotNull( $slowCountValue, 'db_slow_query_count metric missing' );
		$this->assertGreaterThan( 0, $slowCountValue, 'Expected at least one slow query detected' );
	}

	public function testCronRunDurationMetricEmitted() {
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}
		$plugin    = $this->bootPlugin();
		$collector = new Collector( true ); // constructor captures cron start
		usleep( 2000 ); // 2ms sleep to ensure measurable duration
		$collector->finalize();
		$buffer = $plugin->telemetry()->debug_get_buffer();
		$found  = false;
		foreach ( $buffer as $item ) {
			if ( isset( $item[ 'data' ][ 'baseData' ][ 'metrics' ][ 0 ][ 'name' ] ) && $item[ 'data' ][ 'baseData' ][ 'metrics' ][ 0 ][ 'name' ] === 'cron_run_duration_ms' ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'cron_run_duration_ms metric should be present when DOING_CRON' );
	}
}
