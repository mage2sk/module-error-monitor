# Changelog

All notable changes to `mage2kishan/module-error-monitor` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-05-27

### Fixed
- **Monolog 2 / Magento 2.4.6 compatibility.** The PHP log handler declared
  `write(LogRecord $record)` (Monolog 3 only), causing a fatal
  "Declaration ... must be compatible with AbstractProcessingHandler::write(array $record)"
  on Magento 2.4.6, which ships Monolog 2. The handler now accepts an untyped
  record and normalises Monolog 2 arrays and Monolog 3 LogRecord objects at
  runtime, and no longer references the Monolog 3-only `Monolog\Level` enum.
- Alert email now renders in the frontend area so the shared
  `design/email` header/footer templates resolve (previously failed with
  "Template file 'header.html' is not found").

## [1.0.0] - 2026-05-25

### Added
- Initial release.
- PHP exception capture via a Monolog DB handler on the system logger
  (severity-thresholded, re-entrancy-guarded, never throws).
- Storefront JavaScript error capture via a CSP-safe, sampled collector and a
  hardened same-origin, rate-limited beacon endpoint.
- Smart grouping: stable fingerprints with variable-token normalisation;
  occurrence counting; automatic re-open of resolved errors on recurrence.
- Throttled email alerts (digest or individual) with per-group daily dedupe,
  severity threshold, and a per-run cap. Sent by cron only.
- Admin error grid with source/severity/status filters and Resolve / Ignore /
  Delete single + mass actions; detail view with stack traces and context.
- Privacy controls: optional IP storage and IP anonymisation.
- Retention cron + `panth:errormonitor:cleanup` console command.
- Standalone install/heartbeat reporter with Panth_Core fallback.
