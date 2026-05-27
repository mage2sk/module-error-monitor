# Panth Error Monitor for Magento 2

Smart, secure error management. Captures **PHP exceptions** and **storefront
JavaScript console errors** into deduplicated, grouped database records, and
sends **throttled digest email alerts** so your inbox is never flooded.

## Why

`var/log/*.log` grows endlessly, the same exception repeats thousands of times,
and JavaScript errors in customers' browsers are invisible to you entirely.
Naïve "email me every error" extensions then bury you under thousands of
identical messages. Error Monitor fixes both problems:

- **Group, don't spam.** Every error is fingerprinted; repeats increment a
  counter on one row instead of inserting a new one.
- **Store in the database, not your inbox.** Everything lands in two tidy
  tables you review in the admin grid.
- **Email at most once per error per day**, optionally bundled into a single
  digest per cron run, with a severity threshold and a hard per-run cap.

## Features

| Area | What you get |
|------|--------------|
| PHP capture | A Monolog handler on the system logger records every exception ≥ your chosen severity — no code changes needed. |
| JS capture | A tiny, CSP-safe, `defer`-loaded collector reports uncaught errors & promise rejections from the storefront. |
| Grouping | Stable fingerprints collapse near-duplicate messages (numbers, ids, paths normalised out). |
| Email | Digest **or** individual mode, daily per-group dedupe, severity threshold, per-run cap. Sent by cron, never synchronously. |
| Admin | Searchable/filterable grid; per-row & mass Resolve / Ignore / Delete; full detail view with stack traces. |
| Security | Same-origin enforcement, per-IP + global rate limiting, body-size caps, strict validation, control-char stripping, no input ever reflected. |
| Privacy | Optional IP storage, optional IP anonymisation (GDPR). |
| Housekeeping | Daily retention cron + `bin/magento panth:errormonitor:cleanup`. |

## Requirements

- Magento 2.4.7+ (Monolog 3)
- PHP 8.1 – 8.4
- `mage2kishan/module-core` (Panth_Core)

## Installation

```bash
composer require mage2kishan/module-error-monitor
bin/magento module:enable Panth_ErrorMonitor
bin/magento setup:upgrade
bin/magento setup:di:compile        # production mode
bin/magento cache:flush
```

## Configuration

**Stores → Configuration → Panth Infotech → Error Monitor**

Capture is enabled out of the box; **email alerts are off until you enter
recipients**, so nothing is sent by surprise. See `USER_GUIDE.md` for every
field.

## Where errors go

- **Admin → Panth Infotech → Error Monitor → Error Log**
- Tables: `panth_error_group` (aggregates), `panth_error_event` (occurrences)

## License

Proprietary. See `LICENSE.txt`.
