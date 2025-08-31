<?php
declare(strict_types=1);
namespace AzureInsightsMonolog\Security;

class Secrets {
	private const PREFIX = 'enc:';

	private static function key(): ?string {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY )
			return hash( 'sha256', AUTH_KEY, true );
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY )
			return hash( 'sha256', SECURE_AUTH_KEY, true );
		if ( function_exists( 'wp_salt' ) )
			return hash( 'sha256', wp_salt( 'auth' ), true );
		return null; // no key context
	}

	public static function is_encrypted( ?string $value ): bool {
		return is_string( $value ) && str_starts_with( $value, self::PREFIX );
	}

	public static function encrypt( string $plain ): string {
		$plain = trim( $plain );
		if ( $plain === '' )
			return $plain;
		if ( self::is_encrypted( $plain ) )
			return $plain;
		if ( ! function_exists( 'openssl_encrypt' ) )
			return $plain; // fallback
		$key = self::key();
		if ( ! $key )
			return $plain;
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( $cipher === false )
			return $plain;
		return self::PREFIX . base64_encode( $iv . $cipher );
	}

	public static function decrypt( ?string $value ): ?string {
		if ( ! self::is_encrypted( $value ) )
			return $value;
		if ( ! function_exists( 'openssl_decrypt' ) )
			return $value;
		$key = self::key();
		if ( ! $key )
			return $value;
		$b64 = substr( $value, strlen( self::PREFIX ) );
		$raw = base64_decode( $b64, true );
		if ( $raw === false || strlen( $raw ) < 17 )
			return $value;
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return $plain === false ? $value : $plain;
	}
}
