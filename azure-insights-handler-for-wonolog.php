<?php
/**
 * Plugin Name: Azure Insights Handler for Wonolog
 * Description: Integrates Wonolog (Monolog) with Azure Application Insights for enhanced logging and telemetry.
 * Version: 0.3.0
 * Author: Per Søderlind
 * License: GPL2
 * Text Domain: azure-insights-wonolog
 */

// Abort if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'AIW_PLUGIN_FILE', __FILE__ );
define( 'AIW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIW_PLUGIN_VERSION', '0.3.0' );

// PSR-4 like autoloader (lightweight fallback when Composer not used).
// First attempt to load Composer autoloader (provides Monolog, etc.).
$aiw_composer = AIW_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $aiw_composer ) ) {
	require_once $aiw_composer;
}

spl_autoload_register( function ($class) {
	if ( strpos( $class, 'AzureInsightsWonolog\\' ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( 'AzureInsightsWonolog\\' ) );
	$relative = str_replace( '\\', '/', $relative );
	$path     = AIW_PLUGIN_DIR . 'src/' . $relative . '.php';
	if ( file_exists( $path ) ) {
		require_once $path;
	}
} );


$additional_javascript_updater = AzureInsightsWonolog\Updater\GitHubPluginUpdater::create_with_assets(
	'https://github.com/soderlind/azure-insights-handler-for-wonolog',
	AIW_PLUGIN_FILE,
	'azure-insights-handler-for-wonolog',
	'/azure-insights-handler-for-wonolog\.zip/',
	'main'
);


// Activation / Deactivation hooks.
register_activation_hook( __FILE__, function () {
	if ( class_exists( 'AzureInsightsWonolog\\Plugin' ) ) {
		AzureInsightsWonolog\Plugin::activate();
	}
} );

register_deactivation_hook( __FILE__, function () {
	if ( class_exists( 'AzureInsightsWonolog\\Plugin' ) ) {
		AzureInsightsWonolog\Plugin::deactivate();
	}
} );

// Bootstrap after plugins_loaded so Wonolog (if present) is available.
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( '\Monolog\Logger' ) ) {
		// Defer admin notice until admin_notices hook.
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>Azure Insights Handler for Wonolog:</strong> Monolog library not found. Run <code>composer install</code> inside the plugin directory or install Wonolog.</p></div>';
			}
		} );
		return;
	}
	if ( class_exists( 'AzureInsightsWonolog\Plugin' ) ) {
		AzureInsightsWonolog\Plugin::instance()->boot();
	}
} );

// Public helper functions (namespaced wrappers) loaded at end.
require_once AIW_PLUGIN_DIR . 'src/Helpers/functions.php';

// Register WP-CLI commands when running under WP-CLI early so they are always available.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$cli_file = AIW_PLUGIN_DIR . 'src/CLI/Commands.php';
	if ( file_exists( $cli_file ) ) {
		require_once $cli_file; // File self-registers the command.
	}
}

