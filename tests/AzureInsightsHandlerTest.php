<?php
namespace AzureInsightsMonolog\Tests;

use PHPUnit\Framework\TestCase;
use AzureInsightsMonolog\Handler\AzureInsightsHandler;
use AzureInsightsMonolog\Telemetry\TelemetryClient;
use AzureInsightsMonolog\Plugin;
use AzureInsightsMonolog\Telemetry\Correlation;
use Monolog\Logger;

// Shim apply_filters for testing hook behavior.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		global $aiw_test_filters;
		$args = func_get_args();
		if ( isset( $aiw_test_filters[ $tag ] ) ) {
			// pass all args except tag
			return call_user_func_array( $aiw_test_filters[ $tag ], array_slice( $args, 1 ) );
		}
		return $value;
	}
}

class AzureInsightsHandlerTest extends TestCase {
	private function makeClient(): TelemetryClient {
		return new TelemetryClient( [ 
			'instrumentation_key'  => '00000000-0000-0000-0000-000000000000',
			'batch_max_size'       => 100,
			'batch_flush_interval' => 100,
		] );
	}

	public function testSamplingSkipsRecordWhenDecisionFalse() {
		global $aiw_test_filters;
		$aiw_test_filters = [ 'aiw_should_sample' => function ($decision) {
			return false;
		} ];

		$client  = $this->makeClient();
		$handler = new AzureInsightsHandler( $client, [ 'sampling_rate' => 0.0 ], Logger::DEBUG );
		// Initialize correlation on plugin
		$plugin = Plugin::instance();
		$corr   = new Correlation();
		$corr->init();
		$pluginRef = new \ReflectionClass( $plugin );
		$prop      = $pluginRef->getProperty( 'correlation' );
		$prop->setAccessible( true );
		$prop->setValue( $plugin, $corr );

		// Build mock record similar to Monolog processed record structure.
		$record = [ 
			'level'     => Logger::INFO,
			'message'   => 'Sample skipped',
			'context'   => [],
			'formatted' => 'Sample skipped',
		];
		$ref    = new \ReflectionClass( $handler );
		$method = $ref->getMethod( 'write' );
		$method->setAccessible( true );
		$method->invoke( $handler, $record );

		$this->assertCount( 0, $client->debug_get_buffer(), 'Buffer should be empty due to sampling skip.' );
		$aiw_test_filters = [];
	}

	public function testExceptionRecordCreatesExceptionTelemetry() {
		$client  = $this->makeClient();
		$handler = new AzureInsightsHandler( $client, [ 'sampling_rate' => 1 ], Logger::DEBUG );

		// Ensure plugin correlation is initialized.
		$plugin = Plugin::instance();
		// Manually set correlation via reflection since boot not called in test environment.
		$corr = new Correlation();
		$corr->init();
		$pluginRef = new \ReflectionClass( $plugin );
		if ( $pluginRef->hasProperty( 'correlation' ) ) {
			$prop = $pluginRef->getProperty( 'correlation' );
			$prop->setAccessible( true );
			$prop->setValue( $plugin, $corr );
		}

		$exception = new \RuntimeException( 'Handler exception' );
		$record    = [ 
			'level'     => Logger::ERROR,
			'message'   => 'Boom',
			'context'   => [ 'exception' => $exception ],
			'formatted' => 'Boom',
		];
		$ref       = new \ReflectionClass( $handler );
		$method    = $ref->getMethod( 'write' );
		$method->setAccessible( true );
		$method->invoke( $handler, $record );

		$buffer = $client->debug_get_buffer();
		$this->assertCount( 1, $buffer );
		$this->assertEquals( 'Microsoft.ApplicationInsights.Exception', $buffer[ 0 ][ 'name' ] );
		$this->assertEquals( 'Handler exception', $buffer[ 0 ][ 'data' ][ 'baseData' ][ 'exceptions' ][ 0 ][ 'message' ] );
	}

	public function testRedactionFilter() {
		global $aiw_test_filters;
		$aiw_test_filters = [ 'aiw_redact_keys' => function ($keys) {
			if ( ! in_array( 'token', $keys, true ) ) {
				$keys[] = 'token';
			}return $keys;
		} ];

		$client  = $this->makeClient();
		$handler = new AzureInsightsHandler( $client, [ 'sampling_rate' => 1 ], Logger::DEBUG );

		$plugin = Plugin::instance();
		$corr   = new Correlation();
		$corr->init();
		$pluginRef = new \ReflectionClass( $plugin );
		$prop      = $pluginRef->getProperty( 'correlation' );
		$prop->setAccessible( true );
		$prop->setValue( $plugin, $corr );

		$record = [ 
			'level'     => Logger::INFO,
			'message'   => 'Sensitive',
			'context'   => [ 'password' => 'secret', 'token' => 'abc123', 'visible' => 'ok' ],
			'formatted' => 'Sensitive',
		];
		$ref    = new \ReflectionClass( $handler );
		$method = $ref->getMethod( 'write' );
		$method->setAccessible( true );
		$method->invoke( $handler, $record );

		$buffer = $client->debug_get_buffer();
		$this->assertEquals( '[REDACTED]', $buffer[ 0 ][ 'data' ][ 'baseData' ][ 'properties' ][ 'password' ] );
		// If token not redacted, output keys for debugging.
		if ( $buffer[ 0 ][ 'data' ][ 'baseData' ][ 'properties' ][ 'token' ] !== '[REDACTED]' ) {
			// Force failure with context output.
			$this->fail( 'Token not redacted. Properties: ' . json_encode( $buffer[ 0 ][ 'data' ][ 'baseData' ][ 'properties' ] ) );
		}
		$this->assertEquals( 'ok', $buffer[ 0 ][ 'data' ][ 'baseData' ][ 'properties' ][ 'visible' ] );
		$aiw_test_filters = [];
	}

