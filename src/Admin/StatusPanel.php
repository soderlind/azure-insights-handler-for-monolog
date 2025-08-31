<?php
namespace AzureInsightsMonolog\Admin;

use AzureInsightsMonolog\Plugin;

class StatusPanel {
	const PAGE_SLUG = 'aiw-status';

	public function register() {
		if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_submenu_page' ) )
			return;
		add_action( 'admin_menu', function () {
			add_submenu_page(
				'options-general.php',
				'AIW Status',
				'AIW Status',
				'manage_options',
				self::PAGE_SLUG,
				[ $this, 'render' ]
			);
		} );
	}

	private function esc( $v ) {
		return function_exists( 'esc_html' ) ? esc_html( $v ) : htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' );
	}

	public function render() {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) )
			return;
		$status = $this->gather_status();
		echo '<div class="wrap"><h1>Azure Insights Status</h1>';
		if ( function_exists( 'admin_url' ) ) {
			$base  = admin_url( 'options-general.php?page=' . \AzureInsightsMonolog\Admin\SettingsPage::PAGE_SLUG );
			$links = [ 
				'Connection' => $base . '&tab=connection',
				'Behavior'   => $base . '&tab=behavior',
				'Redaction'  => $base . '&tab=redaction',
			];
			$parts = [];
			foreach ( $links as $label => $url ) {
				$href    = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
				$lab     = function_exists( 'esc_html' ) ? esc_html( $label ) : htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
				$parts[] = '<a href="' . $href . '">' . $lab . '</a>';
			}
			echo '<p style="margin:8px 0 18px;">Configure: ' . implode( ' | ', $parts ) . '</p>';
		}
		echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
		foreach ( $status as $k => $v ) {
			echo '<tr><td>' . $this->esc( $k ) . '</td><td style="word-break:break-word">' . $this->esc( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:1em;font-size:12px;opacity:.8;">Filter aiw_enrich_dimensions and aiw_before_send_batch can extend telemetry; this panel surfaces operational counters.</p>';
		echo '</div>';
	}

	private function gather_status(): array {
		$data                                  = [];
		$get                                   = function ($k, $d                                   = '') {
			return function_exists( 'get_option' ) ? get_option( $k, $d ) : $d;
		};
		$data[ 'Last Send (UTC)' ]             = ( $ts = $get( 'aiw_last_send_time' ) ) ? gmdate( 'Y-m-d H:i:s', (int) $ts ) : '—';
		$data[ 'Last Error Code' ]             = $get( 'aiw_last_error_code', '' );
		$data[ 'Last Error Message' ]          = substr( (string) $get( 'aiw_last_error_message', '' ), 0, 140 );
		$data[ 'Retry Queue Depth' ]           = (string) count( (array) $get( 'aiw_retry_queue_v1', [] ) );
		$batches                               = (array) $get( 'aiw_async_batches', [] );
		$data[ 'Async Pending Batches' ]       = (string) count( $batches );
		$data[ 'Sampling Rate' ]               = $get( 'aiw_sampling_rate', '1' );
		$data[ 'Performance Metrics Enabled' ] = $get( 'aiw_enable_performance', 1 ) ? 'Yes' : 'No';
		$data[ 'Events API Enabled' ]          = $get( 'aiw_enable_events_api', 1 ) ? 'Yes' : 'No';
		$data[ 'Async Enabled' ]               = $get( 'aiw_async_enabled', 0 ) ? 'Yes' : 'No';
		$data[ 'Instrumentation Key Set' ]     = $get( 'aiw_instrumentation_key' ) ? 'Yes' : 'No';
		$data[ 'Connection String Set' ]       = $get( 'aiw_connection_string' ) ? 'Yes' : 'No';
		// Extended environment & runtime
		if ( defined( 'AIW_PLUGIN_VERSION' ) )
			$data[ 'Plugin Version' ] = AIW_PLUGIN_VERSION;
		if ( defined( 'WP_VERSION' ) )
			$data[ 'WP Version' ] = WP_VERSION;
		$data[ 'PHP Version' ] = PHP_VERSION;
		if ( function_exists( 'home_url' ) )
			$data[ 'Site URL' ] = (string) home_url();
		try {
			$client = Plugin::instance()->telemetry();
			if ( $client && method_exists( $client, 'debug_get_buffer' ) ) {
				$data[ 'Current Buffer Size' ] = (string) count( $client->debug_get_buffer() );
			}
			if ( $get( 'aiw_use_mock' ) && $client && method_exists( $client, 'sent_items' ) ) {
				$data[ 'Mock Persisted Items' ] = (string) count( $client->sent_items() );
			}
		} catch (\Throwable $e) {
		}
		$queue = (array) $get( 'aiw_retry_queue_v1', [] );
		if ( $queue ) {
			$nexts = array_filter( array_map( function ($e) {
				return isset( $e[ 'next_attempt' ] ) ? (int) $e[ 'next_attempt' ] : null;
			}, $queue ) );
			if ( $nexts ) {
				$data[ 'Next Retry (UTC)' ] = gmdate( 'Y-m-d H:i:s', min( $nexts ) );
			}
		}
		if ( $batches ) {
			$latest = max( array_map( function ($b) {
				return isset( $b[ 'time' ] ) ? (int) $b[ 'time' ] : 0;
			}, $batches ) );
			if ( $latest )
				$data[ 'Latest Async Batch (UTC)' ] = gmdate( 'Y-m-d H:i:s', $latest );
		}
		$data[ 'Slow Hook Threshold (ms)' ]  = (string) $get( 'aiw_slow_hook_threshold_ms', '150' );
		$data[ 'Slow Query Threshold (ms)' ] = (string) $get( 'aiw_slow_query_threshold_ms', '500' );
		$data[ 'Current Memory Usage (MB)' ] = number_format( memory_get_usage( true ) / 1048576, 2 );
		$data[ 'Peak Memory Usage (MB)' ]    = number_format( memory_get_peak_usage( true ) / 1048576, 2 );
		return $data;
	}
}
