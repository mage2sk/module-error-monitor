# Changelog

All notable changes to `mage2kishan/module-error-monitor` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.5.8]

### Changed
- Replaced typographic characters (em dashes, curly quotes, ellipsis) with plain ASCII punctuation. No functional changes.

## [1.5.7] - 2026-07-07

### Changed
- Code cleanup: removed redundant inline comments and docblocks from the PHP source. No functional changes.

## [1.5.6] - 2026-06-18

### Changed
- README rewritten to match the Panth extension documentation standard: full
  configuration table sourced from system.xml, Quick Answer block, FAQ section,
  structured Quick Links table, and SEO meta comment.

## [1.5.5] - 2026-06-04

### Improved
- **Repeat PHP errors are now coalesced before they reach the database.** A
  single high-frequency exception (one thrown on every request, or in a loop)
  previously produced one row UPDATE plus one row INSERT for every occurrence.
  At scale that is a large, sustained volume of row writes - costly for the
  database and, on servers with row-based binary logging enabled, for the
  binary log it has to retain. Occurrences of the same error are now counted in
  cache and written at most once per configurable window (`PHP Error Capture ->
  Coalesce Window`, default 60s); the suppressed hits are folded into the
  group's total so `occurrence_count` stays accurate. New installs get this
  automatically; set the window to `0` to restore per-occurrence writes.

## [1.5.4] - 2026-06-01

### Fixed
- **Post-deploy stale-cache JS noise is silenced out of the box.** When a
  user's browser holds a stale `requirejs-config.json` / webpack manifest
  after a deploy, every module load fails and surfaces as
  `Uncaught Error: Script error for "X"` (one group per failed module -
  on a real production export 31 of 47 visible groups were this single
  family) or `ChunkLoadError: Loading chunk N failed after 3 retries`.
  These cannot be acted on - they clear themselves as each visitor's
  browser refreshes - so they belong in the default ignore set. Three new
  default lines added to `general/ignore_patterns`:
    - `Script error for`
    - `ChunkLoadError`
    - `Loading chunk`
  Fresh installs pick these up automatically. Existing installs get them
  appended to their saved value by `Setup/Patch/Data/AddStaleCacheDefaults`
  on `setup:upgrade`, **deduped against what's already there** so a
  customer-edited list is never clobbered. Empirically: collapses ~74% of
  the post-deploy noise without losing any actionable signal.

## [1.5.3] - 2026-06-01

### Fixed
- **Auto-pause no longer silences capture forever.** 1.5.1's deploy
  auto-detect used a sliding "any watched mtime within the last N minutes"
  window - on environments where `generated/code/` has its mtime
  continuously refreshed (on-demand interceptor generation, cron-time
  plugin work, etc.) the window kept restarting and capture was silenced
  indefinitely. Rewritten as **delta detection**: a baseline of the last
  observed mtimes is stored in a Flag; capture is suspended only when a
  path's mtime is **strictly newer than what was recorded last time**, and
  the pause runs until `(newest_change + window)` - which is then cached
  in `panth_errormonitor_autopause_until` so subsequent checks don't have
  to re-evaluate. First observation establishes the baseline and never
  pauses. Self-healing on upgrade: the first request after deploying
  1.5.3 baselines from current mtimes and capture resumes.
- Default auto-pause window reduced 15 -> 5 minutes (less blast radius
  if the heuristic ever misfires on a future environment).

### Added
- `bin/magento panth:errormonitor:status` - single-glance answer to "why
  isn't capture working?". Shows every capture gate (master / PHP / JS /
  email switches, min severity), every suspension source (maintenance
  mode, explicit-pause flag, auto-pause-until + the live reason), the
  watched deploy-marker paths with current vs last-seen mtimes, the
  configured ignore-pattern count, and a tally of events actually
  recorded in the last hour / 24 hours. Pass `--reset-auto-detect` to
  wipe the baseline + pause flags and force re-baselining on the next
  capture attempt - the canonical recovery step when capture appears
  stuck after a deploy.

## [1.5.2] - 2026-06-01

