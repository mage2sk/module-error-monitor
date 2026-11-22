# Changelog

All notable changes to this extension are documented here. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.8.3] - 2026-11-22

### Upgrade notes
- Run `bin/magento setup:upgrade` and `bin/magento cache:flush`. There are no database changes. In production mode also run `bin/magento setup:di:compile`.

### Fixed
- Default mode also generates classes on demand and writes `generated/` on almost every request, so the deploy detection kept capture paused there too. `generated/code` and `generated/metadata` are now only watched in production mode. `panth:errormonitor:status` shows them as "(not watched: <mode>)".
- `panth:errormonitor:status` showed "Auto-pause (deploy detect) YES until ..." from an old flag while auto-pause was off (window 0) and capture was active. It now only shows a time when the auto-pause is in effect.
- `panth:errormonitor:resume` said capture was resumed but left an active deploy auto-pause in place. It now also ends the auto-pause. The deploy baseline is kept, so the same deploy does not pause capture again. If a new deploy is detected while it runs, it says capture is still suspended and why.
- The email subject showed a space before the bracket when the store view name ended with a space, for example `[Main Store ] Error Monitor: ...`. The name is now trimmed.

### Changed
- `panth:errormonitor:status` notes next to "Ecosystem-alert filter on" that `[Panth*] BLOCKED/REJECTED` log lines are not stored. Turn off "Auto-Filter Sibling Module Operational Alerts" to store blocked requests from sibling modules in the Error Log.

## [1.8.2] - 2026-11-22

### Upgrade notes
- Run `bin/magento setup:upgrade` and `bin/magento cache:flush`. There are no database changes. In production mode also run `bin/magento setup:di:compile`.

### Fixed
- Resolve and Ignore in the Error Log row menu opened the action as a plain link and showed a 404 page, because the controllers only accept POST. Both now ask for confirmation and send a POST request with the admin form key, like Delete.
- In developer mode Magento writes `generated/code` and `generated/metadata` on almost every request, so the deploy detection kept capture paused most of the time. In developer mode only `pub/static/deployed_version.txt` is now watched. Production and default mode are unchanged. `panth:errormonitor:status` shows the skipped paths as "(not watched: dev)".

## [1.8.1] - 2026-11-02

### Upgrade notes
- Run `bin/magento setup:upgrade` and `bin/magento cache:flush`. There are no database changes.
- "Trusted Proxy IPs / Networks" is now empty by default. If your store runs behind a local reverse proxy or load balancer (Varnish, nginx, Traefik, a cloud load balancer), add its address or range there, and behind Cloudflare add Cloudflare's ranges (the README lists them). Until you do, the client IP is always `REMOTE_ADDR`.
- If you saved "Trusted Proxy IPs / Networks" in 1.8.0, your saved list is kept. Remove the private and loopback ranges from it unless your own proxy uses them.

### Fixed
- A visitor could choose the client IP that the module filters, stores and rate limits. The 1.8.0 default trusted list included every private and loopback range, so any request that reached PHP from a private address, for example through a local proxy that passes headers through, had its `CF-Connecting-IP` or `X-Forwarded-For` believed. Proxy headers are now only read when the connecting address is in a range you listed yourself.
- One invalid line in an IP list no longer blocks saving the configuration. Invalid lines are listed in a warning, the rest is saved and used, and invalid lines are skipped at runtime, also when the value comes from `config:set`.
- The same exception logged twice in one request is now merged whatever the log level, for example when the plain message is logged as critical. Matching ignores case, spacing, a leading exception class, a trailing file and line and a trailing request URI, several plain lines can wait for their exception at the same time, and each exception takes exactly one plain line, so a second line or a different message is still recorded.
- When the IP filter drops both records of such a pair, the email footer counts them once instead of twice.
- IPv4-mapped IPv6 ranges such as `::ffff:172.18.0.0/112` are accepted in the IP lists.

### Changed
- Text after `#` in the IP lists is a comment.
- The README describes the new trusted proxy default and how the storefront collector picks the client IP.
