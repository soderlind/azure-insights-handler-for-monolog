<?php
namespace AzureInsightsWonolog\Tests;

use PHPUnit\Framework\TestCase;
use AzureInsightsWonolog\Admin\SettingsPage;
use ReflectionClass;

// WordPress function stubs for test context (only what's needed by methods under test)
if ( ! function_exists( 'add_settings_error' ) ) {
	$GLOBALS[ 'aiw_settings_errors' ] = [];
	function add_settings_error( $setting, $code, $message ) {
		$GLOBALS[ 'aiw_settings_errors' ][] = compact( 'setting', 'code', 'message' );
	}
}
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS[ 'wp_options' ] = $GLOBALS[ 'wp_options' ] ?? [];
	function get_option( $k, $d = false ) {
		return $GLOBALS[ 'wp_options' ][ $k ] ?? $d;
	}
	function update_option( $k, $v, $autoload = false ) {
		$GLOBALS[ 'wp_options' ][ $k ] = $v;
		return true;
	}
}

class SettingsPageTest extends TestCase {

	private function makePage(): SettingsPage {
		return new SettingsPage();
	}

	public function testSanitizeInstrumentationKeyValid() {
		$page   = $this->makePage();
		$ref    = new ReflectionClass( $page );
		$method = $ref->getMethod( 'sanitize_instrumentation_key' );
		$method->setAccessible( true );
		$valid  = '00000000-0000-0000-0000-000000000000';
		$result = $method->invoke( $page, $valid );
		$this->assertSame( $valid, $result, 'Valid instrumentation key should be returned unchanged.' );
	}

	public function testSanitizeInstrumentationKeyInvalid() {
		$page   = $this->makePage();
		$ref    = new ReflectionClass( $page );
		$method = $ref->getMethod( 'sanitize_instrumentation_key' );
		$method->setAccessible( true );
		// Intentionally malformed (contains disallowed chars and wrong length)
		$invalid                        = 'INVALID_KEY_*!';
		$GLOBALS[ 'aiw_settings_errors' ] = [];
		$result                         = $method->invoke( $page, $invalid );
		$this->assertSame( '', $result, 'Invalid key should sanitize to empty string.' );
		// add_settings_error may not fire if WP core not present; ensure no fatal instead of asserting side effect.
	}

	public function testFormatBytes() {
		$page   = $this->makePage();
		$ref    = new ReflectionClass( $page );
		$method = $ref->getMethod( 'format_bytes' );
		$method->setAccessible( true );
		$out = $method->invoke( $page, 2048 ); // 2 KB
		$this->assertStringContainsString( 'KB', $out );
		$this->assertStringStartsWith( '2.0', $out );
	}

	public function testDashboardSummaryStructure() {
		// Seed representative option values
		$GLOBALS[ 'wp_options' ][ 'aiw_retry_queue_v1' ]  = [ [ 'attempts' => 0, 'next_attempt' => time() + 60 ] ];
		$GLOBALS[ 'wp_options' ][ 'aiw_use_mock' ]        = 1;
		$GLOBALS[ 'wp_options' ][ 'aiw_last_send_time' ]  = time() - 30;
		$GLOBALS[ 'wp_options' ][ 'aiw_last_error_code' ] = '';
		$GLOBALS[ 'wp_options' ][ 'aiw_sampling_rate' ]   = '1';

		$page           = $this->makePage();
		$ref            = new ReflectionClass( $page );
		$sectionsMethod = $ref->getMethod( 'dashboard_sections' );
		$sectionsMethod->setAccessible( true );
		$summaryMethod = $ref->getMethod( 'dashboard_summary' );
		$summaryMethod->setAccessible( true );

		$groups  = $sectionsMethod->invoke( $page );
		$summary = $summaryMethod->invoke( $page, $groups );

		$this->assertIsArray( $summary );
		$labels = array_map( fn( $row ) => $row[ 0 ], $summary );
		$this->assertContains( 'Retry Queue', $labels );
		$this->assertContains( 'Mode', $labels );
	}
}
