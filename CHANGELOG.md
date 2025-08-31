# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog (https://keepachangelog.com/en/1.1.0/) and this project adheres to Semantic Versioning.

## [Unreleased]
### Added
- Placeholder section for upcoming changes.

## [0.3.0] - 2025-08-30
### Added
- GitHub-based self-update mechanism integrating plugin-update-checker library with release asset ZIP discovery.
- Automated GitHub Actions workflows to build distributable ZIP manually and attach ZIP on tagged releases.
- Updater bootstrap (`GitHubPluginUpdater`) wired into main plugin file.

### Changed
- Composer dependencies updated to include plugin update checker package (vendored under `vendor/yahnis-elsts`).

### Internal
- CI refinements for packaging; ensures consistent artifact naming and pattern matching.

## [0.2.0] - 2025-08-30
### Added
- Network (multisite) settings page with full feature parity to per-site settings (status, connection, behavior, redaction, test telemetry tabs).
- Dedicated "Test Telemetry" tab separated from redaction/diagnostics for clarity.
- External admin stylesheet (`assets/css/aiw-admin.css`) consolidating previously inline styles for status dashboard, navigation, and form cards.
- Initial `USERGUIDE.md` (What / Why / How) comprehensive documentation.
- PHPUnit coverage for `SettingsPage` sanitization, byte formatting, and dashboard summary logic.

### Changed
- Refactored `SettingsPage` to register tab-specific submenu entries and enqueue shared CSS only on plugin admin pages.
- Moved inline CSS from admin rendering methods into external asset to reduce markup size and improve maintainability.

### Fixed
- Eliminated undefined WordPress helper function notices in static analysis by introducing lightweight polyfills in `NetworkSettingsPage` (esc_*/sanitize_* when running outside WP runtime).
- Resolved namespace mixing issues by adopting consistent namespace style for network admin page.

### Security
- Continued masking of encrypted secrets with clearer visual indicator (🔒 Encrypted) in both site and network admin screens.

### Internal
- Improved multisite activation logic in `Plugin` bootstrap: suppress per-site page only when network-activated, otherwise allow site-level configuration.


## [0.1.0] - 2025-08-30
### Added
- Initial import of Azure Insights handler (originally named for Wonolog).
- WordPress `readme.txt` and `.gitignore`.
- Modernized & restructured settings page UI with improved help tabs.
- Comprehensive contextual help system (Overview, Connection & Security, Sampling & Batching, Performance Metrics, Redaction & Privacy, Retry & Async, CLI & Testing, Filters & Extensibility).
- Performance metrics collection (hook durations, DB query stats, cron duration, memory peak, slow query detection).
- Retry queue with exponential backoff and async batch flushing.
- Redaction system with key list & regex pattern support plus diagnostics metadata.
- Telemetry batching, adaptive sampling, exception deduplication, baseline property enrichment.
- CLI/cron integration for async flushing and retry processing.

### Changed
- Raised minimum PHP requirement to 8.2; introduced strict_types across core classes.
- Added typed properties & method signatures (Correlation, Sampler, Redactor, BatchTransport, TelemetryClient, MockTelemetryClient, Secrets, RetryQueue, Collector, Plugin, AzureInsightsHandler).
- Updated documentation (README.md / readme.txt) to reflect new requirements and features.

### Fixed
- Sampling logic respects adaptive window reduction while preserving error-level events.
- Ensured retry queue drains pending batches on activation and schedules immediate processing when needed.

### Security
- Secrets utility supports encryption/decryption (decrypt-on-load); groundwork for encrypt-on-save.

[Unreleased]: https://example.com/compare/v0.1.0...HEAD
[0.1.0]: https://example.com/releases/tag/v0.1.0
