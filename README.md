# Azure Insights Handler for Monolog

WordPress plugin forwarding Monolog logs and custom telemetry (traces, requests, exceptions, events, metrics) to Azure Application Insights.

## Features
- Trace (MessageData) telemetry for Monolog records
- Request telemetry (captured on shutdown)
- Exception telemetry with parsed stack
- Event telemetry via `aiw_event()` helper
- Metric telemetry via `aiw_metric()` helper and performance collector (hook durations)
- Correlation (trace/span) IDs from incoming `traceparent` header when available
- Sampling with filter override (`aiw_should_sample`)
- Configurable feature toggles (performance metrics, events API, diagnostics)
- Retry queue (exponential backoff) & admin viewer
	- Uses options by default; define `AIW_RETRY_STORAGE` to `transient` to keep queue in a transient (still shadow-synced to option for durability)
- Mock mode (no HTTP) + persistent mock telemetry viewer
- Advanced redaction:
	- Built-in sensitive keys
	- Admin-configurable additional keys
	- Regex value redaction (comma‑separated PCRE patterns)
	- Diagnostic metadata `_aiw_redaction` listing redacted keys/patterns
- Test telemetry button (trace, event, metric, optional exception)
- Batch buffering (size + interval)
- Optional async dispatch (defer network send via cron)
- Secure at-rest option encryption (connection string & instrumentation key) using site salts
- Adaptive sampling (dynamic reduction under burst load)
- Slow query metrics (db_slow_query_ms, db_slow_query_count) with configurable threshold
- Cron execution duration metric (cron_run_duration_ms)
- Expanded baseline dimensions (plugin_version, blog_id, request_method, request_uri)
- Exception deduplication window to suppress floods (hash-based 30s window)
- Outbound correlation header injection (traceparent) via WP HTTP API filter
- WP-CLI commands: `wp aiw status`, `wp aiw test [--error]`, `wp aiw flush_queue`
- Lightweight (no full Azure SDK dependency)
 - Admin Status Panel (live runtime metrics: last send time, retry depth, async batches, buffer size, memory, thresholds, versions)

## Requirements
- WordPress 6.5+
- PHP 8.2+
- Monolog library via Composer (no separate Wonolog plugin required)

