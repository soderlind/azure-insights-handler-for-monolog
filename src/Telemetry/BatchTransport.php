<?php
declare(strict_types=1);
namespace AzureInsightsMonolog\Telemetry; // already renamed earlier (ensure consistency)

/**
 * Extracted transport responsible solely for delivering newline-delimited JSON payloads.
 * Keeps TelemetryClient focused on buffering & item construction.
 */
class BatchTransport {
	private string $ingest_url;

	public function __construct( string $ingest_url ) {
		$this->ingest_url = $ingest_url ?: TelemetryClient::DEFAULT_INGEST_URL;
	}

	/**
	 * Send batch lines (already JSON-encoded one per line).
	 * @param array $lines JSON strings
	 * @param array $original_items Original items (for retry callbacks)
	 * @param callable|null $on_failure Invoked with original items if delivery fails.
	 * @return bool True on success, false on failure.
	 */
	public function send( array $lines, array $original_items, ?callable $on_failure = null ): bool {
		if ( empty( $lines ) )
			return true;
		$body      = implode( "\n", $lines );
		$args      = [ 
			'body'     => $body,
			'timeout'  => 2,
			'blocking' => true,
			'headers'  => [ 'Content-Type' => 'application/x-json-stream' ],
		];
		$had_error = false;
		if ( function_exists( 'wp_remote_post' ) ) {
			$response = wp_remote_post( $this->ingest_url, $args );
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				$had_error = true;
				if ( $on_failure )
					$on_failure( $original_items );
				if ( function_exists( 'update_option' ) ) {
					update_option( 'aiw_last_error_code', 'transport' );
					update_option( 'aiw_last_error_message', $response->get_error_message() );
				}
			} else {
				$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
				if ( $code < 200 || $code >= 300 ) {
					$had_error = true;
					if ( $on_failure )
						$on_failure( $original_items );
					if ( function_exists( 'update_option' ) ) {
						update_option( 'aiw_last_error_code', (string) $code );
						$body_excerpt = '';
						if ( is_array( $response ) && isset( $response[ 'body' ] ) ) {
							$body_excerpt = substr( (string) $response[ 'body' ], 0, 200 );
						}
						update_option( 'aiw_last_error_message', $body_excerpt );
					}
				}
			}
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( 'aiw_last_send_time', time() );
			if ( ! $had_error ) {
				update_option( 'aiw_last_error_code', '' );
				update_option( 'aiw_last_error_message', '' );
			}
		}
		return ! $had_error;
	}
}
