<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Telemetry;

/**
 * Manages correlation (trace & span IDs) for current request.
 */
class Correlation {
	private ?string $trace_id = null;
	private ?string $span_id = null;
	private ?string $parent_span_id = null;

	public function init(): void {
		$header = $this->get_server_header( 'HTTP_TRACEPARENT' );
		if ( $header && preg_match( '/^00-([a-f0-9]{32})-([a-f0-9]{16})-([0-9a-f]{2})$/', $header, $m ) ) {
			$this->trace_id       = $m[ 1 ];
			$this->parent_span_id = $m[ 2 ];
		} else {
			$this->trace_id = $this->generate_trace_id();
		}
		$this->span_id = $this->generate_span_id();
	}

	private function get_server_header( string $key ): ?string {
		return $_SERVER[ $key ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	private function generate_trace_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	private function generate_span_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	public function trace_id(): ?string {
		return $this->trace_id;
	}
	public function span_id(): ?string {
		return $this->span_id;
	}
	public function parent_span_id(): ?string {
		return $this->parent_span_id;
	}

	public function traceparent_header(): string {
		return sprintf( '00-%s-%s-01', (string) $this->trace_id, (string) $this->span_id );
	}
}
