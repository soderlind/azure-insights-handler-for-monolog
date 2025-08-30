# Azure Insights Handler for Wonolog — Design Document

## 1. Goal
Provide a robust, configurable WordPress plugin that augments Wonolog logging by forwarding enriched log, trace, and performance telemetry to Azure Application Insights, enabling observability (logs, traces, metrics, correlation) without materially impacting page performance.

## 2. Scope Summary
In-scope:
- Monolog / Wonolog handler that ships records to Azure Application Insights
- Correlated request + background task telemetry
- Custom log level mapping & threshold filtering
- Structured context & extra fields → customDimensions
- Correlation (Traceparent header, generated trace + span IDs, propagation)
- Performance metrics (request duration, hook timings, DB query stats, memory, WP Cron runs, slow queries threshold)
- Custom events & metrics (e.g., user login, plugin updates) opt‑in
- Sampling & batching with async dispatch (wp_cron or fast non-blocking HTTP)
- Admin UI for configuration & status (instrumentation key / connection string, toggles, log level, sampling %, feature flags)
- Queued retry with exponential backoff on transient failures
- Minimal PII exposure (redaction strategy) & GDPR notice hook

Out-of-scope (initial release):
- Distributed tracing across microservices beyond basic W3C propagation
- Full-blown analytics dashboard inside WP admin (defer to Azure Portal)
- Automatic front-end JS instrumentation (document for later)

## 3. Refined Feature List
| # | Feature | Description | Priority |
|---|---------|-------------|----------|
| 1 | Application Insights Transport Handler | Monolog handler sending records (batch) | Must |
| 2 | Config Admin Page | Settings (connection string, enable flags, level, sampling) | Must |
| 3 | Secure Storage | Store connection string via hashed / obfuscated option & constants override | Must |
| 4 | Log Level Mapping & Threshold | Map Monolog levels → AI Severity & allow minimum level | Must |
| 5 | Correlation IDs | Generate/propagate trace + span; accept traceparent & custom headers | Must |
| 6 | Request Telemetry | Request duration metric + success flag | Must |
| 7 | Performance Metrics | Hook timings, memory peak, DB query count/time, slow hook detection | Should |
| 8 | Custom Events API | Simple function to emit events/metrics (aiw_event(), aiw_metric()) | Should |
| 9 | Sampling & Batching | Configurable (e.g., fixed-rate sampling, max batch size/flush interval) | Must |
|10 | Async Dispatch | Non-blocking wp_remote_post or cron fallback + shutdown flush | Must |
|11 | Retry & Backoff | Queue failed batches with exponential backoff & cap | Should |
|12 | PII Redaction | Configurable list of keys to scrub (email, user_login, etc.) | Should |
|13 | Plugin Health Panel | Last send time, queue depth, errors summary | Could |
|14 | CLI Commands | WP-CLI: test send, flush queue, show status | Could |
|15 | Unit & Integration Tests | Handler serialization, batching, mapping, correlation; mock transport | Must |
|16 | Documentation | README + Azure setup + examples + extensibility points | Must |

Adjustments made: Added admin config, sampling, batching, retry, health status, CLI, security/PII redaction. These support reliability, performance, and compliance goals.

## 4. Architecture Overview
Components:
- Service Provider (bootstrap) hooking into WordPress & Wonolog
- Monolog Handler: `AzureInsightsHandler` (extends `Monolog\Handler\AbstractProcessingHandler`)
- Telemetry Client Abstraction: wraps Application Insights ingestion (Connection String or Instrumentation Key)
- Queue / Buffer: in-memory + transient/option based fallback for retry
- Correlation Manager: manages trace/span IDs, reads/writes headers
- Performance Collector: times selected WP hooks (init, template_redirect, shutdown), collects DB query stats (if SAVEQUERIES), memory usage
- Admin UI Module: settings page + validation + capability checks
- Event API facade functions (imperative helpers)
- Cron / Scheduler: processes retry queue & flushes aged batches
- CLI Commands: leverage WP-CLI if available

Data Flow:
1. Request enters WordPress → Correlation Manager establishes trace context → start timer.
2. Wonolog emits log (Monolog record) → Handler maps & enqueues telemetry item (trace + customDimensions, severity).
3. Performance Collector records metrics (on shutdown) and emits Request + Metric telemetry.
4. Shutdown: buffer flushed (async HTTP). Failures stored for cron retry.

