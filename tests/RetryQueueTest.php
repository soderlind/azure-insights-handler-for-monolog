<?php
namespace AzureInsightsWonolog\Tests;

use PHPUnit\Framework\TestCase;
use AzureInsightsWonolog\Queue\RetryQueue;

// No WP functions: rely on fallback storage inside RetryQueue using global $aiw_retry_store

class RetryQueueTest extends TestCase {
	public function setUp(): void {
		global $aiw_retry_store;
		$aiw_retry_store = [];
	}

	public function testEnqueueAddsItemWithInitialSchedule() {
		$q = new RetryQueue( [ 1, 2, 3 ] );
		$q->enqueue( [ [ 'a' => 1 ] ] );
		if ( function_exists( 'get_option' ) ) {
			$queue = get_option( 'aiw_retry_queue_v1', [] );
		} else {
			global $aiw_retry_store;
			$queue = $aiw_retry_store[ 'aiw_retry_queue_v1' ] ?? [];
		}
		$this->assertNotEmpty( $queue, 'Queue should have one item after enqueue' );
		$this->assertEquals( 0, $queue[ 0 ][ 'attempts' ] );
	}

	public function testDueReturnsItemsReadyForRetry() {
		$q = new RetryQueue( [ 1, 2 ] );
		$q->enqueue( [ [ 'x' => 1 ] ] );
		if ( function_exists( 'get_option' ) ) {
			$queue                        = get_option( 'aiw_retry_queue_v1', [] );
			$queue[ 0 ][ 'next_attempt' ] = time() - 5;
			update_option( 'aiw_retry_queue_v1', $queue );
		} else {
			global $aiw_retry_store;
			$queue                                   = $aiw_retry_store[ 'aiw_retry_queue_v1' ];
			$queue[ 0 ][ 'next_attempt' ]            = time() - 5;
			$aiw_retry_store[ 'aiw_retry_queue_v1' ] = $queue;
		}
		$due = $q->due();
		$this->assertCount( 1, $due );
	}

	public function testMarkAttemptSuccessRemovesItem() {
		$q = new RetryQueue( [ 1, 2 ] );
		$q->enqueue( [ [ 'y' => 2 ] ] );
		if ( function_exists( 'get_option' ) ) {
			$queue                        = get_option( 'aiw_retry_queue_v1', [] );
			$queue[ 0 ][ 'next_attempt' ] = time() - 1;
			update_option( 'aiw_retry_queue_v1', $queue );
		} else {
			global $aiw_retry_store;
			$queue                                   = $aiw_retry_store[ 'aiw_retry_queue_v1' ];
			$queue[ 0 ][ 'next_attempt' ]            = time() - 1;
			$aiw_retry_store[ 'aiw_retry_queue_v1' ] = $queue;
		}
		$due = $q->due();
		$this->assertNotEmpty( $due );
		$q->mark_attempt( array_key_first( $due ), true );
		$due2 = $q->due();
		$this->assertCount( 0, $due2 );
	}

	public function testMarkAttemptFailureReschedulesOrDrops() {
		$q = new RetryQueue( [ 1, 2 ] );
		$q->enqueue( [ [ 'y' => 2 ] ] );
		if ( function_exists( 'get_option' ) ) {
			$queue                        = get_option( 'aiw_retry_queue_v1', [] );
			$queue[ 0 ][ 'next_attempt' ] = time() - 1;
			update_option( 'aiw_retry_queue_v1', $queue );
		} else {
			global $aiw_retry_store;
			$queue                                   = $aiw_retry_store[ 'aiw_retry_queue_v1' ];
			$queue[ 0 ][ 'next_attempt' ]            = time() - 1;
			$aiw_retry_store[ 'aiw_retry_queue_v1' ] = $queue;
		}
		$due = $q->due();
		$this->assertNotEmpty( $due );
		$index = array_key_first( $due );
		$q->mark_attempt( $index, false ); // first failure => reschedule
		if ( function_exists( 'get_option' ) ) {
			$after = get_option( 'aiw_retry_queue_v1', [] );
		} else {
			global $aiw_retry_store;
			$after = $aiw_retry_store[ 'aiw_retry_queue_v1' ];
		}
		$this->assertEquals( 1, $after[ 0 ][ 'attempts' ] );
		$after[ 0 ][ 'next_attempt' ] = time() - 1;
		if ( function_exists( 'update_option' ) ) {
			update_option( 'aiw_retry_queue_v1', $after );
		} else {
			$aiw_retry_store[ 'aiw_retry_queue_v1' ] = $after;
		}
		$dueAgain = $q->due();
		$this->assertNotEmpty( $dueAgain );
		$q->mark_attempt( $index, false ); // second failure exceeds schedule => drop
		$final = $q->due();
		$this->assertCount( 0, $final );
	}
}
