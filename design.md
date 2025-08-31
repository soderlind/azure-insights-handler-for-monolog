# Azure Insights Handler for Monolog — Design Document

## 1. Goal
Provide a robust, configurable WordPress plugin that augments Monolog logging by forwarding enriched log, trace, and performance telemetry to Azure Application Insights, enabling observability (logs, traces, metrics, correlation) without materially impacting page performance.

## 2. Scope Summary
In-scope:
- Monolog handler that ships records to Azure Application Insights
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
- Service Provider (bootstrap) hooking into WordPress & Monolog (via direct handler registration or legacy Wonolog triggers when present)
- Monolog Handler: `AzureInsightsHandler` (extends `Monolog\Handler\AbstractProcessingHandler`)
- Telemetry Client Abstraction: wraps Application Insights ingestion (Connection String or Instrumentation Key)
- Queue / Buffer: in-memory + transient/option based fallback for retry
- Correlation Manager: manages trace/span IDs, reads/writes headers
- Performance Collector: times selected WP hooks (init, template_redirect, shutdown), collects DB query stats (if SAVEQUERIES), memory usage
- Admin UI Module: settings page + validation + capability checks
Provide a robust, configurable WordPress plugin that forwards enriched Monolog log, trace, and performance telemetry to Azure Application Insights, enabling observability (logs, traces, metrics, correlation) without materially impacting page performance.
- Cron / Scheduler: processes retry queue & flushes aged batches
- CLI Commands: leverage WP-CLI if available

- Monolog handler that ships records to Azure Application Insights
1. Request enters WordPress → Correlation Manager establishes trace context → start timer.
2. Monolog emits log (record) → Handler maps & enqueues telemetry item (trace + customDimensions, severity).
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
| Logging Self | Internal diagnostics use existing Monolog at DEBUG but behind feature flag | Avoid recursion & loops |

## 6. Dependencies
Composer (planned):
- monolog/monolog
- (Optional) psr/log (if not bundled)
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

- Optionally inject `traceparent` header into `wp_remote_request` via filter if enabled (future iteration – flagged).

## 9. Performance Metrics
Collected at shutdown:
- Total request duration

Metrics emission:
- `instrumentation_key` (fallback if connection string absent)
- `min_level` (string; default `warning`)
- `slow_hook_threshold_ms` (int) default 150
- `batch_max_size` (int) default 20
- `AIW_CONNECTION_STRING`
- `AIW_INSTRUMENTATION_KEY`
- `AIW_MIN_LEVEL`
event( string $name, array $properties = [], array $measurements = [] );
metric( string $name, float $value, array $properties = [] );
```
Filters / Actions:
- `aiw_enrich_dimensions` (array $dimensions, array $record)
- Hash user identifiers with SHA-256 + site salt.
- No raw cookies or POST bodies.
Sections, use tabs:
- Connection
- Logging
- Performance
- Sampling & Queue
- Diagnostics / Status
Use WordPress Settings API; nonce & capability checks; sanitize callbacks.

Status Indicators:
- Last successful send timestamp
## 15. Class Layout (Namespace suggestion)
Namespace root (final): `AzureInsightsMonolog` (PSR-4: `src/`)
  Telemetry/TelemetryClient.php
  Telemetry/BatchTransport.php
  Admin/SettingsPage.php
  Admin/StatusPanel.php
  Queue/RetryQueue.php
  CLI/Commands.php
## 16. Telemetry Payload Format
Each item JSON with required fields (`name`,`time`,`iKey` or part of connection string, `data` envelope). Batch is newline-delimited JSON per ingestion API. Ensure content-type `application/x-json-stream`.

## 18. Activation / Deactivation Hooks
- Activation: schedule cron, initialize options if absent.
Namespace root: `AzureInsightsMonolog` (PSR-4: `src/`)
- Uninstall (optional future): remove options unless constant `AIW_PRESERVE_SETTINGS` set.

- Redactor: key removal / partial redaction

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
 Logs from Monolog appear in Azure (traces & exceptions) with correlation IDs.

## 24. Open Questions / Future Considerations
- Add front-end JS telemetry injection? (Later)
- Add OpenTelemetry export compatibility? (Investigate) 
- Provide automatic detection of environment (staging vs production)? (Constant or filter) 

---
Prepared: Initial design ready for review before implementation.
