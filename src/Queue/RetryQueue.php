<?php
declare(strict_types=1);
namespace AzureInsightsWonolog\Queue;

/**
 * Simple retry queue stored in a transient/option.
 */
class RetryQueue {
	private string $option_key = 'aiw_retry_queue_v1';
	/** @var int[] */
	private array $schedule; // array of seconds delays
	private bool $use_transient = false;

	/** @param int[] $schedule */
	public function __construct( array $schedule = [ 60, 300, 900, 3600 ] ) {
		$this->schedule = $schedule;
		// Opt-in to transient storage via constant AIW_RETRY_STORAGE = 'transient'.
		if ( defined( 'AIW_RETRY_STORAGE' ) && strtolower( AIW_RETRY_STORAGE ) === 'transient' && function_exists( 'get_transient' ) ) {
			$this->use_transient = true;
		}
	}

	private function load(): array {
		$data = [];
		if ( $this->use_transient && function_exists( 'get_transient' ) ) {
			$loaded = get_transient( $this->option_key . '_t' );
			if ( $loaded === false ) {
				// Fallback / migration from option if present.
				$opt = function_exists( 'get_option' ) ? get_option( $this->option_key, [] ) : [];
				if ( is_array( $opt ) ) {
					$loaded = $opt;
					// Seed transient for next time.
					if ( function_exists( 'set_transient' ) ) {
						set_transient( $this->option_key . '_t', $opt, 0 );
					}
				}
			}
			$data = is_array( $loaded ) ? $loaded : [];
		} elseif ( function_exists( 'get_option' ) ) {
			$opt  = get_option( $this->option_key, [] );
			$data = is_array( $opt ) ? $opt : [];
		} else {
			global $aiw_retry_store;
			if ( isset( $aiw_retry_store[ $this->option_key ] ) && is_array( $aiw_retry_store[ $this->option_key ] ) ) {
				$data = $aiw_retry_store[ $this->option_key ];
			}
		}
		return $data;
	}

	private function save( array $queue ): void {
		if ( $this->use_transient && function_exists( 'set_transient' ) ) {
			set_transient( $this->option_key . '_t', $queue, 0 ); // no expiry; cron/backoff controls lifetime
			// Maintain shadow option for durability & status display
			if ( function_exists( 'update_option' ) ) {
				update_option( $this->option_key, $queue, false );
			}
		} elseif ( function_exists( 'update_option' ) ) {
			update_option( $this->option_key, $queue, false );
		} else {
			global $aiw_retry_store;
			$aiw_retry_store[ $this->option_key ] = $queue;
		}
	}

	/** @param array<int,array<string,mixed>> $batch */
	public function enqueue( array $batch ): void {
		$queue   = $this->load();
		$queue[] = [ 
			'batch'        => $batch,
			'attempts'     => 0,
			'next_attempt' => time() + $this->schedule[ 0 ],
		];
		$maxSize = defined( 'AIW_RETRY_QUEUE_MAX' ) ? (int) AIW_RETRY_QUEUE_MAX : 100;
		if ( $maxSize > 0 && count( $queue ) > $maxSize ) {
			$queue = array_slice( $queue, -1 * $maxSize );
		}
		$this->save( $queue );
	}

	public function due(): array {
		$now   = time();
		$queue = $this->load();
		$due   = [];
		foreach ( $queue as $idx => $item ) {
			if ( $item[ 'next_attempt' ] <= $now ) {
				$due[ $idx ] = $item;
			}
		}
		return $due;
	}

	public function mark_attempt( int $index, bool $success ): void {
		$queue = $this->load();
		if ( ! isset( $queue[ $index ] ) )
			return;
		// Backward compatibility guard: ensure attempts key exists.
		if ( ! isset( $queue[ $index ][ 'attempts' ] ) ) {
			$queue[ $index ][ 'attempts' ] = 0;
		}
		if ( $success ) {
			unset( $queue[ $index ] );
		} else {
			$queue[ $index ][ 'attempts' ]++;
			$a = $queue[ $index ][ 'attempts' ];
			if ( $a >= count( $this->schedule ) ) {
				unset( $queue[ $index ] ); // drop
			} else {
				$queue[ $index ][ 'next_attempt' ] = time() + $this->schedule[ $a ];
			}
		}
		$this->save( $queue );
	}

	public function option_key(): string {
		return $this->option_key;
	}

	/** Return full queue for diagnostics */
	public function get_items(): array {
		return $this->load();
	}

	/** Clear entire retry queue */
	public function clear_all(): void {
		$this->save( [] );
	}
}