## 5. Key Design Decisions
| Concern | Decision | Rationale |
|---------|----------|-----------|
| Transport | Direct ingestion REST (v2 track) via curl / wp_remote_post | Avoid heavy dependency if official SDK stale; control batching |
| Batching | Size & time thresholds (e.g., 20 items or 5 seconds / shutdown) | Balance latency & overhead |
| Async | Non-blocking HTTP with short timeout; fallback cron | Prevent user-facing latency |
| Sampling | Fixed-rate sampling at handler entry; store sample rate dimension | Reduce cost & noise |
| Correlation | W3C traceparent header; generate if absent; expose helper `aiw_current_trace_id()` | Interoperability |
| Storage | Options API with autoload=no; constants override | Performance & deploy automation |
| Security | Redact configurable keys before send | Compliance |
| Error Handling | Exponential backoff (1m, 5m, 15m, 1h) with max attempts | Prevent hammering |
| Extensibility | Actions/filters: `aiw_before_send_batch`, `aiw_enrich_dimensions` | Plugin ecosystem support |
| Logging Self | Internal diagnostics use existing Wonolog at DEBUG but behind feature flag | Avoid recursion & loops |

## 6. Dependencies
Composer (planned):
- monolog/monolog (already via Wonolog)
- (Optional) psr/log (if not bundled)
- (Optional) guzzlehttp/guzzle (consider using WP HTTP API instead to avoid overhead) — initial plan: NO external HTTP client.
- ramsey/uuid (optional) — may implement lightweight random hex generator instead.

Goal: Minimize third-party libs; rely on core + Wonolog.

## 7. Telemetry Mapping
Monolog → Application Insights Types:
- Log records → Trace telemetry
- Exception stack → Exception telemetry (if `context['exception']` instance of Throwable)
- Metrics API (custom) → Metric telemetry
- Performance (request) → Request telemetry

Level Mapping (Monolog -> AI SeverityLevel):
- DEBUG → Verbose (0)
- INFO → Information (1)
- NOTICE → Information (1)
- WARNING → Warning (2)
- ERROR → Error (3)
- CRITICAL/ALERT/EMERGENCY → Critical (4)

Dimensions (customDimensions) baseline:
- site_url, blog_id (multisite), environment (WP_ENV or constant), php_version, wp_version, plugin_version
- trace_id, span_id, parent_span_id (if applicable)
- user_id (hashed) if logged in & allowed
- request_method, request_uri, status_code
- sampling_rate

## 8. Correlation Strategy
- Accept inbound `traceparent` header (W3C). If present: parse trace-id & parent-id; create new span-id for request.
- If absent: generate 16-byte trace-id (hex) & span-id.
- Expose helper functions and filter `aiw_correlation_headers` for outgoing calls.
- Optionally inject `traceparent` header into `wp_remote_request` via filter if enabled (future iteration – flagged).

## 9. Performance Metrics
Collected at shutdown:
- Total request duration
- Memory peak (bytes)
- DB query count & total time (if SAVEQUERIES)
- Slow hook durations (tracked via `add_action` wrapper list) over threshold (e.g., > 150ms)
- WP Cron task durations when running in cron context

Metrics emission:
- Each metric as separate telemetry item (Metric type)
- Aggregate in batch flush

## 10. Configuration Model

- `connection_string` (string)
- `instrumentation_key` (fallback if connection string absent)
- `min_level` (string; default `warning`)
- `sampling_rate` (float 0..1; default 1)
- `enable_performance` (bool)
- `enable_events_api` (bool)
- `enable_internal_diagnostics` (bool)
- `redact_keys` (array) default `[ 'password', 'pwd', 'pass', 'email', 'user_email' ]`
- `slow_hook_threshold_ms` (int) default 150
- `batch_max_size` (int) default 20
- `batch_flush_interval` (int seconds) default 5
- `retry_schedule` (array) default `[60,300,900,3600]`

Constants override (if defined in wp-config):
- `AIW_CONNECTION_STRING`
- `AIW_INSTRUMENTATION_KEY`
- `AIW_MIN_LEVEL`
- `AIW_SAMPLING_RATE`

## 11. Public API (Initial)
Functions:
```php
event( string $name, array $properties = [], array $measurements = [] );
metric( string $name, float $value, array $properties = [] );
current_trace_id(): ?string;
current_span_id(): ?string;
```
Filters / Actions:
- `aiw_enrich_dimensions` (array $dimensions, array $record)
- `aiw_before_send_batch` (array $payload)
- `aiw_should_sample` (bool $decision, array $record)
- `aiw_redact_keys` (array $keys)

