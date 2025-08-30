<?php
// Minimal bootstrap for non-WordPress unit tests.
require_once __DIR__ . '/../vendor/autoload.php';

// Don't load the main plugin file directly because it exits if ABSPATH isn't defined
// and pulls in many WordPress-specific functions we don't stub here. Instead we just
// load the helper functions file which defines aiw_event/aiw_metric wrappers.
if ( ! defined( 'AIW_PLUGIN_VERSION' ) ) {
	define( 'AIW_PLUGIN_VERSION', '0.0-test' );
}
require_once __DIR__ . '/../src/Helpers/functions.php';

// Shim WordPress functions used inside code under test.
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( $str ) : $str;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '/' ) {
		return 'https://example.test' . $path;
	}
}

// Option emulation for feature toggles & settings.
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
