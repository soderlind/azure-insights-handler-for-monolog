<?php
namespace AzureInsightsWonolog\Tests;

use PHPUnit\Framework\TestCase;
use AzureInsightsWonolog\Telemetry\TelemetryClient;

class TelemetryClientTest extends TestCase {
	private function makeClient(): TelemetryClient {
		return new TelemetryClient( [ 
			'instrumentation_key'  => '00000000-0000-0000-0000-000000000000',
			'batch_max_size'       => 50,
			'batch_flush_interval' => 10,
		] );
	}

	public function testBuildTraceItemHasExpectedFields() {
		$client = $this->makeClient();
		$record = [ 
			'level'   => 200,
			'message' => 'Test message',
			'context' => [ 'foo' => 'bar' ],
		];
		$item   = $client->build_trace_item( $record, 'traceid1234567890traceid12345678', 'spanid1234567890' );
		$this->assertEquals( 'Microsoft.ApplicationInsights.Message', $item[ 'name' ] );
		$this->assertArrayHasKey( 'data', $item );
		$this->assertEquals( 'MessageData', $item[ 'data' ][ 'baseType' ] );
		$this->assertEquals( 'Test message', $item[ 'data' ][ 'baseData' ][ 'message' ] );
		$this->assertEquals( 'bar', $item[ 'data' ][ 'baseData' ][ 'properties' ][ 'foo' ] );
		$this->assertEquals( 'traceid1234567890traceid12345678', $item[ 'data' ][ 'baseData' ][ 'properties' ][ 'trace_id' ] );
	}

	public function testBuildRequestItemFormatsDuration() {
		$client = $this->makeClient();
		$item   = $client->build_request_item( [ 
			'name' => 'GET /',
			'code' => 200,
			'url'  => 'https://example.test/',
		], 1234.56, 'traceid1234567890traceid12345678', 'spanid1234567890', null );
		$this->assertEquals( 'Microsoft.ApplicationInsights.Request', $item[ 'name' ] );
		$this->assertMatchesRegularExpression( '/^00:00:01\.[0-9]{3}$/', $item[ 'data' ][ 'baseData' ][ 'duration' ] );
	}

	public function testBuildExceptionItem() {
		$client    = $this->makeClient();
		$exception = new \RuntimeException( 'Boom' );
		$record    = [ 'level' => 400 ];
		$item      = $client->build_exception_item( $exception, $record, 'traceid1234567890traceid12345678', 'spanid1234567890' );
		$this->assertEquals( 'Microsoft.ApplicationInsights.Exception', $item[ 'name' ] );
		$this->assertEquals( 'Boom', $item[ 'data' ][ 'baseData' ][ 'exceptions' ][ 0 ][ 'message' ] );
		$this->assertEquals( 'traceid1234567890traceid12345678', $item[ 'data' ][ 'baseData' ][ 'properties' ][ 'trace_id' ] );
	}

	public function testBuildEventItem() {
		$client = $this->makeClient();
		$props  = [ 'foo' => 'bar' ];
		$meas   = [ 'count' => 3.14 ];
		$item   = $client->build_event_item( 'SampleEvent', $props, $meas, 'traceid1234567890traceid12345678', 'spanid1234567890' );
		$this->assertEquals( 'Microsoft.ApplicationInsights.Event', $item[ 'name' ] );
		$this->assertEquals( 'EventData', $item[ 'data' ][ 'baseType' ] );
		$this->assertEquals( 'SampleEvent', $item[ 'data' ][ 'baseData' ][ 'name' ] );
		$this->assertEquals( 'bar', $item[ 'data' ][ 'baseData' ][ 'properties' ][ 'foo' ] );
		$this->assertEquals( 3.14, $item[ 'data' ][ 'baseData' ][ 'measurements' ][ 'count' ] );
	}

	public function testBatchLinesCaptured() {
		$client = new TelemetryClient( [ 
			'instrumentation_key'  => '00000000-0000-0000-0000-000000000000',
			'batch_max_size'       => 1,
			'batch_flush_interval' => 999,
			'async_enabled'        => false,
		] );
		$item   = $client->build_trace_item( [ 'level' => 200, 'message' => 'hello', 'context' => [] ], 'trace', 'span' );
		$client->add( $item );
		$lines = $client->debug_last_payload_lines();
		$this->assertNotEmpty( $lines );
		$this->assertStringContainsString( '"hello"', $lines[ 0 ] );
	}
}
