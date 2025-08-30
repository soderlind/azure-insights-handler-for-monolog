<?php
namespace AzureInsightsWonolog\Handler;

use AzureInsightsWonolog\Telemetry\TelemetryClient;
use AzureInsightsWonolog\Plugin;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use AzureInsightsWonolog\Telemetry\Sampler;
use AzureInsightsWonolog\Telemetry\Redactor;

/**
 * Monolog handler that forwards records to Azure Application Insights.
 */
class AzureInsightsHandler extends AbstractProcessingHandler {

	/** @var TelemetryClient */
	private $client;

	/** @var array */
	private $config;

	private $sampler;

	public function __construct( TelemetryClient $client, array $config, $level = Logger::DEBUG, $bubble = true ) {
		$this->client  = $client;
		$this->config  = $config;
		$this->sampler = new Sampler( (float) ( $config[ 'sampling_rate' ] ?? 1 ) );
		parent::__construct( $level, $bubble );
	}

	protected function write( array $record ): void {
		list( $decision, $effective ) = $this->sampler->decide( $record );
		if ( function_exists( 'apply_filters' ) ) {
			$decision = apply_filters( 'aiw_should_sample', $decision, $record, $effective );
		}
		// Always keep error+ (>= 400 Monolog) even if decision false
		$level = $record[ 'level' ] ?? 200;
		if ( ! $decision && $level < Logger::ERROR ) {
			return;
		}

		$plugin              = Plugin::instance();
		$corr                = $plugin->correlation();
		$record[ 'context' ] = isset( $record[ 'context' ] ) && is_array( $record[ 'context' ] ) ? $record[ 'context' ] : [];
		$record[ 'context' ] = Redactor::redact( $record[ 'context' ] );
		// Add baseline properties & allow enrichment filter.
		if ( method_exists( $this->client, 'baseline_properties' ) ) {
			$record[ 'context' ] = array_merge( $this->client->baseline_properties(), $record[ 'context' ] );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$record[ 'context' ] = apply_filters( 'aiw_enrich_dimensions', $record[ 'context' ], $record );
		}

		if ( isset( $record[ 'context' ][ 'exception' ] ) && $record[ 'context' ][ 'exception' ] instanceof \Throwable ) {
			$ex = $record[ 'context' ][ 'exception' ];
			static $recentHashes = [];
			$hash = substr( hash( 'sha256', get_class( $ex ) . '|' . $ex->getMessage() . '|' . ( $ex->getFile() . ':' . $ex->getLine() ) ), 0, 16 );
			$skip = false;
			if ( isset( $recentHashes[ $hash ] ) && ( microtime( true ) - $recentHashes[ $hash ] ) < 30 ) {
				$skip = true; // dedupe identical exception for 30s window
			}
			$recentHashes[ $hash ] = microtime( true );
			if ( ! $skip ) {
				$exItem                                                             = $this->client->build_exception_item( $ex, $record, $corr->trace_id(), $corr->span_id() );
				$exItem[ 'data' ][ 'baseData' ][ 'properties' ][ 'exception_hash' ] = $hash;
				$this->client->add( $exItem );
			}
		} else {
			$item = $this->client->build_trace_item( $record, $corr->trace_id(), $corr->span_id() );
			$this->client->add( $item );
		}
	}

	// apply_redaction removed (now in Redactor)
}
