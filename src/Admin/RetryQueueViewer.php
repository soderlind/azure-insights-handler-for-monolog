<?php
namespace AzureInsightsWonolog\Admin;

use AzureInsightsWonolog\Plugin;

class RetryQueueViewer {
	private $slug = 'aiw-retry-queue';

	public function register() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', function () {
				if ( function_exists( 'add_submenu_page' ) ) {
					add_submenu_page( 'options-general.php', 'AIW Retry Queue', 'AIW Retry Queue', 'manage_options', $this->slug, [ $this, 'render' ] );
				}
			} );
			add_action( 'admin_post_aiw_clear_retry_queue', [ $this, 'handle_clear' ] );
		}
	}

	public function handle_clear() {
		if ( function_exists( 'check_admin_referer' ) ) {
			check_admin_referer( 'aiw_clear_retry' );
		}
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) )
			return;
		try {
			$ref  = new \ReflectionClass( Plugin::class);
			$inst = Plugin::instance();
			$prop = $ref->getProperty( 'retry_queue' );
			$prop->setAccessible( true );
			$queue = $prop->getValue( $inst );
			if ( $queue && method_exists( $queue, 'clear_all' ) ) {
				$queue->clear_all();
			}
		} catch (\Throwable $e) {
		}
		if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'admin_url' ) ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=' . $this->slug . '&cleared=1' ) );
			exit;
		}
	}

	public function render() {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) )
			return;
		echo '<div class="wrap"><h1>Azure Insights Retry Queue</h1>';
		$items = [];
		try {
			$ref  = new \ReflectionClass( Plugin::class);
			$inst = Plugin::instance();
			$prop = $ref->getProperty( 'retry_queue' );
			$prop->setAccessible( true );
			$queue = $prop->getValue( $inst );
			if ( $queue && method_exists( $queue, 'get_items' ) ) {
				$items = $queue->get_items();
			}
		} catch (\Throwable $e) {
		}
		if ( isset( $_GET[ 'cleared' ] ) ) {
			echo '<div class="notice notice-success"><p>Retry queue cleared.</p></div>';
		}
		if ( empty( $items ) ) {
			echo '<p>No queued batches.</p>';
		} else {
			echo '<p>Total batches: ' . (int) count( $items ) . '</p>';
			echo '<table class="widefat fixed striped"><thead><tr><th>#</th><th>Attempts</th><th>Next Attempt (UTC)</th><th>Items in Batch</th><th>Preview (first item)</th></tr></thead><tbody>';
			foreach ( $items as $i => $entry ) {
				$attempts = (int) ( $entry[ 'attempts' ] ?? 0 );
				$next     = isset( $entry[ 'next_attempt' ] ) ? gmdate( 'Y-m-d H:i:s', (int) $entry[ 'next_attempt' ] ) : '';
				$batch    = $entry[ 'batch' ] ?? [];
				$preview  = '';
				if ( $batch ) {
					$json    = function_exists( 'wp_json_encode' ) ? wp_json_encode( $batch[ 0 ] ) : json_encode( $batch[ 0 ] );
					$esc     = function_exists( 'esc_html' ) ? esc_html( $json ) : htmlspecialchars( (string) $json, ENT_QUOTES, 'UTF-8' );
					$preview = '<code style="white-space:pre;display:block;max-height:120px;overflow:auto;">' . $esc . '</code>';
				}
				$nextEsc = function_exists( 'esc_html' ) ? esc_html( $next ) : htmlspecialchars( $next, ENT_QUOTES, 'UTF-8' );
				echo '<tr><td>' . (int) $i . '</td><td>' . $attempts . '</td><td>' . $nextEsc . '</td><td>' . (int) count( $batch ) . '</td><td>' . $preview . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( function_exists( 'wp_nonce_field' ) && function_exists( 'admin_url' ) ) {
			$action_url = admin_url( 'admin-post.php' );
			$action_url = function_exists( 'esc_url' ) ? esc_url( $action_url ) : htmlspecialchars( $action_url, ENT_QUOTES, 'UTF-8' );
			echo '<form method="post" action="' . $action_url . '" style="margin-top:20px;">';
			echo '<input type="hidden" name="action" value="aiw_clear_retry_queue" />';
			wp_nonce_field( 'aiw_clear_retry' );
			echo '<button type="submit" class="button button-secondary" onclick="return confirm(\'Clear all queued batches?\');">Clear Queue</button>';
			echo '</form>';
		}
		echo '</div>';
	}
}
