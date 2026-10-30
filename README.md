# Magento 2 Error Monitor

Panth Error Monitor records PHP exceptions and storefront JavaScript errors into database tables inside Magento. A Monolog handler attached to the system logger captures server-side errors, and a small deferred script posts browser errors to a storefront endpoint. Every error is reduced to a fingerprint; repeats of the same error increment a counter on one group row instead of adding new rows, and individual occurrences are kept as separate event rows for a limited time.

Administrators review the groups in an admin grid with Resolve, Ignore and Delete actions, open a detail page that lists the pages and stack traces behind a group, and can receive one summary email per day listing the groups that had occurrences in the last 24 hours. The module is aimed at store owners and developers who want to see what a store is throwing without reading log files, while keeping the amount of stored data and mail bounded even when one error fires thousands of times.

Product page: [kishansavaliya.com/magento-2-error-monitor.html](https://kishansavaliya.com/magento-2-error-monitor.html)

## Features

- Captures PHP log records at or above a configurable severity through a Monolog handler registered on `Magento\Framework\Logger\Monolog`; exceptions attached to the log record supply class, file, line and stack trace.
- Captures uncaught JavaScript errors and unhandled promise rejections on the storefront with a deferred, non-inline collector script that works under a strict Content-Security-Policy.
- Groups errors by a SHA-256 fingerprint of source, error type, normalised message and normalised file; numbers, IDs, URLs, paths, hashes, IP addresses and quoted values are replaced by placeholders before hashing so near-identical messages fall into one group.
- Coalesces high-frequency repeats: within a configurable window only one database write is made per group, and the suppressed occurrences are added to the counter on the next write.
- Drops errors that match an admin-defined list of substrings, and optionally drops the `[PanthModule] BLOCKED ...` style operational alerts written by sibling Panth modules.
- Noise filters, on by default and editable: errors from bots and crawlers (User-Agent pattern list), known browser and third-party noise (Outlook Safe Links scanner, private-mode localStorage errors, `Script error.` without a stack, browser extensions) and client-safe GraphQL errors.
- Canonical grouping of the common Chrome, Safari and Firefox `TypeError` wordings, the `Uncaught ` prefix and deploy-versioned static paths, plus a `panth:errormonitor:refingerprint` command that merges existing duplicate groups.
- Suspends capture while Magento maintenance mode is on, during an explicit pause set from the command line, and for a configurable window after a deploy is detected from file modification times.
- Admin grid of error groups with filters, column controls, export, per-row View / Resolve / Ignore / Delete links and mass Resolve / Ignore / Delete actions; a detail page with the most frequent URLs and paginated occurrences including stack traces.
- Keyword search on the error log grid (message, file, error type, source).
- One summary email per UTC day, sent by cron after a configurable hour, listing only groups with occurrences in the last 24 hours (counted from exact hourly counters), sorted by that count, with "N in last 24 h (M total)", the top URL, the bot share, first / last seen in the store timezone and a footer counting hidden groups.
- Per-IP and global rate limiting, same-origin check and a body-size cap on the JavaScript collection endpoint.
- Optional storage of the client IP, with optional anonymisation of the last IPv4 octet or the last 80 bits of an IPv6 address.
- Daily cleanup cron that deletes old occurrence rows and old resolved groups; seven `bin/magento` commands for cleanup, sending the summary, pausing, resuming, refingerprinting (plus its deprecated alias regroup) and status.
- Errors are stored only in the Magento database; the module has no integration with third-party error-tracking services.

## Compatibility

| Platform | Versions |
|---|---|
| Magento Open Source | 2.4.4 to 2.4.9 |
| Adobe Commerce | 2.4.4 to 2.4.9 |
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
- `setup:upgrade` creates the four tables and runs the data patches listed under Developer Notes.

Check that the module is enabled:

```bash
bin/magento module:status Panth_ErrorMonitor
```

### Upgrading from 1.6.x

```bash
composer update mage2kishan/module-error-monitor
bin/magento setup:upgrade
bin/magento panth:errormonitor:refingerprint --dry-run
bin/magento panth:errormonitor:refingerprint
```

- `setup:upgrade` adds the tables `panth_error_group_hourly` and `panth_error_rate_limit` and the column `panth_error_group.line_unreliable`. Existing rows keep working.
- 1.7.0 changes the grouping rules, so errors that were split into several groups in 1.6.x only merge after `panth:errormonitor:refingerprint` has run once. The command also sets stored GraphQL client errors to notice (unless "GraphQL Client Errors" is "Record") and trims merged groups to the occurrence limit. It is not run automatically by `setup:upgrade`, because on large tables it is better started at a quiet time. Until it has run, the admin shows a notice. The `--dry-run` run prints what would change without writing.
- The daily summary counts the last 24 hours from hourly counters written since the upgrade; for older hours it falls back to the stored occurrence rows, so the first email after the upgrade is still complete.

## Configuration

Admin path: Stores > Configuration > Panth Infotech > Error Monitor (the section `panth_errormonitor` is placed in the `panth` tab, labelled "Panth Infotech", defined in `etc/adminhtml/system.xml`). The section requires the ACL resource `Panth_ErrorMonitor::system_config`. "Enable Error Monitor", the JavaScript Error Capture fields and the Privacy fields are available at default, website and store view scope. "Auto-pause After Deploy (minutes)", "Auto-Filter Sibling Module Operational Alerts", "Ignore Errors Matching", "Max Stored Occurrences per Error Group", "Max New JS Error Groups per Hour", "When the Occurrence Limit Is Reached", all Noise Filters fields and all PHP Error Capture, Email Alerts and Data Retention fields are default scope only, because they are read globally.

The admin menu adds "Error Monitor" under the `Panth_Core::panth_extensions` menu item, with two children: "Error Log" (opens the grid, requires `Panth_ErrorMonitor::view`) and "Configuration" (opens this section, requires `Panth_ErrorMonitor::system_config`).

Default behaviour after installation: PHP and JavaScript capture are on, no email is sent until "Send Email Alerts" is enabled and at least one valid recipient is entered, client IPs are stored anonymised, occurrences are kept for 30 days and resolved groups for 90 days.

### General

| Setting | Default | What it does |
|---|---|---|
| Enable Error Monitor | Yes | Master switch. When off, nothing is captured and no email is sent. |
| Auto-pause After Deploy (minutes) | 5 | Capture is suspended for this many minutes after a strictly newer modification time is seen on `pub/static/deployed_version.txt`, `generated/code` or `generated/metadata`. Accepts 0 to 120; the code caps the value at 120. 0 disables auto-pause. |
| Auto-Filter Sibling Module Operational Alerts | Yes | Drops log messages starting with `[Panth<Name>]` followed by `BLOCKED`, `REJECTED`, `DENIED`, `REFUSED`, `DROPPED` or `QUARANTINED`. |
| Ignore Errors Matching | Six lines: `Script error for`, `ChunkLoadError`, `Loading chunk`, `ResizeObserver loop limit exceeded`, `ResizeObserver loop completed with undelivered notifications.`, `Non-Error promise rejection captured` | One substring per line, matched case-insensitively against the message, file path, error type and stack trace. Matching errors are dropped before they are stored. If this field is empty, the legacy path `panth_errormonitor/js_capture/ignore_patterns` is read instead. |
| Max Stored Occurrences per Error Group | 50 | See "Storage caps" below. 0 = no limit. |
| When the Occurrence Limit Is Reached | Keep the oldest rows | `oldest` (default, the 1.6.x behaviour): no stored row is deleted; further occurrences only raise the counter, Last Seen and the hourly counters. `newest`: the oldest rows of the group (by time) are deleted so the newest stack traces are kept. The daily summary counts from hourly counters in both modes. |

### Noise Filters

| Setting | Default | What it does |
|---|---|---|
| Ignore Errors from Bots/Crawlers | Yes | Storefront JS reports, and PHP errors raised during a web request, whose User-Agent matches the pattern list are dropped. When No, they are stored with `"is_bot": true` in the occurrence context and the email shows the bot share per group. |
| Bot User-Agent Patterns | `bot`, `crawl`, `spider`, `slurp`, `bingbot`, `baiduspider`, `sogou`, `dotbot`, `ahrefs`, `semrush`, `yandex`, `petal`, `headless`, `probe`, `preview`, `facebookexternalhit`, `facebookcatalog`, `Google-Read-Aloud` (one per line) | Case-insensitive. Plain words match as substrings, except the single word `bot`, which only matches as a separate word or at the end of a name followed by `/`, `;`, `)` or `-` (so `bingbot/2.0` and `SEBot-WA` match, but CUBOT phones, `RobotVacuumApp` and `AbbottLibreLink` do not). Any other line is used as a regular expression. Every line is tested on its own; invalid expressions are skipped, and if every line is invalid the default list is used and one warning is logged. An empty list disables bot detection. |
| Leave Bot-only Groups out of the Summary Email | Yes | A group whose occurrences in the last 24 hours all came from bots (stored `is_bot` flag or a matching User-Agent) is not listed; the footer counts it. |
| Ignore Known Browser and Third-party Noise | Yes | Applies the Noise Patterns below at capture time and hides matching older groups from the email. |
| Noise Patterns | See below | One rule per line, a case-insensitive regular expression tested against the message. Prefixes: `file:` (file / source URL), `stack:` (stack trace), `source:` (file or stack trace), `any:` (message, file and stack), `nostack:` (message, only when there is no stack trace). Conditions joined with ` && ` must all match. Shown only when the switch above is Yes. |

Default noise patterns:

```
Object Not Found Matching Id:\d+, MethodName:\w+, ParamCount:\d+
source:customer-data|js\.storage|jquery[.-]storageapi && message:storage\.remove|delete tmp\[a\[i\]\]|QuotaExceededError|\(reading 'remove'\)|'remove' of (?:undefined|null)|evaluating '[\w$.]*remove'|SecurityError|operation is insecure|localStorage|sessionStorage
nostack:^(?:Uncaught\s+)?Script error\.?$
any:(?:chrome|moz|safari|safari-web|ms-browser)-extension://
```

They cover the Microsoft Outlook / Safe Links scanner, private-mode and full localStorage errors raised by `customer-data.js` / `js.storage.js` / `jquery.storageapi` (the storage rule, including blocked-localStorage `SecurityError`s, only applies when the file or stack trace is one of these, so storage bugs in your own code and unrelated `SecurityError`s stay visible), `Script error.` without a stack trace (with a stack trace it is kept) and errors coming from browser extensions. A rule with an invalid expression is skipped and can never stop capture.

### PHP Error Capture

| Setting | Default | What it does |
|---|---|---|
| Capture PHP Exceptions | Yes | Enables the Monolog handler that stores log records written to the Magento system log. |
| Minimum Severity to Capture | Error | Records below this level are not stored. Options: Notice, Warning, Error, Critical, Alert, Emergency. Shown only when capture is enabled. |
| Coalesce Window (seconds) | 60 | Within this window only one database write is made per error group; further occurrences are counted in cache and folded into the total on the next write. 0 writes every occurrence. Shown only when capture is enabled. |
| GraphQL Client Errors | Do not record | Errors GraphQL marks as client-safe (`isClientSafe()`), for example `Cannot query field ... on type ...` or address validation messages. Options: do not record; record as notice and never email; record as error (behaviour before 1.7.0). |

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
| Daily Summary Hour (0-23, UTC) | 23 | The summary is sent by the first hourly cron run at or after this UTC hour, once per UTC day. The email itself shows times in the store timezone. Shown only when alerts are enabled. |
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

Stored URLs and referers are stripped of their `#fragment`, and the value of any query parameter whose name contains `token`, `pass`, `pwd`, `secret`, `key`, `auth`, `session`, `sid`, `sig`, `hash`, `code`, `nonce`, `otp`, `email`, `mail`, `phone` or `telephone` is replaced with `redacted`. This applies to PHP and JavaScript events. Scalar logger context values whose key contains `pass`, `pwd`, `secret`, `token`, `api_key`, `private_key`, `auth`, `cookie`, `session`, `sessid`, `form_key`, `card`, `cc_`, `cvv`, `cvc`, `email`, `phone` or `telephone` (or equals `sid`) are stored as `redacted`.

### Data Retention

| Setting | Default | What it does |
|---|---|---|
| Keep Individual Events (days) | 30 | Event rows older than this are deleted by the daily cleanup cron. Group rows and their counters are kept. |
| Keep Resolved Groups (days) | 90 | Groups with status Resolved whose last occurrence is older than this are deleted, together with their remaining events. |
| Keep Unresolved Groups (days) | 0 | Groups with status New whose last occurrence is older than this are deleted, together with their remaining events. Ignored groups are never removed by this setting. 0 keeps New groups until an administrator resolves or deletes them. |

Config paths: `panth_errormonitor/general/enabled`, `panth_errormonitor/general/auto_pause_window_minutes`, `panth_errormonitor/general/filter_ecosystem_alerts`, `panth_errormonitor/general/ignore_patterns`, `panth_errormonitor/general/max_events_per_group`, `panth_errormonitor/general/event_cap_mode`, `panth_errormonitor/filters/ignore_bots`, `panth_errormonitor/filters/bot_user_agents`, `panth_errormonitor/filters/email_exclude_bot_only`, `panth_errormonitor/filters/noise_enabled`, `panth_errormonitor/filters/noise_patterns`, `panth_errormonitor/php_capture/enabled`, `panth_errormonitor/php_capture/min_severity`, `panth_errormonitor/php_capture/throttle_window_seconds`, `panth_errormonitor/php_capture/graphql_client_errors`, `panth_errormonitor/js_capture/enabled`, `panth_errormonitor/js_capture/sample_rate`, `panth_errormonitor/js_capture/rate_limit_per_minute`, `panth_errormonitor/js_capture/max_body_kb`, `panth_errormonitor/js_capture/max_new_groups_per_hour`, `panth_errormonitor/email/enabled`, `panth_errormonitor/email/mode`, `panth_errormonitor/email/send_hour`, `panth_errormonitor/email/recipients`, `panth_errormonitor/email/sender`, `panth_errormonitor/email/min_severity`, `panth_errormonitor/email/max_per_run`, `panth_errormonitor/privacy/store_ip`, `panth_errormonitor/privacy/anonymize_ip`, `panth_errormonitor/retention/event_days`, `panth_errormonitor/retention/resolved_group_days`, `panth_errormonitor/retention/unresolved_group_days`.

## Usage

### How PHP errors are captured

`Panth\ErrorMonitor\Logger\DbHandler` is added to the handler list of `Magento\Framework\Logger\Monolog` in `etc/di.xml` at Monolog level NOTICE, so it sees every record the system logger receives at notice level or above, including uncaught exceptions. For each record it checks, in this order: the master switch and "Capture PHP Exceptions", whether capture is suspended (see below), whether the message is empty, whether the record's level is at or above "Minimum Severity to Capture", and whether the message contains the module's own marker `[PanthErrorMonitor]` (its own log lines are never recorded).

The same `handlers` argument also lists Magento's own handlers `system` (`Magento\Framework\Logger\Handler\System`, var/log/system.log and, for records with an exception, var/log/exception.log), `debug` (`Magento\Framework\Logger\Handler\Debug`, var/log/debug.log when debug logging is enabled) and `syslog` (`Magento\Framework\Logger\Handler\Syslog`, only when syslog logging is enabled). Magento declares these in app/etc/di.xml, and a module di.xml that sets the `handlers` argument of this type replaces that list instead of merging with it, so they have to be repeated for file logging to keep working. Items added by other modules in their own di.xml are still merged by name.

If the record carries a `Throwable` in its context, the exception class, message, file, line and trace are used. Argument values are removed from every stored trace frame (`#0 file(line): Class->method()`), whatever the PHP setting `zend.exception_ignore_args` is, so passwords, tokens or customer data passed to a function never reach the database or the summary email. Traces stored before version 1.6.0 keep their arguments until they are pruned by the event retention. Otherwise the handler splits a `Stack trace:` section out of the message, or, for messages that are only a trace starting with `#0`, builds a short message from the first frame. The error type is mined from the message with a set of patterns (exception class names, JS error names, PHP `Warning` / `Notice` / `Deprecated Functionality` prefixes, Elasticsearch `caused_by` / `root_cause` types, invalid template file module names); if none matches, the Monolog channel name is used unless it is a generic one (`main`, `report`, `error`, `exception`), in which case the type is `error`. The request URI, referer, user agent, HTTP method and (subject to the Privacy settings) the client IP from `$_SERVER` are stored on the event row. PHP events are stored with store ID 0.

The stored context keeps the Monolog channel, every scalar context value, small arrays, the Monolog `extra` values (prefixed `extra_`), and for exceptions `exception_class`, `exception_file`, `exception_line` and up to five previous exceptions. When there is no HTTP request (cron, CLI, queue consumers) the context also gets `sapi`, `argv` (the command line with passwords, keys, tokens, URL passwords and long secret-looking values replaced by `***`) and `cron_job` (the job code of the running cron job, or the code from Magento's `Cron Job <code> has an error` message); if the record carries no trace, a backtrace of the logging call is stored.

Bot requests: when the request User-Agent matches the bot list and "Ignore Errors from Bots/Crawlers" is Yes, the record is dropped; otherwise it is stored with `is_bot`.

Console usage errors are not recorded: Symfony's command-not-found and namespace-not-found exceptions, invalid options, and console exceptions with the messages "Not enough arguments", "Too many arguments", "No arguments expected" or "The \"--x\" option does not exist / requires a value / does not accept a value" (or the same messages logged as text with a Symfony console trace frame). Any other exception, including one thrown by a command's own code, is recorded.

Context limits: string values in the log context are cut to 2,000 characters, arrays larger than that are replaced by a short note, and the stored context JSON is kept below 60,000 bytes so it always fits its column and stays valid JSON.

GraphQL: in production mode Magento wraps some client errors (for example an expired customer token) in an exception with the message `Report ID: graph-ql-...; Message: ...`; when the wrapped exception is client-safe, the wrapper is treated as a GraphQL client error.

GraphQL: a plugin on `Magento\Framework\GraphQl\Query\ErrorHandlerInterface::handle()` remembers which errors of the current request are client-safe before Magento logs them; the handler also checks a `Throwable` in the log context and stringified `GraphQL\Error\Error` messages. Client-safe errors are skipped, recorded as notice with `graphql_client_error`, or recorded as before, according to "GraphQL Client Errors". Server faults wrapped in a GraphQL error are always recorded.

Preprocessed templates: a file under `var/view_preprocessed/pub/static/` is stored as its source path (for example `vendor/magento/module-review/view/frontend/templates/form.phtml`); the original path and the note "Line numbers of minified or preprocessed templates are not reliable." are kept in the occurrence context, and the email marks such lines as possibly unreliable.

### How JavaScript errors are captured

`view/frontend/layout/default.xml` adds `Panth\ErrorMonitor\Block\Js\Beacon` to the `before.body.end` container on every storefront page. When "Capture Storefront JS Errors" is on for the current store view, the template writes a JSON configuration block (`<script type="application/json" id="panth-em-config">`) and loads `Panth_ErrorMonitor::js/error-monitor.js` with `defer`. No inline JavaScript is emitted.

The collector decides once per page load whether to report at all (Sample Rate), then listens for `window` `error` events (resource-load errors without a message are ignored) and `unhandledrejection` events. It sends at most 10 reports per page load, skips a report whose name, message, source and line were already sent on that page, caps each field, and posts the JSON body with `navigator.sendBeacon`, falling back to `fetch` with `keepalive` and no credentials.

The endpoint is `POST /panth_errormonitor/js/collect` (`Panth\ErrorMonitor\Controller\Js\Collect`). It always answers `204 No Content` with `Cache-Control: no-store`. A report is stored only if, in this order: JS capture is enabled for the store view, capture is not suspended, the `Origin` (or, failing that, `Referer`) host matches the host of a configured store base URL or secure link URL, the body is non-empty and within "Max Report Body Size (KB)", the per-IP and global rate limits allow it (the client IP comes from Magento's `RemoteAddress`, i.e. `REMOTE_ADDR` or the proxy headers configured for Magento, not from client-supplied `X-Forwarded-For` or `Client-IP` headers), the User-Agent is not a bot (when bots are ignored), and the body is a JSON object with a non-empty `message`. A `message` sent as an object (older collectors, or other senders) is turned into text from its `message`, `responseText` or `statusText`, or JSON, truncated to 500 characters. Values are capped (message 2000, name 191, source 1024, stack 8000 characters). JavaScript errors are always stored with severity `error`, source `js`, the visitor's store view ID, and a context of `colno`, `kind` (`error` or `unhandledrejection`), `origin: storefront-js`, `reason_type` (the raw type of a non-Error rejection reason or thrown value, for example `object:Object`) and `is_bot` when applicable.

For `unhandledrejection` reasons and thrown values that are not `Error` objects, the collector sends `reason.message || reason.responseText || reason.statusText || JSON.stringify(reason)` (circular references are replaced by `[Circular]`), truncated to 500 characters, instead of `[object Object]`. Form-key CSRF validation is bypassed for this endpoint because the beacon cannot send one.

Only `http` and `https` page URLs and referers are stored; any other scheme (for example `javascript:` or `data:`) is stored as empty. Sensitive keys in a serialised rejection reason (password, token, secret, key, authorization, cookie, card, cvv, email) are replaced by `redacted`, and `reason_type` is stored only when it is a plain type name such as `object:Object`, otherwise as `unknown`.

### Client IP behind a reverse proxy

The per-IP rate limit and the stored IP use Magento's `Magento\Framework\HTTP\PhpEnvironment\RemoteAddress`. Behind a reverse proxy or load balancer (Traefik, Varnish, nginx, a CDN) every request arrives from the proxy address, so all visitors would share one rate-limit budget. Tell Magento which header the proxy sets, in a small project module or in `app/etc/di.xml`:

```xml
<type name="Magento\Framework\HTTP\PhpEnvironment\RemoteAddress">
    <arguments>
        <argument name="alternativeHeaders" xsi:type="array">
            <item name="x-forwarded-for" xsi:type="string">HTTP_X_FORWARDED_FOR</item>
        </argument>
        <argument name="trustedProxies" xsi:type="array">
            <item name="proxy" xsi:type="string">10.0.0.2</item>
        </argument>
    </arguments>
</type>
```

Only add the header when the proxy always overwrites it, and list the proxy addresses in `trustedProxies` where your Magento version supports it; otherwise a visitor can send a forged `X-Forwarded-For`. The module never reads `X-Forwarded-For` by itself. The counters are kept in `panth_error_rate_limit` and are incremented atomically, so parallel requests cannot exceed the limit.

### Storage caps

Two Default Config settings bound how much the error tables can grow, whatever the traffic:

- "Max Stored Occurrences per Error Group" (`panth_errormonitor/general/max_events_per_group`, default 50, 0 = no limit). Before an occurrence row is written, the recorder counts the group's stored rows; when the group already has this many, the group's occurrence count, Last Seen time and status are still updated, and with "When the Occurrence Limit Is Reached" = oldest (default) no new row is inserted; with = newest the group's oldest rows (by time) are deleted until it is below the limit and the new one is inserted. Applies to PHP and JavaScript errors. Every occurrence, also one at the limit or merged by the coalescing window, increments the group's counter for the current UTC hour in `panth_error_group_hourly`, so the daily summary stays exact. `+` after a 24-hour count only appears for hours before the upgrade, when the count comes from stored rows and the limit cut them.
- "Max New JS Error Groups per Hour" (`panth_errormonitor/js_capture/max_new_groups_per_hour`, default 100, 0 = no limit). A store-wide counter in the Magento cache, per clock hour, limits how many never-seen JavaScript error groups the beacon may create. Beyond it the report is dropped (reports for existing groups are still counted) and one warning per hour, prefixed `[PanthErrorMonitor]`, is written to `var/log/system.log`. PHP errors are not limited. If the cache is unavailable the limit fails open.

Neither cap deletes existing data in the default mode. Recommendation: on public stores also set "Keep Unresolved Groups (days)" (for example 90), because its default of 0 keeps unresolved groups until an administrator resolves or deletes them; the retention defaults themselves are unchanged.

### Grouping rules

`Panth\ErrorMonitor\Service\ErrorRecorder::record()` first drops the payload when the message is empty, when the sibling-module alert filter matches, or when any "Ignore Errors Matching" substring is found in the message, file, type or stack trace. It then asks `Panth\ErrorMonitor\Service\Fingerprinter` for a SHA-256 hash of:

- the source (`php` or `js`);
- the error type in lower case (if the stored type is generic, a type mined from the message is used instead);
- the normalised message: lower-cased, cut before `Stack trace:`, with JSON blobs, URLs, filesystem paths, file names, UUIDs, hex hashes, session IDs, IPv4/IPv6 addresses, line / position / row numbers, trace frame numbers, parameter numbers, version numbers and other numbers replaced by placeholders, quoted values collapsed unless they look like identifiers, whitespace collapsed, and a `caused_by=` / `root_cause=` suffix appended for Elasticsearch-style errors; truncated to 500 characters;
- before these placeholders: a leading `Uncaught ` / `Uncaught (in promise) ` is removed, `/static/versionNNNN/` becomes `/static/`, query strings and `:line:column` after `.js` / `.min.js` file names and `#fragments` after file names are removed, Windows and Unix line endings are treated alike, and the browser wordings `Cannot read properties of undefined (reading 'x')` (Chrome), `Cannot read property 'x' of undefined`, `undefined is not an object (evaluating 'a.x')` (Safari), `can't access property "x", a is undefined` / `can't access property "x" of undefined` and `TypeError: a.x is undefined` (Firefox) all become `typeerror: cannot read 'x' of undefined` (and `... of null` for the null variants; Safari bracket keys such as `x["data-id"]` match Chrome's `'data-id'`), which also ignores the file; quoted identifiers may contain `$`, so `$el` and `$refs` stay distinct;
- the normalised file: query string, scheme and host, `var/view_preprocessed/pub/static/`, the `/static/versionNNNN/` segment, the `pub/static/<version>/<area>/<vendor>/<theme>/<locale>/` prefix and a trailing `:line:column` are removed. For JavaScript files served from `static/` the module path after the theme and locale is used (for example `magento_checkout/js/view/summary.js`), so the same file name in two modules gives two groups, while deploy versions, themes and locales do not split a group; other JavaScript URLs use the base name. The file is ignored entirely for generic browser messages such as `cannot read properties of undefined` or `x is not a function`.

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

The cron job `panth_errormonitor_dispatch_notifications` runs at minute 5 of every hour (`5 * * * *`). It returns immediately unless the master switch and "Send Email Alerts" are on and at least one valid recipient is configured. It then applies these gates: the current UTC hour must be at or after "Daily Summary Hour"; and no flag `panth_errormonitor_summary_<YYYY-MM-DD>` may exist for the current UTC date. `Panth\ErrorMonitor\Model\Summary\DigestBuilder` then sums the hourly counters in `panth_error_group_hourly` for the full hours inside the window (occurrences and bot occurrences, exact even for throttled and capped groups); for the part of the first hour that lies inside the window, and for hours before the counters existed, it counts `panth_error_event` rows with `created_at` in that range instead (bot count from the stored `is_bot` flag or a User-Agent matching the bot list). The most frequent URL comes from the stored rows. Groups without events in the window are not listed, whatever their lifetime total. Of the rest it lists groups in status New with severity at or above "Minimum Severity to Email"; groups in status Ignored or matching the ignore / noise rules, bot-only groups (when "Leave Bot-only Groups out of the Summary Email" is Yes) and GraphQL client errors stored as notice are hidden and counted in the footer; Resolved groups that had activity are not listed but counted in the footer. Rows are sorted by the 24-hour count, then severity, then last seen, and limited to "Max Error Groups per Email" (the footer says how many more were not listed). If no group qualifies, nothing is sent and no flag is set, so later runs on the same day check again. Otherwise one email is sent through the template "Panth Error Monitor: Error Alert / Digest" (`panth_errormonitor_alert_template`, rendered in the frontend area under the default store view) with the subject `[<store name>] Error Monitor: N error group(s) in the last 24 hours`, where N counts every qualifying group, also the ones not listed because of the per-email limit. Each card shows severity, source, type, short message, file and line, top URL, `N in last 24 h (M total)`, the bot percentage and first / last seen in the timezone of the store view where the error was last seen (the default store timezone when unknown); on success the day's flag is set and the listed groups get `last_emailed_date` and an incremented `emailed_count`. Each card in the email links to the admin detail page. The link carries no per-session secret key, and the detail action accepts it without one even when "Add Secret Key to URLs" is enabled; an admin login and the `Panth_ErrorMonitor::view` permission are still required. Resolved and Ignored groups are never emailed.

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
| `bin/magento panth:errormonitor:send-summary` | `--dry-run` | Builds and sends the summary email now, ignoring the send-hour and once-per-day gates. Requires alerts enabled and valid recipients. `--dry-run` prints the groups, counts, times and hidden-group numbers without sending. |
| `bin/magento panth:errormonitor:pause` | `--minutes` / `-m` (default 60) | Suspends capture for the given number of minutes; the pause expires on its own. |
| `bin/magento panth:errormonitor:resume` | | Clears the explicit pause flag. Capture stays suspended while maintenance mode is on. |
| `bin/magento panth:errormonitor:refingerprint` (alias `panth:error-monitor:refingerprint`) | `--dry-run` | Recomputes the fingerprint of every group with the current rules (and maps preprocessed template paths) and merges groups that now describe the same error: events and hourly counters move to the earliest group, occurrence counts are summed, the earliest first seen and latest last seen are kept, the most severe severity wins and the status becomes New if any merged group was New, otherwise Ignored if any was Ignored, otherwise Resolved; the merged group is then trimmed to "Max Stored Occurrences per Error Group" following "When the Occurrence Limit Is Reached". Stored GraphQL client errors (Magento GraphQL input, authorization, authentication and no-such-entity exceptions, GraphQL validation errors such as `Cannot query field`, and `Report ID` wrappers of expired customer tokens) are set to notice unless "GraphQL Client Errors" is "Record". The groups are re-read with `SELECT ... FOR UPDATE` inside one transaction, so occurrences recorded at the same moment are not lost; on an error everything is rolled back. Idempotent (a second run changes nothing). Run it once after upgrading to 1.7.0. `--dry-run` prints the plan, including the real number of occurrences that would be moved and trimmed. Sensitive `config:set` / `config:sensitive:set` values in a stored command line are masked whatever the option order. |
| `bin/magento panth:errormonitor:regroup` | `--dry-run` | Deprecated alias kept for scripts: runs `panth:errormonitor:refingerprint` and prints the older summary line. The 1.6.x regrouper is only used by the old `RegroupErrorsV2` / `RegroupErrorsV3` data patches. |
| `bin/magento panth:errormonitor:status` | `--reset-auto-detect` | Prints every capture gate, the suspension state, the deploy-marker modification times, filter settings and recent capture counts. `--reset-auto-detect` clears the deploy baseline and auto-pause flags first. |

### Timezones

All timestamps (`first_seen_at`, `last_seen_at`, `created_at`, `updated_at`) are stored in UTC: the module writes them with `gmdate()`, and Magento's database connection runs with `time_zone = '+00:00'`, so the 24-hour window (computed with `gmdate()`) and the stored values use the same timezone. The columns are MySQL `TIMESTAMP`, which a database client converts to its own session timezone; a phpMyAdmin or CLI session that is not UTC therefore shows values shifted by the session offset. The summary email converts times to the timezone of the store view where each error was last seen, or the default store timezone (Stores > Configuration > General > Locale Options > Timezone) when the store view is unknown (PHP errors), and labels them, for example `2026-10-27 13:48:58 CDT`. Earlier versions also wrote UTC with `gmdate()`, so existing rows need no repair; the email of earlier versions printed the raw UTC value with a `UTC` label. The send hour remains a UTC hour.

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
  - `Service\Refingerprinter`: `run(bool $dryRun)`, `plan()`, `isGraphQlClientGroup()`; throws `Service\RefingerprintRolledBackException` when a run was rolled back. `Service\Regrouper` (`plan()`, `regroupAll()`) remains for the old data patches only.
  - `Model\System\Message\RefingerprintPending`: admin notice shown while the flag `panth_errormonitor_refingerprint_pending` is set (set by the `FlagRefingerprintPending` data patch when groups exist, cleared by a successful refingerprint run).
  - `Model\EmailNotifier::send(array $groups): bool`; `Block\Email\Summary` renders the cards in `view/frontend/templates/email/summary.phtml`.
  - `Cron\DispatchNotifications`, `Cron\Cleanup` (`run()` returns the deleted counts); `Console\Command\*` for the six commands.
  - `Helper\Config` (extends `Panth\Core\Helper\AbstractConfig`) exposes typed getters for every setting, with fallbacks when a value is empty.
  - `Model\ErrorGroup` (constants `STATUS_NEW` 0, `STATUS_RESOLVED` 1, `STATUS_IGNORED` 2, `SOURCE_PHP`, `SOURCE_JS`; event prefix `panth_error_group`) and `Model\ErrorEvent` (event prefix `panth_error_event`) with resource models and collections; `Model\ResourceModel\ErrorGroup\Grid\Collection` backs the listing data source.
  - `Model\Config\Source\Severity` holds the rank table (`debug` 1 to `emergency` 8) and the `rank()` helper used by all severity comparisons.
- Routes: admin `panth_errormonitor` (controllers `Error/Index`, `Error/View`, `Error/Resolve`, `Error/Ignore`, `Error/Delete`, `Error/MassResolve`, `Error/MassIgnore`, `Error/MassDelete`); frontend `panth_errormonitor` (`Js/Collect`).
- ACL resources: `Panth_ErrorMonitor::errors` ("Error Monitor"), `Panth_ErrorMonitor::view` ("View Errors"), `Panth_ErrorMonitor::manage` ("Manage Errors (resolve / ignore / delete)"), `Panth_ErrorMonitor::config` ("Configuration") and `Panth_ErrorMonitor::system_config` ("Configuration").
- Database tables (`etc/db_schema.xml`): `panth_error_group` (one row per fingerprint: source, severity, error_type, message, file, line, status, occurrence_count, store_id, first_seen_at, last_seen_at, last_emailed_date, emailed_count) (plus `line_unreliable`), `panth_error_event` (group_id, url, referer, user_agent, ip, http_method, stack_trace, context, store_id, created_at) with a cascading foreign key to the group, `panth_error_group_hourly` (group_id, hour_start, occurrences, bot_occurrences; primary key group and hour, covering index on hour, cascading foreign key; rows older than 35 days are removed by the cleanup cron) and `panth_error_rate_limit` (collector rate limit counters per IP hash and minute, updated with an atomic `INSERT ... ON DUPLICATE KEY UPDATE`; expired rows are removed by the cleanup cron).
- Flags in the `flag` table: `panth_errormonitor_capture_paused_until`, `panth_errormonitor_autopause_until`, `panth_errormonitor_deploy_mtimes`, and one `panth_errormonitor_summary_<date>` per emailed day.
- Cache keys: `panth_em_thr_*` (coalescing) and `panth_em_ng_*` (new JS groups per hour) in the default Magento cache; `panth_em_rl_*` keys are only used for rate limiting when the `panth_error_rate_limit` table is not available yet.
- Data patches (`Setup/Patch/Data`): `MigrateEmailModeToDaily` (rewrites a legacy `immediate_digest` mode to `daily_summary`), `MigrateIgnorePatternsToGeneral` (copies saved `js_capture/ignore_patterns` values to `general/ignore_patterns`), `AddStaleCacheDefaults` (appends `Script error for`, `ChunkLoadError` and `Loading chunk` to saved ignore lists), `RegroupErrorsV2` and `RegroupErrorsV3` (run the regrouper once after upgrading the fingerprint rules), `FlagRefingerprintPending` (shows the upgrade notice until `panth:errormonitor:refingerprint` has run).
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