## Installation

    1. Download [azure-insights-handler-for-monolog.zip](https://github.com/soderlind/azure-insights-handler-for-monolog/releases/latest/download/azure-insights-handler-for-monolog.zip)
   2. Upload via  Plugins > Add New > Upload Plugin
   3. Activate in WP Admin.
   4. Provide Connection String (preferred) or legacy Instrumentation Key under Settings → Azure Insights.
       - Or set one of these before WordPress loads (wp-config.php / server env):
           - `AIW_CONNECTION_STRING`
           - `APPLICATIONINSIGHTS_CONNECTION_STRING` (fallback)
           - `APPINSIGHTS_CONNECTION_STRING` (fallback)
           - `AIW_INSTRUMENTATION_KEY` or `APPLICATIONINSIGHTS_INSTRUMENTATION_KEY`
       Constants still override environment variables if both are present.
   5. (Optional) Enable Mock Mode for local development and open the Mock Telemetry Viewer.

### Updates
Plugin updates are handled automatically via GitHub. No need to manually download and install updates.


### Azure Setup
1. In Azure Portal create an Application Insights resource (or within existing Log Analytics workspace).
2. Open the resource → Overview → Copy Connection String.
3. (Optional) Lock down ingestion by IP restriction using Azure Monitor Private Link / firewall if required.
4. In `wp-config.php` you may define constants to avoid storing secrets in DB:
```php
define( 'AIW_CONNECTION_STRING', 'InstrumentationKey=...;IngestionEndpoint=https://...');
define( 'AIW_SAMPLING_RATE', 0.5 ); // 50% sampling
```
5. Trigger a test batch:
```bash
wp aiw test
```
6. In Azure → Logs run a basic query (may take a minute to appear):
```kusto
traces
| where customDimensions.plugin_version != ''
| order by timestamp desc
| limit 50
```

### Useful Kusto Queries
Recent exceptions:
```kusto
exceptions
| where timestamp > ago(1h)
| summarize count() by type, innermostMessage
| order by count_ desc
```
Slow hooks (custom metric):
```kusto
customMetrics
| where name == 'hook_duration_ms'
| extend hook=tostring(customDimensions.hook)
| summarize p95=valuePercentile(value,95), avg(value) by hook
| order by p95 desc
```
Slow DB queries (counts & individual samples):
```kusto
customMetrics
| where name in ('db_slow_query_ms','db_slow_query_count')
| order by timestamp desc
| take 100
```
Request performance with sampling rate dimension:
```kusto
requests
| project timestamp, name, duration, success, sampling_rate=tostring(customDimensions.sampling_rate)
| order by timestamp desc
| take 100
```
Cardinality of trace IDs (health of correlation):
```kusto
traces
| where timestamp > ago(15m)
| summarize dcount(customDimensions.trace_id)
```

## Configuration (Settings → Azure Insights)
- Connection String: `InstrumentationKey=...;IngestionEndpoint=https://...`
- Instrumentation Key (legacy): Use only if no connection string.
- Mock Mode: Store telemetry locally; inspect via viewer.
- Minimum Log Level: Discard below threshold pre-sampling.
- Sampling Rate: Probability (0–1) for non-error traces/requests.
- Batch Max Size / Flush Interval: Control sending cadence.
 - Async Dispatch: Enable cron‑deferred sending to avoid blocking the request lifecycle.
- Feature Toggles: Enable/disable performance metrics, events API helpers, internal diagnostics.
- Additional Redact Keys: Comma list of extra context keys to mask.
- Regex Redact Patterns: Comma list of PCRE patterns; matching values become `[REDACTED]`.
- Send Test Telemetry: Emits sample trace, event, metric, and optional exception.
- Slow Hook Threshold: Milliseconds above which hook duration metrics are recorded.
 - Slow Query Threshold: Milliseconds above which slow DB query metrics are recorded.

## Helper Functions
```php
aiw_current_trace_id();
aiw_current_span_id();
aiw_event( 'UserRegistered', [ 'user_id' => 123 ], [ 'duration_ms' => 45 ] );
aiw_metric( 'orders_processed', 10, [ 'source' => 'cron' ] );
```

## Filters
```php
add_filter( 'aiw_use_mock_telemetry', function( $use, $config ) { return WP_DEBUG; }, 10, 2 );
add_filter( 'aiw_should_sample', function( $decision, $record, $rate ) { return $decision; }, 10, 3 );
add_filter( 'aiw_redact_keys', function( $keys ) { $keys[] = 'api_secret'; return $keys; } );
add_filter( 'aiw_propagate_correlation', function( $enable, $url, $args ) { return $enable; }, 10, 3 );
add_filter( 'aiw_before_send_batch', function( $lines, $config ) { /* mutate newline-delimited JSON item lines */ return $lines; }, 10, 2 );
```

## Redaction Diagnostics
If keys or patterns redacted, `_aiw_redaction` is added:
```json
"_aiw_redaction": { "keys": ["password","token"], "patterns": ["/[0-9]{16}/"] }
```

## Retry Queue
Failed batches (blocking send failures) re-queued with delays (1m,5m,15m,1h). View & clear via Settings → AIW Retry Queue.
Status shows depth plus cumulative & max attempt counts.

## Status Panel
Accessible via Settings → Azure Insights → Status. Displays snapshot operational metrics to aid diagnostics:

| Metric | Description |
| ------ | ----------- |
| Last Send (UTC) | Timestamp of last successful (or attempted) batch send. |
| Last Error Code / Message | Most recent transport or HTTP status code & truncated message body. Empty if last send succeeded. |
| Retry Queue Depth | Number of pending retry batch entries. |
| Next Retry (UTC) | Earliest scheduled retry attempt time (if queue not empty). |
| Async Pending Batches | Batches staged for cron processing when async dispatch enabled. |
| Latest Async Batch (UTC) | Timestamp of newest staged async batch. |
| Current Buffer Size | In‑memory unsent telemetry items for the active request (synchronous mode). |
| Mock Persisted Items | Count of locally stored items when Mock Mode is enabled. |
| Sampling Rate | Effective configured base sampling probability (pre adaptive reduction). |
| Slow Hook Threshold (ms) | Current threshold for hook duration telemetry emission. |
| Slow Query Threshold (ms) | Current threshold for slow DB query metrics. |
| Current / Peak Memory Usage (MB) | PHP memory usage (real) at panel render & peak during request. |
| PHP / WP / Plugin Version | Runtime versions for quick environment correlation. |
| Site URL | Home URL (useful in multisite / multi‑env). |
| Instrumentation Key / Connection String Set | Indicates secret presence (never displays raw secret). |

Use this panel for a fast health check before deeper log / Kusto analysis.

## Mock Mode
Enable to avoid HTTP. Items persisted (last 200) and browsed in viewer with filtering, pagination, copy JSON.

## Testing
PHPUnit tests cover trace, request duration formatting, exception, event, sampling, redaction (including regex), retry queue, diagnostics.

## Performance Considerations
Sampling & batching reduce overhead. Regex patterns can add cost—keep them minimal. Mock mode flushes per item for visibility.
Disable unused subsystems (events API, performance metrics) via Feature Toggles to reduce overhead further.

## Roadmap / Ideas
- Per-telemetry-type adaptive sampling tuning
- Percentile performance dashboards
- OpenTelemetry exporter bridge
- Front-end JS auto-instrumentation toggle

## WP-CLI
```
wp aiw status
wp aiw test --error
wp aiw flush_queue
```

Note: The subcommand uses an underscore (`flush_queue`). The earlier README draft showed a hyphenated form; use the underscore variant.

## Uninstall Behavior
On uninstall plugin deletes its options (including encrypted secrets) unless `AIW_PRESERVE_SETTINGS` is defined and truthy.

## Security / Encryption
Connection string & instrumentation key are stored encrypted (AES-256-CBC) using WordPress salts. Displayed as masked once saved. Re-enter to rotate.
Privacy Notice: `aiw_privacy_notice()` returns a short text you can display; filter with `aiw_privacy_notice_text`.

## Correlation Propagation
Adds `traceparent` header to outbound requests (`http_request_args` hook). Disable or modify via `aiw_propagate_correlation` filter.

## License
MIT
