<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Telemetry;

use Monolog\Logger;

/** Encapsulated sampling + adaptive adjustment logic. */
class Sampler {
	private float $baseRate;
	/** @var array<int,int> */
	private static array $window = [];

	public function __construct( float $baseRate ) {
		$this->baseRate = max( 0.0, min( 1.0, $baseRate ) );
	}
	/** Decide if record should be kept. Errors always kept. Returns [bool decision, float effectiveRate]. */
	public function decide( array $record ): array {
		$now    = time();
		$bucket = $now - ( $now % 10 );
		if ( ! isset( self::$window[ $bucket ] ) ) {
			foreach ( array_keys( self::$window ) as $b ) {
				if ( $b < $bucket ) {
					unset( self::$window[ $b ] );
				}
			}
			self::$window[ $bucket ] = 0;
		}
		self::$window[ $bucket ]++;
		$effective = $this->baseRate;
		if ( ( $record[ 'level' ] ?? Logger::INFO ) < Logger::WARNING && self::$window[ $bucket ] > 50 && $effective > 0.1 ) {
			$effective = max( 0.1, $effective * 0.5 );
		}
		$decision = true;
		if ( ( $record[ 'level' ] ?? Logger::INFO ) < Logger::ERROR && $effective < 1.0 ) {
			$decision = ( mt_rand() / mt_getrandmax() ) <= $effective;
		}
		if ( ( $record[ 'level' ] ?? Logger::INFO ) >= Logger::ERROR ) {
			$decision = true;
		}
		return [ $decision, $effective ];
	}
}
