# Changelog

All notable changes to `mage2kishan/module-error-monitor` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.3.0] - 2026-05-28

### Added
- **CSV / Excel XML export from the admin error grid.** A standard Magento UI
  `<exportButton>` is wired into the listing toolbar; admins can export all
  matching rows (respecting current filters) to either format using the
  built-in `mui/export/gridToCsv` / `gridToXml` endpoints. ACL-gated by the
  existing `Panth_ErrorMonitor::view` resource — no additional permission needed.

## [1.2.0] - 2026-05-28

### Added
- **Deployment-time capture pause.** Visitors / bots hitting URLs while a
  site is being deployed throw exceptions that previously got logged as
  "real" errors. Capture is now automatically suspended via two complementary
  signals:
    - `MaintenanceMode` — when an admin runs `bin/magento maintenance:enable`
      for a deploy, capture pauses for its duration. No extra work needed.
    - `bin/magento panth:errormonitor:pause [--minutes=N]` /
      `bin/magento panth:errormonitor:resume` — explicit kill-switch with
      auto-expiry, for deploys that don't use maintenance mode.
  Implemented in `Service\DeploymentGuard`; consulted by both the PHP log
  handler and the JS beacon endpoint.

## [1.1.0] - 2026-05-28

### Fixed
- **Email HTML now renders correctly.** Error cards were passed through
  `{{var}}` and double-escaped by the email filter, showing as literal markup
  in the inbox. They are now rendered by a `{{block}}` directive
  (Block\Email\Summary + view/frontend/templates/email/summary.phtml) as
  trusted HTML, with every dynamic field still escaped.

### Changed
- **Default email cadence is now a once-per-day summary** instead of frequent
  digests. A single email is sent after a configurable send-hour (default 23:00
  UTC), covering the error groups seen in the last 24 hours, guarded by a
  per-day flag so it can never send twice in one day. "Immediate digest" remains
  available as a mode. Dispatch cron now runs hourly (gated), not every 15 min.
- Added **Daily Summary Hour** config field; relabelled the per-email cap.

### Added
- `bin/magento panth:errormonitor:send-summary` — send the summary on demand
  (bypasses the daily gate) to verify email configuration.
- Unit tests for Fingerprinter, IpAnonymizer, and Severity.

### Quality
- Passes `phpcs --standard=Magento2` with zero errors. Strict constructor DI
  throughout (no ObjectManager).

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