## 12. Error & Retry Handling
- Transport returns HTTP status; non-2xx queued.
- Queue persistence: transient `aiw_retry_queue` containing array of batches with metadata (attempt count, next_attempt_timestamp).
- Cron event `aiw_process_retry_queue` scheduled on activation; processes due batches.
- Max attempts: length of retry schedule; after that, drop & emit internal diagnostic.

## 13. Data Privacy & Security
- Redact configured keys from context/extra arrays.
- Hash user identifiers with SHA-256 + site salt.
- No raw cookies or POST bodies.
- Provide doc section describing what is sent & opt-outs.

## 14. Admin UI
Menu: Tools → Azure Insights (capability `manage_options`)
Sections, use tabs:
- Connection
- Logging
- Performance
- Sampling & Queue
- Diagnostics / Status
Use WordPress Settings API; nonce & capability checks; sanitize callbacks.

Status Indicators:
- Last successful send timestamp
- Items in memory buffer (live display best-effort) — optional
- Retry queue length
- Last error code/message

## 15. Class Layout (Namespace suggestion)
Namespace root: `AzureInsightsWonolog` (PSR-4: `src/`)
```
src/
  Plugin.php (bootstrap)
  Handler/AzureInsightsHandler.php
  Telemetry/TelemetryClient.php
  Telemetry/BatchTransport.php
  Telemetry/Correlation.php
  Telemetry/Redactor.php
  Telemetry/Sampler.php
  Performance/Collector.php
  Admin/SettingsPage.php
  Admin/StatusPanel.php
  Queue/RetryQueue.php
  CLI/Commands.php
  Helpers/functions.php
```

Autoload via Composer PSR-4; fallback simple autoloader if Composer not present.

## 16. Telemetry Payload Format
Each item JSON with required fields (`name`,`time`,`iKey` or part of connection string, `data` envelope). Batch is newline-delimited JSON per ingestion API. Ensure content-type `application/x-json-stream`.

## 17. Sampling Implementation
- Fixed rate: generate random float via `mt_rand()/mt_getrandmax()`; if > rate, skip record (except ERROR+). Add sampling rate dimension.
- Provide filter to override.

## 18. Activation / Deactivation Hooks
- Activation: schedule cron, initialize options if absent.
- Deactivation: clear cron events, flush queue (best effort).
- Uninstall (optional future): remove options unless constant `AIW_PRESERVE_SETTINGS` set.

## 19. Testing Strategy
Unit:
- Handler: level mapping, context → dimensions, exception extraction
- Sampler: probability boundaries
- Correlation: header parsing & generation
- Redactor: key removal / partial redaction
- RetryQueue: schedule progression

Integration (with WordPress test suite):
- Simulate request, emit logs, verify batch assembly (mock HTTP transport)
- Admin settings save & sanitize

Manual / Smoke:
- Use WP-CLI command to emit test event and confirm appearance in Azure (doc instructions).

## 20. Azure Setup (Doc Outline)
Will include: Create Application Insights resource; copy connection string; set WP constants; validate with WP-CLI test command; query in Logs (Kusto) examples (Traces, Requests, CustomEvents, CustomMetrics).

## 21. Risks & Mitigations
| Risk | Mitigation |
|------|------------|
| Network latency delaying shutdown | Async fire-and-forget with low timeout, shrink batch size |
| SDK changes / ingestion API drift | Avoid tight coupling; encapsulate transport | 
| High volume logs cost | Sampling + level threshold | 
| PII leakage | Redaction, documented keys list | 
| Queue growth on persistent failure | Max attempts & size cap, drop with diagnostic notice |

## 22. Phase Plan
Phase 1 (MVP): Handler, correlation, basic config, request telemetry, batching, sampling, docs.
Phase 2: Performance metrics, retry queue, events API, admin status panel.
Phase 3: CLI commands, advanced redaction, slow hook profiler, health metrics.

## 23. Definition of Done (Phase 1)
- Logs from Wonolog appear in Azure (traces & exceptions) with correlation IDs.
- Request telemetry recorded with duration & success status.
- Configurable min level & sampling rate via admin page.
- Basic documentation & Azure setup guide present.
- Unit tests for mapping & correlation pass.

## 24. Open Questions / Future Considerations
- Add front-end JS telemetry injection? (Later)
- Add OpenTelemetry export compatibility? (Investigate) 
- Provide automatic detection of environment (staging vs production)? (Constant or filter) 

---
Prepared: Initial design ready for review before implementation.
