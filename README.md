<!-- SEO Meta -->
<!--
  Title: Magento 2 Error Monitor Extension: PHP and JavaScript Error Tracking with Admin Grid and Email Alerts | Panth Infotech
  Description: Panth Error Monitor captures PHP exceptions and storefront JavaScript errors into grouped, deduplicated database records. Sends throttled digest email alerts, provides an admin review grid with mass actions, and supports GDPR-friendly IP anonymisation. Works on Magento 2.4.7+ and PHP 8.1 to 8.4. Built by Top Rated Plus Magento developer Kishan Savaliya.
  Keywords: magento 2 error monitor, magento 2 error tracking, magento 2 php error logging, magento 2 javascript error capture, magento 2 error notification, magento 2 exception monitoring, magento 2 error alert email, magento 2 admin error grid, hyva error monitor, magento 2 error management extension
  Author: Kishan Savaliya (Panth Infotech)
  Canonical: https://kishansavaliya.com/magento-2-error-monitor.html
-->

# Magento 2 Error Monitor: PHP and JavaScript Error Tracking with Admin Grid and Email Alerts

[![Magento 2.4.7+](https://img.shields.io/badge/Magento-2.4.7%2B-orange?logo=magento&logoColor=white)](https://magento.com)
[![PHP 8.1 - 8.4](https://img.shields.io/badge/PHP-8.1%20--%208.4-blue?logo=php&logoColor=white)](https://php.net)
[![Hyva + Luma](https://img.shields.io/badge/Themes-Hyva%20%2B%20Luma-14b8a6)](https://www.hyva.io)
[![Live Demo & Details](https://img.shields.io/badge/Live%20Demo%20%26%20Details-magento--2--error--monitor-0D9488?style=flat)](https://kishansavaliya.com/magento-2-error-monitor.html)
[![Packagist](https://img.shields.io/badge/Packagist-mage2kishan%2Fmodule--error--monitor-orange?logo=packagist&logoColor=white)](https://packagist.org/packages/mage2kishan/module-error-monitor)
[![Upwork Top Rated Plus](https://img.shields.io/badge/Upwork-Top%20Rated%20Plus-14a800?logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/~016dd1767321100e21)
[![Website](https://img.shields.io/badge/Website-kishansavaliya.com-0D9488)](https://kishansavaliya.com)

> **Know about errors before your customers do.** Panth Error Monitor captures PHP exceptions and storefront JavaScript errors into grouped, deduplicated database records and sends a daily digest email so you stay informed without inbox flooding. Includes an admin review grid with mass actions, per-IP rate limiting, GDPR-ready IP anonymisation, and automatic data retention.

**Product page:** [kishansavaliya.com/magento-2-error-monitor.html](https://kishansavaliya.com/magento-2-error-monitor.html)

---

## Quick Answer

**What is Panth Error Monitor?** It is a Magento 2 error tracking extension that captures PHP exceptions and storefront JavaScript errors into grouped database records, with an admin grid for review and optional daily digest email alerts.

**What does it add to my store?**

- **PHP error capture** via a Monolog handler that records every exception at or above your chosen severity level.
- **JavaScript error capture** via a tiny, CSP-safe script that reports uncaught errors and promise rejections from the storefront.
- **Smart deduplication**: identical errors are grouped by fingerprint and increment a counter instead of inserting a new row every time.
- **Throttled email alerts** sent by cron, at most once per error group per day, with a hard cap on groups per email.
- **Admin error grid** with per-row and mass Resolve, Ignore, and Delete actions, plus a full detail view with stack traces.
- **Automatic cleanup** cron and a CLI command to prune old data so tables stay manageable.

**Which themes are supported?** Both **Hyva** and **Luma**. PHP capture works server-side regardless of theme. The JavaScript collector works on any storefront.

**What does it need?** Magento 2.4.7+, PHP 8.1 to 8.4, and the free `mage2kishan/module-core` package.

---

## Need Custom Magento 2 Development?

> **Get a free quote for your project in 24 hours** for custom modules, Hyva themes, performance work, M1 to M2 migrations, and Adobe Commerce Cloud.

<p align="center">
  <a href="https://kishansavaliya.com/get-quote">
    <img src="https://img.shields.io/badge/Get%20a%20Free%20Quote%20%E2%86%92-Reply%20within%2024%20hours-DC2626?style=for-the-badge" alt="Get a Free Quote" />
  </a>
</p>

<table>
<tr>
<td width="50%" align="center">

### Kishan Savaliya
**Top Rated Plus on Upwork**

[![Hire on Upwork](https://img.shields.io/badge/Hire%20on%20Upwork-Top%20Rated%20Plus-14a800?style=for-the-badge&logo=upwork&logoColor=white)](https://www.upwork.com/freelancers/~016dd1767321100e21)

100% Job Success - 10+ Years Magento Experience
Adobe Certified - Hyva Specialist

</td>
<td width="50%" align="center">

### Panth Infotech Agency
**Magento Development Team**

[![Visit Agency](https://img.shields.io/badge/Visit%20Agency-Panth%20Infotech-14a800?style=for-the-badge&logo=upwork&logoColor=white)](https://www.upwork.com/agencies/1881421506131960778/)

Custom Modules - Theme Design - Migrations
Performance - SEO - Adobe Commerce Cloud

</td>
</tr>
</table>

**Visit our website:** [kishansavaliya.com](https://kishansavaliya.com) &nbsp;|&nbsp; **Get a quote:** [kishansavaliya.com/get-quote](https://kishansavaliya.com/get-quote)

---

## Table of Contents

- [Who Is It For](#who-is-it-for)
- [Key Features](#key-features)
- [Compatibility](#compatibility)
- [Installation](#installation)
- [Configuration](#configuration)
- [How It Works](#how-it-works)
- [Admin Error Grid](#admin-error-grid)
- [FAQ](#faq)
- [Support](#support)
- [About Panth Infotech](#about-panth-infotech)
- [Quick Links](#quick-links)

---

## Who Is It For

- **Store owners who miss errors until customers report them.** Error Monitor surfaces PHP and JavaScript problems in the admin the moment they occur.
- **Development teams running regular deployments.** The auto-pause feature keeps the error grid clean during deploys so real regressions stand out.
- **Merchants on high-traffic stores.** Deduplication and coalescing mean one noisy exception never floods the database or your inbox.
- **Stores with GDPR obligations.** IP storage is optional and IP anonymisation is a single config toggle.
- **Agencies and developers managing multiple client stores.** The daily digest gives a store health summary in one email per day, not thousands.

---

## Key Features

### PHP Error Capture
- **Monolog handler** plugs into the system logger so every exception at or above your minimum severity level is recorded automatically.
- **No code changes required** in your own modules or theme.
- **Coalesce window** batches high-frequency repeats in cache and writes at most one database row per error group per window (default 60 seconds), protecting the database and binary log during incidents.
- **Severity filter** so you can capture only errors and above, or include warnings and notices.

### JavaScript Error Capture
- **CSP-safe, defer-loaded collector** injected into all storefront pages.
- **Captures uncaught errors and unhandled promise rejections** from the visitor's browser.
- **Same-origin enforcement** so the endpoint only accepts reports from your own store domain.
- **Per-IP and global rate limiting** drop requests beyond the configured threshold, preventing abuse and flooding.
- **Body-size cap** rejects oversized payloads before parsing.
- **Configurable sample rate** so you can lighten the load on very high-traffic stores.

### Smart Deduplication and Grouping
- **Fingerprint-based grouping**: each unique error gets one row in `panth_error_group`; repeat occurrences increment the counter on that row.
- **Numbers, IDs, and paths normalised out** of fingerprints so near-duplicate messages collapse into the same group.
- **First seen and last seen timestamps** tracked per group so you can tell if an old issue came back.

### Email Alerts
- **Digest email** lists new error groups, sent by cron at your configured hour each day.
- **At most one email per error group per day**, regardless of how many times the error fires.
- **Severity threshold for email**: lower-severity errors are stored but not emailed.
- **Hard cap on groups per email** prevents enormous emails during incidents.
- **Email is off until you enter recipients**, so nothing is sent by surprise after install.

### Admin Error Grid
- **Searchable and filterable grid** under Admin -> Panth Infotech -> Error Monitor -> Error Log.
- **Per-row actions**: Resolve, Ignore, Delete.
- **Mass actions**: Mass Resolve, Mass Ignore, Mass Delete.
- **Detail view** with full stack trace, URL, referer, user agent, and IP.
- **Status tracking**: New, Resolved, Ignored.

### Privacy and GDPR
- **Store Client IP is optional**: turn it off to store no IP address at all.
- **IP anonymisation**: masks the last octet for IPv4 and last 80 bits for IPv6 before storing.

### Automatic Housekeeping
- **Daily retention cron** removes old occurrence rows and resolved groups based on your configured day counts.
- **CLI command** `bin/magento panth:errormonitor:cleanup` for manual runs.
- **Auto-filter for sibling module alerts** drops operational log lines from other Panth security modules so they do not pollute the error grid.
- **Auto-pause after deploy** suspends capture for a configurable window after a deploy is detected, so stale-cache noise does not fill the grid.

---

## Compatibility

| Requirement | Versions Supported |
|---|---|
| Magento Open Source | 2.4.7, 2.4.8 |
| Adobe Commerce | 2.4.7, 2.4.8 |
| Adobe Commerce Cloud | 2.4.7 to 2.4.8 |
| PHP | 8.1.x, 8.2.x, 8.3.x, 8.4.x |
| Hyva Theme | Any (storefront JS collector is theme-agnostic) |
| Luma Theme | Native support |
| Required Dependency | `mage2kishan/module-core` (free) |

---

## Installation

### Composer Installation (Recommended)

```bash
composer require mage2kishan/module-error-monitor
bin/magento module:enable Panth_Core Panth_ErrorMonitor
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

### Manual Installation via ZIP

1. Download the latest release from [Packagist](https://packagist.org/packages/mage2kishan/module-error-monitor) or from the [product page](https://kishansavaliya.com/magento-2-error-monitor.html).
2. Extract it to `app/code/Panth/ErrorMonitor/` in your Magento install.
3. Make sure `Panth_Core` is installed too (required dependency).
4. Run the commands above starting from `bin/magento module:enable`.

### Verify Installation

```bash
bin/magento module:status Panth_ErrorMonitor
# Expected: Module is enabled
```

After install, open:
```
Admin -> Stores -> Configuration -> Panth Infotech -> Error Monitor
```

---

## Configuration

Go to **Stores -> Configuration -> Panth Infotech -> Error Monitor**.

### General

| Setting | Default | Description |
|---|---|---|
| Enable Error Monitor | Yes | Master switch. When off, no errors are captured and no emails are sent. |
| Auto-pause After Deploy (minutes) | 5 | Suspends capture for this many minutes after a deploy is detected, to avoid stale-cache noise. Set to 0 to disable. |
| Auto-Filter Sibling Module Operational Alerts | Yes | Drops log lines from other Panth security/firewall modules that are operational events, not defects. |
| Ignore Errors Matching | (empty) | One substring per line. An error is dropped at capture time if any substring appears in its message, file path, error class, or stack trace. |

### PHP Error Capture

| Setting | Default | Description |
|---|---|---|
| Capture PHP Exceptions | Yes | Records exceptions written to the Magento system log. |
| Minimum Severity to Capture | Error | Only errors at this level or above are stored. |
| Coalesce Window (seconds) | 60 | When the same error repeats, only one database write is made per group within this window. Set to 0 to write every occurrence. |

### JavaScript Error Capture (Storefront)

| Setting | Default | Description |
|---|---|---|
| Capture Storefront JS Errors | Yes | Injects a CSP-safe collector that reports uncaught JS errors and promise rejections. |
| Sample Rate (%) | 100 | Percentage of visitors whose JS errors are reported. Lower this on very high-traffic sites. |
| Max Reports per IP per Minute | (configured) | Server-side rate limit. Requests beyond this are silently dropped. |
| Max Report Body Size (KB) | (configured) | Larger payloads are rejected before parsing. |

### Email Alerts

| Setting | Default | Description |
|---|---|---|
| Send Email Alerts | No | Alerts are sent by cron, never synchronously. Off until you configure recipients. |
| Daily Summary Hour (0-23, UTC) | 23 | Hour of day the once-per-day summary is sent. |
| Recipient Email(s) | (empty) | Comma- or newline-separated. Required when email is enabled. |
| Email Sender Identity | (store default) | Which email identity to send from. |
| Minimum Severity to Email | Error | Lower-severity errors are stored but not emailed. |
| Max Error Groups per Email | (configured) | Hard cap on how many error groups a single email lists. |

### Privacy

| Setting | Default | Description |
|---|---|---|
| Store Client IP | Yes | When off, no IP address is stored on error events. |
| Anonymise IP | No | Masks the last octet (IPv4) or last 80 bits (IPv6) before storing. Recommended for GDPR. |

### Data Retention

| Setting | Default | Description |
|---|---|---|
| Keep Individual Events (days) | (configured) | Occurrence rows older than this are deleted by the daily cleanup cron. |
| Keep Resolved Groups (days) | (configured) | Resolved error groups not seen for this many days are removed entirely. |

---

## How It Works

1. **PHP errors**: A Monolog handler is registered on Magento's system logger. When an exception fires, the handler fingerprints the message (normalising numbers, IDs, and paths), looks up or creates a group row in `panth_error_group`, and saves the occurrence in `panth_error_event`.
2. **JavaScript errors**: A small, CSP-safe script is injected into storefront pages. When an uncaught error or promise rejection fires in the visitor's browser, it posts to the module's endpoint. The server validates the request (same-origin, rate limit, body size), fingerprints the error, and stores it the same way as PHP errors.
3. **Coalescing**: For PHP errors, occurrences of the same group within the coalesce window are counted in cache and folded into one database write per window. This protects the database during high-frequency exceptions.
4. **Email**: A daily cron job checks for error groups that have not been emailed today and are at or above the email severity threshold. It sends one digest email listing those groups, up to the per-email cap.
5. **Cleanup**: A second daily cron removes old `panth_error_event` rows and resolved `panth_error_group` rows past the retention period. You can also trigger cleanup manually with `bin/magento panth:errormonitor:cleanup`.

---

## Admin Error Grid

Open **Admin -> Panth Infotech -> Error Monitor -> Error Log**.

The grid shows:

- **Error type** (exception class or JS error name) and a short message preview.
- **Source** (PHP or JS) and **severity** (emergency, alert, critical, error, warning, etc.).
- **Occurrence count** and **first seen / last seen** timestamps.
- **Status** (New, Resolved, Ignored).

From the grid you can:

- **View the full detail** including the complete stack trace, the URL where the error occurred, referer, user agent, and client IP.
- **Resolve or Ignore** individual errors or in bulk using mass actions.
- **Delete** errors you no longer need.

---

## FAQ

### Does this work on Hyva themes?

Yes. PHP error capture is server-side and works on any theme. The JavaScript collector is injected into storefront pages regardless of theme, so both Hyva and Luma stores capture JS errors.

### Will it flood my database if a single exception fires on every request?

No. The coalesce window batches high-frequency repeats in cache and writes at most one database row per error group per window (default 60 seconds). The occurrence count stays accurate; only the number of writes is reduced.

### Can I stop it from capturing certain errors?

Yes. The "Ignore Errors Matching" field in General settings lets you enter substrings (one per line). Any error whose message, file path, error class, or stack trace contains a substring is dropped at capture time, before it reaches the database.

### Will I get one email per error?

No. Each error group is emailed at most once per day, and you can set a hard cap on how many groups appear in a single email. This means even during a bad incident you receive one summary email, not thousands of individual alerts.

### Is client IP stored?

By default, yes. You can turn off IP storage entirely, or enable IP anonymisation to mask the last octet (IPv4) or last 80 bits (IPv6) before storing.

### Does the auto-pause after deploy mean I lose error visibility?

Only for the window you configure (default 5 minutes). The pause is triggered by a change in the mtime of deploy-related files, and it ends automatically. Set the window to 0 to disable it entirely.

### What does the cleanup cron remove?

It removes individual `panth_error_event` occurrence rows older than the configured number of days, and removes `panth_error_group` rows that are in Resolved status and have not been seen for the configured period. Group aggregates for active errors are kept indefinitely until you resolve or delete them.

### Does it need Panth Core?

Yes. `mage2kishan/module-core` is a free, required dependency that Composer installs for you automatically.

### Can I run cleanup manually?

Yes. Run `bin/magento panth:errormonitor:cleanup` from the command line to trigger a cleanup run outside the normal cron schedule.

---

## Support

| Channel | Contact |
|---|---|
| Product Page | [kishansavaliya.com/magento-2-error-monitor.html](https://kishansavaliya.com/magento-2-error-monitor.html) |
| Email | kishansavaliyakb@gmail.com |
| Website | [kishansavaliya.com](https://kishansavaliya.com) |
| WhatsApp | +91 84012 70422 |
| GitHub Issues | [github.com/mage2sk/module-error-monitor/issues](https://github.com/mage2sk/module-error-monitor/issues) |
| Upwork (Top Rated Plus) | [Hire Kishan Savaliya](https://www.upwork.com/freelancers/~016dd1767321100e21) |
| Upwork Agency | [Panth Infotech](https://www.upwork.com/agencies/1881421506131960778/) |

Response time: 1-2 business days.

### Need Custom Magento Development?

Looking for **custom Magento module development**, **Hyva theme work**, **store migrations**, or **performance tuning**? Get a free quote in 24 hours:

<p align="center">
  <a href="https://kishansavaliya.com/get-quote">
    <img src="https://img.shields.io/badge/%F0%9F%92%AC%20Get%20a%20Free%20Quote-kishansavaliya.com%2Fget--quote-DC2626?style=for-the-badge" alt="Get a Free Quote" />
  </a>
</p>

<p align="center">
  <a href="https://www.upwork.com/freelancers/~016dd1767321100e21">
    <img src="https://img.shields.io/badge/Hire%20Kishan-Top%20Rated%20Plus-14a800?style=for-the-badge&logo=upwork&logoColor=white" alt="Hire on Upwork" />
  </a>
  &nbsp;&nbsp;
  <a href="https://www.upwork.com/agencies/1881421506131960778/">
    <img src="https://img.shields.io/badge/Visit-Panth%20Infotech%20Agency-14a800?style=for-the-badge&logo=upwork&logoColor=white" alt="Visit Agency" />
  </a>
  &nbsp;&nbsp;
  <a href="https://kishansavaliya.com/magento-2-error-monitor.html">
    <img src="https://img.shields.io/badge/View%20Product%20Page-magento--2--error--monitor-0D9488?style=for-the-badge" alt="View Product Page" />
  </a>
</p>

---

## About Panth Infotech

Built and maintained by **Kishan Savaliya** ([kishansavaliya.com](https://kishansavaliya.com)), a **Top Rated Plus** Magento developer on Upwork with 10+ years of eCommerce experience.

**Panth Infotech** is a Magento 2 development agency that builds high quality, security focused extensions and themes for both Hyva and Luma storefronts. The extension suite covers SEO, performance, checkout, product presentation, customer engagement, and store management, with each module built to MEQP standards and tested across Magento 2.4.4 to 2.4.8.

Browse the full extension catalog on our [Magento extensions page](https://kishansavaliya.com/magento-extensions.html) or on [Packagist](https://packagist.org/packages/mage2kishan/).

---

## Quick Links

| Resource | Link |
|---|---|
| **Product Page** | [magento-2-error-monitor.html](https://kishansavaliya.com/magento-2-error-monitor.html) |
| **Packagist** | [mage2kishan/module-error-monitor](https://packagist.org/packages/mage2kishan/module-error-monitor) |
| **GitHub** | [mage2sk/module-error-monitor](https://github.com/mage2sk/module-error-monitor) |
| **Website** | [kishansavaliya.com](https://kishansavaliya.com) |
| **Free Quote** | [kishansavaliya.com/get-quote](https://kishansavaliya.com/get-quote) |
| **Upwork (Top Rated Plus)** | [Hire Kishan Savaliya](https://www.upwork.com/freelancers/~016dd1767321100e21) |
| **Upwork Agency** | [Panth Infotech](https://www.upwork.com/agencies/1881421506131960778/) |
| **Email** | kishansavaliyakb@gmail.com |
| **WhatsApp** | +91 84012 70422 |

---

<p align="center">
  <strong>Stay ahead of store errors before customers notice them.</strong><br/>
  <a href="https://kishansavaliya.com/magento-2-error-monitor.html">
    <img src="https://img.shields.io/badge/See%20Error%20Monitor%20%E2%86%92-Product%20Page%20%26%20Details-DC2626?style=for-the-badge" alt="See Error Monitor" />
  </a>
</p>

---

**SEO Keywords:** magento 2 error monitor, magento 2 error tracking, magento 2 php error logging, magento 2 javascript error capture, magento 2 error notification, magento 2 exception monitoring, magento 2 error alert email, magento 2 admin error grid, hyva error monitor, magento 2 error management extension, magento 2 error log module, magento 2 error reporting, magento 2 error digest email, magento 2 js error tracking, magento 2 storefront error capture, magento 2 error deduplication, magento 2 error grouping, magento 2 gdpr error logging, magento 2 ip anonymisation errors, mage2kishan error monitor, panth error monitor, panth infotech, hire magento developer, top rated plus upwork, kishan savaliya magento, custom magento development
