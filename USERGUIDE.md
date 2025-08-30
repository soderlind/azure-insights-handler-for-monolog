# User Guide – Azure Insights Handler for Wonolog

## 1. What Is This Plugin?
Azure Insights Handler for Wonolog is a WordPress plugin that bridges your site’s (or network’s) PHP / WordPress logs and custom operational telemetry into Azure Application Insights. It layers on top of Wonolog (which provides a Monolog logger inside WordPress) and converts Monolog records plus internally captured signals (requests, exceptions, performance metrics, custom events & metrics) into the JSON line payload Azure expects.

It’s intentionally lightweight (no heavy Azure SDK) and production‑oriented: sampling, batching, retry with exponential backoff, secure secret storage, adaptive throttling, correlation propagation, and rich redaction to keep sensitive data out of the wire.

## 2. Why Use It?
- Centralize observability: unify PHP errors, WP hooks timing, business events, and custom metrics in Azure Monitor (Kusto queries, dashboards, alerts).
- Faster incident response: request traces & correlation IDs tie together slow hooks, DB latency, and log spikes.
- Cost control & performance: client‑side sampling + batching + async mode reduce request latency & Azure ingestion volume.
- Security & privacy: strong configurable redaction, encryption at rest for secrets, minimized footprint (no raw secret echoing).
- Multisite clarity: network‑level overrides ensure consistent telemetry across a fleet while still allowing per‑site activation when not network‑wide.
- Local/dev flexibility: mock mode for zero‑network iteration and viewer to inspect fabricated telemetry.

## 3. Core Concepts
| Concept | Summary |
| ------- | ------- |
| Connection String | Primary secret describing Instrumentation Key + ingestion endpoint. Preferred over legacy key. |
| Telemetry Types | Traces (logs), Requests, Exceptions, Events, Metrics, Internal Diagnostics. |
| Sampling | Probabilistic drop of low‑value telemetry to control volume; errors & critical logs typically bypass or can be filtered. |
| Batching | Items buffered (count + interval) into newline‑delimited JSON for fewer HTTP round‑trips. |
| Async Dispatch | Persists batches & ships them via cron to eliminate foreground latency. |
| Retry Queue | Failed batches re‑attempted (1m→5m→15m→1h). |
| Correlation | traceparent header parsing + outbound propagation → end‑to‑end trace stitching. |
| Redaction | Key & regex based masking, plus diagnostic metadata of what was removed. |
| Adaptive Sampling | Auto lowers sampling rate during bursts to protect performance & quota. |
| Performance Metrics | Hook duration, slow query timing, cron run time, memory usage. |
| Mock Mode | Telemetry captured locally for inspection (no HTTP). |

## 4. Installation & Activation
1. Copy plugin folder into `wp-content/plugins/`.
2. (Optional) Run `composer install` inside the plugin directory if you rely on bundled vendor libs (Wonolog may already provide Monolog).
3. Activate in the WordPress Admin (or network‑activate in multisite if you want global control).
4. Provide a Connection String (Settings → Azure Insights) OR set it securely before WP loads:
```php
// wp-config.php
define( 'AIW_CONNECTION_STRING', 'InstrumentationKey=...;IngestionEndpoint=https://region.ingest.applicationinsights.azure.com/' );
```
5. (Multisite) If network‑activated: configure in Network Admin → Azure Insights (per‑site settings page is suppressed). If only site‑activated on a subset of sites you’ll see the normal per‑site page.

## 5. Configuration Sources & Precedence
Highest wins:
1. PHP constants (e.g., `AIW_CONNECTION_STRING`, `AIW_MIN_LEVEL`)
2. Environment variables (e.g., `AIW_CONNECTION_STRING`, fallbacks like `APPLICATIONINSIGHTS_CONNECTION_STRING`)
3. Network (site) options (if multisite + network‑activated)
4. Per‑site options

This allows ops to enforce mandatory settings via code / infrastructure, while still letting UI changes fill gaps.

## 6. Key Settings Explained
| Setting | Guidance |
| ------- | -------- |
| Connection String | Use the full string; includes endpoint for sovereign or custom clouds. Stored encrypted (AES‑256‑CBC with WP salts). |
| Instrumentation Key | Legacy; only supply if you cannot use connection strings. |
| Minimum Log Level | Pre‑sampling gate (Monolog level). Start with `warning` in prod. |
| Sampling Rate | 0–1. 1 = send all. Lower for high traffic to reduce ingestion costs. |
| Batch Max Size / Interval | Tune for latency vs. network efficiency. 20 / 5s defaults are safe. Lower interval if expecting low traffic to avoid stale items. |
| Async Dispatch | Enable when you want to offload HTTP from main page requests (cron must run reliably). |
| Performance Metrics | Off if you only need logs. On to collect hook duration, slow queries, memory, cron time. |
| Slow Hook Threshold (ms) | Only emit hook metrics above threshold to reduce cardinality. |
| Slow Query Threshold (ms) | Emit metrics for slow DB queries; set based on typical query baseline. |
| Additional Redact Keys / Regex Patterns | Keep concise—each adds cost. Test patterns in staging. |
| Mock Mode | Ideal for local dev. Don’t leave on in production; no data leaves the server. |

## 7. Multisite Behavior
- Network‑activated: single Network Settings page; per‑site pages hidden to ensure uniform config.
- Site‑specific activation (not network‑activated): each active site gets its own Settings page.
- Network options override site options (still beneath constant/env). This gives central governance with optional site‑specific keys if you selectively activate.

