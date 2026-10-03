# Magento 2 Error Monitor

Panth Error Monitor records PHP exceptions and storefront JavaScript errors into two database tables inside Magento. A Monolog handler attached to the system logger captures server-side errors, and a small deferred script posts browser errors to a storefront endpoint. Every error is reduced to a fingerprint; repeats of the same error increment a counter on one group row instead of adding new rows, and individual occurrences are kept as separate event rows for a limited time.

Administrators review the groups in an admin grid with Resolve, Ignore and Delete actions, open a detail page that lists the pages and stack traces behind a group, and can receive one summary email per day listing the new groups of the last 24 hours. The module is aimed at store owners and developers who want to see what a store is throwing without reading log files, while keeping the amount of stored data and mail bounded even when one error fires thousands of times.

Product page: [kishansavaliya.com/magento-2-error-monitor.html](https://kishansavaliya.com/magento-2-error-monitor.html)

## Features

- Captures PHP log records at or above a configurable severity through a Monolog handler registered on `Magento\Framework\Logger\Monolog`; exceptions attached to the log record supply class, file, line and stack trace.
- Captures uncaught JavaScript errors and unhandled promise rejections on the storefront with a deferred, non-inline collector script that works under a strict Content-Security-Policy.
- Groups errors by a SHA-256 fingerprint of source, error type, normalised message and normalised file; numbers, IDs, URLs, paths, hashes, IP addresses and quoted values are replaced by placeholders before hashing so near-identical messages fall into one group.
- Coalesces high-frequency repeats: within a configurable window only one database write is made per group, and the suppressed occurrences are added to the counter on the next write.
- Drops errors that match an admin-defined list of substrings, and optionally drops the `[PanthModule] BLOCKED ...` style operational alerts written by sibling Panth modules.
- Suspends capture while Magento maintenance mode is on, during an explicit pause set from the command line, and for a configurable window after a deploy is detected from file modification times.
- Admin grid of error groups with filters, column controls, export, per-row View / Resolve / Ignore / Delete links and mass Resolve / Ignore / Delete actions; a detail page with the most frequent URLs and paginated occurrences including stack traces.
- Keyword search on the error log grid (message, file, error type, source).
- One summary email per UTC day, sent by cron after a configurable hour, limited to a configurable number of groups and to groups at or above a configurable severity.
- Per-IP and global rate limiting, same-origin check and a body-size cap on the JavaScript collection endpoint.
- Optional storage of the client IP, with optional anonymisation of the last IPv4 octet or the last 80 bits of an IPv6 address.
- Daily cleanup cron that deletes old occurrence rows and old resolved groups; six `bin/magento` commands for cleanup, sending the summary, pausing, resuming, regrouping and status.
- Errors are stored only in the Magento database; the module has no integration with third-party error-tracking services.

## Compatibility

| Platform | Versions |
|---|---|
| Magento Open Source | 2.4.4 to 2.4.8 |
| Adobe Commerce | 2.4.4 to 2.4.8 |
| PHP | 8.1, 8.2, 8.3, 8.4 |

Composer constraints from `composer.json`: `magento/framework ^103.0`, `magento/module-backend ^102.0`, `magento/module-config ^101.2`, `magento/module-store ^101.1`, `magento/module-cron ^100.4`, `magento/module-email ^101.1`, `magento/module-ui ^101.2`; PHP `~8.1.0||~8.2.0||~8.3.0||~8.4.0`.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4 to 2.4.8.
- PHP 8.1, 8.2, 8.3 or 8.4.
- `mage2kishan/module-core` (module `Panth_Core`), required with no version constraint. It provides the `Panth\Core\Helper\AbstractConfig` base class used by the configuration helper and the parent admin menu item `Panth_Core::panth_extensions`.
- A working Magento cron for the summary email and the cleanup job.
- A working mail transport if the summary email is enabled.

`composer.json` declares no suggested packages.

## Installation

```bash
composer require mage2kishan/module-error-monitor
bin/magento module:enable Panth_Core Panth_ErrorMonitor
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

- `setup:di:compile` is only needed when Magento runs in production mode.
- `setup:static-content:deploy -f` is needed because the module ships the storefront collector at `view/frontend/web/js/error-monitor.js`.
- `setup:upgrade` creates the two tables and runs the data patches listed under Developer Notes.

Check that the module is enabled:

```bash
bin/magento module:status Panth_ErrorMonitor
```

## Configuration

Admin path: Stores > Configuration > Panth Infotech > Error Monitor (the section `panth_errormonitor` is placed in the `panth` tab, labelled "Panth Infotech", defined in `etc/adminhtml/system.xml`). The section requires the ACL resource `Panth_ErrorMonitor::system_config`. All fields are available at default, website and store view scope except "Auto-pause After Deploy (minutes)", which is default scope only.

The admin menu adds "Error Monitor" under the `Panth_Core::panth_extensions` menu item, with two children: "Error Log" (opens the grid, requires `Panth_ErrorMonitor::view`) and "Configuration" (opens this section, requires `Panth_ErrorMonitor::system_config`).

Default behaviour after installation: PHP and JavaScript capture are on, no email is sent until "Send Email Alerts" is enabled and at least one valid recipient is entered, client IPs are stored anonymised, occurrences are kept for 30 days and resolved groups for 90 days.

### General

| Setting | Default | What it does |
|---|---|---|
| Enable Error Monitor | Yes | Master switch. When off, nothing is captured and no email is sent. |
| Auto-pause After Deploy (minutes) | 5 | Capture is suspended for this many minutes after a strictly newer modification time is seen on `pub/static/deployed_version.txt`, `generated/code` or `generated/metadata`. Accepts 0 to 120; the code caps the value at 120. 0 disables auto-pause. |
| Auto-Filter Sibling Module Operational Alerts | Yes | Drops log messages starting with `[Panth<Name>]` followed by `BLOCKED`, `REJECTED`, `DENIED`, `REFUSED`, `DROPPED` or `QUARANTINED`. |
| Ignore Errors Matching | Seven lines: `Script error.`, `Script error for`, `ChunkLoadError`, `Loading chunk`, `ResizeObserver loop limit exceeded`, `ResizeObserver loop completed with undelivered notifications.`, `Non-Error promise rejection captured` | One substring per line, matched case-insensitively against the message, file path, error type and stack trace. Matching errors are dropped before they are stored. If this field is empty, the legacy path `panth_errormonitor/js_capture/ignore_patterns` is read instead. |

### PHP Error Capture

| Setting | Default | What it does |
|---|---|---|
| Capture PHP Exceptions | Yes | Enables the Monolog handler that stores log records written to the Magento system log. |
| Minimum Severity to Capture | Error | Records below this level are not stored. Options: Notice, Warning, Error, Critical, Alert, Emergency. Shown only when capture is enabled. |
| Coalesce Window (seconds) | 60 | Within this window only one database write is made per error group; further occurrences are counted in cache and folded into the total on the next write. 0 writes every occurrence. Shown only when capture is enabled. |

### JavaScript Error Capture (Storefront)

| Setting | Default | What it does |
|---|---|---|
| Capture Storefront JS Errors | Yes | Adds the collector script to every storefront page and enables the collection endpoint. |
| Sample Rate (%) | 100 | Percentage of page loads that report errors at all; the decision is made once per page load in the browser. Shown only when capture is enabled. |
| Max Reports per IP per Minute | 30 | Server-side limit per client IP and minute. A global limit of 50 times this value per minute applies to all IPs together. Requests beyond either limit are dropped silently. Shown only when capture is enabled. |
| Max Report Body Size (KB) | 16 | Request bodies larger than this are rejected before parsing. Shown only when capture is enabled. |

### Email Alerts

| Setting | Default | What it does |
|---|---|---|
| Send Email Alerts | No | Enables the daily summary email. Mail is only ever sent by cron or by the `send-summary` command, never during the request that recorded the error. |
| Daily Summary Hour (0-23, UTC) | 23 | The summary is sent by the first hourly cron run at or after this UTC hour, once per UTC day. Shown only when alerts are enabled. |
| Recipient Email(s) | (empty) | Comma- or newline-separated addresses; entries that fail email validation are skipped. Required when alerts are enabled. Shown only when alerts are enabled. |
| Email Sender Identity | General Contact | Magento email identity used as sender (`general`). Shown only when alerts are enabled. |
| Minimum Severity to Email | Error | Groups below this level are stored but left out of the email. Shown only when alerts are enabled. |
| Max Error Groups per Email | 50 | Maximum number of groups listed in one summary. Shown only when alerts are enabled. |

The config path `panth_errormonitor/email/mode` exists with the default `daily_summary`; it has no admin field, and the only mode implemented is the daily summary.

### Privacy

| Setting | Default | What it does |
|---|---|---|
| Store Client IP | Yes | When off, no IP address is written to event rows. |
| Anonymise IP | Yes | Replaces the last IPv4 octet with 0, or zeroes the last 80 bits of an IPv6 address, before storing. Shown only when "Store Client IP" is Yes. |

Stored URLs and referers are stripped of their `#fragment`, and the value of any query parameter whose name contains `token`, `pass`, `pwd`, `secret`, `key`, `auth`, `session`, `sid`, `sig`, `hash`, `code`, `nonce`, `otp`, `email`, `mail`, `phone` or `telephone` is replaced with `redacted`. This applies to PHP and JavaScript events.

### Data Retention

| Setting | Default | What it does |
|---|---|---|
| Keep Individual Events (days) | 30 | Event rows older than this are deleted by the daily cleanup cron. Group rows and their counters are kept. |
| Keep Resolved Groups (days) | 90 | Groups with status Resolved whose last occurrence is older than this are deleted, together with their remaining events. |
| Keep Unresolved Groups (days) | 0 | Groups with status New whose last occurrence is older than this are deleted, together with their remaining events. Ignored groups are never removed by this setting. 0 keeps New groups until an administrator resolves or deletes them. |

Config paths: `panth_errormonitor/general/enabled`, `panth_errormonitor/general/auto_pause_window_minutes`, `panth_errormonitor/general/filter_ecosystem_alerts`, `panth_errormonitor/general/ignore_patterns`, `panth_errormonitor/php_capture/enabled`, `panth_errormonitor/php_capture/min_severity`, `panth_errormonitor/php_capture/throttle_window_seconds`, `panth_errormonitor/js_capture/enabled`, `panth_errormonitor/js_capture/sample_rate`, `panth_errormonitor/js_capture/rate_limit_per_minute`, `panth_errormonitor/js_capture/max_body_kb`, `panth_errormonitor/email/enabled`, `panth_errormonitor/email/mode`, `panth_errormonitor/email/send_hour`, `panth_errormonitor/email/recipients`, `panth_errormonitor/email/sender`, `panth_errormonitor/email/min_severity`, `panth_errormonitor/email/max_per_run`, `panth_errormonitor/privacy/store_ip`, `panth_errormonitor/privacy/anonymize_ip`, `panth_errormonitor/retention/event_days`, `panth_errormonitor/retention/resolved_group_days`, `panth_errormonitor/retention/unresolved_group_days`.

## Usage

### How PHP errors are captured

`Panth\ErrorMonitor\Logger\DbHandler` is added to the handler list of `Magento\Framework\Logger\Monolog` in `etc/di.xml` at Monolog level NOTICE, so it sees every record the system logger receives at notice level or above, including uncaught exceptions. For each record it checks, in this order: the master switch and "Capture PHP Exceptions", whether capture is suspended (see below), whether the message is empty, whether the record's level is at or above "Minimum Severity to Capture", and whether the message contains the module's own marker `[PanthErrorMonitor]` (its own log lines are never recorded).

The same `handlers` argument also lists Magento's own handlers `system` (`Magento\Framework\Logger\Handler\System`, var/log/system.log and, for records with an exception, var/log/exception.log), `debug` (`Magento\Framework\Logger\Handler\Debug`, var/log/debug.log when debug logging is enabled) and `syslog` (`Magento\Framework\Logger\Handler\Syslog`, only when syslog logging is enabled). Magento declares these in app/etc/di.xml, and a module di.xml that sets the `handlers` argument of this type replaces that list instead of merging with it, so they have to be repeated for file logging to keep working. Items added by other modules in their own di.xml are still merged by name.

If the record carries a `Throwable` in its context, the exception class, message, file, line and trace are used. Argument values are removed from every stored trace frame (`#0 file(line): Class->method()`), whatever the PHP setting `zend.exception_ignore_args` is, so passwords, tokens or customer data passed to a function never reach the database or the summary email. Traces stored before version 1.6.0 keep their arguments until they are pruned by the event retention. Otherwise the handler splits a `Stack trace:` section out of the message, or, for messages that are only a trace starting with `#0`, builds a short message from the first frame. The error type is mined from the message with a set of patterns (exception class names, JS error names, PHP `Warning` / `Notice` / `Deprecated Functionality` prefixes, Elasticsearch `caused_by` / `root_cause` types, invalid template file module names); if none matches, the Monolog channel name is used unless it is a generic one (`main`, `report`, `error`, `exception`), in which case the type is `error`. The request URI, referer, user agent, HTTP method and (subject to the Privacy settings) the client IP from `$_SERVER` are stored on the event row. PHP events are stored with store ID 0.

### How JavaScript errors are captured

`view/frontend/layout/default.xml` adds `Panth\ErrorMonitor\Block\Js\Beacon` to the `before.body.end` container on every storefront page. When "Capture Storefront JS Errors" is on for the current store view, the template writes a JSON configuration block (`<script type="application/json" id="panth-em-config">`) and loads `Panth_ErrorMonitor::js/error-monitor.js` with `defer`. No inline JavaScript is emitted.

The collector decides once per page load whether to report at all (Sample Rate), then listens for `window` `error` events (resource-load errors without a message are ignored) and `unhandledrejection` events. It sends at most 10 reports per page load, skips a report whose name, message, source and line were already sent on that page, caps each field, and posts the JSON body with `navigator.sendBeacon`, falling back to `fetch` with `keepalive` and no credentials.

The endpoint is `POST /panth_errormonitor/js/collect` (`Panth\ErrorMonitor\Controller\Js\Collect`). It always answers `204 No Content` with `Cache-Control: no-store`. A report is stored only if, in this order: JS capture is enabled for the store view, capture is not suspended, the `Origin` (or, failing that, `Referer`) host matches the host of a configured store base URL or secure link URL, the body is non-empty and within "Max Report Body Size (KB)", the per-IP and global rate limits allow it (the client IP comes from Magento's `RemoteAddress`, i.e. `REMOTE_ADDR` or the proxy headers configured for Magento, not from client-supplied `X-Forwarded-For` or `Client-IP` headers), and the body is a JSON object with a non-empty `message`. Values are capped (message 2000, name 191, source 1024, stack 8000 characters). JavaScript errors are always stored with severity `error`, source `js`, the visitor's store view ID, and a context of `colno`, `kind` (`error` or `unhandledrejection`) and `origin: storefront-js`. Form-key CSRF validation is bypassed for this endpoint because the beacon cannot send one.

### Grouping rules

`Panth\ErrorMonitor\Service\ErrorRecorder::record()` first drops the payload when the message is empty, when the sibling-module alert filter matches, or when any "Ignore Errors Matching" substring is found in the message, file, type or stack trace. It then asks `Panth\ErrorMonitor\Service\Fingerprinter` for a SHA-256 hash of:

- the source (`php` or `js`);
- the error type in lower case (if the stored type is generic, a type mined from the message is used instead);
- the normalised message: lower-cased, cut before `Stack trace:`, with JSON blobs, URLs, filesystem paths, file names, UUIDs, hex hashes, session IDs, IPv4/IPv6 addresses, line / position / row numbers, trace frame numbers, parameter numbers, version numbers and other numbers replaced by placeholders, quoted values collapsed unless they look like identifiers, whitespace collapsed, and a `caused_by=` / `root_cause=` suffix appended for Elasticsearch-style errors; truncated to 500 characters;
- the normalised file: query string, scheme and host, the `pub/static/<version>/<area>/<vendor>/<theme>/<locale>/` prefix and a trailing `:line:column` are removed. For JavaScript errors only the file's base name is used, and the file is ignored entirely for generic browser messages such as `cannot read properties of undefined` or `x is not a function`.

The group row is inserted or updated in one `INSERT ... ON DUPLICATE KEY UPDATE` statement on the unique `fingerprint` column: the occurrence counter is increased, `last_seen_at`, severity, message and store ID are refreshed, and a group in status Resolved is set back to New. A group in status Ignored keeps counting but stays Ignored. An event row is then inserted for the occurrence.

Coalescing (`Panth\ErrorMonitor\Service\CaptureThrottle`) uses the Magento cache: within one "Coalesce Window (seconds)" bucket only the first occurrence of a fingerprint is written; later ones increment a pending counter (kept for at least 15 minutes) that is added to the group counter on the next write. With a window of 0 every occurrence is written.

### Capture suspension

`Panth\ErrorMonitor\Service\DeploymentGuard` suspends both PHP and JavaScript capture when any of these is true:

- Magento maintenance mode is on;
- an explicit pause set with `bin/magento panth:errormonitor:pause` has not expired (flag `panth_errormonitor_capture_paused_until`);
- a deploy was detected: the modification times of `pub/static/deployed_version.txt`, `generated/code` and `generated/metadata` are compared with the last seen values stored in the flag `panth_errormonitor_deploy_mtimes`. A strictly newer time starts a pause of "Auto-pause After Deploy (minutes)" from that time (flag `panth_errormonitor_autopause_until`). The first observation only records a baseline.

`bin/magento panth:errormonitor:status` prints every gate, the watched paths with their current and last-seen times, and the number of events recorded in the last hour and 24 hours.

### Admin grid and detail page

The "Error Log" page (`panth_errormonitor/error/index`, ACL `Panth_ErrorMonitor::view`) is the UI listing `panth_errormonitor_group_listing` over `panth_error_group`. Columns: ID, Source (PHP / JavaScript), Severity, Type, Message, Occurrences, First Seen, Last Seen, Status (New / Resolved / Ignored) and an Actions column with View, Resolve, Ignore and Delete (Delete asks for confirmation). Message is shown on up to three lines and Type on up to two (full text in the tooltip and on the view page); First Seen is hidden by default and can be enabled under Columns. The toolbar offers filters, bookmarks, column controls, export and paging. In the CSV export of this grid, text cells that start with `=`, `+`, `-`, `@`, a tab or a carriage return are prefixed with a single quote so spreadsheet programs do not run them as formulas (messages from the storefront endpoint are visitor-controlled). The Excel XML export and other grids are not changed. Mass actions Resolve, Ignore and Delete act on the selected groups; the status and delete actions require `Panth_ErrorMonitor::manage` and accept only POST requests with a valid form key.

The detail page (`panth_errormonitor/error/view/group_id/<id>`) shows status, source, severity, type, message, file and line, occurrence count and first / last seen (UTC), with "Mark Resolved" and "Ignore" buttons that submit a POST form. Below it lists up to 50 distinct URLs where the error occurred, most frequent first, and the recent occurrences 50 per page (up to 200 pages) with timestamp, HTTP method, IP, URL, user agent, stack trace and the stored context as JSON.

Deleting a group deletes its event rows through the foreign key (`ON DELETE CASCADE`).

### Email notifications

The cron job `panth_errormonitor_dispatch_notifications` runs at minute 5 of every hour (`5 * * * *`). It returns immediately unless the master switch and "Send Email Alerts" are on and at least one valid recipient is configured. It then applies these gates: the current UTC hour must be at or after "Daily Summary Hour"; and no flag `panth_errormonitor_summary_<YYYY-MM-DD>` may exist for the current UTC date. It selects groups with status New, severity at or above "Minimum Severity to Email" and `last_seen_at` within the last 24 hours, ordered by severity and occurrence count descending, limited to "Max Error Groups per Email". If no group qualifies, nothing is sent and no flag is set, so later runs on the same day check again. Otherwise one email is sent through the template "Panth Error Monitor: Error Alert / Digest" (`panth_errormonitor_alert_template`, rendered in the frontend area under the default store view) with the subject `[<store name>] Error Monitor: N error group(s)`; on success the day's flag is set and the listed groups get `last_emailed_date` and an incremented `emailed_count`. Each card in the email links to the admin detail page. Resolved and Ignored groups are never emailed.

### Cron jobs

| Job | Schedule | What it does |
|---|---|---|
| `panth_errormonitor_dispatch_notifications` | `5 * * * *` | Sends the daily summary email once the send hour is reached (see above). |
| `panth_errormonitor_cleanup` | `27 3 * * *` | Deletes `panth_error_event` rows older than "Keep Individual Events (days)" and `panth_error_group` rows in status Resolved whose `last_seen_at` is older than "Keep Resolved Groups (days)"; when "Keep Unresolved Groups (days)" is above 0, also rows in status New whose `last_seen_at` is older than that value. |

Both jobs are in the `default` cron group.

### Console commands

| Command | Options | What it does |
|---|---|---|
| `bin/magento panth:errormonitor:cleanup` | | Runs the retention cleanup now and prints how many events, resolved groups and unresolved groups were deleted. |
| `bin/magento panth:errormonitor:send-summary` | | Builds and sends the summary email now, ignoring the send-hour and once-per-day gates. Requires alerts enabled and valid recipients. |
| `bin/magento panth:errormonitor:pause` | `--minutes` / `-m` (default 60) | Suspends capture for the given number of minutes; the pause expires on its own. |
| `bin/magento panth:errormonitor:resume` | | Clears the explicit pause flag. Capture stays suspended while maintenance mode is on. |
| `bin/magento panth:errormonitor:regroup` | `--dry-run` | Re-fingerprints all existing groups with the current rules, merges groups that now share a fingerprint (events are moved, counters summed, oldest first-seen kept) and deletes the duplicates, inside one transaction. `--dry-run` prints the plan without writing. |
| `bin/magento panth:errormonitor:status` | `--reset-auto-detect` | Prints every capture gate, the suspension state, the deploy-marker modification times, filter settings and recent capture counts. `--reset-auto-detect` clears the deploy baseline and auto-pause flags first. |

### Retention

Event rows are pruned after "Keep Individual Events (days)"; a group keeps its counters and first / last seen timestamps after its events are gone. Resolved groups are removed after "Keep Resolved Groups (days)" without new occurrences. New groups are removed after "Keep Unresolved Groups (days)" without new occurrences when that setting is above 0; with the default 0 they are kept until an administrator resolves or deletes them. Ignored groups are always kept until an administrator deletes them, so the error stays ignored.

## Developer Notes

- Module name: `Panth_ErrorMonitor`; Composer package: `mage2kishan/module-error-monitor`; PSR-4 namespace: `Panth\ErrorMonitor`.
- Module sequence: `Panth_Core`, `Magento_Store`, `Magento_Config`, `Magento_Backend`, `Magento_Cron`, `Magento_Email`, `Magento_Ui`.
- Key classes:
  - `Logger\DbHandler`: Monolog handler; its `ErrorRecorder`, `IpAnonymizer`, `DeploymentGuard` and `Fingerprinter` dependencies are injected as proxies in `etc/di.xml` because the logger is built before the database connection is available.
  - `Service\ErrorRecorder::record(ErrorPayload $payload): ?int`: the single entry point that filters, fingerprints, throttles and stores an error and returns the group ID. Custom code can build a `Service\ErrorPayload` (source, severity, type, message, file, line, stackTrace, url, referer, userAgent, ip, httpMethod, context, storeId) and call it.
  - `Service\Fingerprinter`: `fingerprint()`, `normalizeMessage()`, `extractType()`, `isFrameworkGenericJs()`, `shortName()`.
  - `Service\CaptureThrottle::register()`, `Service\RateLimiter::allow()`, `Service\IpAnonymizer::process()`.
  - `Service\DeploymentGuard`: `isCaptureSuspended()`, `pause()`, `resume()`, `resetAutoDetect()`, `status()`.
  - `Service\Regrouper`: `plan()`, `regroupAll()`.
  - `Model\EmailNotifier::send(array $groups): bool`; `Block\Email\Summary` renders the cards in `view/frontend/templates/email/summary.phtml`.
  - `Cron\DispatchNotifications`, `Cron\Cleanup` (`run()` returns the deleted counts); `Console\Command\*` for the six commands.
  - `Helper\Config` (extends `Panth\Core\Helper\AbstractConfig`) exposes typed getters for every setting, with fallbacks when a value is empty.
  - `Model\ErrorGroup` (constants `STATUS_NEW` 0, `STATUS_RESOLVED` 1, `STATUS_IGNORED` 2, `SOURCE_PHP`, `SOURCE_JS`; event prefix `panth_error_group`) and `Model\ErrorEvent` (event prefix `panth_error_event`) with resource models and collections; `Model\ResourceModel\ErrorGroup\Grid\Collection` backs the listing data source.
  - `Model\Config\Source\Severity` holds the rank table (`debug` 1 to `emergency` 8) and the `rank()` helper used by all severity comparisons.
- Routes: admin `panth_errormonitor` (controllers `Error/Index`, `Error/View`, `Error/Resolve`, `Error/Ignore`, `Error/Delete`, `Error/MassResolve`, `Error/MassIgnore`, `Error/MassDelete`); frontend `panth_errormonitor` (`Js/Collect`).
- ACL resources: `Panth_ErrorMonitor::errors` ("Error Monitor"), `Panth_ErrorMonitor::view` ("View Errors"), `Panth_ErrorMonitor::manage` ("Manage Errors (resolve / ignore / delete)"), `Panth_ErrorMonitor::config` ("Configuration") and `Panth_ErrorMonitor::system_config` ("Configuration").
- Database tables (`etc/db_schema.xml`): `panth_error_group` (one row per fingerprint: source, severity, error_type, message, file, line, status, occurrence_count, store_id, first_seen_at, last_seen_at, last_emailed_date, emailed_count) and `panth_error_event` (group_id, url, referer, user_agent, ip, http_method, stack_trace, context, store_id, created_at) with a cascading foreign key to the group.
- Flags in the `flag` table: `panth_errormonitor_capture_paused_until`, `panth_errormonitor_autopause_until`, `panth_errormonitor_deploy_mtimes`, and one `panth_errormonitor_summary_<date>` per emailed day.
- Cache keys: `panth_em_thr_*` (coalescing) and `panth_em_rl_*` (rate limiting) in the default Magento cache.
- Data patches (`Setup/Patch/Data`): `MigrateEmailModeToDaily` (rewrites a legacy `immediate_digest` mode to `daily_summary`), `MigrateIgnorePatternsToGeneral` (copies saved `js_capture/ignore_patterns` values to `general/ignore_patterns`), `AddStaleCacheDefaults` (appends `Script error for`, `ChunkLoadError` and `Loading chunk` to saved ignore lists), `RegroupErrorsV2` and `RegroupErrorsV3` (run the regrouper once after upgrading the fingerprint rules).
- Email template: `panth_errormonitor_alert_template` (`view/frontend/email/error_alert.html`), area frontend.
- Unit tests live under `Test/Unit` (fingerprinting, throttling, IP anonymisation, ecosystem filter, severity ranks, trace synthesis). Translations: `i18n/en_US.csv`.

## Uninstallation

```bash
bin/magento module:disable Panth_ErrorMonitor
composer remove mage2kishan/module-error-monitor
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

These steps leave the `panth_error_group` and `panth_error_event` tables, the configuration rows under `panth_errormonitor/` in `core_config_data`, and the module's rows in the `flag` table in place. Drop or delete them manually if the data is no longer needed. Remaining `cron_schedule` rows for the two jobs are cleaned up by Magento's own cron history settings.

## Support

- Product page: [kishansavaliya.com/magento-2-error-monitor.html](https://kishansavaliya.com/magento-2-error-monitor.html)
- Contact form: [kishansavaliya.com/contact](https://kishansavaliya.com/contact)
- Email: kishansavaliyakb@gmail.com
- GitHub issues: [github.com/mage2sk/module-error-monitor/issues](https://github.com/mage2sk/module-error-monitor/issues)

## Documentation

[USER_GUIDE.md](USER_GUIDE.md) covers how capture works, a configuration reference for each group, reviewing errors in the admin, the cron jobs and CLI commands, silencing deploy noise, the security model of the JavaScript endpoint, and troubleshooting.

## License

Commercial software license. See [LICENSE.txt](LICENSE.txt) in this repository.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Links

- Website: [kishansavaliya.com](https://kishansavaliya.com)
- All extensions: [kishansavaliya.com/magento-extensions.html](https://kishansavaliya.com/magento-extensions.html)
- GitHub: [github.com/mage2sk/module-error-monitor](https://github.com/mage2sk/module-error-monitor)
- Packagist: [packagist.org/packages/mage2kishan/module-error-monitor](https://packagist.org/packages/mage2kishan/module-error-monitor)
