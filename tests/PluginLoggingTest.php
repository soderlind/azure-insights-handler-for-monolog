<?php
namespace AzureInsightsMonolog\Tests;

use PHPUnit\Framework\TestCase;
use AzureInsightsMonolog\Plugin;
use AzureInsightsMonolog\Handler\AzureInsightsHandler;
use AzureInsightsMonolog\Telemetry\TelemetryClient;
use Monolog\Logger;

class PluginLoggingTest extends TestCase {
	public function testMonologInfoCreatesTraceTelemetry() {
		// Build telemetry client directly (avoid full plugin boot complexity for test isolation)
		$config = [ 
			'instrumentation_key'  => '00000000-0000-0000-0000-000000000000',
			'batch_max_size'       => 100,
			'batch_flush_interval' => 100,
			'sampling_rate'        => 1,
			'min_level'            => 'debug',
			'async_enabled'        => false,
		];
		$client = new TelemetryClient( $config );

		// Minimal correlation via plugin to supply IDs
		$plugin = Plugin::instance();
		// Inject correlation manually to avoid boot overhead
		$corrRef = new \ReflectionClass( $plugin );
		if ( $corrRef->hasProperty( 'correlation' ) ) {
			$prop = $corrRef->getProperty( 'correlation' );
			$prop->setAccessible( true );
			$corr = new \AzureInsightsMonolog\Telemetry\Correlation();
			$corr->init();
			$prop->setValue( $plugin, $corr );
		}

		$handler = new AzureInsightsHandler( $client, [ 'sampling_rate' => 1 ], Logger::DEBUG );
		$logger  = new Logger( 'aiw-test' );
		$logger->pushHandler( $handler );

		// Reset sampler adaptive window to avoid probabilistic drop from previous tests inflating window count.
		$refSamplerWindow = new \ReflectionProperty( \AzureInsightsMonolog\Telemetry\Sampler::class, 'window' );
		$refSamplerWindow->setAccessible( true );
		$refSamplerWindow->setValue( null, [] );

		$logger->info( 'Integration info test', [ 'foo' => 'bar' ] );
		$buffer = $client->debug_get_buffer();
		if ( empty( $buffer ) ) {
			// force flush attempt (should be no-op for buffer) but keeps logic symmetrical
			$client->flush();
			$buffer = $client->debug_get_buffer();
		}
		$this->assertNotEmpty( $buffer, 'Telemetry buffer should have at least one item' );
		$first = $buffer[ 0 ];
		$this->assertEquals( 'Microsoft.ApplicationInsights.Message', $first[ 'name' ] );
		$this->assertEquals( 'Integration info test', $first[ 'data' ][ 'baseData' ][ 'message' ] );
		$this->assertEquals( 'bar', $first[ 'data' ][ 'baseData' ][ 'properties' ][ 'foo' ] );
	}
}
