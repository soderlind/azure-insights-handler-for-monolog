# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog (https://keepachangelog.com/en/1.1.0/) and this project adheres to Semantic Versioning.

## [Unreleased]
### Added
- Placeholder section for upcoming changes.

## [0.1.0] - 2025-08-30
### Added
- Initial import of Azure Insights handler for Wonolog.
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
