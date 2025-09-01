<?php
/**
 * Plugin Name: Azure Insights Handler for Monolog
 * Description: Forwards Monolog logs and custom telemetry (requests, events, metrics, exceptions) to Azure Application Insights.
 * Version: 0.5.0
 * Author: Per Søderlind
 * License: GPL2
 * Text Domain: azure-insights-monolog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants
define( 'AIW_PLUGIN_FILE', __FILE__ );
define( 'AIW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIW_PLUGIN_VERSION', '0.5.0' );

// Opinionated recommended defaults (used when options not yet saved)
if ( ! defined( 'AIW_DEFAULT_BATCH_MAX_SIZE' ) ) {
	define( 'AIW_DEFAULT_BATCH_MAX_SIZE', 20 ); // Balance memory vs. HTTP overhead
}
if ( ! defined( 'AIW_DEFAULT_BATCH_FLUSH_INTERVAL' ) ) {
	define( 'AIW_DEFAULT_BATCH_FLUSH_INTERVAL', 5 ); // Low latency flush without excessive calls
}
if ( ! defined( 'AIW_DEFAULT_SLOW_HOOK_THRESHOLD_MS' ) ) {
	define( 'AIW_DEFAULT_SLOW_HOOK_THRESHOLD_MS', 150 ); // Only capture noticeably slow hooks
}
if ( ! defined( 'AIW_DEFAULT_SLOW_QUERY_THRESHOLD_MS' ) ) {
	define( 'AIW_DEFAULT_SLOW_QUERY_THRESHOLD_MS', 500 ); // Common WP perf baseline
}

// Optional disable & diagnostics (same semantics as previous version)
if ( defined( 'AIW_DISABLE' ) && AIW_DISABLE ) {
	if ( function_exists( 'error_log' ) )
		@error_log( '[AIW] Disabled via AIW_DISABLE' );
	return;
}
if ( ! function_exists( 'aiw_diag' ) ) {
	function aiw_diag( string $m, array $c = [] ): void {
		if ( defined( 'AIW_DIAGNOSTICS' ) && AIW_DIAGNOSTICS && function_exists( 'error_log' ) ) {
			$line = '[AIW] ' . $m . ( $c ? ' ' . json_encode( $c ) : '' );
			@error_log( $line );
		}
	}
}

if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	if ( function_exists( 'add_action' ) ) {
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>Azure Insights Handler for Monolog:</strong> Requires PHP 8.2+. Detected ' . esc_html( PHP_VERSION ) . '. Plugin not initialized.</p></div>';
			}
		} );
	}
	aiw_diag( 'php too low -> abort' );
	return;
}

// Composer autoloader
$autoload = AIW_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
	aiw_diag( 'composer autoload loaded' );
} else {
	aiw_diag( 'composer autoload missing', [ 'path' => $autoload ] );
}

// PSR-4 fallback for our namespace
spl_autoload_register( function ($class) {
	if ( strpos( $class, 'AzureInsightsMonolog\\' ) !== 0 )
		return;
	$rel  = substr( $class, strlen( 'AzureInsightsMonolog\\' ) );
	$rel  = str_replace( '\\', '/', $rel );
	$file = AIW_PLUGIN_DIR . 'src/' . $rel . '.php';
	if ( file_exists( $file ) )
		require_once $file;
} );

// Updater
try {
	$updater = AzureInsightsMonolog\Updater\GitHubPluginUpdater::create_with_assets(
		'https://github.com/soderlind/azure-insights-handler-for-monolog',
		AIW_PLUGIN_FILE,
		'azure-insights-handler-for-monolog',
		'/azure-insights-handler-for-monolog\\.zip/',
		'main'
	);
	aiw_diag( 'updater initialized' );
} catch (\Throwable $e) {
	aiw_diag( 'updater failed', [ 'error' => $e->getMessage() ] );
}

// Activation / Deactivation
register_activation_hook( __FILE__, function () {
	if ( class_exists( 'AzureInsightsMonolog\\Plugin' ) ) {
		AzureInsightsMonolog\Plugin::activate();
	}
} );
register_deactivation_hook( __FILE__, function () {
	if ( class_exists( 'AzureInsightsMonolog\\Plugin' ) ) {
		AzureInsightsMonolog\Plugin::deactivate();
	}
} );

// Bootstrap
add_action( 'plugins_loaded', function () {
	aiw_diag( 'plugins_loaded' );
	if ( ! class_exists( '\\Monolog\\Logger' ) ) {
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>Azure Insights Handler for Monolog:</strong> Monolog library not found. Run <code>composer install</code> inside the plugin directory.</p></div>';
			}
		} );
		aiw_diag( 'monolog missing' );
		return;
	}
	if ( class_exists( 'AzureInsightsMonolog\\Plugin' ) ) {
		try {
			aiw_diag( 'boot start' );
			AzureInsightsMonolog\Plugin::instance()->boot();
			aiw_diag( 'boot done' );
		} catch (\Throwable $e) {
			aiw_diag( 'boot failed', [ 'error' => $e->getMessage() ] );
		}
	}
} );

// Helpers
require_once AIW_PLUGIN_DIR . 'src/Helpers/functions.php';

// WP-CLI
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$cli = AIW_PLUGIN_DIR . 'src/CLI/Commands.php';
	if ( file_exists( $cli ) )
		require_once $cli;
}