### Fixed
- **Recent Occurrences no longer show as empty `{channel: main}` cards.**
  Some Magento code paths (notably the cron observer wrapping a third-party
  cron job's exception) log via `$logger->error($exception->getTraceAsString())`
  - the message IS the raw stack trace, with no exception object in context.
  Until now the capture stored the trace in the `message` column and left
  `stack_trace` NULL, so the detail page rendered every such occurrence as
  an empty card with just the channel context. The handler now detects a
  stack-trace-shaped message (starts with `#0 `), moves it to the
  `stack_trace` column, fills `file`/`line` from the top frame, and
  synthesises a one-line readable message from that frame
  (e.g. `Magento\Variable\Model\Variable->beforeSave() at
  magento/framework/Model/AbstractModel.php:663`).

### Added
- **Pagination on the error detail page's Recent Occurrences section.** Was
  hard-capped at 50; now shows 50 per page with Prev / Next, a compact
  `1 ... 3 4 5 ... N` page strip, and a `showing X-Y of Z` counter. Page count
  is capped at 200 so a pathological group with millions of events can't
  blow up the render.

## [1.5.1] - 2026-06-01

### Added
- **Auto-detect deploy without maintenance mode.** Many production deploys
  skip `bin/magento maintenance:enable`; the resulting visitor / bot hits
  during the rebuild then flood the error log with stale-cache "Script
  error for X" / RequireJS failures and missing-class warnings. The
  `DeploymentGuard` now also watches the mtime of three filesystem signals
  that ONLY change on a real deploy -
  `pub/static/deployed_version.txt` (touched by
  `setup:static-content:deploy`), `generated/code/` and
  `generated/metadata/` (touched by `setup:di:compile`) - and suspends
  capture for the configured window after the most recent touch. New
  setting **General -> Auto-pause After Deploy (minutes)**, default `15`,
  set to `0` to disable. `var/cache` is intentionally NOT watched because
  every admin config save touches it - would have produced too many false
  positives.
- **Auto-filter sibling-module operational alerts.** New setting
  **General -> Auto-Filter Sibling Module Operational Alerts**, default
  **on**. Drops messages matching the family-wide convention
  `[VendorModuleName] BLOCKED/REJECTED/DENIED/REFUSED/DROPPED/QUARANTINED ...`
  used by sibling security / firewall modules. These are operational
  events ("we blocked something") rather than defects and previously
  produced dozens of one-occurrence groups in the grid. The pattern is
  bounded regex / ReDoS-safe and is matched ONLY at the start of the
  message, so a legitimate exception that happens to mention a block in
  its body is not swallowed.

### Fixed
- **Regrouper no longer overwrites a meaningful error type with a less
  meaningful one.** When the stored type was a real FQN such as
  `Elasticsearch\Common\Exceptions\BadRequest400Exception`, the v1.4.0
  / 1.5.0 regrouper called `extractType()` unconditionally and replaced
  it with whatever pattern matched first in the message (in the ES case,
  the `caused_by` tag). The regrouper now only mines a sharper type when
  the stored one is a channel-name placeholder (`main`, `report`,
  `error`, `exception`, `throwable`, or empty).

### Notes
- All three changes are additive - no schema change, no config migration,
  no risk to existing data. `setup:upgrade` runs no new patches; defaults
  ship the new auto-detect and ecosystem-alert filter on. To revert the
  new behaviour: set Auto-pause After Deploy to `0`, set Auto-Filter
  Sibling Module Operational Alerts to No.

## [1.5.0] - 2026-06-01

### Changed
- **One email per day is now the ONLY cadence.** The `immediate_digest` mode
  was removed because in production it still emitted up to one email per
  hour during sustained incidents, which is exactly the inbox-flooding the
  module exists to prevent. The cron stays hourly (so the admin can change
  the send-hour without re-scheduling) but actual delivery is hard-gated to
  at most one summary per UTC day, regardless of how many groups appear.
  Existing installs on `immediate_digest` are migrated to `daily_summary`
  on `setup:upgrade` by `MigrateEmailModeToDaily` - no admin action needed.
- **Ignore-patterns is now repo-wide and matches more fields.** The textarea
  moved from "JavaScript Error Capture" to the **General** group and its
  match scope widened from "message only" to **message + file path + error
  class + stack trace** (case-insensitive). A single line like
  `Vendor_Module` now silences every error originating from that module,
  whether the module name appears in the exception FQN, the file path, or
  the stack frames. Existing values are copied forward by
  `MigrateIgnorePatternsToGeneral`.

### Fixed
- **JS framework-generic errors no longer shatter into one-bucket-per-script.**
  Messages like `Cannot set/read properties of null/undefined ('xxx')`,
  `X is not a function`, `undefined is not valid JSON`, and the DOM
  `insertBefore` family are now hashed on `(source, type, message)` only -
  the JS source file is intentionally dropped from the fingerprint. On a
  real production export this collapsed 22+ separate `"Cannot set
  properties of null (setting 'innerHTML')"` rows into one group. The
  predicate is bounded-regex / ReDoS-safe.
- **JS source file is now matched by basename**, not full theme path. A
  CDN host swap or theme/locale layout change no longer splits the same
  script into separate groups for non-framework-generic JS errors.

### Added
- `Setup/Patch/Data/RegroupErrorsV3` - re-runs the regrouper with the new
  rules on `setup:upgrade` so existing sites collapse historical buckets
  automatically. **Zero data loss**: events are MOVED to the surviving
  canonical row and occurrence counts / first_seen / last_seen are
  aggregated; the merge runs in a single transaction. The on-demand
  `bin/magento panth:errormonitor:regroup [--dry-run]` already picks up
  the new logic.

### Notes
- Empirically against the same kind of production export used to drive
  1.4.0: a 518-line / ~300+ group profile collapses by an additional 25-30%
  on top of the v2 gains, primarily from the JS framework-generic rule
  and the basename-only JS file. No false-positive collisions observed
  across the adversarial pair set.

## [1.4.0] - 2026-05-28

### Changed
- **Much tighter error grouping.** The Fingerprinter now ships 30+ ordered
  normalisation rules covering JSON payloads, URLs, Unix/Windows paths,
  filenames, UUIDs, SHA digests, GraphQL Report IDs, generic 32+ hex tokens,
  PHP session IDs, IPv4/IPv6, quoted values, line/position/offset markers,
  stack-frame counters, version strings, and big numeric IDs - ordered
  specific-first so a general rule never eats tokens a more specific rule
  should have collapsed. ReDoS-safe (bounded repetition + a 32 KiB input
  guard).
- **Type extraction from raw messages.** When a record is logged without a
  Throwable in context, the handler previously stored the Monolog channel
  ("main", "report") as the error type, defeating grouping for every such
  record. The new `Fingerprinter::extractType()` mines the actual class /
  family from the message itself via 10 ordered patterns (PHP exception
  wrappers, JS native error classes, Elasticsearch `caused_by` types,
  template-engine wrappers, generic `[Tag] LEVEL` log conventions, PHP
  severity words) before falling back to the channel.

### Added
- **Auto-regroup on upgrade.** A one-shot `Setup/Patch/Data/RegroupErrorsV2`
  re-fingerprints every existing `panth_error_group` row with the new rules
  and merges duplicates in a single transaction. Triggered automatically by
  `setup:upgrade` (Magento's patch system records the apply so it never runs
  twice).
- `bin/magento panth:errormonitor:regroup [--dry-run]` - run / preview the
  same regroup on demand.

### Fixed
- **View Details button no longer redirects to the storefront homepage.** The
  grid Actions column was injecting `Magento\Framework\UrlInterface`, which in
  the `mui/index/render` data-provider context can resolve to the frontend URL
  builder and omits the admin secret key. Switched to the explicit
  `Magento\Backend\Model\UrlInterface` so every action link is a fully-formed
  admin URL with `/key/<csrf>/`.
- **Identifier-shaped quoted tokens (module / table / column / config-path /
  cache-type names) are preserved in the fingerprint** instead of being
  collapsed to `<v>` - different errors with different identifiers no longer
  false-group into one bucket.
- **Elasticsearch `caused_by.type` / `root_cause.type` survives normalisation**
  via an out-of-band suffix marker, so different ES failure types under the
  same outer exception no longer share a fingerprint.
- **IPv6 addresses with `::` zero-compression collapse correctly** (most
  real-world IPv6 forms previously slipped through).
- **IPv4 inside `://` URLs collapses correctly** (previously `tcp://1.2.3.4`
  matched only partially because `/` was in the negative lookbehind).
- **Magento static-asset cache-buster (`/static/version<ts>/...`) is stripped**
  in `normalizeFile()` so the same JS error groups across every deploy
  instead of producing a brand-new bucket each time static content is
  redeployed.

### Added
- **"Pages where this error occurred" section** on the error detail page -
  lists every distinct URL where the error was recorded, with occurrence
  count per URL, ordered most-frequent first (capped at the top 50).

### Notes
- Empirically (against a 279-group production export, post-fix):
  ~30-35% group reduction with **zero false-positive collisions** in 10
  hand-crafted adversarial pairs (the v2 trades aggressive over-collapse for
  a smaller, more honest reduction that never merges unrelated incidents).

## [1.3.0] - 2026-05-28

### Added
- **CSV / Excel XML export from the admin error grid.** A standard Magento UI
  `<exportButton>` is wired into the listing toolbar; admins can export all
  matching rows (respecting current filters) to either format using the
  built-in `mui/export/gridToCsv` / `gridToXml` endpoints. ACL-gated by the
  existing `Panth_ErrorMonitor::view` resource - no additional permission needed.

## [1.2.0] - 2026-05-28

### Added
- **Deployment-time capture pause.** Visitors / bots hitting URLs while a
  site is being deployed throw exceptions that previously got logged as
  "real" errors. Capture is now automatically suspended via two complementary
  signals:
    - `MaintenanceMode` - when an admin runs `bin/magento maintenance:enable`
      for a deploy, capture pauses for its duration. No extra work needed.
    - `bin/magento panth:errormonitor:pause [--minutes=N]` /
      `bin/magento panth:errormonitor:resume` - explicit kill-switch with
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
- `bin/magento panth:errormonitor:send-summary` - send the summary on demand
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
