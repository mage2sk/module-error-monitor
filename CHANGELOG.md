# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.7.1] - 2026-10-30

### Changed
- Released under the MIT License: this module is free and open-source software.

## [1.7.0] - 2026-10-30

### Upgrade notes
- Run `bin/magento setup:upgrade`, then run `bin/magento panth:errormonitor:refingerprint` once. The admin shows a notice until it has run. Fingerprints changed again in this release (JS files are identified by their module path instead of the file name, `$` is kept in property names such as `$el`, and Safari bracket keys match Chrome), so existing groups only merge after the command has run.
- Three tables are added: `panth_error_group_hourly` (exact occurrences per group and hour) and `panth_error_rate_limit` (collector rate limit counters), plus the column `panth_error_group.line_unreliable`. Existing rows keep working.

### Fixed
- The daily summary email now lists only error groups that had occurrences in the last 24 hours and shows "N in last 24 h (M total)" instead of the lifetime total. Groups without new occurrences are no longer listed, and the list is sorted by the 24-hour count.
- The 24-hour counts, the bot share and the choice of groups come from exact hourly counters that are written for every occurrence, including occurrences merged by the capture throttle and occurrences of groups that reached the stored-occurrence limit. For hours before the upgrade, and for the part of the first hour that lies inside the window, the email counts the stored occurrence rows, so the window is exactly 24 hours. A "+" after the count is only shown when the limit really cut stored rows in that fallback.
- The email subject and heading count every group with activity, not only the rows shown under the per-email limit. Groups that had activity but are already resolved are counted in the footer.
- The "View details" link in the email opens the error even when "Add Secret Key to URLs" is enabled. Access still requires an admin login and the Error Monitor permission.
- Times in the summary email are shown in the timezone of the store view where the error was last seen (the default store timezone when unknown) and labelled with it, for example `CDT`. All values are still stored in UTC and the 24-hour window is computed in UTC.
- Storefront rejections and thrown values that are not `Error` objects are no longer reported as `[object Object]`: the collector sends the reason's `message`, `responseText` or `statusText`, or a JSON text cut to 500 characters, and stores the raw type as `reason_type`. Sensitive keys (password, token, secret, key, authorization, cookie, card, cvv, email) are redacted in that JSON, and a reason type that is not a plain type name is stored as `unknown`.
- One error is no longer split into several groups by the `Uncaught ` prefix, by `/static/versionNNNN/` in file paths and messages, by query strings, `#fragments` and line/column numbers of minified files, or by Windows line endings. The Chrome, Safari and Firefox wordings of "cannot read property of undefined/null" are grouped as `TypeError: cannot read 'x' of undefined` (or `of null`), including Safari bracket keys such as `x["data-id"]`.
- Different errors are no longer merged: property names with `$` (`$el`, `$refs`) stay distinct, and the same message from two modules' files with the same name (for example `view/summary.js`) stays in two groups.
- PHP errors logged from cron and the command line now carry `sapi`, the command line with secrets masked, the cron job code when known, and a backtrace of the logging call. Masking now also covers values of sensitive `config:set` paths and every `config:sensitive:set` value in any option order (`--scope websites`, `--scope=websites`, `--scope-code`, `--lock-env`, `--lock-config`, `-e`, `-c`, `-i` before or after the path), `-p <password>` and `-p<password>`, DSN `password=` values and the admin e-mail and name options, while Magento's own `--bootstrap=standaloneProcessStarted=1` stays readable.
- Long context values are cut to 2,000 characters and the stored context always fits its column as valid JSON.
- File paths under `var/view_preprocessed/pub/static/` are stored as the source template path. The group remembers that its line number may be unreliable, so the email and the admin page show the note for every line of such a template; the admin page lists the occurrence context as readable fields instead of raw JSON.
- GraphQL client errors that Magento wraps as `Report ID: graph-ql-...; Message: ...` (for example an expired customer token) are treated as client errors.
- Mistyped console commands and options (`Command "x" is not defined`, unknown namespaces, unknown options, options missing a value, too many or too few arguments) are no longer recorded as errors. Errors thrown by a command's own code are still recorded.
- One invalid bot pattern (for example `\Qfoo`) can no longer break bot detection, PHP error capture or the email: every pattern is tested on its own, pattern errors never escape, and a list in which every line is invalid falls back to the default list with one log entry. The same protection applies to the noise rules.
- The bare `bot` pattern no longer matches phone and app names such as CUBOT, RobotVacuumApp or AbbottLibreLink.
- The storage noise rules, including the `SecurityError` rule for blocked localStorage, only hide errors whose file or stack trace is `customer-data`, `js.storage` or `jquery.storageapi`, so storage bugs in the store's own code and unrelated `SecurityError`s (for example a Worker script from another origin) stay visible. `Script error.` is only hidden when it has no stack trace; the email uses the stored stack traces to decide this.
- The storefront collector stores only `http` and `https` page URLs and referers, and its per-IP rate limit is counted atomically in the database, so parallel requests can no longer exceed it.

### Added
- "Noise Filters" settings: "Ignore Errors from Bots/Crawlers" (default Yes) with an editable User-Agent pattern list (now including `facebookexternalhit`, `facebookcatalog` and `Google-Read-Aloud`), applied by the storefront collector and by the PHP handler during web requests. When bots are not ignored, their occurrences are stored with an `is_bot` flag, the email shows the bot share per group, and bot-only groups are left out of the email (configurable).
- "Ignore Known Browser and Third-party Noise" (default Yes) with editable rules for the Outlook Safe Links scanner (`Object Not Found Matching Id`), private-mode and full localStorage errors from `customer-data.js` and `js.storage.js`, `Script error.` without a stack trace and browser extension files. Rules can test the message, `file:`, `stack:`, `source:` (file or stack), `any:` or `nostack:`.
- "GraphQL Client Errors" setting: errors that GraphQL marks as client-safe are not recorded by default; they can instead be recorded as notice and kept out of the email, or recorded as before.
- "When the Occurrence Limit Is Reached" setting. The default keeps the oldest stored rows, as in 1.6.x, so no stored occurrence is deleted; "Keep the newest rows" replaces the oldest rows (ordered by time) instead. The limit is enforced fully, also after merges.
- Email footer with the number of groups hidden by ignore rules, the bot filter and the GraphQL filter, resolved groups that had activity, the number not listed because of the per-email limit, and the timezone used; each card shows the top URL and the bot percentage.
- `bin/magento panth:errormonitor:refingerprint [--dry-run]` (alias `panth:error-monitor:refingerprint`) recomputes fingerprints with the current rules and merges duplicate groups in one transaction that locks the groups first, so occurrences recorded at the same time are not lost: occurrences and hourly counters move to the earliest group, counts are summed, the earliest first seen and latest last seen are kept, the most severe severity and the most open status win, and the merged group is trimmed to the occurrence limit. Stored GraphQL client errors are set to notice unless "GraphQL Client Errors" is "Record". The dry run reports the real number of occurrences that would be moved and trimmed. Running it again changes nothing. `panth:errormonitor:regroup` now runs the same command and is deprecated.
- `bin/magento panth:errormonitor:send-summary --dry-run` prints the summary without sending it.
- The daily cleanup cron also removes hourly counters older than 35 days and expired rate limit rows.
