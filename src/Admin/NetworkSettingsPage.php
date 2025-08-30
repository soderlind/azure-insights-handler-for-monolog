<?php
declare(strict_types=1);

/*
 * Polyfill minimal WordPress helper functions when running in a non-WP
 * static analysis / test context so this file does not trigger undefined
 * function errors. These are intentionally lightweight and ONLY aim to
 * satisfy tooling. In real WordPress runtime the core implementations
 * will already exist and these will be skipped.
 */
namespace {
	if ( ! function_exists( 'esc_html' ) ) {
		function esc_html( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_attr' ) ) {
		function esc_attr( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_url' ) ) {
		function esc_url( $url ) {
			return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'esc_textarea' ) ) {
		function esc_textarea( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		}
	}
	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( $key ) {
			return preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $key ) );
		}
	}
	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( $str ) {
			$str = (string) $str;
			$str = strip_tags( $str );
			return trim( $str );
		}
	}
	if ( ! function_exists( 'wp_unslash' ) ) {
		function wp_unslash( $value ) {
			return is_string( $value ) ? stripslashes( $value ) : $value;
		}
	}
}

namespace AzureInsightsWonolog\Admin {

	/**
	 * Full-featured network admin settings page providing parity with per-site SettingsPage.
	 * Uses site options (get_site_option / update_site_option) and hides per-site page when network activated.
	 */
	class NetworkSettingsPage {
		private string $slug = 'aiw-network-settings';
		private string $cap = 'manage_network_options';
		private array $tabs = [ 'status', 'connection', 'behavior', 'redaction', 'test' ];

		public function register(): void {
			if ( ! function_exists( 'add_action' ) )
				return;
			add_action( 'network_admin_menu', function () {
				if ( ! function_exists( 'add_menu_page' ) )
					return;
				$hook = add_menu_page( 'Azure Insights (Network)', 'Azure Insights', $this->cap, $this->slug, [ $this, 'render' ], 'dashicons-chart-line' );
				// Enqueue shared admin CSS
				if ( $hook && function_exists( 'add_action' ) ) {
					add_action( 'admin_enqueue_scripts', function ($h) use ($hook) {
						if ( $h === $hook && function_exists( 'wp_enqueue_style' ) ) {
							wp_enqueue_style( 'aiw-admin', defined( 'AIW_PLUGIN_URL' ) ? AIW_PLUGIN_URL . 'assets/css/aiw-admin.css' : '', [], defined( 'AIW_PLUGIN_VERSION' ) ? AIW_PLUGIN_VERSION : '1.0' );
						}
					} );
				}
			} );
			add_action( 'network_admin_edit_aiw_network_save', [ $this, 'save' ] );
		}

		private function so( string $key, $default = '' ) {
			return function_exists( 'get_site_option' ) ? get_site_option( $key, $default ) : $default;
		}

