<?php
declare(strict_types=1);
namespace AzureInsightsMonolog\Telemetry; // ensure consistency

/** Central redaction utility. */

class Redactor {
	/**
	 * Redact sensitive values in a context array.
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function redact( array $context ): array {
		$default_redact = [ 'password', 'pwd', 'pass', 'email', 'user_email', 'token' ];
		$keys           = $default_redact;
		if ( function_exists( 'apply_filters' ) )
			$keys = apply_filters( 'aiw_redact_keys', $keys );
		if ( function_exists( 'get_option' ) ) {
			$extra = get_option( 'aiw_redact_additional_keys', '' );
			if ( is_string( $extra ) && trim( $extra ) !== '' ) {
				$parts = array_filter( array_map( 'trim', explode( ',', $extra ) ) );
				if ( $parts )
					$keys = array_merge( $keys, $parts );
			}
		}
		if ( isset( $GLOBALS[ 'aiw_test_redact_extra_keys' ] ) ) {
			$g = $GLOBALS[ 'aiw_test_redact_extra_keys' ];
			if ( is_string( $g ) ) {
				$parts = array_filter( array_map( 'trim', explode( ',', $g ) ) );
				$keys  = array_merge( $keys, $parts );
			} elseif ( is_array( $g ) ) {
				$keys = array_merge( $keys, $g );
			}
		}
		$lower         = array_map( 'strtolower', $keys );
		$redacted_keys = [];
		$matched_pats  = [];
		foreach ( $context as $k => $v ) {
			if ( in_array( strtolower( (string) $k ), $lower, true ) ) {
				$context[ $k ]   = '[REDACTED]';
				$redacted_keys[] = (string) $k;
			}
		}
		$patterns = [];
		if ( function_exists( 'get_option' ) ) {
			$raw = get_option( 'aiw_redact_patterns', '' );
			if ( is_string( $raw ) && trim( $raw ) !== '' ) {
				$segs = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
				foreach ( $segs as $seg )
					if ( @preg_match( $seg, '' ) !== false )
						$patterns[] = $seg;
			}
		}
		if ( isset( $GLOBALS[ 'aiw_test_redact_patterns' ] ) ) {
			$p   = $GLOBALS[ 'aiw_test_redact_patterns' ];
			$add = [];
			if ( is_string( $p ) )
				$add = array_filter( array_map( 'trim', explode( ',', $p ) ) );
			elseif ( is_array( $p ) )
				$add = $p;
			foreach ( $add as $seg )
				if ( is_string( $seg ) && @preg_match( $seg, '' ) !== false )
					$patterns[] = $seg;
		}
		if ( $patterns ) {
			foreach ( $context as $k => $v ) {
				if ( is_string( $v ) ) {
					foreach ( $patterns as $pat ) {
						if ( @preg_match( $pat, $v ) ) {
							$context[ $k ]  = '[REDACTED]';
							$matched_pats[] = $pat;
							break;
						}
					}
				}
			}
		}
		if ( $redacted_keys || $matched_pats ) {
			$context[ '_aiw_redaction' ] = [ 'keys' => array_values( array_unique( $redacted_keys ) ), 'patterns' => array_values( array_unique( $matched_pats ) ) ];
		}
		return $context;
	}
}