## 8. Sending Telemetry
### Automatic
- Logs via Wonolog / Monolog handlers.
- Request telemetry on `shutdown` (duration, result code, correlation IDs).
- Performance metrics gathered by hook instrumentation & thresholds.
- Slow query metrics (if thresholds enabled).

### Helpers
```php
aiw_event( 'CheckoutCompleted', [ 'order_id' => 987 ], [ 'amount' => 149.00 ] );
aiw_metric( 'orders_processed', 1, [ 'channel' => 'web' ] );
$trace_id = aiw_current_trace_id(); // For linking to other systems/logs
```

### CLI
```
wp aiw test --error   # emits sample trace, event, metric and (optionally) an exception
wp aiw status         # dump current config state / counts
wp aiw flush_queue    # force process retry queue
```

## 9. Redaction Strategy
1. Built‑in sensitive key list (password, secret, token, authorization, etc.).
2. Administrator supplied key names (comma separated).
3. Regex pattern pass (apply patterns to string values).
4. Diagnostic dimension `_aiw_redaction` enumerates which keys/patterns triggered masking to aid tuning.

Tip: Prefer key redaction over regex when possible—cheaper & clearer.

## 10. Sampling & Adaptive Behavior
- Base sampling applies to non‑error traces & requests.
- You can override per record via `aiw_should_sample` filter (e.g., always keep security events).
- Adaptive sampling (when large bursts detected) lowers effective rate automatically; base rate still reported in properties for observability.

## 11. Batching & Async
- Synchronous mode: buffer until size/interval reached, then POST to ingest endpoint.
- Async mode: stage newline‑delimited batches into an option; cron event ships them soon after. Minimizes user‑perceived latency (ensure cron executes promptly—consider real cron vs WP cron on busy sites).

## 12. Retry Queue
Stores unsent batches with attempt metadata. View & clear in the Retry Queue admin panel. Backoff schedule: 1m → 5m → 15m → 60m. Items drop after final failure to avoid infinite loops (consider alerting via Azure on ingestion gaps).

## 13. Status Panel
Visual dashboard summarizing health: last send times, queue depth, async pending count, memory, thresholds, versions, secret presence flags. Use this for quick smoke validation post‑deploy.

## 14. Security & Secrets
- Secrets encrypted at rest using WordPress salts + IV.
- Not displayed fully once stored (masked). Re-enter to rotate.
- Constants / env avoid DB storage entirely.
- Redaction ensures removed data never leaves memory path post sanitization.

## 15. Performance Tips
| Situation | Recommendation |
| --------- | -------------- |
| High traffic spikes | Lower sampling (e.g., 0.2) + enable adaptive sampling. |
| Latency sensitive pages | Enable async dispatch. |
| Memory constrained hosts | Keep batch size modest (≤ 20) and disable unused features. |
| Regex heavy redaction | Consolidate patterns; pre‑test for catastrophic backtracking. |

## 16. Observability in Azure (Sample Kusto)
Recent traces with plugin dimension:
```kusto
traces
| where timestamp > ago(10m)
| project timestamp, message, severityLevel, plugin_version=customDimensions.plugin_version
| take 50
```
Slowest hooks p95:
```kusto
customMetrics
| where name == 'hook_duration_ms'
| summarize p95=valuePercentile(value,95) by hook=tostring(customDimensions.hook)
| top 15 by p95 desc
```
Request durations (raw vs sampled):
```kusto
requests
| project timestamp, name, duration, success, sampling_rate=tostring(customDimensions.sampling_rate)
| order by timestamp desc
| take 100
```

## 17. Extensibility (Filters)
```php
add_filter( 'aiw_use_mock_telemetry', function( $use, $config ){ return defined('WP_DEBUG') && WP_DEBUG; }, 10, 2 );
add_filter( 'aiw_enrich_dimensions', function( $props, $ctx ){ $props['deployment_slot'] = 'blue'; return $props; }, 10, 2 );
add_filter( 'aiw_should_sample', function( $decision, $record, $rate ){ if( isset($record['channel']) && $record['channel']==='security') return true; return $decision; }, 10, 3 );
add_filter( 'aiw_redact_keys', function( $keys ){ $keys[] = 'jwt'; return $keys; } );
add_filter( 'aiw_before_send_batch', function( $lines, $config ){ /* mutate or audit */ return $lines; }, 10, 2 );
```

## 18. Privacy Notice Helper
Call `aiw_privacy_notice()` (or filter `aiw_privacy_notice_text`) to surface end‑user disclosure text in your site’s privacy page if required.

## 19. Troubleshooting
| Symptom | Check |
| ------- | ----- |
| No data in Azure | Correct connection string? Delay (1–2m)? Sampling too low? Network blocked? |
| High ingestion cost | Lower sampling, prune verbose log channels, minimize custom dimensions cardinality. |
| Slow admin pages | Disable performance metrics or async flush; review hook threshold settings. |
| Redaction missing a key | Add key name to Additional Redact Keys (case insensitive). |
| Retry queue growing | Inspect transport errors in Status Panel; verify outbound firewall / endpoint. |
| Per-site settings hidden unexpectedly | Plugin may be network‑activated; deactivate network‑wide and activate per site if you need isolation. |

## 20. Roadmap Ideas
- OpenTelemetry exporter bridge
- Web UI for historical metric spark lines
- Automatic detection of common PII fields for suggestion mode

## 21. Uninstall
Unless `AIW_PRESERVE_SETTINGS` is defined truthy, all plugin options & network options (prefixed `aiw_`) are removed during uninstall.

## 22. License & Attribution
MIT. Built to complement Wonolog + Azure Application Insights for production WordPress observability.

---
Questions or improvement ideas? Open an issue or submit a PR. Happy observing!
