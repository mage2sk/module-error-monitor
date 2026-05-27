# Panth Error Monitor — User Guide

## 1. How it works

```
PHP exception ──► Monolog DB handler ─┐
                                       ├─► ErrorRecorder ─► fingerprint ─► panth_error_group (upsert, +1)
Storefront JS  ──► /panth_errormonitor │                                   └─► panth_error_event (detail row)
   error beacon    /js/collect  ───────┘
                                                 │
                  cron every 15 min ──► DispatchNotifications ──► digest / individual email
                  cron daily ─────────► Cleanup (retention)
```

Capture writes to the database **instantly and synchronously is avoided for
email** — alerts are only ever sent by cron, so a burst of errors can never
slow a request or flood mail in real time.

## 2. Configuration reference

**Stores → Configuration → Panth Infotech → Error Monitor**

### General
- **Enable Error Monitor** — master switch.

### PHP Error Capture
- **Capture PHP Exceptions** — on/off.
- **Minimum Severity to Capture** — `error` and above by default. Lower-severity
  log lines are left in `var/log` only.

### JavaScript Error Capture (Storefront)
- **Capture Storefront JS Errors** — injects the collector.
- **Sample Rate (%)** — report only this share of page loads (lower it on
  high-traffic stores).
- **Max Reports per IP per Minute** — server-side rate limit; excess is dropped.
- **Max Report Body Size (KB)** — oversized payloads rejected before parsing.
- **Ignore Messages Containing** — one substring per line; matching errors
  (PHP *and* JS) are dropped. Pre-seeded with common browser noise like
  `Script error.` and `ResizeObserver loop limit exceeded`.

### Email Alerts
- **Send Email Alerts** — off until configured.
- **Delivery Mode** — `Digest` (one email per cron run, recommended) or
  `Individual` (one email per error group).
- **Recipient Email(s)** — comma/newline separated. Invalid addresses skipped.
- **Email Sender Identity** — standard Magento sender.
- **Minimum Severity to Email** — errors below this are stored but not emailed.
- **Max Error Groups per Run** — hard cap per cron run; protects you during
  incident storms.

> **The "email once a day" guarantee:** each error group records the UTC date
> it was last emailed. It will not be emailed again until the next day, even if
> it keeps happening. A *resolved* error that recurs is automatically re-opened
> and becomes eligible again — so you still hear about regressions.

### Privacy
- **Store Client IP** — off = never store an IP.
- **Anonymise IP** — mask the last IPv4 octet / IPv6 host bits (GDPR).

### Data Retention
- **Keep Individual Events (days)** — occurrence rows older than this are pruned.
- **Keep Resolved Groups (days)** — resolved groups not seen for this long are
  deleted entirely (their events cascade away).

## 3. Reviewing errors

**Admin → Panth Infotech → Error Monitor → Error Log**

- Filter by source (PHP/JS), severity, status, date.
- **View** opens the detail page: full aggregate plus the most recent
  occurrences with stack traces, URLs and context.
- **Resolve** marks an error fixed (stops alerts; re-opens on recurrence).
- **Ignore** silences alerts permanently while still counting occurrences.
- **Delete** removes a group and all its occurrences.
- Mass-action versions of all three are in the grid toolbar.

## 4. Cron & CLI

| Job | Schedule | Purpose |
|-----|----------|---------|
| `panth_errormonitor_dispatch_notifications` | every 15 min | build & send the alert/digest |
| `panth_errormonitor_cleanup` | daily 03:27 | enforce retention |
| `panth_errormonitor_send_heartbeat` | daily 04:43 | suite heartbeat (no-op if Panth_Core present) |

```bash
bin/magento panth:errormonitor:cleanup    # prune on demand
```

## 5. Security model (JS endpoint)

The public beacon (`/panth_errormonitor/js/collect`) is deliberately
CSRF-exempt (FPC pages have no fresh form key). It is hardened with layered,
independent defences:

1. POST-only.
2. **Same-origin** — `Origin`/`Referer` host must match a store base URL host.
3. Body-size cap **before** JSON parsing.
4. **Per-IP and global rate limits** (cache-backed fixed window).
5. Strict field validation, length caps, control-character stripping.
6. Ignore-list + server-side dedupe collapse noise.
7. Writes **only** to the error tables — no session/customer/order/auth access.
8. Always responds `204 No Content` — input is **never reflected**, so it
   cannot be abused for reflected XSS.

All stored values are HTML-escaped on output in the admin grid, detail view,
and email.

## 6. Troubleshooting

- **No PHP errors captured** — confirm general + PHP capture are enabled and the
  severity threshold isn't above what you're logging; trigger a test error.
- **No JS errors** — check capture is on, your CDN/theme renders
  `before.body.end`, and the error isn't a cross-origin `Script error.` (these
  are intentionally ignored).
- **No emails** — alerts require recipients **and** the dispatch cron running;
  remember each group is capped to one email per day.
