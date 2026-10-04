# MMI Real-Time Server Monitor

Real-time server performance monitoring for WordPress: load average, CPU,
memory, disk, connections and PHP workers in the admin bar, a dashboard
widget and a tabbed admin page (MannMade → Server Monitor). Included with all
MMI Suite licenses.

**Requires:** WordPress 5.8+, PHP 7.4+ · **License:** GPL-2.0-or-later

## Features

- Live server metrics (admin bar popup, dashboard widget, admin page)
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
process kills, manual incident resolution, Cloudflare Under Attack Mode
on/off, manual cron spawns, license activation/deactivation and settings
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