	public function testErrorsBypassSamplingDrop() {
		global $aiw_test_filters;
		$aiw_test_filters = [ 'aiw_should_sample' => function ($decision) {
			return false;
		} ];
		$client           = $this->makeClient();
		$handler          = new AzureInsightsHandler( $client, [ 'sampling_rate' => 0.0 ], Logger::DEBUG );
		$plugin           = Plugin::instance();
		$corr             = new Correlation();
		$corr->init();
		$pluginRef = new \ReflectionClass( $plugin );
		$prop      = $pluginRef->getProperty( 'correlation' );
		$prop->setAccessible( true );
		$prop->setValue( $plugin, $corr );
		$record = [ 'level' => Logger::ERROR, 'message' => 'Should send', 'context' => [], 'formatted' => 'Should send' ];
		$ref    = new \ReflectionClass( $handler );
		$method = $ref->getMethod( 'write' );
		$method->setAccessible( true );
		$method->invoke( $handler, $record );
		$buffer = $client->debug_get_buffer();
		$this->assertCount( 1, $buffer, 'Error level should bypass sampling skip.' );
		$aiw_test_filters = [];
	}

	public function testAdvancedRedactionDiagnostics() {
		// Inject test config via globals
		$GLOBALS[ 'aiw_test_redact_extra_keys' ] = 'secretKey,ApiKey';
		$GLOBALS[ 'aiw_test_redact_patterns' ]   = '/[0-9]{4}-[0-9]{4}-[0-9]{4}/';
		$client                                  = $this->makeClient();
		$handler                                 = new AzureInsightsHandler( $client, [ 'sampling_rate' => 1 ], Logger::DEBUG );
		$plugin                                  = Plugin::instance();
		$corr                                    = new Correlation();
		$corr->init();
		$pluginRef = new \ReflectionClass( $plugin );
		$prop      = $pluginRef->getProperty( 'correlation' );
		$prop->setAccessible( true );
		$prop->setValue( $plugin, $corr );
		$record = [ 
			'level'     => Logger::INFO,
			'message'   => 'Advanced redact',
			'context'   => [ 'password' => 'p', 'secretKey' => 'abc', 'visible' => 'ok', 'card' => '1234-5678-9999', 'note' => 'no match' ],
			'formatted' => 'Advanced redact',
		];
		$ref    = new \ReflectionClass( $handler );
		$method = $ref->getMethod( 'write' );
		$method->setAccessible( true );
		$method->invoke( $handler, $record );
		$buffer = $client->debug_get_buffer();
		$this->assertCount( 1, $buffer );
		$props = $buffer[ 0 ][ 'data' ][ 'baseData' ][ 'properties' ];
		$this->assertEquals( '[REDACTED]', $props[ 'password' ] );
		$this->assertEquals( '[REDACTED]', $props[ 'secretKey' ] );
		$this->assertEquals( '[REDACTED]', $props[ 'card' ] );
		$this->assertEquals( 'ok', $props[ 'visible' ] );
		$this->assertArrayHasKey( '_aiw_redaction', $props );
		$this->assertContains( 'password', array_map( 'strtolower', $props[ '_aiw_redaction' ][ 'keys' ] ) );
		$this->assertContains( 'secretkey', array_map( 'strtolower', $props[ '_aiw_redaction' ][ 'keys' ] ) );
		$this->assertNotEmpty( $props[ '_aiw_redaction' ][ 'patterns' ] );
		unset( $GLOBALS[ 'aiw_test_redact_extra_keys' ], $GLOBALS[ 'aiw_test_redact_patterns' ] );
	}

	public function testEventsApiDisabledSkipsEmission() {
		// Disable events API via option simulation
		$GLOBALS[ 'wp_options' ][ 'aiw_enable_events_api' ] = 0;
		$client                                             = $this->makeClient();
		$plugin                                             = Plugin::instance();
		$corr                                               = new Correlation();
		$corr->init();
		$refPl = new \ReflectionClass( $plugin );
		$propC = $refPl->getProperty( 'correlation' );
		$propC->setAccessible( true );
		$propC->setValue( $plugin, $corr );
		// Call helper
		\aiw_event( 'DisabledEvent', [] );
		$this->assertTrue( true, 'No exception when events API disabled.' );
	}

	public function testSamplerAdaptiveReducesRate() {
		$client  = $this->makeClient();
		$handler = new AzureInsightsHandler( $client, [ 'sampling_rate' => 1 ], Logger::DEBUG );
		$plugin  = Plugin::instance();
		$corr    = new Correlation();
		$corr->init();
		$refPl = new \ReflectionClass( $plugin );
		$propC = $refPl->getProperty( 'correlation' );
		$propC->setAccessible( true );
		$propC->setValue( $plugin, $corr );
		// Flood with many info logs to trigger adaptive branch lowering effective rate (window > 50)
		$refH  = new \ReflectionClass( $handler );
		$write = $refH->getMethod( 'write' );
		$write->setAccessible( true );
		for ( $i = 0; $i < 60; $i++ ) {
			$write->invoke( $handler, [ 'level' => Logger::INFO, 'message' => 'Flood ' . $i, 'context' => [], 'formatted' => '' ] );
		}
		// We can't directly read effective rate; assert at least one item was sampled (non-zero) and not all (some skipped) to infer reduction.
		$bufCount = count( $client->debug_get_buffer() );
		$this->assertGreaterThan( 0, $bufCount );
		$this->assertLessThan( 60, $bufCount );
	}
}
