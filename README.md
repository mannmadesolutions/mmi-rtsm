# MMI Real-Time Server Monitor

Real-time server performance monitoring for WordPress: load average, CPU,
memory, disk, visitors and PHP workers in the admin bar, a dashboard
widget and a tabbed admin page (MannMade → Server Monitor). Included with all
MMI Suite licenses.

**Requires:** WordPress 5.8+, PHP 7.4+ · **License:** GPL-2.0-or-later

## Features

- Live server metrics (admin bar popup, dashboard widget, admin page)
- Visitors: how many different addresses reached the site in the last
  minute, with bots named. Counted from the web server's access log when
  PHP can read it (found automatically for RunCloud, cPanel, Plesk, GridPane
  and common nginx/Apache/LiteSpeed paths, or set in Settings or with
  `define( 'RTSM_ACCESS_LOG', '/path/to/access.log' )`). Otherwise counted
  from the requests WordPress handles, which works on any host but misses
  pages served from a cache. Add log locations with the
  `rtsm_access_log_paths` filter
- Likely bad actors: each visitor is labelled from its last 6 hours of
  requests. **Likely bad**: asked for paths no visitor asks for (`.env`,
  `.git`, phpunit, `wp-config` backups, shells, directory listings), made
  five or more login attempts, or claims to be a search engine's crawler and
  fails the reverse DNS check. **Watch**: scripts, crawlers that name
  themselves, heavy page rates. **Verified**: Google, Bing, Apple, Yandex and
  Baidu crawlers confirmed by reverse DNS. Shown in the admin bar popup and in
  Dashboard → Visitors (15 min to 24 h, filters, every heading sorts), which
  copies the chosen addresses or a Cloudflare rule expression. Nothing is
  blocked automatically. With MMI Google Services installed, the table also
  names each address's network
- Process monitor with safe termination of non-system processes (licensed)
- High-load traffic analysis: which URLs, request types and client IPs were
  active while load was elevated, with an automatic verdict (Diagnostics tab)
- Incident tracking with automatic resolution when load normalises
- Optional auto-maintenance mode at Critical/Emergency load (off by default)
- Fires `rtsm_critical_load`, `rtsm_emergency_mode_activated` and
  `rtsm_emergency_mode_deactivated` actions so other code can react to load
  (for example, by escalating at a CDN). RTSM holds no CDN or Cloudflare
  credentials itself
- Hourly WP-Cron backlog check with an email alert to the site admin

Some features use `shell_exec()` (`ps`, `nproc`, `uptime`, `free`); on hosts
where it is disabled those panels degrade gracefully.

## Security & privacy

**Who can see it.** Every screen and AJAX action requires the
`manage_options` capability plus a WordPress nonce. To grant access to a
custom role instead:

```php
add_filter( 'rtsm_required_capability', function () {
    return 'rtsm_monitor'; // a capability you grant to that role
} );
```

**What is logged.** While server load is elevated, RTSM records each request's
time, path and query string, method, client IP, user agent, referer and the
logged-in user ID to `server-traffic-analysis.log` in the MMI log directory
(`wp-content/mmi-logs/` by default, or `MMI_SHARED_LOG_DIR`). These are
personal data:

- Values of sensitive query parameters (`key`, `token`, `nonce`, `password`,
  `email`, `login`, ...) are replaced with `REDACTED` before writing. Extend
  the list with the `rtsm_sensitive_query_keys` filter.
- Log lines older than 30 days, and rotated backups, are purged daily. Change
  the period with `rtsm_log_retention_days` (return `0` to disable purging).
- Deleting the plugin removes its logs, settings, transients and cron events.
- Make sure your web server does not serve the log directory (nginx ignores
  `.htaccess`; add a `location` deny rule or set `MMI_SHARED_LOG_DIR` to a
  path outside the web root).

**Visitors count.** Reads only the visitor address, time and user agent of
the last minute's lines from the access log; nothing from it is stored.
When counting from WordPress requests instead, each visitor's address is
kept for up to two minutes (in the object cache, or the
`rtsm_visitor_requests` option without one) and then dropped.

**Visitor activity.** To label visitors, each address's request counts,
latest user agent and first suspicious path are kept for 24 hours in the
`{prefix}rtsm_visitor_activity` table, in 5-minute buckets. Reverse DNS is
looked up only for visitors claiming to be a search engine, and the result
kept a day. The "Reports" link opens AbuseIPDB in your browser; RTSM sends
no addresses anywhere itself. Deleting the plugin drops the table.

**Client IP detection.** `CF-Connecting-IP`, `X-Forwarded-For` and
`X-Real-IP` are only trusted when the connecting peer is a published
Cloudflare address. If you run another reverse proxy in front of PHP, add
its address/CIDR:

```php
add_filter( 'rtsm_trusted_proxies', function ( $ranges ) {
    $ranges[] = '127.0.0.1';
    return $ranges;
} );
```

**Audit trail.** When the MMI shared audit log is available, RTSM records
process kills, manual incident resolution, manual cron spawns, license activation/deactivation and settings
changes (key names only), including denied attempts.

Please report security issues privately to the plugin author rather than in
a public issue.

## Development

- `mmi-rtsm.php` — bootstrap, capability/audit helpers, cron health check
- `includes/` — metrics collection, traffic logger, admin UI, license manager
- `includes/mmi-shared/` — vendored MMI shared library (synced; do not edit here)
- `templates/` — admin page, tabs and partials
- `assets/` — CSS/JS (Chart.js is bundled in `assets/vendor/`)

WP-CLI: `wp rtsm stats`
