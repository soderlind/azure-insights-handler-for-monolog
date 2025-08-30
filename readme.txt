=== Azure Insights Handler for Wonolog ===
Contributors: persoderlind
Tags: azure, application insights, logging, telemetry, monitoring
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Wonolog (Monolog) with Azure Application Insights providing structured telemetry, sampling, redaction, retry queue, async batching, and an admin status dashboard.

== Description ==
This plugin bridges your WordPress (and Wonolog / Monolog) logging + custom operational metrics to **Azure Application Insights**.

It batches & sends traces, requests, exceptions, events, and custom metrics with optional adaptive sampling, advanced redaction, a resilient retry queue (including transient storage mode), async cron dispatch, correlation headers, and a native WordPress status dashboard.

Key features:
* Trace telemetry for Monolog records (severity mapped)
* Request & exception telemetry with stack + correlation IDs (traceparent)
* Event & metric helper APIs (`aiw_event()`, `aiw_metric()`)
* Adaptive + fixed sampling (override filter `aiw_should_sample`)
* Advanced redaction (key + regex) with diagnostics metadata
* Exponential backoff retry queue (option or transient-based)
* Async dispatch (cron) to reduce request latency
* Batch buffering (size + interval)
* Performance metrics: hook duration, slow db queries, cron duration
* Outbound correlation propagation (`traceparent` header)
* Mock mode (persist locally, no HTTP) + viewer
* WP-CLI: `wp aiw status`, `wp aiw test [--error]`, `wp aiw flush_queue`
* Encrypted storage of secrets (connection string / instrumentation key)

Filters let you alter sampling, redaction, correlation, and mutate batches pre-send.

== Installation ==
1. Upload or clone into `wp-content/plugins/azure-insights-handler-for-wonolog`.
2. Activate the plugin.
3. In Azure create / locate an Application Insights resource and copy its Connection String.
4. Open: Azure Insights (top-level admin menu) → Settings. Paste Connection String (preferred) or Instrumentation Key.
5. (Optional) Define constants in `wp-config.php` to keep secrets out of DB:
```
define( 'AIW_CONNECTION_STRING', 'InstrumentationKey=...;IngestionEndpoint=...' );
```
6. (Optional) Enable Async Dispatch and/or Mock Mode while testing.
7. Use the Test Telemetry button or run `wp aiw test` (CLI) then inspect data in Azure (allow up to ~1 minute).

== Frequently Asked Questions ==

= Do I need the Wonolog plugin? =
Recommended for seamless Monolog integration, but Monolog availability (via another source) is sufficient.

= How do I change the sampling rate dynamically? =
Adjust in settings UI or override per-record with filter `aiw_should_sample`.

= How are secrets stored? =
Connection string / key are encrypted (AES-256-CBC) using site salts before persisting.

= How do I add extra redaction keys or regex patterns? =
Use the settings fields or filters `aiw_redact_keys` and provide comma-separated PCRE patterns for value redaction.

= What is the transient retry storage mode? =
Set `define( 'AIW_RETRY_STORAGE', 'transient' );`. Queue lives in a transient (with shadow option for durability) reducing autoloaded option weight.

= Will failed batches be retried forever? =
No. They follow an exponential backoff schedule (e.g., 1m → 5m → 15m → 60m) with capped attempts; exhausted batches are dropped with a diagnostic trace.

= Can I disable performance metrics? =
Yes—toggle off in the Feature Toggles section of settings.

= CLI commands? =
`wp aiw status`, `wp aiw test --error`, `wp aiw flush_queue`.

== Screenshots ==
1. Status dashboard with runtime metrics.
2. Settings page (connection & sampling configuration).
3. Retry queue viewer.

== Changelog ==
= 0.3.0 =
* Added GitHub updater integration (release asset ZIP detection) using plugin-update-checker.
* Added GitHub Actions workflows for manual build and release asset attachment.
* Composer/vendor additions for updater.

= 0.2.0 =
* Network settings page with full parity (status, connection, behavior, redaction, test telemetry tabs).
* Separated Test Telemetry into its own tab for clarity.
* Externalized admin UI CSS (dashboard, navigation, form cards) into `assets/css/aiw-admin.css`.
* Added comprehensive `USERGUIDE.md` documentation.
* Added PHPUnit tests for settings sanitization and dashboard summary helpers.
* Refactored settings page registration & asset loading; reduced inline CSS.
* Polyfills added for esc_/sanitize_ helpers in network page for static analysis context.
* Improved multisite logic: per-site page suppressed only when network-activated.
* Secret masking indicator clarified (🔒 Encrypted).

= 0.1.0 =
* Initial public release: core telemetry pipeline, sampling, redaction, retry queue (option/transient), async batching, correlation, performance metrics, admin dashboard, CLI commands.

== Upgrade Notice ==
= 0.2.0 =
Introduces network admin parity, dedicated test telemetry tab, external CSS, and new documentation/testing. No breaking schema changes; review new network settings behavior if multisite.

= 0.1.0 =
Initial release.

== License ==
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with WordPress; if not, see https://www.gnu.org/licenses/old-licenses/gpl-2.0.html .