		private function active_tab(): string {
			$tab = isset( $_GET[ 'tab' ] ) ? sanitize_key( $_GET[ 'tab' ] ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return in_array( $tab, $this->tabs, true ) ? $tab : 'status';
		}

		public function render(): void {
			if ( function_exists( 'current_user_can' ) && ! current_user_can( $this->cap ) )
				return;
			// Handle test telemetry
			$this->handle_test_request();
			echo '<div class="wrap"><h1>Azure Insights – Network</h1>';
			$this->nav();
			switch ( $this->active_tab() ) {
				case 'connection':
					$this->tab_connection();
					break;
				case 'behavior':
					$this->tab_behavior();
					break;
				case 'redaction':
					$this->tab_redaction();
					break;
				case 'test':
					$this->tab_test();
					break;
				default:
					$this->tab_status();
					break;
			}
			echo '</div>';
		}

		private function nav(): void {
			$base   = function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php?page=' . $this->slug ) : '?page=' . $this->slug;
			$labels = [ 
				'status'     => [ 'Status', 'dashicons-chart-area' ],
				'connection' => [ 'Connection', 'dashicons-admin-links' ],
				'behavior'   => [ 'Behavior', 'dashicons-admin-settings' ],
				'redaction'  => [ 'Redaction', 'dashicons-privacy' ],
				'test'       => [ 'Test Telemetry', 'dashicons-controls-repeat' ],
			];
			$active = $this->active_tab();
			echo '<h2 class="nav-tab-wrapper aiw-modern-nav">';
			foreach ( $labels as $slug => $meta ) {
				[ $label, $icon ] = $meta;
				$url           = $base . '&tab=' . $slug;
				$cls           = 'nav-tab' . ( $slug === $active ? ' nav-tab-active' : '' );
				$escL          = esc_html( $label );
				$escU          = esc_url( $url );
				echo '<a class="' . $cls . '" href="' . $escU . '"><span class="dashicons ' . $icon . '"></span>' . $escL . '</a>';
			}
			echo '</h2>';
		}

		/* ---------- STATUS TAB ---------- */
		private function tab_status(): void {
			echo '<p class="aiw-intro">Network-wide operational snapshot.</p>';
			$groups  = $this->dashboard_sections();
			$summary = $this->dashboard_summary( $groups );
			echo '<div class="aiw-dashboard"><div class="aiw-summary-cards">';
			foreach ( $summary as $row ) {
				[ $label, $value, $state ] = $row;
				$badge                 = $state ? '<span class="aiw-badge ' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>' : '';
				echo '<div class="card"><h3 style="margin:0 0 4px;font-size:13px;color:var(--aiw-text-light);font-weight:500;">' . esc_html( $label ) . $badge . '</h3><div class="aiw-metric-val">' . esc_html( $value ) . '</div></div>';
			}
			echo '</div>';
			foreach ( $groups as $group => $cards ) {
				echo '<h2 class="aiw-section-header">' . esc_html( $group ) . '</h2><div class="aiw-section-wrap">';
				foreach ( $cards as $card ) {
					$title = esc_html( $card[ 'title' ] );
					echo '<div class="card"><h3 class="aiw-card-title">' . $title . '</h3>';
					if ( ! empty( $card[ 'lines' ] ) ) {
						echo '<table class="aiw-kv-table"><tbody>';
						foreach ( $card[ 'lines' ] as $kv ) {
							$k     = esc_html( $kv[ 0 ] );
							$v     = esc_html( (string) $kv[ 1 ] );
							$state = $kv[ 2 ] ?? '';
							$sb    = $state ? '<span class="aiw-badge ' . esc_attr( $state ) . '">' . esc_html( $state ) . '</span>' : '';
							echo '<tr><td>' . $k . '</td><td><code>' . $v . '</code> ' . $sb . '</td></tr>';
						}
						echo '</tbody></table>';
					}
					if ( ! empty( $card[ 'footer' ] ) )
						echo '<p class="aiw-card-footer">' . esc_html( $card[ 'footer' ] ) . '</p>';
					echo '</div>';
				}
				echo '</div>';
			}
			echo '</div>';
		}

		/* ---------- CONNECTION TAB ---------- */
		private function tab_connection(): void {
			echo '<p class="aiw-intro">Configure Azure connection at network scope. Overrides per-site options.</p>';
			$this->open_form();
			echo '<div class="aiw-form-card"><h2>🔗 Connection</h2><table class="aiw-form-table" role="presentation">';
			$this->field_checkbox( 'aiw_use_mock', 'Mock Mode', 'Enable local telemetry storage (no HTTP requests)' );
			$this->field_secret( 'aiw_connection_string', 'Connection String', 'InstrumentationKey=...;IngestionEndpoint=https://...' );
			$this->field_secret( 'aiw_instrumentation_key', 'Instrumentation Key (Legacy)', '00000000-0000-0000-0000-000000000000' );
			echo '</table></div>';
			$this->close_form();
		}

		/* ---------- BEHAVIOR TAB ---------- */
		private function tab_behavior(): void {
			echo '<p class="aiw-intro">Sampling, batching and performance thresholds (network-wide).</p>';
			$this->open_form();
			echo '<div class="aiw-form-card"><h2>⚙️ Sampling & Performance</h2><table class="aiw-form-table" role="presentation">';
			$this->field_select( 'aiw_min_level', 'Minimum Log Level', [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ] );
			$this->field_sampling();
			echo '</table></div>';
			echo '<div class="aiw-form-card"><h2>📦 Batching</h2><table class="aiw-form-table" role="presentation">';
			$this->field_number( 'aiw_batch_max_size', 'Batch Size', (int) $this->so( 'aiw_batch_max_size', 20 ), 1, 100, 'Maximum telemetry items per batch.' );
			$this->field_number( 'aiw_batch_flush_interval', 'Flush Interval (s)', (int) $this->so( 'aiw_batch_flush_interval', 10 ), 1, 300, 'Auto-flush incomplete batches after this many seconds.' );
			$this->field_checkbox( 'aiw_async_enabled', 'Async Processing', 'Defer sending via cron to reduce request latency' );
			echo '</table></div>';
			echo '<div class="aiw-form-card"><h2>📊 Performance Monitoring</h2><table class="aiw-form-table" role="presentation">';
			$this->field_number( 'aiw_slow_hook_threshold_ms', 'Slow Hook Threshold (ms)', (int) $this->so( 'aiw_slow_hook_threshold_ms', 150 ), 10, 5000, 'Record hook metrics above this duration.' );
			$this->field_number( 'aiw_slow_query_threshold_ms', 'Slow Query Threshold (ms)', (int) $this->so( 'aiw_slow_query_threshold_ms', 500 ), 10, 10000, 'Record db_slow_query_ms metrics above this duration.' );
			echo '</table></div>';
			echo '<div class="aiw-form-card"><h2>🔧 Feature Toggles</h2>';
			$this->field_checkbox( 'aiw_enable_performance', 'Performance Metrics', 'Hook timing, slow queries, cron metrics' );
			$this->field_checkbox( 'aiw_enable_events_api', 'Events & Metrics API', 'aiw_event() and aiw_metric() helpers' );
			$this->field_checkbox( 'aiw_enable_internal_diagnostics', 'Internal Diagnostics', 'Debug logging to error_log' );
			echo '<p class="aiw-description">Disable unused features to reduce overhead.</p></div>';
			$this->close_form();
		}

		/* ---------- REDACTION TAB ---------- */
		private function tab_redaction(): void {
			echo '<p class="aiw-intro">Network-wide privacy controls.</p>';
			$this->open_form();
			echo '<div class="aiw-form-card"><h2>🔒 Privacy & Redaction</h2><table class="aiw-form-table" role="presentation">';
			$this->field_textarea( 'aiw_redact_additional_keys', 'Sensitive Keys', $this->so( 'aiw_redact_additional_keys', '' ), 'Comma-separated keys to redact in addition to built-ins.' );
			$this->field_textarea( 'aiw_redact_patterns', 'Regex Patterns', $this->so( 'aiw_redact_patterns', '' ), 'Comma-separated PCRE patterns for value redaction.' );
			echo '</table></div>';
			$this->close_form();
		}

		/* ---------- TEST TAB ---------- */
		private function tab_test(): void {
			echo '<p class="aiw-intro">Send sample telemetry to verify network configuration.</p>';
			$this->render_test_form();
		}

		/* ---------- FORMS & FIELDS HELPERS ---------- */
		private function open_form(): void {
			$action = function_exists( 'network_admin_url' ) ? network_admin_url( 'edit.php?action=aiw_network_save' ) : '';
			echo '<form method="post" action="' . esc_url( $action ) . '">';
			if ( function_exists( 'wp_nonce_field' ) )
				wp_nonce_field( 'aiw_network_save', '_aiw_ns' );
		}
		private function close_form(): void {
			echo '<p class="submit"><button type="submit" class="button-primary">Save Changes</button></p></form>';
		}

		private function field_checkbox( string $key, string $label, string $desc = '' ): void {
			$val     = (int) $this->so( $key, 0 );
			$checked = $val ? 'checked' : '';
			$id      = esc_attr( $key );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label class="aiw-checkbox-wrapper" style="padding:0;background:none;">';
			echo '<input type="checkbox" name="' . $id . '" value="1" ' . $checked . ' /> ' . $label . '</label>';
			if ( $desc )
				echo '<p class="aiw-description">' . esc_html( $desc ) . '</p>';
			echo '</td></tr>';
		}
		private function field_secret( string $key, string $label, string $placeholder = '' ): void {
			$raw     = (string) $this->so( $key, '' );
			$isEnc   = \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw );
			$display = $isEnc ? '•••••• (encrypted)' : $raw;
			$val     = esc_attr( $display );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="text" class="aiw-input regular-text" name="' . esc_attr( $key ) . '" value="' . $val . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off" />';
			if ( $isEnc )
				echo '<span class="aiw-status-indicator encrypted" style="margin-left:8px;">🔒 Encrypted</span>';
			echo '</td></tr>';
		}
		private function field_select( string $key, string $label, array $options ): void {
			$current = (string) $this->so( $key, 'info' );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><select name="' . esc_attr( $key ) . '" class="aiw-input" style="width:200px;">';
			foreach ( $options as $opt ) {
				$sel = $opt === $current ? 'selected' : '';
				echo '<option value="' . esc_attr( $opt ) . '" ' . $sel . '>' . esc_html( ucfirst( $opt ) ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		private function field_sampling(): void {
			$val = (string) $this->so( 'aiw_sampling_rate', '1' );
			if ( ! is_numeric( $val ) )
				$val = '1';
			$pct = round( (float) $val * 100 );
			echo '<tr><th scope="row">Sampling Rate</th><td><div class="aiw-input-group"><div style="display:flex;align-items:center;gap:16px;margin-bottom:8px;">';
			echo '<input type="range" min="0" max="1" step="0.01" value="' . esc_attr( $val ) . '" name="aiw_sampling_rate" style="flex:1;height:8px;" oninput="this.nextElementSibling.textContent=Math.round(this.value*100)+\'%\'" />';
			echo '<span style="min-width:40px;font-weight:600;color:#667eea;">' . $pct . '%</span></div><p class="aiw-description">Percentage of non-error traces/requests to send (errors always kept).</p></div></td></tr>';
		}
		private function field_number( string $key, string $label, int $value, int $min, int $max, string $desc = '' ): void {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="number" class="aiw-input" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) $value ) . '" min="' . $min . '" max="' . $max . '" style="width:110px;" />';
			if ( $desc )
				echo '<p class="aiw-description">' . esc_html( $desc ) . '</p>';
			echo '</td></tr>';
		}
		private function field_textarea( string $key, string $label, string $value, string $desc = '' ): void {
			$val = esc_textarea( $value );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><textarea name="' . esc_attr( $key ) . '" rows="3" class="aiw-input large-text" style="font-family:ui-monospace,monospace;resize:vertical;">' . $val . '</textarea>';
			if ( $desc )
				echo '<p class="aiw-description">' . esc_html( $desc ) . '</p>';
			echo '</td></tr>';
		}

		/* ---------- TEST FORM ---------- */
		private function render_test_form(): void {
			if ( ! function_exists( 'wp_nonce_field' ) ) {
				echo '<div class="aiw-form-card"><p>WordPress context unavailable.</p></div>';
				return;
			}
			echo '<div class="aiw-form-card"><h2>🧪 Test Telemetry</h2>';
			echo '<form method="post" action="">';
			wp_nonce_field( 'aiw_network_test', 'aiw_test_nonce' );
			echo '<table class="aiw-form-table" role="presentation"><tr><th scope="row">Test Type</th><td><select name="aiw_test_kind" class="aiw-input" style="width:220px;"><option value="info">Info Trace + Metrics</option><option value="error">Error Trace + Exception</option></select> <button class="button button-primary" style="padding:6px 18px;">Send</button><input type="hidden" name="aiw_send_test" value="1" />';
			echo '<p class="aiw-description">Appears in Azure (traces / customMetrics) usually within 1–2 minutes.</p></td></tr></table></form>';
			if ( isset( $_GET[ 'aiw_test_sent' ] ) ) {
				$ok  = (int) $_GET[ 'aiw_test_sent' ] === 1;
				$msg = $ok ? 'Test telemetry dispatched.' : 'Test telemetry failed.';
				echo '<div style="margin-top:12px;padding:12px;border-radius:8px;' . ( $ok ? 'background:#e7f7ed;border:1px solid #7ad03a;color:#185b37;' : 'background:#fce5e5;border:1px solid #d63638;color:#8b0000;' ) . '">' . esc_html( $msg ) . '</div>';
			}
			echo '</div>';
		}

		private function handle_test_request(): void {
			if ( isset( $_POST[ 'aiw_send_test' ] ) && function_exists( 'wp_verify_nonce' ) ) {
				$nonce_ok = wp_verify_nonce( $_POST[ 'aiw_test_nonce' ] ?? '', 'aiw_network_test' );
				if ( $nonce_ok ) {
					$success = $this->send_test();
					if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'add_query_arg' ) ) {
						$base = function_exists( 'network_admin_url' ) ? network_admin_url( 'admin.php' ) : 'admin.php';
						wp_safe_redirect( add_query_arg( [ 'page' => $this->slug, 'tab' => 'test', 'aiw_test_sent' => $success ? 1 : 0 ], $base ) );
						exit;
					}
				}
			}
		}
		private function send_test(): bool {
			try {
				$kind = isset( $_POST[ 'aiw_test_kind' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'aiw_test_kind' ] ) ) : 'info';
				if ( class_exists( '\\AzureInsightsWonolog\\Plugin' ) ) {
					$plugin = \AzureInsightsWonolog\Plugin::instance();
					$corr   = $plugin->correlation();
					$client = $plugin->telemetry();
					if ( $client ) {
						$client->add( $client->build_trace_item( [ 'level' => 200, 'message' => 'AIW network test trace', 'context' => [ 'scope' => 'network' ] ], $corr->trace_id(), $corr->span_id() ) );
						$client->add( $client->build_event_item( 'AIWNetworkTestEvent', [ 'alpha' => 'beta' ], [ 'value' => 99 ], $corr->trace_id(), $corr->span_id() ) );
						$client->add( $client->build_metric_item( 'aiw_network_test_metric', 321.0, [ 'unit' => 'ms' ], $corr->trace_id(), $corr->span_id() ) );
						if ( $kind === 'error' ) {
							$ex = new \RuntimeException( 'AIW network test exception' );
							$client->add( $client->build_exception_item( $ex, [ 'level' => 400 ], $corr->trace_id(), $corr->span_id() ) );
						}
						$client->flush();
						return true;
					}
				}
			} catch (\Throwable $e) {
				return false;
			}
			return false;
		}

		/* ---------- DASHBOARD (reuse logic with site options) ---------- */
		private function dashboard_summary( array $groups ): array {
			$q          = function_exists( 'get_option' ) ? get_option( 'aiw_retry_queue_v1', [] ) : [];
			$retryDepth = is_array( $q ) ? count( $q ) : 0;
			$mock       = (int) $this->so( 'aiw_use_mock', 0 );
			$lastSend   = function_exists( 'get_option' ) ? get_option( 'aiw_last_send_time' ) : null;
			$since      = $lastSend ? time() - (int) $lastSend : null;
			$sinceTxt   = $since === null ? 'Never' : ( $since < 60 ? $since . 's ago' : floor( $since / 60 ) . 'm ago' );
			$err        = function_exists( 'get_option' ) ? get_option( 'aiw_last_error_code', '' ) : '';
			$rate       = (string) $this->so( 'aiw_sampling_rate', '1' );
			return [ [ 'Retry Queue', (string) $retryDepth, $retryDepth > 0 ? ( $retryDepth > 5 ? 'warn' : 'ok' ) : 'ok' ], [ 'Last Send', $sinceTxt, $since !== null && $since > 300 ? 'warn' : 'ok' ], [ 'Errors', $err ?: 'None', $err ? 'err' : 'ok' ], [ 'Sampling', $rate, ( $rate === '1' || $rate === '1.0' ) ? 'ok' : '' ], [ 'Mode', $mock ? 'Mock' : 'Live', $mock ? 'warn' : 'ok' ] ];
		}
		private function dashboard_sections(): array {
			$opt                       = function ($k, $d                       = null) {
				return function_exists( 'get_option' ) ? get_option( $k, $d ) : $d; };
			$queue                     = $opt( 'aiw_retry_queue_v1', [] );
			$depth                     = is_array( $queue ) ? count( $queue ) : 0;
			$totalAttempts             = 0;
			$maxAttempts               = 0;
			$nextAttemptTs             = null;
			if ( is_array( $queue ) ) {
				foreach ( $queue as $entry ) {
					$att           = (int) ( $entry[ 'attempts' ] ?? 0 );
					$totalAttempts += $att;
					if ( $att > $maxAttempts )
						$maxAttempts = $att;
					$na = (int) ( $entry[ 'next_attempt' ] ?? 0 );
					if ( $na && ( $nextAttemptTs === null || $na < $nextAttemptTs ) )
						$nextAttemptTs = $na;
				}
			}
			$nextAttempt               = $nextAttemptTs ? ( $nextAttemptTs <= time() ? 'due now' : ( ( $nextAttemptTs - time() ) . 's' ) ) : '—';
			$async                     = (int) $this->so( 'aiw_async_enabled', 0 );
			$lastErrCode               = (string) $opt( 'aiw_last_error_code', '' );
			$lastErrMsg                = (string) $opt( 'aiw_last_error_message', '' );
			$lastSend                  = $opt( 'aiw_last_send_time' );
			$lastSendFmt               = $lastSend ? gmdate( 'Y-m-d H:i:s', (int) $lastSend ) . ' UTC' : 'Never';
			$rate                      = (string) $this->so( 'aiw_sampling_rate', '1' );
			$mock                      = (int) $this->so( 'aiw_use_mock', 0 );
			$hookThresh                = (int) $this->so( 'aiw_slow_hook_threshold_ms', 150 );
			$queryThresh               = (int) $this->so( 'aiw_slow_query_threshold_ms', 500 );
			$perf                      = (int) $this->so( 'aiw_enable_performance', 1 );
			$events                    = (int) $this->so( 'aiw_enable_events_api', 1 );
			$diag                      = (int) $this->so( 'aiw_enable_internal_diagnostics', 0 );
			$storage                   = defined( 'AIW_RETRY_STORAGE' ) ? strtolower( constant( 'AIW_RETRY_STORAGE' ) ) : 'option';
			$sections                  = [];
			$sections[ 'Connection' ][]  = [ 'title' => 'Connection', 'lines' => [ 
				[ 'Conn String', $this->so( 'aiw_connection_string' ) ? 'Yes' : 'No', $this->so( 'aiw_connection_string' ) ? 'ok' : 'warn' ],
				[ 'Instr Key', $this->so( 'aiw_instrumentation_key' ) ? 'Yes' : 'No', '' ],
				[ 'Mode', $mock ? 'Mock' : 'Live', $mock ? 'warn' : 'ok' ],
				[ 'Sampling Rate', $rate, '' ],
			], 'footer' => 'Errors bypass sampling.' ];
			$sections[ 'Queue' ][]       = [ 'title' => 'Retry Queue', 'lines' => [ 
				[ 'Depth', $depth, $depth > 0 ? ( $depth > 5 ? 'warn' : 'ok' ) : 'ok' ],
				[ 'Attempts (total)', $totalAttempts, '' ],
				[ 'Attempts (max)', $maxAttempts, '' ],
				[ 'Next Attempt', $nextAttempt, '' ],
				[ 'Storage', $storage, '' ],
			], 'footer' => 'Exponential backoff.' ];
			$sections[ 'Sending' ][]     = [ 'title' => 'Dispatch', 'lines' => [ 
				[ 'Last Send', $lastSendFmt, $lastSend ? 'ok' : 'warn' ],
				[ 'Async Enabled', $async ? 'Yes' : 'No', $async ? 'ok' : '' ],
				[ 'Last Error Code', $lastErrCode ?: '—', $lastErrCode ? 'err' : '' ],
				[ 'Last Error Msg', $lastErrMsg ? substr( $lastErrMsg, 0, 60 ) . '…' : '—', $lastErrCode ? 'err' : '' ],
			], 'footer' => 'Async cron reduces latency.' ];
			$sections[ 'Features' ][]    = [ 'title' => 'Features', 'lines' => [ 
				[ 'Performance', $perf ? 'On' : 'Off', $perf ? 'ok' : 'warn' ],
				[ 'Events API', $events ? 'On' : 'Off', $events ? 'ok' : 'warn' ],
				[ 'Diagnostics', $diag ? 'On' : 'Off', $diag ? 'warn' : '' ],
				[ 'Hook Threshold (ms)', $hookThresh, '' ],
				[ 'Query Threshold (ms)', $queryThresh, '' ],
			], 'footer' => 'Toggle subsystems.' ];
			$sections[ 'Environment' ][] = [ 'title' => 'Runtime', 'lines' => [ 
				[ 'PHP', PHP_VERSION, '' ],
				[ 'WP', function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '—', '' ],
				[ 'Memory', $this->format_bytes( function_exists( 'memory_get_usage' ) ? memory_get_usage() : 0 ), '' ],
			], 'footer' => 'Env snapshot.' ];
			return $sections;
		}
		private function format_bytes( int $bytes ): string {
			$units = [ 'B', 'KB', 'MB', 'GB' ];
			$i     = 0;
			while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
				$bytes /= 1024;
				$i++;
			}
			return sprintf( '%0.1f %s', $bytes, $units[ $i ] );
		}

		public function save(): void {
			if ( function_exists( 'current_user_can' ) && ! current_user_can( $this->cap ) )
				return;
			if ( function_exists( 'check_admin_referer' ) )
				check_admin_referer( 'aiw_network_save', '_aiw_ns' );
			$keys = [ 'aiw_connection_string', 'aiw_instrumentation_key', 'aiw_min_level', 'aiw_sampling_rate', 'aiw_batch_max_size', 'aiw_batch_flush_interval', 'aiw_async_enabled', 'aiw_slow_hook_threshold_ms', 'aiw_slow_query_threshold_ms', 'aiw_enable_performance', 'aiw_enable_events_api', 'aiw_enable_internal_diagnostics', 'aiw_redact_additional_keys', 'aiw_redact_patterns' ];
			foreach ( $keys as $k ) {
				$raw = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
				$val = is_string( $raw ) ? trim( $raw ) : $raw;
				if ( function_exists( 'update_site_option' ) )
					update_site_option( $k, $val );
			}
			if ( function_exists( 'network_admin_url' ) ) {
				$url = network_admin_url( 'admin.php?page=' . $this->slug . '&updated=1' );
				if ( function_exists( 'wp_safe_redirect' ) ) {
					wp_safe_redirect( $url );
					exit;
				}
			}
		}
	}

}
