<?php
namespace AzureInsightsWonolog\Admin;

class SettingsPage {
	const OPTION_GROUP = 'aiw_settings';
	const PAGE_SLUG    = 'azure-insights-wonolog';

	public function register() {
		if ( ! function_exists( 'add_menu_page' ) )
			return;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', [ $this, 'menu' ] );
			add_action( 'admin_init', [ $this, 'settings' ] );
			// Generic help tab loader for top-level + subpages.
			add_action( 'current_screen', function () {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( ! $screen )
					return;
				$id = $screen->id;
				if ( strpos( $id, self::PAGE_SLUG ) === false )
					return;
				$screen->add_help_tab( [ 
					'id'      => 'aiw_overview',
					'title'   => 'Overview',
					'content' => '<p>Configure how Wonolog / Monolog events are forwarded to Azure Application Insights. Use Mock mode for local development to inspect telemetry without network calls.</p>',
				] );
				$screen->add_help_tab( [ 
					'id'      => 'aiw_sampling',
					'title'   => 'Sampling',
					'content' => '<p>Sampling reduces traffic by sending only a percentage of trace & request events. Errors (severity &gt;= error) are always sent regardless of sampling.</p>',
				] );
				$screen->set_help_sidebar( '<p><strong>Docs</strong></p><p><a href="https://learn.microsoft.com/azure/azure-monitor/app/app-insights-overview" target="_blank" rel="noopener">Application Insights</a></p>' );
			} );
		}
	}

	private function render_status_dashboard_modern() {
		$groups  = $this->dashboard_sections();
		$summary = $this->dashboard_summary( $groups );
		echo '<style>
		.aiw-summary-cards,.aiw-section-wrap{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:12px 0;}
		.aiw-summary-cards .card,.aiw-section-wrap .card{margin:0;}
		.aiw-metric-val{font-size:20px;font-weight:600;margin-top:2px;}
		.aiw-badge{display:inline-block;border-radius:12px;padding:1px 8px;font-size:11px;line-height:1.4;background:#f0f0f1;margin-left:6px;}
		.aiw-badge.ok{background:#e7f7ed;color:#185b37;} .aiw-badge.warn{background:#fff4cf;color:#8a5d00;} .aiw-badge.err{background:#fce5e5;color:#8b0000;}
		.aiw-kv-table{width:100%;} .aiw-kv-table td{padding:4px 8px;vertical-align:top;} .aiw-kv-table td:first-child{width:55%;font-weight:500;}
		</style>';
		echo '<div class="aiw-summary-cards">';
		foreach ( $summary as $item ) {
			list( $label, $value, $state ) = $item;
			$escL                          = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
			$escV                          = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
			$badge                         = $state ? '<span class="aiw-badge ' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '">' . strtoupper( htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) ) . '</span>' : '';
			echo '<div class="card"><h3 style="margin:0 0 2px;font-size:13px;">' . $escL . $badge . '</h3><div class="aiw-metric-val">' . $escV . '</div></div>';
		}
		echo '</div>';
		foreach ( $groups as $group => $metrics ) {
			$escG = htmlspecialchars( $group, ENT_QUOTES, 'UTF-8' );
			echo '<h2 style="margin:24px 0 8px;">' . $escG . '</h2>';
			echo '<div class="aiw-section-wrap">';
			foreach ( $metrics as $card ) {
				$title = htmlspecialchars( $card[ 'title' ], ENT_QUOTES, 'UTF-8' );
				echo '<div class="card"><h3 style="margin-top:0;">' . $title . '</h3>';
				if ( ! empty( $card[ 'lines' ] ) ) {
					echo '<table class="widefat striped aiw-kv-table"><tbody>';
					foreach ( $card[ 'lines' ] as $kv ) {
						$k      = htmlspecialchars( $kv[ 0 ], ENT_QUOTES, 'UTF-8' );
						$vraw   = (string) $kv[ 1 ];
						$v      = htmlspecialchars( $vraw, ENT_QUOTES, 'UTF-8' );
						$state  = $kv[ 2 ] ?? '';
						$stateB = $state ? '<span class="aiw-badge ' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '">' . strtoupper( htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) ) . '</span>' : '';
						echo '<tr><td>' . $k . '</td><td><code style="font-size:11px;">' . $v . '</code> ' . $stateB . '</td></tr>';
					}
					echo '</tbody></table>';
				}
				if ( ! empty( $card[ 'footer' ] ) ) {
					$footer = htmlspecialchars( $card[ 'footer' ], ENT_QUOTES, 'UTF-8' );
					echo '<p style="margin:8px 2px 0;color:#555;font-size:11px;line-height:1.4;">' . $footer . '</p>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
	}
	public function menu() {
		if ( ! function_exists( 'add_menu_page' ) || ! function_exists( 'add_submenu_page' ) )
			return;
		// Top-level page (Status default)
		add_menu_page( 'Azure Insights', 'Azure Insights', 'manage_options', self::PAGE_SLUG, [ $this, 'render' ], 'dashicons-chart-area', 60 );
		$subtabs = [ 
			self::PAGE_SLUG . '-status' => [ 'Status', 'status' ],
			self::PAGE_SLUG . '-connection' => [ 'Connection', 'connection' ],
			self::PAGE_SLUG . '-behavior' => [ 'Behavior', 'behavior' ],
			self::PAGE_SLUG . '-redaction' => [ 'Redaction', 'redaction' ],
		];
		foreach ( $subtabs as $slug => $meta ) {
			list( $label, $tab ) = $meta;
			add_submenu_page( self::PAGE_SLUG, $label, $label, 'manage_options', $slug, function () use ($tab) {
				$this->render( $tab );
			} );
		}
	}

	public function settings() {
		if ( ! function_exists( 'register_setting' ) )
			return; // outside WP
		register_setting( self::OPTION_GROUP, 'aiw_connection_string' );
		register_setting( self::OPTION_GROUP, 'aiw_instrumentation_key', [ 'sanitize_callback' => [ $this, 'sanitize_instrumentation_key' ] ] );
		register_setting( self::OPTION_GROUP, 'aiw_use_mock' );
		register_setting( self::OPTION_GROUP, 'aiw_min_level' );
		register_setting( self::OPTION_GROUP, 'aiw_sampling_rate' );
		register_setting( self::OPTION_GROUP, 'aiw_batch_max_size' );
		register_setting( self::OPTION_GROUP, 'aiw_batch_flush_interval' );
		register_setting( self::OPTION_GROUP, 'aiw_redact_additional_keys' );
		register_setting( self::OPTION_GROUP, 'aiw_redact_patterns' );
		register_setting( self::OPTION_GROUP, 'aiw_slow_hook_threshold_ms' );
		register_setting( self::OPTION_GROUP, 'aiw_slow_query_threshold_ms' );
		register_setting( self::OPTION_GROUP, 'aiw_enable_performance' );
		register_setting( self::OPTION_GROUP, 'aiw_enable_events_api' );
		register_setting( self::OPTION_GROUP, 'aiw_enable_internal_diagnostics' );
		register_setting( self::OPTION_GROUP, 'aiw_async_enabled' );

		// Primary section
		if ( ! function_exists( 'add_settings_section' ) || ! function_exists( 'add_settings_field' ) )
			return;
		add_settings_section( 'aiw_main', 'Azure Application Insights', function () {
			echo '<p>Core connection & behavior. Connection String (recommended) overrides individual legacy instrumentation key if both are present.</p>';
		}, self::PAGE_SLUG );

		// Field: Mock Mode
		add_settings_field( 'aiw_use_mock', 'Mock Mode', function () {
			$val     = function_exists( 'get_option' ) ? (int) get_option( 'aiw_use_mock', 0 ) : 0;
			$checked = function_exists( 'checked' ) ? checked( 1, $val, false ) : ( $val ? 'checked' : '' );
			echo '<label><input type="checkbox" name="aiw_use_mock" value="1" ' . $checked . ' /> Enable (no HTTP) – inspect telemetry locally.</label>';
		}, self::PAGE_SLUG, 'aiw_main' );

		// Field: Connection String
		add_settings_field( 'aiw_connection_string', 'Connection String', function () {
			$raw = function_exists( 'get_option' ) ? get_option( 'aiw_connection_string', '' ) : '';
			// Mask encrypted values visually
			if ( \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw ) ) {
				$display = '******** (encrypted)';
			} else {
				$display = $raw;
			}
			$val = function_exists( 'esc_attr' ) ? esc_attr( $display ) : htmlspecialchars( $display, ENT_QUOTES, 'UTF-8' );
			echo '<input type="text" name="aiw_connection_string" value="' . $val . '" class="regular-text code" placeholder="InstrumentationKey=...;IngestionEndpoint=https://..." autocomplete="off" />';
			echo '<p class="description">Paste full connection string from Azure Portal &ldquo;Connection String&rdquo; blade.</p>';
		}, self::PAGE_SLUG, 'aiw_main' );

		// Field: Instrumentation Key (legacy)
		add_settings_field( 'aiw_instrumentation_key', 'Instrumentation Key (legacy)', function () {
			$raw = function_exists( 'get_option' ) ? get_option( 'aiw_instrumentation_key', '' ) : '';
			if ( \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw ) ) {
				$display = '******** (encrypted)';
			} else {
				$display = $raw;
			}
			$val = function_exists( 'esc_attr' ) ? esc_attr( $display ) : htmlspecialchars( $display, ENT_QUOTES, 'UTF-8' );
			echo '<input type="text" name="aiw_instrumentation_key" value="' . $val . '" class="regular-text code" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />';
			echo '<p class="description">Deprecated. Provide only if not using Connection String.</p>';
		}, self::PAGE_SLUG, 'aiw_main' );

		// Behavior subsection (visual grouping)
		add_settings_section( 'aiw_behavior', 'Behavior & Performance', function () {
			echo '<p>Control verbosity, sampling and batching. Errors bypass sampling automatically.</p>';
		}, self::PAGE_SLUG );

		// Minimum level select
		add_settings_field( 'aiw_min_level', 'Minimum Log Level', function () {
			$current = function_exists( 'get_option' ) ? get_option( 'aiw_min_level', 'info' ) : 'info';
			$levels  = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];
			echo '<select name="aiw_min_level">';
			foreach ( $levels as $lvl ) {
				$sel = ( $lvl === $current ) ? 'selected' : '';
				$lab = ucfirst( $lvl );
				echo '<option value="' . htmlspecialchars( $lvl, ENT_QUOTES, 'UTF-8' ) . '" ' . $sel . '>' . htmlspecialchars( $lab, ENT_QUOTES, 'UTF-8' ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">Events below this level are ignored before sampling.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		// Sampling rate slider + number
		add_settings_field( 'aiw_sampling_rate', 'Sampling Rate', function () {
			add_settings_field( 'aiw_feature_toggles', 'Feature Toggles', function () {
				$perf   = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_performance', 1 ) : 1;
				$events = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_events_api', 1 ) : 1;
				$diag   = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_internal_diagnostics', 0 ) : 0;
				$cb     = function ($name, $label, $val) {
					$checked = $val ? 'checked' : '';
					echo '<label style="display:block;margin:2px 0;"><input type="checkbox" name="' . $name . '" value="1" ' . $checked . ' /> ' . $label . '</label>';
				};
				$cb( 'aiw_enable_performance', 'Performance Metrics', $perf );
				$cb( 'aiw_enable_events_api', 'Custom Events & Metrics API', $events );
				$cb( 'aiw_enable_internal_diagnostics', 'Internal Diagnostics (error_log)', $diag );
				echo '<p class="description">Disable unused subsystems to reduce overhead.</p>';
			}, self::PAGE_SLUG, 'aiw_behavior' );
			$val = function_exists( 'get_option' ) ? get_option( 'aiw_sampling_rate', '1' ) : '1';
			$val = is_numeric( $val ) ? $val : '1';
			$v   = htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' );
			echo '<input type="range" min="0" max="1" step="0.01" value="' . $v . '" oninput="this.nextElementSibling.value=this.value" name="aiw_sampling_rate" style="width:220px;">';
			echo '<input type="number" min="0" max="1" step="0.01" value="' . $v . '" oninput="this.previousElementSibling.value=this.value" style="width:70px;margin-left:8px;">';
			echo '<p class="description">Fraction of non-error traces/requests to send (1 = all).</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		add_settings_field( 'aiw_batch_max_size', 'Batch Max Size', function () {
			$val = function_exists( 'get_option' ) ? (int) get_option( 'aiw_batch_max_size', 20 ) : 20;
			echo '<input type="number" min="1" name="aiw_batch_max_size" value="' . (int) $val . '" style="width:90px;" />';
			echo '<p class="description">Flush when this many telemetry items queued.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		add_settings_field( 'aiw_batch_flush_interval', 'Flush Interval (s)', function () {
			$val = function_exists( 'get_option' ) ? (int) get_option( 'aiw_batch_flush_interval', 10 ) : 10;
			echo '<input type="number" min="1" name="aiw_batch_flush_interval" value="' . (int) $val . '" style="width:90px;" />';
			echo '<p class="description">Auto flush oldest batch if no flush after this many seconds.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		add_settings_field( 'aiw_async_enabled', 'Async Send', function () {
			$val     = function_exists( 'get_option' ) ? (int) get_option( 'aiw_async_enabled', 0 ) : 0;
			$checked = $val ? 'checked' : '';
			echo '<label><input type="checkbox" name="aiw_async_enabled" value="1" ' . $checked . ' /> Defer network send via cron (reduces request latency).</label>';
			echo '<p class="description">Batches queued & sent shortly by aiw_async_flush cron. Use filter aiw_before_send_batch to mutate lines.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		// Slow hook threshold (ms) configuration (future use by performance collector)
		add_settings_field( 'aiw_slow_hook_threshold_ms', 'Slow Hook Threshold (ms)', function () {
			$val = function_exists( 'get_option' ) ? (int) get_option( 'aiw_slow_hook_threshold_ms', 150 ) : 150;
			echo '<input type="number" min="10" name="aiw_slow_hook_threshold_ms" value="' . (int) $val . '" style="width:90px;" />';
			echo '<p class="description">Record hook_duration_ms metrics only when duration exceeds this threshold.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		// Slow DB query threshold (ms)
		add_settings_field( 'aiw_slow_query_threshold_ms', 'Slow Query Threshold (ms)', function () {
			$val = function_exists( 'get_option' ) ? (int) get_option( 'aiw_slow_query_threshold_ms', 500 ) : 500;
			echo '<input type="number" min="10" name="aiw_slow_query_threshold_ms" value="' . (int) $val . '" style="width:90px;" />';
			echo '<p class="description">Emit db_slow_query_ms metrics for queries ≥ this duration (SAVEQUERIES required). Also counts db_slow_query_count.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		// Diagnostics / status section (read-only)
		add_settings_section( 'aiw_status', 'Runtime Status', function () {
			$status = $this->runtime_status();
			echo '<ul style="margin-left:1em;list-style:disc">';
			foreach ( $status as $label => $value ) {
				$l = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
				$v = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
				echo '<li><strong>' . $l . ':</strong> ' . $v . '</li>';
			}
			echo '</ul>';
			$useMock = function_exists( 'get_option' ) ? get_option( 'aiw_use_mock' ) : false;
			if ( $useMock && function_exists( 'admin_url' ) ) {
				$url = admin_url( 'options-general.php?page=aiw-mock-telemetry' );
				$url = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
				echo '<p><a class="button" href="' . $url . '">Open Mock Telemetry Viewer</a></p>';
			}
		}, self::PAGE_SLUG );

		// Redaction / Diagnostics section
		add_settings_section( 'aiw_privacy', 'Redaction & Diagnostics', function () {
			echo '<p>Configure additional sensitive keys and regex patterns to redact from telemetry context. Test telemetry sends a sample trace, event, metric, and (if error chosen) exception.</p>';
		}, self::PAGE_SLUG );

		add_settings_field( 'aiw_redact_additional_keys', 'Additional Redact Keys', function () {
			$raw = function_exists( 'get_option' ) ? get_option( 'aiw_redact_additional_keys', '' ) : '';
			$val = function_exists( 'esc_textarea' ) ? esc_textarea( $raw ) : htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' );
			echo '<textarea name="aiw_redact_additional_keys" rows="3" class="large-text" placeholder="key1,key2,email_address">' . $val . '</textarea>';
			echo '<p class="description">Comma-separated case-insensitive keys to redact. Applied in addition to built-in list.</p>';
		}, self::PAGE_SLUG, 'aiw_privacy' );

		add_settings_field( 'aiw_redact_patterns', 'Regex Redact Patterns', function () {
			$raw = function_exists( 'get_option' ) ? get_option( 'aiw_redact_patterns', '' ) : '';
			$val = function_exists( 'esc_textarea' ) ? esc_textarea( $raw ) : htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' );
			echo '<textarea name="aiw_redact_patterns" rows="3" class="large-text" placeholder="/(?:bearer)\s+[a-z0-9\-\._]+/i,/[0-9]{16}/">' . $val . '</textarea>';
			echo '<p class="description">Comma-separated PCRE patterns. Any matching values will be replaced with [REDACTED]. Use cautiously for performance.</p>';
		}, self::PAGE_SLUG, 'aiw_privacy' );

		add_settings_field( 'aiw_test_telemetry', 'Send Test Telemetry', function () {
			if ( ! function_exists( 'wp_nonce_field' ) ) {
				echo '<p>WordPress context unavailable.</p>';
				return;
			}
			echo '<form method="post" action="" style="margin:0;">';
			wp_nonce_field( 'aiw_send_test_telemetry', 'aiw_test_nonce' );
			echo '<select name="aiw_test_kind"><option value="info">Info Trace</option><option value="error">Error + Exception</option></select> ';
			echo '<button class="button">Send</button>';
			echo '<input type="hidden" name="aiw_send_test" value="1" />';
			echo '</form>';
			if ( isset( $_GET[ 'aiw_test_sent' ] ) ) {
				$ok = intval( $_GET[ 'aiw_test_sent' ] ) === 1;
				echo '<p style="margin-top:8px;" class="' . ( $ok ? 'description' : 'error' ) . '">' . ( $ok ? 'Test telemetry dispatched.' : 'Test telemetry failed.' ) . '</p>';
			}
		}, self::PAGE_SLUG, 'aiw_privacy' );
	}

	public function render( $forced_tab = null ) {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) )
			return;
		// Handle test telemetry postback early (same page submit) to avoid separate endpoint.
		if ( isset( $_POST[ 'aiw_send_test' ] ) && function_exists( 'wp_verify_nonce' ) ) {
			$nonce_ok = wp_verify_nonce( $_POST[ 'aiw_test_nonce' ] ?? '', 'aiw_send_test_telemetry' );
			if ( $nonce_ok ) {
				try {
					$kind = isset( $_POST[ 'aiw_test_kind' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'aiw_test_kind' ] ) ) : 'info';
					if ( class_exists( '\AzureInsightsWonolog\Plugin' ) ) {
						$plugin = \AzureInsightsWonolog\Plugin::instance();
						$corr   = $plugin->correlation();
						$client = $plugin->telemetry();
						if ( $client ) {
							// Info trace
							$client->add( $client->build_trace_item( [ 'level' => 200, 'message' => 'AIW test trace', 'context' => [ 'foo' => 'bar' ] ], $corr->trace_id(), $corr->span_id() ) );
							// Event
							$client->add( $client->build_event_item( 'AIWTestEvent', [ 'alpha' => 'beta' ], [ 'value' => 42 ], $corr->trace_id(), $corr->span_id() ) );
							// Metric
							$client->add( $client->build_metric_item( 'aiw_test_metric', 123.45, [ 'unit' => 'ms' ], $corr->trace_id(), $corr->span_id() ) );
							if ( $kind === 'error' ) {
								$ex = new \RuntimeException( 'AIW test exception' );
								$client->add( $client->build_exception_item( $ex, [ 'level' => 400 ], $corr->trace_id(), $corr->span_id() ) );
							}
							$client->flush();
						}
					}
					if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'add_query_arg' ) ) {
						wp_safe_redirect( add_query_arg( 'aiw_test_sent', 1, $_SERVER[ 'REQUEST_URI' ] ) );
						exit;
					}
				} catch (\Throwable $e) {
					if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'add_query_arg' ) ) {
						wp_safe_redirect( add_query_arg( 'aiw_test_sent', 0, $_SERVER[ 'REQUEST_URI' ] ) );
						exit;
					}
				}
			}
		}
		echo '<div class="wrap"><h1>Azure Insights</h1>';
		$tabs       = [ 
			'status'     => 'Status',
			'connection' => 'Connection',
			'behavior'   => 'Behavior',
			'redaction'  => 'Redaction & Diagnostics',
		];
		$active_tab = $forced_tab ? $forced_tab : ( isset( $_GET[ 'tab' ] ) ? sanitize_key( $_GET[ 'tab' ] ) : 'status' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $active_tab ] ) )
			$active_tab = 'connection';
		$base_url = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . self::PAGE_SLUG ) : '?page=' . self::PAGE_SLUG;
		echo '<style>.aiw-badge{display:inline-block;background:#2271b1;color:#fff;border-radius:3px;padding:2px 6px;font-size:11px;margin-left:6px;} .aiw-intro{margin-top:4px;color:#555;} .aiw-actions{margin:14px 0;} </style>';
		echo '<h2 class="nav-tab-wrapper" style="margin-bottom:8px;">';
		foreach ( $tabs as $slug => $label ) {
			$url  = $base_url . '&tab=' . $slug;
			$cls  = 'nav-tab' . ( $slug === $active_tab ? ' nav-tab-active' : '' );
			$escL = function_exists( 'esc_html' ) ? esc_html( $label ) : htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
			$escU = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
			echo '<a class="' . $cls . '" href="' . $escU . '">' . $escL . '</a>';
		}
		echo '</h2>';
		if ( $active_tab === 'status' ) {
			echo '<p class="aiw-intro">Operational snapshot of the telemetry pipeline. Use other tabs to change configuration.</p>';
			$this->render_status_dashboard_modern();
		}
	}


	private function dashboard_summary( $groups ) {
		// Choose representative high-level metrics
		// Compute on the fly from options.
		$q          = function_exists( 'get_option' ) ? get_option( 'aiw_retry_queue_v1', [] ) : [];
		$retryDepth = is_array( $q ) ? count( $q ) : 0;
		$mock       = function_exists( 'get_option' ) ? (int) get_option( 'aiw_use_mock', 0 ) : 0;
		$lastSend   = function_exists( 'get_option' ) ? get_option( 'aiw_last_send_time' ) : null;
		$since      = $lastSend ? ( time() - (int) $lastSend ) : null;
		$sinceTxt   = $since === null ? 'Never' : ( $since < 60 ? $since . 's ago' : floor( $since / 60 ) . 'm ago' );
		$err        = function_exists( 'get_option' ) ? get_option( 'aiw_last_error_code', '' ) : '';
		$rate       = function_exists( 'get_option' ) ? get_option( 'aiw_sampling_rate', '1' ) : '1';
		$status     = [];
		$status[]   = [ 'Retry Queue', (string) $retryDepth, $retryDepth > 0 ? ( $retryDepth > 5 ? 'warn' : 'ok' ) : 'ok' ];
		$status[]   = [ 'Last Send', $sinceTxt, $since !== null && $since > 300 ? 'warn' : 'ok' ];
		$status[]   = [ 'Errors', $err ? $err : 'None', $err ? 'err' : 'ok' ];
		$status[]   = [ 'Sampling', (string) $rate, ( $rate === '1' || $rate === '1.0' ) ? 'ok' : '' ];
		$status[]   = [ 'Mode', $mock ? 'Mock' : 'Live', $mock ? 'warn' : 'ok' ];
		return $status;
	}

	private function dashboard_sections() {
		$sections      = [];
		$opt           = function ($k, $d           = null) {
			return function_exists( 'get_option' ) ? get_option( $k, $d ) : $d;
		};
		$queue         = $opt( 'aiw_retry_queue_v1', [] );
		$depth         = is_array( $queue ) ? count( $queue ) : 0;
		$totalAttempts = 0;
		$maxAttempts   = 0;
		$nextAttemptTs = null;
		if ( is_array( $queue ) ) {
			foreach ( $queue as $entry ) {
				$att           = isset( $entry[ 'attempts' ] ) ? (int) $entry[ 'attempts' ] : 0;
				$totalAttempts += $att;
				if ( $att > $maxAttempts )
					$maxAttempts = $att;
				$na = isset( $entry[ 'next_attempt' ] ) ? (int) $entry[ 'next_attempt' ] : 0;
				if ( $na && ( $nextAttemptTs === null || $na < $nextAttemptTs ) )
					$nextAttemptTs = $na;
			}
		}
		$nextAttempt = $nextAttemptTs ? ( $nextAttemptTs <= time() ? 'due now' : ( ( $nextAttemptTs - time() ) . 's' ) ) : '—';
		$async       = (int) $opt( 'aiw_async_enabled', 0 );
		$lastErrCode = (string) $opt( 'aiw_last_error_code', '' );
		$lastErrMsg  = (string) $opt( 'aiw_last_error_message', '' );
		$lastSend    = $opt( 'aiw_last_send_time' );
		$lastSendFmt = $lastSend ? gmdate( 'Y-m-d H:i:s', (int) $lastSend ) . ' UTC' : 'Never';
		$rate        = (string) $opt( 'aiw_sampling_rate', '1' );
		$mock        = (int) $opt( 'aiw_use_mock', 0 );
		$hookThresh  = (int) $opt( 'aiw_slow_hook_threshold_ms', 150 );
		$queryThresh = (int) $opt( 'aiw_slow_query_threshold_ms', 500 );
		$perf        = (int) $opt( 'aiw_enable_performance', 1 );
		$events      = (int) $opt( 'aiw_enable_events_api', 1 );
		$diag        = (int) $opt( 'aiw_enable_internal_diagnostics', 0 );
		$storage     = defined( 'AIW_RETRY_STORAGE' ) ? strtolower( constant( 'AIW_RETRY_STORAGE' ) ) : 'option';
		// Connection
		$sections[ 'Connection' ][] = [ 'title' => 'Connection', 'lines' => [ 
			[ 'Conn String', $opt( 'aiw_connection_string' ) ? 'Yes' : 'No', $opt( 'aiw_connection_string' ) ? 'ok' : 'warn' ],
			[ 'Instr Key', $opt( 'aiw_instrumentation_key' ) ? 'Yes' : 'No', '' ],
			[ 'Mode', $mock ? 'Mock' : 'Live', $mock ? 'warn' : 'ok' ],
			[ 'Sampling Rate', $rate, '' ],
		], 'footer' => 'Errors bypass sampling.' ];
		// Queue
		$sections[ 'Queue' ][] = [ 'title' => 'Retry Queue', 'lines' => [ 
			[ 'Depth', $depth, $depth > 0 ? ( $depth > 5 ? 'warn' : 'ok' ) : 'ok' ],
			[ 'Attempts (total)', $totalAttempts, '' ],
			[ 'Attempts (max)', $maxAttempts, '' ],
			[ 'Next Attempt', $nextAttempt, '' ],
			[ 'Storage', $storage, '' ],
		], 'footer' => 'Exponential backoff with persistence.' ];
		// Sending
		$sections[ 'Sending' ][] = [ 'title' => 'Dispatch', 'lines' => [ 
			[ 'Last Send', $lastSendFmt, $lastSend ? 'ok' : 'warn' ],
			[ 'Async Enabled', $async ? 'Yes' : 'No', $async ? 'ok' : '' ],
			[ 'Last Error Code', $lastErrCode ? $lastErrCode : '—', $lastErrCode ? 'err' : '' ],
			[ 'Last Error Msg', $lastErrMsg ? substr( $lastErrMsg, 0, 60 ) . '…' : '—', $lastErrCode ? 'err' : '' ],
		], 'footer' => 'Async cron reduces request latency.' ];
		// Performance
		$sections[ 'Features' ][] = [ 'title' => 'Features', 'lines' => [ 
			[ 'Performance', $perf ? 'On' : 'Off', $perf ? 'ok' : 'warn' ],
			[ 'Events API', $events ? 'On' : 'Off', $events ? 'ok' : 'warn' ],
			[ 'Diagnostics', $diag ? 'On' : 'Off', $diag ? 'warn' : '' ],
			[ 'Hook Threshold (ms)', $hookThresh, '' ],
			[ 'Query Threshold (ms)', $queryThresh, '' ],
		], 'footer' => 'Toggle subsystems as needed.' ];
		// Environment
		$sections[ 'Environment' ][] = [ 'title' => 'Runtime', 'lines' => [ 
			[ 'PHP', PHP_VERSION, '' ],
			[ 'WP', function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '—', '' ],
			[ 'Memory', $this->format_bytes( function_exists( 'memory_get_usage' ) ? memory_get_usage() : 0 ), '' ],
		], 'footer' => 'Environment snapshot.' ];
		return $sections;
	}

	private function format_bytes( $bytes ) {
		$units = [ 'B', 'KB', 'MB', 'GB' ];
		$i     = 0;
		while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
			$bytes /= 1024;
			$i++;
		}
		return sprintf( '%0.1f %s', $bytes, $units[ $i ] );
	}

	public function sanitize_instrumentation_key( $value ) {
		$value = trim( (string) $value );
		if ( $value && ! preg_match( '/^[a-f0-9-]{32,36}$/i', $value ) ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( 'aiw_instrumentation_key', 'aiw_invalid_ikey', 'Invalid instrumentation key format.' );
			}
			return '';
		}
		return $value;
	}

	private function runtime_status(): array {
		// Keep legacy method minimal (used by settings sections not dashboard) – return key counts.
		$status                        = [];
		$status[ 'Mock Mode' ]         = function_exists( 'get_option' ) && get_option( 'aiw_use_mock' ) ? 'Enabled' : 'Disabled';
		$status[ 'Sampling Rate' ]     = function_exists( 'get_option' ) ? (string) get_option( 'aiw_sampling_rate', '1' ) : '1';
		$queue                         = function_exists( 'get_option' ) ? get_option( 'aiw_retry_queue_v1', [] ) : [];
		$status[ 'Retry Queue Depth' ] = is_array( $queue ) ? (string) count( $queue ) : '0';
		$lastSend                      = function_exists( 'get_option' ) ? get_option( 'aiw_last_send_time' ) : null;
		if ( $lastSend )
			$status[ 'Last Send (UTC)' ] = gmdate( 'Y-m-d H:i:s', (int) $lastSend );
		return $status;
	}
}
