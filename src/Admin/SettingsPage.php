<?php
namespace AzureInsightsWonolog\Admin;

class SettingsPage {
	const OPTION_GROUP = 'aiw_settings';
	const PAGE_SLUG    = 'azure-insights-wonolog';

	/** @var array<string,bool> */
	private array $page_hooks = [];

	public function register() {
		if ( ! function_exists( 'add_menu_page' ) )
			return;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'admin_menu', [ $this, 'menu' ] );
			add_action( 'admin_init', [ $this, 'settings' ] );
			// Enqueue assets only on our pages
			add_action( 'admin_enqueue_scripts', function ($hook) {
				if ( isset( $this->page_hooks[ $hook ] ) ) {
					if ( function_exists( 'wp_enqueue_style' ) ) {
						wp_enqueue_style( 'aiw-admin', defined( 'AIW_PLUGIN_URL' ) ? AIW_PLUGIN_URL . 'assets/css/aiw-admin.css' : '', [], defined( 'AIW_PLUGIN_VERSION' ) ? AIW_PLUGIN_VERSION : '1.0' );
					}
				}
			} );
			// Generic help tab loader for top-level + subpages.
			add_action( 'current_screen', function () {
				$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
				if ( ! $screen )
					return;
				$id = $screen->id;
				if ( strpos( $id, self::PAGE_SLUG ) === false )
					return;

				$tabs = [ 
					'aiw_overview'    => [ 'Overview', '<p><strong>Azure Insights</strong> forwards Wonolog / Monolog logs plus request, event, metric & exception telemetry to <em>Azure Application Insights</em> with correlation, batching & sampling to keep overhead low.</p><p><strong>Quick Start:</strong> Add Connection String &rarr; Send Test Telemetry &rarr; View in Azure Logs (<code>traces</code>, <code>requests</code>, <code>customMetrics</code>).</p><p><strong>Modes:</strong> <em>Live</em> (sends to Azure) / <em>Mock</em> (stores locally for inspection). Switch via the Connection tab.</p>' ],
					'aiw_conn_sec'    => [ 'Connection & Security', '<p>Prefer a <strong>Connection String</strong>; legacy Instrumentation Key only if needed. Secrets are stored encrypted (AES-256-CBC w/ WP salts) and masked after save. You can also define constants (<code>AIW_CONNECTION_STRING</code>) in <code>wp-config.php</code> to keep secrets out of the DB.</p><p><strong>Correlation:</strong> Incoming <code>traceparent</code> is honored; new span ID always generated. Outbound HTTP requests get a <code>traceparent</code> header (filter <code>aiw_propagate_correlation</code> to disable).</p>' ],
					'aiw_sampling'    => [ 'Sampling & Batching', '<p><strong>Sampling</strong> probabilistically drops lower-severity telemetry (errors are always kept). Effective rate auto-drops under burst load. Adjust with slider (0 – 1). Filter <code>aiw_should_sample</code> to override.</p><p><strong>Batching:</strong> Size (<code>Batch Max Size</code>) or interval (<code>Flush Interval</code>) triggers send. Enable <em>Async Send</em> to queue lines for cron (<code>aiw_async_flush</code>) reducing request latency.</p>' ],
					'aiw_performance' => [ 'Performance Metrics', '<p>If enabled, hook durations exceeding threshold, memory peak, DB query counts / time, slow queries (≥ threshold), cron durations are emitted as <code>customMetrics</code>. Tune thresholds in Behavior tab. Disable if minimal footprint needed.</p>' ],
					'aiw_redaction'   => [ 'Redaction & Privacy', '<p>Built-in redaction for sensitive keys (password, token, email etc.). Add more keys (comma list) or regex patterns (PCRE) to redact matching <em>values</em>. Redacted telemetry includes a <code>_aiw_redaction</code> metadata object listing affected keys/patterns. Keep regex list short for performance.</p><p>Display or filter a privacy notice via <code>aiw_privacy_notice()</code> & <code>aiw_privacy_notice_text</code>.</p>' ],
					'aiw_retry'       => [ 'Retry & Async', '<p>Failed batches are queued with exponential backoff (1m,5m,15m,1h). View depth & attempts in Status dashboard or Retry Queue viewer. Storage defaults to options; define <code>AIW_RETRY_STORAGE=\'transient\'</code> to prefer transients (mirrored to option).</p><p>Async dispatch stores short-lived batches in <code>aiw_async_batches</code> – a cron event sends them soon after.</p>' ],
					'aiw_cli'         => [ 'CLI & Testing', '<p>WP-CLI commands:</p><ul><li><code>wp aiw status</code> &ndash; show last send / queue</li><li><code>wp aiw test --error</code> &ndash; emit sample telemetry</li><li><code>wp aiw flush_queue</code> &ndash; force retry processing</li></ul><p>The <em>Send Test Telemetry</em> button in Redaction tab emits a trace, event, metric (+ exception if selected). Data appears in Azure typically within 1–2 minutes.</p>' ],
					'aiw_filters'     => [ 'Filters & Extensibility', '<p>Key filters/actions:</p><ul style="margin-left:1.2em;list-style:disc"><li><code>aiw_use_mock_telemetry</code></li><li><code>aiw_should_sample</code></li><li><code>aiw_redact_keys</code></li><li><code>aiw_before_send_batch</code></li><li><code>aiw_enrich_dimensions</code></li><li><code>aiw_propagate_correlation</code></li></ul><p>Use <code>aiw_event()</code> and <code>aiw_metric()</code> to submit custom telemetry, gated by the Events API toggle.</p>' ],
				];
				foreach ( $tabs as $id => $def ) {
					list( $title, $content ) = $def;
					$screen->add_help_tab( [ 'id' => $id, 'title' => $title, 'content' => $content ] );
				}
				$screen->set_help_sidebar( '<p><strong>Resources</strong></p><p><a href="https://learn.microsoft.com/azure/azure-monitor/app/app-insights-overview" target="_blank" rel="noopener">Azure Application Insights</a></p><p><a href="https://kusto.azurewebsites.net/docs" target="_blank" rel="noopener">Kusto Query Language</a></p>' );
			} );
		}
	}

	private function render_status_dashboard_modern() {
		$groups  = $this->dashboard_sections();
		$summary = $this->dashboard_summary( $groups );

		// Enhanced modern CSS
		echo '<style>
		:root {
			--aiw-primary: #2271b1;
			--aiw-primary-light: #3582c4;
			--aiw-success: #00a32a;
			--aiw-warning: #dba617;
			--aiw-error: #d63638;
			--aiw-surface: #ffffff;
			--aiw-border: #c3c4c7;
			--aiw-text: #1d2327;
			--aiw-text-light: #646970;
			--aiw-shadow: 0 1px 3px rgba(0,0,0,0.1);
			--aiw-radius: 6px;
		}
		
		.aiw-dashboard {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
		}
		
		.aiw-summary-cards, .aiw-section-wrap {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
			gap: 16px;
			margin: 20px 0;
		}
		
		.aiw-summary-cards .card, .aiw-section-wrap .card {
			margin: 0;
			border: 1px solid var(--aiw-border);
			border-radius: var(--aiw-radius);
			box-shadow: var(--aiw-shadow);
			transition: all 0.2s ease;
			background: var(--aiw-surface);
		}
		
		.aiw-summary-cards .card:hover, .aiw-section-wrap .card:hover {
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(0,0,0,0.15);
		}
		
		.aiw-metric-val {
			font-size: 24px;
			font-weight: 700;
			margin-top: 4px;
			color: var(--aiw-text);
			line-height: 1.2;
		}
		
		.aiw-badge {
			display: inline-flex;
			align-items: center;
			border-radius: 12px;
			padding: 3px 8px;
			font-size: 10px;
			font-weight: 600;
			line-height: 1;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			margin-left: 8px;
			background: var(--aiw-border);
			color: var(--aiw-text);
		}
		
		.aiw-badge.ok {
			background: #e7f7ed;
			color: #185b37;
			border: 1px solid #7ad03a;
		}
		
		.aiw-badge.warn {
			background: #fff4cf;
			color: #8a5d00;
			border: 1px solid var(--aiw-warning);
		}
		
		.aiw-badge.err {
			background: #fce5e5;
			color: #8b0000;
			border: 1px solid var(--aiw-error);
		}
		
		.aiw-kv-table {
			width: 100%;
			border-collapse: collapse;
		}
		
		.aiw-kv-table td {
			padding: 8px 12px;
			vertical-align: top;
			border-bottom: 1px solid #f0f0f1;
		}
		
		.aiw-kv-table td:first-child {
			width: 45%;
			font-weight: 500;
			color: var(--aiw-text);
		}
		
		.aiw-kv-table td:last-child {
			font-family: ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, "DejaVu Sans Mono", monospace;
			font-size: 12px;
		}
		
		.aiw-kv-table code {
			background: #f6f7f7;
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 11px;
		}
		
		.aiw-section-header {
			margin: 32px 0 16px;
			font-size: 20px;
			font-weight: 600;
			color: var(--aiw-text);
			border-bottom: 2px solid var(--aiw-primary);
			padding-bottom: 8px;
		}
		
		.aiw-card-title {
			margin: 0 0 16px;
			font-size: 16px;
			font-weight: 600;
			color: var(--aiw-text);
			display: flex;
			align-items: center;
		}
		
		.aiw-card-title::before {
			content: "";
			width: 4px;
			height: 20px;
			background: var(--aiw-primary);
			border-radius: 2px;
			margin-right: 12px;
		}
		
		.aiw-card-footer {
			margin: 12px 0 0;
			padding: 8px 0 0;
			border-top: 1px solid #f0f0f1;
			color: var(--aiw-text-light);
			font-size: 12px;
			line-height: 1.4;
		}
		</style>';

		echo '<div class="aiw-dashboard">';
		echo '<div class="aiw-summary-cards">';
		foreach ( $summary as $item ) {
			list( $label, $value, $state ) = $item;
			$escL                          = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
			$escV                          = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
			$badge                         = $state ? '<span class="aiw-badge ' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '">' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '</span>' : '';
			echo '<div class="card"><h3 style="margin:0 0 4px;font-size:13px;color:var(--aiw-text-light);font-weight:500;">' . $escL . $badge . '</h3><div class="aiw-metric-val">' . $escV . '</div></div>';
		}
		echo '</div>';

		foreach ( $groups as $group => $metrics ) {
			$escG = htmlspecialchars( $group, ENT_QUOTES, 'UTF-8' );
			echo '<h2 class="aiw-section-header">' . $escG . '</h2>';
			echo '<div class="aiw-section-wrap">';
			foreach ( $metrics as $card ) {
				$title = htmlspecialchars( $card[ 'title' ], ENT_QUOTES, 'UTF-8' );
				echo '<div class="card"><h3 class="aiw-card-title">' . $title . '</h3>';
				if ( ! empty( $card[ 'lines' ] ) ) {
					echo '<table class="aiw-kv-table"><tbody>';
					foreach ( $card[ 'lines' ] as $kv ) {
						$k      = htmlspecialchars( $kv[ 0 ], ENT_QUOTES, 'UTF-8' );
						$vraw   = (string) $kv[ 1 ];
						$v      = htmlspecialchars( $vraw, ENT_QUOTES, 'UTF-8' );
						$state  = $kv[ 2 ] ?? '';
						$stateB = $state ? '<span class="aiw-badge ' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '">' . htmlspecialchars( $state, ENT_QUOTES, 'UTF-8' ) . '</span>' : '';
						echo '<tr><td>' . $k . '</td><td><code>' . $v . '</code> ' . $stateB . '</td></tr>';
					}
					echo '</tbody></table>';
				}
				if ( ! empty( $card[ 'footer' ] ) ) {
					$footer = htmlspecialchars( $card[ 'footer' ], ENT_QUOTES, 'UTF-8' );
					echo '<p class="aiw-card-footer">' . $footer . '</p>';
				}
				echo '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}
	public function menu() {
		if ( ! function_exists( 'add_menu_page' ) || ! function_exists( 'add_submenu_page' ) )
			return;
		// Top-level page (Status default)
		$top = add_menu_page( 'Azure Insights', 'Azure Insights', 'manage_options', self::PAGE_SLUG, [ $this, 'render' ], 'dashicons-chart-area', 60 );
		if ( is_string( $top ) ) {
			$this->page_hooks[ $top ] = true;
		}
		$subtabs = [ 
			self::PAGE_SLUG . '-status' => [ 'Status', 'status' ],
			self::PAGE_SLUG . '-connection' => [ 'Connection', 'connection' ],
			self::PAGE_SLUG . '-behavior' => [ 'Behavior', 'behavior' ],
			self::PAGE_SLUG . '-redaction' => [ 'Redaction', 'redaction' ],
			self::PAGE_SLUG . '-test' => [ 'Test Telemetry', 'test' ],
		];
		foreach ( $subtabs as $slug => $meta ) {
			list( $label, $tab ) = $meta;
			$hook                = add_submenu_page( self::PAGE_SLUG, $label, $label, 'manage_options', $slug, function () use ($tab) {
				$this->render( $tab );
			} );
			if ( is_string( $hook ) ) {
				$this->page_hooks[ $hook ] = true;
			}
		}
	}

	public function settings() {
		if ( ! function_exists( 'register_setting' ) ) {
			return; // outside WP runtime
		}
		// Register stored options
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

		if ( ! function_exists( 'add_settings_section' ) || ! function_exists( 'add_settings_field' ) ) {
			return; // cannot render sections in this context
		}

		// Main section (connection)
		add_settings_section( 'aiw_main', 'Azure Application Insights', function () {
			echo '<p>Core connection & behavior. Connection String (recommended) overrides legacy instrumentation key if both are present.</p>';
		}, self::PAGE_SLUG );

		// Mock Mode toggle
		add_settings_field( 'aiw_use_mock', 'Mock Mode', function () {
			$val     = function_exists( 'get_option' ) ? (int) get_option( 'aiw_use_mock', 0 ) : 0;
			$checked = function_exists( 'checked' ) ? checked( 1, $val, false ) : ( $val ? 'checked' : '' );
			echo '<label><input type="checkbox" name="aiw_use_mock" value="1" ' . $checked . ' /> Enable (no HTTP) – inspect telemetry locally.</label>';
		}, self::PAGE_SLUG, 'aiw_main' );

		// Connection String field (masked if encrypted)
		add_settings_field( 'aiw_connection_string', 'Connection String', function () {
			$raw     = function_exists( 'get_option' ) ? get_option( 'aiw_connection_string', '' ) : '';
			$display = \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw ) ? '******** (encrypted)' : $raw;
			$val     = function_exists( 'esc_attr' ) ? esc_attr( $display ) : htmlspecialchars( $display, ENT_QUOTES, 'UTF-8' );
			echo '<input type="text" name="aiw_connection_string" value="' . $val . '" class="regular-text code" placeholder="InstrumentationKey=...;IngestionEndpoint=https://..." autocomplete="off" />';
			echo '<p class="description">Paste full connection string from Azure Portal.</p>';
		}, self::PAGE_SLUG, 'aiw_main' );

		// Legacy instrumentation key
		add_settings_field( 'aiw_instrumentation_key', 'Instrumentation Key (legacy)', function () {
			$raw     = function_exists( 'get_option' ) ? get_option( 'aiw_instrumentation_key', '' ) : '';
			$display = \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw ) ? '******** (encrypted)' : $raw;
			$val     = function_exists( 'esc_attr' ) ? esc_attr( $display ) : htmlspecialchars( $display, ENT_QUOTES, 'UTF-8' );
			echo '<input type="text" name="aiw_instrumentation_key" value="' . $val . '" class="regular-text code" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />';
			echo '<p class="description">Deprecated. Provide only if not using a Connection String.</p>';
		}, self::PAGE_SLUG, 'aiw_main' );

		// Behavior section
		add_settings_section( 'aiw_behavior', 'Behavior & Performance', function () {
			echo '<p>Control verbosity, sampling and batching. Errors bypass sampling automatically.</p>';
		}, self::PAGE_SLUG );

		add_settings_field( 'aiw_min_level', 'Minimum Log Level', function () {
			$current = function_exists( 'get_option' ) ? get_option( 'aiw_min_level', 'info' ) : 'info';
			$levels  = [ 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' ];
			echo '<select name="aiw_min_level">';
			foreach ( $levels as $lvl ) {
				$sel = $lvl === $current ? 'selected' : '';
				echo '<option value="' . htmlspecialchars( $lvl, ENT_QUOTES, 'UTF-8' ) . '" ' . $sel . '>' . ucfirst( htmlspecialchars( $lvl, ENT_QUOTES, 'UTF-8' ) ) . '</option>';
			}
			echo '</select><p class="description">Events below this level are ignored before sampling.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		add_settings_field( 'aiw_sampling_rate', 'Sampling Rate', function () {
			$val = function_exists( 'get_option' ) ? get_option( 'aiw_sampling_rate', '1' ) : '1';
			$val = is_numeric( $val ) ? $val : '1';
			echo '<input type="range" min="0" max="1" step="0.01" value="' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '" oninput="this.nextElementSibling.value=this.value" name="aiw_sampling_rate" style="width:220px;">';
			echo '<input type="number" min="0" max="1" step="0.01" value="' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '" oninput="this.previousElementSibling.value=this.value" style="width:70px;margin-left:8px;">';
			echo '<p class="description">Fraction of non-error traces/requests to send (1 = all).</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		add_settings_field( 'aiw_feature_toggles', 'Feature Toggles', function () {
			$perf   = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_performance', 1 ) : 1;
			$events = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_events_api', 1 ) : 1;
			$diag   = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_internal_diagnostics', 0 ) : 0;
			$cb     = function ($name, $label, $val, $desc     = '') {
				$checked = $val ? 'checked' : '';
				echo '<label style="display:block;margin:4px 0;"><input type="checkbox" name="' . $name . '" value="1" ' . $checked . ' /> ' . $label . '</label>';
				if ( $desc )
					echo '<p class="description" style="margin-left:20px;margin-top:2px;color:#666;">' . $desc . '</p>';
			};
			$cb( 'aiw_enable_performance', 'Performance Metrics', $perf, 'Hook duration, slow queries, cron timing' );
			$cb( 'aiw_enable_events_api', 'Custom Events & Metrics API', $events, 'aiw_event() and aiw_metric() helpers' );
			$cb( 'aiw_enable_internal_diagnostics', 'Internal Diagnostics', $diag, 'Debug logging to error_log' );
			echo '<p class="description" style="margin-top:8px;">Disable unused subsystems to reduce overhead.</p>';
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

		add_settings_field( 'aiw_slow_hook_threshold_ms', 'Slow Hook Threshold (ms)', function () {
			$val = function_exists( 'get_option' ) ? (int) get_option( 'aiw_slow_hook_threshold_ms', 150 ) : 150;
			echo '<input type="number" min="10" name="aiw_slow_hook_threshold_ms" value="' . (int) $val . '" style="width:90px;" />';
			echo '<p class="description">Record hook_duration_ms metrics only when duration exceeds this threshold.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		add_settings_field( 'aiw_slow_query_threshold_ms', 'Slow Query Threshold (ms)', function () {
			$val = function_exists( 'get_option' ) ? (int) get_option( 'aiw_slow_query_threshold_ms', 500 ) : 500;
			echo '<input type="number" min="10" name="aiw_slow_query_threshold_ms" value="' . (int) $val . '" style="width:90px;" />';
			echo '<p class="description">Emit db_slow_query_ms metrics for queries ≥ this duration (SAVEQUERIES required). Also counts db_slow_query_count.</p>';
		}, self::PAGE_SLUG, 'aiw_behavior' );

		// Runtime status section (read-only list)
		add_settings_section( 'aiw_status', 'Runtime Status', function () {
			$status = $this->runtime_status();
			echo '<ul style="margin-left:1em;list-style:disc">';
			foreach ( $status as $label => $value ) {
				$l = htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
				$v = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
				echo '<li><strong>' . $l . ':</strong> ' . $v . '</li>';
			}
			echo '</ul>';
		}, self::PAGE_SLUG );

		// Privacy / redaction section
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
			echo '<textarea name="aiw_redact_patterns" rows="3" class="large-text" placeholder="/(?:bearer)\\s+[a-z0-9\\-\\._]+/i,/[0-9]{16}/">' . $val . '</textarea>';
			echo '<p class="description">Comma-separated PCRE patterns. Any matching values will be replaced with [REDACTED]. Use cautiously for performance.</p>';
		}, self::PAGE_SLUG, 'aiw_privacy' );
	}

	public function render( $forced_tab = null ) {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) )
			return;

		// Handle test telemetry postback
		$this->handle_test_telemetry_request();

		echo '<div class="wrap"><h1>Azure Insights</h1>';
		$this->render_navigation( $forced_tab );
		$this->render_tab_content( $this->get_active_tab( $forced_tab ) );
		echo '</div>';
	}

	private function handle_test_telemetry_request() {
		if ( isset( $_POST[ 'aiw_send_test' ] ) && function_exists( 'wp_verify_nonce' ) ) {
			$nonce_ok = wp_verify_nonce( $_POST[ 'aiw_test_nonce' ] ?? '', 'aiw_send_test_telemetry' );
			if ( $nonce_ok ) {
				$success = $this->send_test_telemetry();
				if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'add_query_arg' ) ) {
					wp_safe_redirect( add_query_arg( 'aiw_test_sent', $success ? 1 : 0, $_SERVER[ 'REQUEST_URI' ] ) );
					exit;
				}
			}
		}
	}

	private function send_test_telemetry() {
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
					return true;
				}
			}
		} catch (\Throwable $e) {
			return false;
		}
		return false;
	}

	private function get_active_tab( $forced_tab ) {
		$tabs       = [ 'status', 'connection', 'behavior', 'redaction', 'test' ];
		$active_tab = $forced_tab ? $forced_tab : ( isset( $_GET[ 'tab' ] ) ? sanitize_key( $_GET[ 'tab' ] ) : 'status' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $active_tab, $tabs ) ? $active_tab : 'status';
	}

	private function render_navigation( $forced_tab ) {
		$tabs       = [ 
			'status'     => [ 'Status', 'dashicons-chart-area' ],
			'connection' => [ 'Connection', 'dashicons-admin-links' ],
			'behavior'   => [ 'Behavior', 'dashicons-admin-settings' ],
			'redaction'  => [ 'Redaction & Diagnostics', 'dashicons-privacy' ],
			'test'       => [ 'Test Telemetry', 'dashicons-controls-repeat' ],
		];
		$active_tab = $this->get_active_tab( $forced_tab );
		$base_url   = function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=' . self::PAGE_SLUG ) : '?page=' . self::PAGE_SLUG;

		echo '<h2 class="nav-tab-wrapper aiw-modern-nav">';
		foreach ( $tabs as $slug => $meta ) {
			list( $label, $icon ) = $meta;
			$url                  = $base_url . '&tab=' . $slug;
			$cls                  = 'nav-tab' . ( $slug === $active_tab ? ' nav-tab-active' : '' );
			$escL                 = function_exists( 'esc_html' ) ? esc_html( $label ) : htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' );
			$escU                 = function_exists( 'esc_url' ) ? esc_url( $url ) : htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
			echo '<a class="' . $cls . '" href="' . $escU . '"><span class="dashicons ' . $icon . '"></span>' . $escL . '</a>';
		}
		echo '</h2>';
	}

	private function render_tab_content( $active_tab ) {
		switch ( $active_tab ) {
			case 'status':
				echo '<p class="aiw-intro">Operational snapshot of the telemetry pipeline. Use other tabs to change configuration.</p>';
				$this->render_status_dashboard_modern();
				break;
			case 'connection':
				$this->render_connection_tab();
				break;
			case 'behavior':
				$this->render_behavior_tab();
				break;
			case 'redaction':
				$this->render_redaction_tab();
				break;
			case 'test':
				$this->render_test_tab();
				break;
		}
	}

	private function render_connection_tab() {
		echo '<p class="aiw-intro">Configure Azure Application Insights connection. Connection String is recommended over legacy Instrumentation Key.</p>';
		echo '<form method="post" action="options.php">';
		if ( function_exists( 'settings_fields' ) && function_exists( 'do_settings_sections' ) ) {
			settings_fields( self::OPTION_GROUP );
			// Only show connection-related sections
			$this->render_connection_fields();
		}
		if ( function_exists( 'submit_button' ) ) {
			submit_button();
		}
		echo '</form>';
	}

	private function render_behavior_tab() {
		echo '<p class="aiw-intro">Control sampling, batching, and feature toggles to optimize performance.</p>';
		echo '<form method="post" action="options.php">';
		if ( function_exists( 'settings_fields' ) && function_exists( 'do_settings_sections' ) ) {
			settings_fields( self::OPTION_GROUP );
			$this->render_behavior_fields();
		}
		if ( function_exists( 'submit_button' ) ) {
			submit_button();
		}
		echo '</form>';
	}

	private function render_redaction_tab() {
		echo '<p class="aiw-intro">Configure privacy settings and test telemetry functionality.</p>';
		echo '<form method="post" action="options.php">';
		if ( function_exists( 'settings_fields' ) && function_exists( 'do_settings_sections' ) ) {
			settings_fields( self::OPTION_GROUP );
			$this->render_redaction_fields();
		}
		if ( function_exists( 'submit_button' ) ) {
			submit_button();
		}
		echo '</form>';
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

	private function render_connection_fields() {
		// Styles moved to external CSS (aiw-admin.css)
		echo '<div class="aiw-form-card">';
		echo '<h2>🔗 Connection Settings</h2>';
		echo '<table class="aiw-form-table" role="presentation">';

		// Mock Mode
		$val     = function_exists( 'get_option' ) ? (int) get_option( 'aiw_use_mock', 0 ) : 0;
		$checked = $val ? 'checked' : '';
		echo '<tr><th scope="row">Mock Mode</th><td>';
		echo '<div class="aiw-checkbox-wrapper">';
		echo '<input type="checkbox" name="aiw_use_mock" value="1" ' . $checked . ' id="aiw_mock_mode" />';
		echo '<label for="aiw_mock_mode">Enable local telemetry storage (no HTTP requests)</label>';
		echo '</div>';
		echo '<p class="aiw-description">Perfect for development and testing. Inspect telemetry data locally without sending to Azure.</p>';
		echo '</td></tr>';

		// Connection String
		$raw          = function_exists( 'get_option' ) ? get_option( 'aiw_connection_string', '' ) : '';
		$is_encrypted = \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw );
		$display      = $is_encrypted ? '••••••••••••••••••••••••••••••••••••••••' : $raw;
		$val          = function_exists( 'esc_attr' ) ? esc_attr( $display ) : htmlspecialchars( $display, ENT_QUOTES, 'UTF-8' );
		echo '<tr><th scope="row">Connection String</th><td>';
		echo '<div class="aiw-input-group">';
		echo '<input type="text" name="aiw_connection_string" value="' . $val . '" class="aiw-input regular-text" placeholder="InstrumentationKey=...;IngestionEndpoint=https://..." autocomplete="off" />';
		if ( $is_encrypted ) {
			echo '<span class="aiw-status-indicator encrypted">🔒 Encrypted & Stored Securely</span>';
		}
		echo '<p class="aiw-description">📋 Copy the full connection string from Azure Portal → Application Insights → Overview. This is the recommended authentication method.</p>';
		echo '</div>';
		echo '</td></tr>';

		// Instrumentation Key (legacy)
		$raw              = function_exists( 'get_option' ) ? get_option( 'aiw_instrumentation_key', '' ) : '';
		$is_encrypted_key = \AzureInsightsWonolog\Security\Secrets::is_encrypted( $raw );
		$display          = $is_encrypted_key ? '••••••••-••••-••••-••••-••••••••••••' : $raw;
		$val              = function_exists( 'esc_attr' ) ? esc_attr( $display ) : htmlspecialchars( $display, ENT_QUOTES, 'UTF-8' );
		echo '<tr><th scope="row">Instrumentation Key<br><small style="color:#646970;">(Legacy)</small></th><td>';
		echo '<div class="aiw-input-group">';
		echo '<input type="text" name="aiw_instrumentation_key" value="' . $val . '" class="aiw-input regular-text" placeholder="00000000-0000-0000-0000-000000000000" autocomplete="off" />';
		if ( $is_encrypted_key ) {
			echo '<span class="aiw-status-indicator encrypted">🔒 Encrypted & Stored Securely</span>';
		}
		echo '<p class="aiw-description">⚠️ Deprecated method. Only use if you cannot obtain a Connection String from Azure Portal.</p>';
		echo '</div>';
		echo '</td></tr>';

		echo '</table>';
		echo '</div>';
	}

	private function render_behavior_fields() {
		echo '<div class="aiw-form-card">';
		echo '<h2>⚙️ Sampling & Performance</h2>';
		echo '<table class="aiw-form-table" role="presentation">';

		// Minimum level
		$current = function_exists( 'get_option' ) ? get_option( 'aiw_min_level', 'info' ) : 'info';
		$levels  = [ 
			'debug'     => 'Debug', 'info' => 'Info', 'notice' => 'Notice', 'warning' => 'Warning',
			'error'     => 'Error', 'critical' => 'Critical', 'alert' => 'Alert', 'emergency' => 'Emergency',
		];
		echo '<tr><th scope="row">Minimum Log Level</th><td>';
		echo '<select name="aiw_min_level" class="aiw-input" style="width:200px;">';
		foreach ( $levels as $value => $label ) {
			$sel = ( $value === $current ) ? 'selected' : '';
			echo '<option value="' . htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ) . '" ' . $sel . '>' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</option>';
		}
		echo '</select>';
		echo '<p class="aiw-description">Events below this severity level will be filtered out before sampling decisions.</p>';
		echo '</td></tr>';

		// Sampling rate with modern slider
		$val        = function_exists( 'get_option' ) ? get_option( 'aiw_sampling_rate', '1' ) : '1';
		$val        = is_numeric( $val ) ? $val : '1';
		$percentage = round( $val * 100 );
		echo '<tr><th scope="row">Sampling Rate</th><td>';
		echo '<div class="aiw-input-group">';
		echo '<div style="display:flex;align-items:center;gap:16px;margin-bottom:8px;">';
		echo '<input type="range" min="0" max="1" step="0.01" value="' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '" name="aiw_sampling_rate" style="flex:1;height:8px;border-radius:4px;background:#e0e0e0;outline:none;" oninput="this.nextElementSibling.textContent=Math.round(this.value*100)+\'%\'" />';
		echo '<span style="min-width:40px;font-weight:600;color:#667eea;">' . $percentage . '%</span>';
		echo '</div>';
		echo '<p class="aiw-description">🎯 Percentage of non-error traces and requests to send to Azure. Errors always bypass sampling for reliability.</p>';
		echo '</div>';
		echo '</td></tr>';

		echo '</table>';
		echo '</div>';

		echo '<div class="aiw-form-card">';
		echo '<h2>📦 Batching Configuration</h2>';
		echo '<table class="aiw-form-table" role="presentation">';

		$batch_size     = function_exists( 'get_option' ) ? (int) get_option( 'aiw_batch_max_size', 20 ) : 20;
		$flush_interval = function_exists( 'get_option' ) ? (int) get_option( 'aiw_batch_flush_interval', 10 ) : 10;

		echo '<tr><th scope="row">Batch Size</th><td>';
		echo '<input type="number" min="1" max="100" name="aiw_batch_max_size" value="' . (int) $batch_size . '" class="aiw-input" style="width:100px;" />';
		echo '<p class="aiw-description">Maximum telemetry items per batch. Larger batches reduce HTTP overhead but use more memory.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Flush Interval</th><td>';
		echo '<div style="display:flex;align-items:center;gap:8px;">';
		echo '<input type="number" min="1" max="300" name="aiw_batch_flush_interval" value="' . (int) $flush_interval . '" class="aiw-input" style="width:100px;" />';
		echo '<span style="color:#646970;">seconds</span>';
		echo '</div>';
		echo '<p class="aiw-description">⏱️ Auto-flush incomplete batches after this timeout to ensure timely delivery.</p>';
		echo '</td></tr>';

		// Async send with modern toggle
		$async = function_exists( 'get_option' ) ? (int) get_option( 'aiw_async_enabled', 0 ) : 0;
		echo '<tr><th scope="row">Async Processing</th><td>';
		echo '<div class="aiw-checkbox-wrapper">';
		echo '<input type="checkbox" name="aiw_async_enabled" value="1" ' . ( $async ? 'checked' : '' ) . ' id="aiw_async" />';
		echo '<label for="aiw_async">Enable background sending via WordPress cron</label>';
		echo '</div>';
		echo '<p class="aiw-description">🚀 Improves page load times by deferring network requests to background processes.</p>';
		echo '</td></tr>';

		echo '</table>';
		echo '</div>';

		echo '<div class="aiw-form-card">';
		echo '<h2>📊 Performance Monitoring</h2>';
		echo '<table class="aiw-form-table" role="presentation">';

		$hook_thresh  = function_exists( 'get_option' ) ? (int) get_option( 'aiw_slow_hook_threshold_ms', 150 ) : 150;
		$query_thresh = function_exists( 'get_option' ) ? (int) get_option( 'aiw_slow_query_threshold_ms', 500 ) : 500;

		echo '<tr><th scope="row">Slow Hook Threshold</th><td>';
		echo '<div style="display:flex;align-items:center;gap:8px;">';
		echo '<input type="number" min="10" max="5000" name="aiw_slow_hook_threshold_ms" value="' . (int) $hook_thresh . '" class="aiw-input" style="width:100px;" />';
		echo '<span style="color:#646970;">milliseconds</span>';
		echo '</div>';
		echo '<p class="aiw-description">🐌 Record hook execution times that exceed this threshold as custom metrics.</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">Slow Query Threshold</th><td>';
		echo '<div style="display:flex;align-items:center;gap:8px;">';
		echo '<input type="number" min="10" max="10000" name="aiw_slow_query_threshold_ms" value="' . (int) $query_thresh . '" class="aiw-input" style="width:100px;" />';
		echo '<span style="color:#646970;">milliseconds</span>';
		echo '</div>';
		echo '<p class="aiw-description">🗃️ Track database queries slower than this threshold (requires SAVEQUERIES constant).</p>';
		echo '</td></tr>';

		echo '</table>';
		echo '</div>';

		echo '<div class="aiw-form-card">';
		echo '<h2>🔧 Feature Toggles</h2>';

		$perf   = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_performance', 1 ) : 1;
		$events = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_events_api', 1 ) : 1;
		$diag   = function_exists( 'get_option' ) ? (int) get_option( 'aiw_enable_internal_diagnostics', 0 ) : 0;

		$features = [ 
			[ 'aiw_enable_performance', 'Performance Metrics', $perf, '📈 Hook timing, slow queries, cron execution monitoring' ],
			[ 'aiw_enable_events_api', 'Events & Metrics API', $events, '🎯 aiw_event() and aiw_metric() helper functions' ],
			[ 'aiw_enable_internal_diagnostics', 'Debug Logging', $diag, '🔍 Internal diagnostics written to error_log (development only)' ],
		];

		foreach ( $features as $feature ) {
			list( $name, $label, $enabled, $description ) = $feature;
			$id                                           = str_replace( 'aiw_enable_', 'feature_', $name );
			echo '<div class="aiw-checkbox-wrapper" style="margin-bottom:12px;">';
			echo '<input type="checkbox" name="' . $name . '" value="1" ' . ( $enabled ? 'checked' : '' ) . ' id="' . $id . '" />';
			echo '<div>';
			echo '<label for="' . $id . '" style="font-weight:500;">' . $label . '</label>';
			echo '<p style="margin:4px 0 0;font-size:12px;color:#646970;">' . $description . '</p>';
			echo '</div>';
			echo '</div>';
		}

		echo '<p class="aiw-description" style="margin-top:16px;">💡 Disable unused features to minimize performance overhead and reduce telemetry noise.</p>';
		echo '</div>';
	}

	private function render_redaction_fields() {
		echo '<div class="aiw-form-card">';
		echo '<h2>🔒 Privacy & Redaction</h2>';
		echo '<table class="aiw-form-table" role="presentation">';

		// Additional redact keys
		$raw = function_exists( 'get_option' ) ? get_option( 'aiw_redact_additional_keys', '' ) : '';
		$val = function_exists( 'esc_textarea' ) ? esc_textarea( $raw ) : htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' );
		echo '<tr><th scope="row">Sensitive Keys</th><td>';
		echo '<div class="aiw-input-group">';
		echo '<textarea name="aiw_redact_additional_keys" rows="3" class="aiw-input large-text" placeholder="password,token,secret,api_key,email" style="font-family:ui-monospace,monospace;resize:vertical;">' . $val . '</textarea>';
		echo '<p class="aiw-description">🏷️ Comma-separated list of context keys to automatically redact. Case-insensitive matching applied to both keys and nested object properties.</p>';
		echo '</div>';
		echo '</td></tr>';

		// Regex patterns with enhanced styling
		$raw = function_exists( 'get_option' ) ? get_option( 'aiw_redact_patterns', '' ) : '';
		$val = function_exists( 'esc_textarea' ) ? esc_textarea( $raw ) : htmlspecialchars( $raw, ENT_QUOTES, 'UTF-8' );
		echo '<tr><th scope="row">Pattern Matching</th><td>';
		echo '<div class="aiw-input-group">';
		echo '<textarea name="aiw_redact_patterns" rows="4" class="aiw-input large-text" placeholder="/(?:bearer|token)\s+[a-z0-9\-\._]+/i,/\b[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}\b/,/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/" style="font-family:ui-monospace,monospace;resize:vertical;">' . $val . '</textarea>';
		echo '<div style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px;border-left:4px solid #667eea;">';
		echo '<p style="margin:0 0 8px;font-weight:500;color:#1d2327;">Common Patterns:</p>';
		echo '<code style="display:block;margin:4px 0;font-size:11px;color:#646970;">• Credit Cards: /\\b[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}\\b/</code>';
		echo '<code style="display:block;margin:4px 0;font-size:11px;color:#646970;">• Email Addresses: /\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Z|a-z]{2,}\\b/</code>';
		echo '<code style="display:block;margin:4px 0;font-size:11px;color:#646970;">• Bearer Tokens: /(?:bearer|token)\\s+[a-z0-9\\-\\._]+/i</code>';
		echo '</div>';
		echo '<p class="aiw-description">🎯 Advanced PCRE regex patterns for value-based redaction. Use sparingly as complex patterns impact performance.</p>';
		echo '</div>';
		echo '</td></tr>';

		echo '</table>';

		// Privacy notice section
		echo '<div style="margin-top:20px;padding:16px;background:linear-gradient(135deg,#e8f4f8 0%,#f0f8ff 100%);border-radius:8px;border:1px solid #b8d4ea;">';
		echo '<h4 style="margin:0 0 8px;color:#1d2327;display:flex;align-items:center;gap:8px;"><span>🛡️</span> Privacy Protection</h4>';
		echo '<p style="margin:0;font-size:13px;line-height:1.5;color:#495057;">All redacted data is replaced with <code>[REDACTED]</code> markers. When redaction occurs, diagnostic metadata is added to help track what was filtered. Secrets (connection strings, instrumentation keys) are encrypted using WordPress salt keys before database storage.</p>';
		echo '</div>';
		echo '</div>';
	}

	private function render_test_telemetry_form() {
		echo '<div class="aiw-form-card">';
		echo '<h2>🧪 Test Telemetry</h2>';
		if ( ! function_exists( 'wp_nonce_field' ) ) {
			echo '<p>WordPress context unavailable.</p>';
			echo '</div>';
			return;
		}

		echo '<div style="margin-bottom:20px;padding:16px;background:#f8f9fa;border-radius:8px;border-left:4px solid #667eea;">';
		echo '<h4 style="margin:0 0 8px;color:#1d2327;">🎯 Test Suite</h4>';
		echo '<p style="margin:0;font-size:13px;line-height:1.5;color:#495057;">Send sample telemetry to validate your Azure Application Insights configuration. This will generate a trace, custom event, metric, and optionally an exception.</p>';
		echo '</div>';

		echo '<form method="post" action="" style="margin:0;">';
		wp_nonce_field( 'aiw_send_test_telemetry', 'aiw_test_nonce' );
		echo '<table class="aiw-form-table" role="presentation">';
		echo '<tr><th scope="row">Test Type</th><td>';
		echo '<div style="display:flex;align-items:center;gap:16px;">';
		echo '<select name="aiw_test_kind" class="aiw-input" style="width:200px;">';
		echo '<option value="info">📊 Info Trace + Metrics</option>';
		echo '<option value="error">🚨 Error Trace + Exception</option>';
		echo '</select>';
		echo '<button type="submit" class="button button-primary" style="padding:8px 20px;font-weight:500;">Send Test Data</button>';
		echo '</div>';
		echo '<input type="hidden" name="aiw_send_test" value="1" />';
		echo '<p class="aiw-description">Results typically appear in Azure within 1-2 minutes. Check the Application Insights Logs blade for traces table.</p>';
		echo '</td></tr>';
		echo '</table>';
		echo '</form>';

		// Enhanced status display
		if ( isset( $_GET[ 'aiw_test_sent' ] ) ) {
			$ok          = intval( $_GET[ 'aiw_test_sent' ] ) === 1;
			$icon        = $ok ? '✅' : '❌';
			$bgColor     = $ok ? '#e7f7ed' : '#fce5e5';
			$borderColor = $ok ? '#7ad03a' : '#d63638';
			$textColor   = $ok ? '#185b37' : '#8b0000';
			$message     = $ok ? 'Test telemetry dispatched successfully!' : 'Test telemetry failed to send.';
			$details     = $ok
				? 'Data sent to Azure Application Insights. Check the Logs blade in Azure Portal within 1-2 minutes.'
				: 'Check your connection settings and Azure Application Insights configuration.';

			echo '<div style="margin-top:20px;padding:16px;background:' . $bgColor . ';border:1px solid ' . $borderColor . ';border-radius:8px;color:' . $textColor . ';">';
			echo '<p style="margin:0 0 4px;font-weight:600;font-size:14px;">' . $icon . ' ' . $message . '</p>';
			echo '<p style="margin:0;font-size:12px;opacity:0.8;">' . $details . '</p>';
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_test_tab() {
		echo '<p class="aiw-intro">Send sample telemetry payloads to verify pipeline configuration without altering other settings.</p>';
		$this->render_test_telemetry_form();
	}
}
