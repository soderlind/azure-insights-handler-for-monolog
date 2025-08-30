<?php
namespace AzureInsightsWonolog\CLI;

use WP_CLI;
use AzureInsightsWonolog\Plugin;

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
	/**
	 * Azure Insights Wonolog integration commands.
	 */
	class Commands {
		/**
		 * Show telemetry status (last send, queue depth, last error).
		 *
		 * ## EXAMPLES
		 *
		 *     wp aiw status
		 */
		public function status( $args, $assoc_args ) {
			$status               = [ 'last_send' => function_exists( 'get_option' ) ? get_option( 'aiw_last_send_time' ) : null ];
			$status[ 'queue' ]      = function_exists( 'get_option' ) ? count( (array) get_option( 'aiw_retry_queue_v1', [] ) ) : 0;
			$status[ 'error_code' ] = function_exists( 'get_option' ) ? get_option( 'aiw_last_error_code', '' ) : '';
			foreach ( $status as $k => $v ) {
				if ( class_exists( 'WP_CLI' ) && method_exists( '\WP_CLI', 'log' ) ) {
					\WP_CLI::log( sprintf( '%s: %s', $k, is_scalar( $v ) ? $v : json_encode( $v ) ) );
				}
			}
		}

		/**
		 * Send a test trace + event + metric (and optional exception) to verify configuration.
		 *
		 * ## OPTIONS
		 *
		 * [--error]
		 * : Include a test exception.
		 *
		 * ## EXAMPLES
		 *
		 *     wp aiw test --error
		 */
		public function test( $args, $assoc_args ) {
			$plugin = Plugin::instance();
			if ( ! $plugin->telemetry() && class_exists( 'WP_CLI' ) && method_exists( '\WP_CLI', 'error' ) ) {
				\WP_CLI::error( 'Telemetry client not initialized.' );
			}
			$corr   = $plugin->correlation();
			$client = $plugin->telemetry();
			$client->add( $client->build_trace_item( [ 'level' => 200, 'message' => 'CLI test trace', 'context' => [ 'origin' => 'wp-cli' ] ], $corr->trace_id(), $corr->span_id() ) );
			$client->add( $client->build_event_item( 'CLI.TestEvent', [ 'foo' => 'bar' ], [ 'value' => 1 ], $corr->trace_id(), $corr->span_id() ) );
			$client->add( $client->build_metric_item( 'cli_test_metric', 1.23, [ 'unit' => 's' ], $corr->trace_id(), $corr->span_id() ) );
			if ( isset( $assoc_args[ 'error' ] ) ) {
				$ex = new \RuntimeException( 'CLI test exception' );
				$client->add( $client->build_exception_item( $ex, [ 'level' => 400 ], $corr->trace_id(), $corr->span_id() ) );
			}
			$client->flush();
			if ( class_exists( 'WP_CLI' ) && method_exists( '\WP_CLI', 'success' ) ) {
				\WP_CLI::success( 'Test telemetry dispatched.' );
			}
		}

		/**
		 * Flush retry queue immediately (attempt resend of due batches regardless of schedule).
		 */
		public function flush_queue( $args, $assoc_args ) {
			$queue = function_exists( 'get_option' ) ? (array) get_option( 'aiw_retry_queue_v1', [] ) : [];
			if ( empty( $queue ) ) {
				if ( class_exists( 'WP_CLI' ) && method_exists( '\WP_CLI', 'log' ) ) {
					\WP_CLI::log( 'Retry queue empty.' );
				}
				return;
			}
			$plugin = Plugin::instance();
			$sent   = 0;
			foreach ( $queue as $index => $item ) {
				$batch = $item[ 'batch' ] ?? [];
				if ( empty( $batch ) )
					continue;
				$payload_lines = [];
				foreach ( $batch as $i ) {
					$payload_lines[] = wp_json_encode( $i );
				}
				$body = implode( "\n", $payload_lines );
				$resp = function_exists( 'wp_remote_post' ) ? wp_remote_post( 'https://dc.services.visualstudio.com/v2/track', [ 'body' => $body, 'timeout' => 3, 'blocking' => true, 'headers' => [ 'Content-Type' => 'application/x-json-stream' ] ] ) : null;
				$code = ( function_exists( 'is_wp_error' ) && is_wp_error( $resp ) ) ? 0 : ( ( function_exists( 'wp_remote_retrieve_response_code' ) && is_array( $resp ) ) ? (int) wp_remote_retrieve_response_code( $resp ) : 0 );
				if ( $code >= 200 && $code < 300 ) {
					unset( $queue[ $index ] );
					$sent++;
				}
			}
			if ( function_exists( 'update_option' ) ) {
				update_option( 'aiw_retry_queue_v1', array_values( $queue ), false );
			}
			if ( class_exists( 'WP_CLI' ) ) {
				\WP_CLI::success( sprintf( 'Flushed %d batch(es). Remaining: %d', $sent, count( $queue ) ) );
			}
		}
	}

	if ( class_exists( 'WP_CLI' ) ) {
		\WP_CLI::add_command( 'aiw', Commands::class);
	}
}
