<?php
namespace {
	// Global shims for WordPress functions used by production code.
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
	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() {
			return true;
		}
	}
	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return 42;
		}
	}
	if ( ! function_exists( 'wp_salt' ) ) {
		function wp_salt( $scheme = 'auth' ) {
			return 'unit-test-salt';
		}
	}
	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $d ) {
			return json_encode( $d );
		}
	}
}

namespace AzureInsightsMonolog\Tests {
	use PHPUnit\Framework\TestCase;
	use AzureInsightsMonolog\Security\Secrets;
	use AzureInsightsMonolog\Plugin;
	use AzureInsightsMonolog\Telemetry\TelemetryClient;
	use AzureInsightsMonolog\Handler\AzureInsightsHandler;
	use AzureInsightsMonolog\Telemetry\Correlation;
	use Monolog\Logger;

	class SecurityAndDedupeTest extends TestCase {
		public function testEncryptionAndDecryptionOfConnectionString() {
			$plain = 'InstrumentationKey=00000000-0000-0000-0000-000000000000;IngestionEndpoint=https://eu.applicationinsights.azure.com/';
			$enc   = Secrets::encrypt( $plain );
			if ( function_exists( 'openssl_encrypt' ) ) {
				$this->assertNotEquals( $plain, $enc, 'Encrypted value should differ from plain text when OpenSSL available.' );
				$this->assertTrue( Secrets::is_encrypted( $enc ) );
			} else {
				$this->assertFalse( Secrets::is_encrypted( $enc ) );
			}
			// Store encrypted values (simulate settings save)
			update_option( 'aiw_connection_string', $enc );
			update_option( 'aiw_instrumentation_key', Secrets::encrypt( '00000000-0000-0000-0000-000000000000' ) );
			$plugin = Plugin::instance();
			$ref    = new \ReflectionClass( $plugin );
			$m      = $ref->getMethod( 'load_config' );
			$m->setAccessible( true );
			$config = $m->invoke( $plugin );
			$this->assertEquals( $plain, $config[ 'connection_string' ] );
			$this->assertEquals( '00000000-0000-0000-0000-000000000000', $config[ 'instrumentation_key' ] );
		}

		public function testBaselinePropertiesIncludesUserHash() {
			$client = new TelemetryClient( [ 'instrumentation_key' => '00000000-0000-0000-0000-000000000000', 'batch_max_size' => 10, 'batch_flush_interval' => 99, 'sampling_rate' => 1 ] );
			$props  = $client->baseline_properties();
			$this->assertArrayHasKey( 'user_hash', $props, 'User hash should be present for logged in user.' );
			$expected = substr( hash( 'sha256', '42|unit-test-salt' ), 0, 32 );
			$this->assertEquals( $expected, $props[ 'user_hash' ] );
		}

		public function testExceptionDeduplicationSkipsDuplicateWithinWindow() {
			$client  = new TelemetryClient( [ 'instrumentation_key' => '00000000-0000-0000-0000-000000000000', 'batch_max_size' => 100, 'batch_flush_interval' => 100, 'sampling_rate' => 1 ] );
			$handler = new AzureInsightsHandler( $client, [ 'sampling_rate' => 1 ], Logger::DEBUG );
			// Inject correlation
			$plugin = Plugin::instance();
			$corr   = new Correlation();
			$corr->init();
			$pref = new \ReflectionClass( $plugin );
			$prop = $pref->getProperty( 'correlation' );
			$prop->setAccessible( true );
			$prop->setValue( $plugin, $corr );
			$template                       = [ 'level' => Logger::ERROR, 'message' => 'Boom', 'context' => [], 'formatted' => 'Boom' ];
			$ex1                            = new \RuntimeException( 'Repeatable failure' );
			$r1                             = $template;
			$r1[ 'context' ][ 'exception' ] = $ex1;
			$ref                            = new \ReflectionClass( $handler );
			$m                              = $ref->getMethod( 'write' );
			$m->setAccessible( true );
			$m->invoke( $handler, $r1 );
			$this->assertCount( 1, $client->debug_get_buffer(), 'First exception recorded' );
			$r2                             = $template;
			$r2[ 'context' ][ 'exception' ] = $ex1;
			$m->invoke( $handler, $r2 );
			$this->assertCount( 1, $client->debug_get_buffer(), 'Duplicate suppressed' );
			$ex2                            = new \RuntimeException( 'Different failure' );
			$r3                             = $template;
			$r3[ 'context' ][ 'exception' ] = $ex2;
			$m->invoke( $handler, $r3 );
			$this->assertCount( 2, $client->debug_get_buffer(), 'Different exception allowed' );
		}
	}
}

