<?php
/**
 * Active visitors: how many different addresses reached the site in the last minute.
 *
 * Replaces the old "Connections" count, which listed open sockets on port 80
 * and dropped Cloudflare's addresses. HTTPS traffic arrives on 443, and on a
 * site behind a proxy or CDN every socket belongs to the proxy, so that count
 * was 0 on most real sites. A visitor's own address can only be seen in two
 * places a plugin can reach on any host:
 *
 *  1. The web server's access log (nginx, Apache, LiteSpeed), when PHP can
 *     read it and it records the visitor's address rather than the proxy's.
 *     It sees every request, cached pages included.
 *  2. The requests WordPress itself handles. Works on every host, but pages
 *     served from a page cache never reach PHP, so a cached site shows fewer.
 *
 * The access log is used when one is found and usable; otherwise WordPress
 * requests are counted. Neither needs shell access. Which one is in use is
 * re-checked hourly and shown under the count and on the Settings tab.
 *
 * @package MMI_RTSM
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Active_Visitors {

    /** Seconds counted as "now". */
    const WINDOW = 60;

    /** Autoloaded option: which source is in use, so a page request knows whether to count itself without a query. */
    const STATE_OPTION = 'rtsm_visitor_source';

    /** Non-autoloaded option holding counted request addresses (used only without a persistent object cache). */
    const TRACK_OPTION = 'rtsm_visitor_requests';

    /** Settings key for an access log path the owner sets by hand. */
    const PATH_SETTING = 'rtsm_access_log_path';

    const CACHE_GROUP    = 'rtsm';
    const RECHECK        = 3600;     // re-detect the source hourly
    const CHUNK          = 65536;    // read the log backwards in 64 KB steps
    const MAX_READ       = 4194304;  // never read more than 4 MB of log per count
    const PROBE_READ     = 262144;   // 256 KB is enough to recognise a log
    const PROBE_WINDOW   = 900;      // judge proxy share over the last 15 minutes
    const MAX_TRACKED    = 300;      // addresses kept when counting requests
    const TRACK_THROTTLE = 15;       // seconds before the same address is written again
    const MAX_DETAILS    = 20;

    /** "IP [ident user] [time]" — combined/common log format, with an optional leading vhost field. */
    const LINE_RE = '/^(\S+)\s+(\S+).*?\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]/';

    /** User agent: the quoted field after status, bytes and referer. */
    const UA_RE = '/"\s+\d{3}\s+\S+\s+"[^"]*"\s+"([^"]*)"/';

    /**
     * Register the request counter. It only does work while the request
     * source is in use; with a usable access log it returns at once.
     */
    public static function boot() {
        add_action('init', [__CLASS__, 'track_request'], 99);
    }

    /* ── Public API ─────────────────────────────────────────────────── */

    /**
     * Visitors in the last minute.
     *
     * @return array{count:int, details:array, bots:int, source:string, scope:string, window:int, note:string, explain:string}
     */
    public static function get() {
        $state = self::state();

        if ($state['source'] === 'access_log') {
            $read = self::read_logs($state['paths'], $state['self'], self::WINDOW, self::MAX_READ);
            if ($read['opened'] === 0) {
                // The saved log can't be opened here (moved, or saved before
                // a permission change): detect again for this context.
                $state = self::save_state(self::detect());
                $read = $state['source'] === 'access_log'
                    ? self::read_logs($state['paths'], $state['self'], self::WINDOW, self::MAX_READ)
                    : null;
            }
            if ($read !== null && $read['proxied']) {
                // The log stopped carrying visitor addresses (a proxy was put
                // in front since the last check) — count requests instead.
                $state = self::save_state(self::request_state('proxied', $state['self']));
            } elseif ($read !== null) {
                return self::result($read['ips'], 'access_log', $state);
            }
        }

        return self::result(self::tracked_ips(), 'requests', $state);
    }

    /**
     * Current source as stored, for the Settings tab.
     *
     * @return array
     */
    public static function describe() {
        $state = self::state();
        $r = self::result([], $state['source'], $state);
        return [
            'source'  => $state['source'],
            'paths'   => $state['paths'],
            'scope'   => $state['scope'],
            'note'    => $r['note'],
            'explain' => $r['explain'],
        ];
    }

    /** Forget the detected source so the next count detects it again (after Settings change). */
    public static function reset() {
        delete_option(self::STATE_OPTION);
        delete_transient('rtsm_stat_connections');
        delete_transient('rtsm_snapshot');
    }

    /**
     * Validate an access log path an owner typed in Settings.
     *
     * @param string $path
     * @return string '' when usable, otherwise why not.
     */
    public static function check_path($path) {
        $path = (string) $path;
        if ($path === '' || strpos($path, "\0") !== false) {
            return __('No file given.', 'mmi-rtsm');
        }
        if (!self::readable_logs([$path])) {
            return __('WordPress cannot read that file. It may not exist, or the web server user may not have permission to read it.', 'mmi-rtsm');
        }
        $probe = self::read_log($path, self::own_addresses(), self::PROBE_WINDOW, self::PROBE_READ);
        if ($probe['parsed'] === 0) {
            return __('That file does not look like a web server access log.', 'mmi-rtsm');
        }
        if ($probe['proxied']) {
            return __('That log records your proxy or CDN\'s addresses, not your visitors\'.', 'mmi-rtsm');
        }
        return '';
    }

    /* ── Request counter (works on every host) ──────────────────────── */

    /**
     * Note this request's visitor address while the request source is in use.
     * Writes at most once per address per TRACK_THROTTLE seconds.
     */
    public static function track_request() {
        if ((defined('DOING_CRON') && DOING_CRON) || (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        $state = get_option(self::STATE_OPTION);
        if (!is_array($state) || ($state['source'] ?? '') !== 'requests') {
            return;
        }

        $ip = self::client_ip();
        if ($ip === '') {
            return;
        }
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) wp_unslash($_SERVER['HTTP_USER_AGENT']) : '';
        // Private addresses are counted here (a LAN or local site's visitors
        // are private); only the server talking to itself is left out.
        if (self::is_self($ip, (array) ($state['self'] ?? []), $ua)) {
            return;
        }

        $now = time();
        $map = self::load_tracked();
        if (isset($map[$ip]['t']) && $now - (int) $map[$ip]['t'] < self::TRACK_THROTTLE) {
            return;
        }

        $map[$ip] = ['t' => $now, 'b' => self::bot_name($ua)];
        $cut = $now - self::WINDOW;
        $map = array_filter($map, static function ($row) use ($cut) {
            return is_array($row) && (int) ($row['t'] ?? 0) >= $cut;
        });
        if (count($map) > self::MAX_TRACKED) {
            uasort($map, static function ($a, $b) {
                return (int) $b['t'] <=> (int) $a['t'];
            });
            $map = array_slice($map, 0, self::MAX_TRACKED, true);
        }
        self::save_tracked($map);
    }

    /** @return array<string, array{count:int|null, bot:string}> */
    private static function tracked_ips() {
        $cut = time() - self::WINDOW;
        $ips = [];
        foreach (self::load_tracked() as $ip => $row) {
            if (is_array($row) && (int) ($row['t'] ?? 0) >= $cut) {
                // Writes are throttled, so a request count per address isn't known.
                $ips[(string) $ip] = ['count' => null, 'bot' => (string) ($row['b'] ?? '')];
            }
        }
        return $ips;
    }

    private static function load_tracked() {
        $map = wp_using_ext_object_cache()
            ? wp_cache_get('visitor_requests', self::CACHE_GROUP)
            : get_option(self::TRACK_OPTION, []);
        return is_array($map) ? $map : [];
    }

    private static function save_tracked(array $map) {
        if (wp_using_ext_object_cache()) {
            wp_cache_set('visitor_requests', $map, self::CACHE_GROUP, self::WINDOW * 2);
        } else {
            update_option(self::TRACK_OPTION, $map, false);
        }
    }

    /**
     * The visitor's address for this request. Forwarding headers are honoured
     * only when the connecting peer is a known CDN (filter 'rtsm_trusted_proxies')
     * or a private/loopback address, i.e. a proxy on the same machine or network.
     */
    private static function client_ip() {
        $remote = self::valid_ip(isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
        if ($remote === '') {
            return '';
        }
        if (self::is_trusted_proxy($remote) || !self::is_public($remote)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
                if (empty($_SERVER[$key])) {
                    continue;
                }
                $candidate = self::valid_ip(explode(',', (string) $_SERVER[$key])[0]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
        return $remote;
    }

    /* ── Access log ─────────────────────────────────────────────────── */

    /**
     * Read the last WINDOW seconds of one or more logs and merge them.
     */
    private static function read_logs(array $paths, array $self, $window, $max_read) {
        $merged = ['ips' => [], 'proxied' => false, 'parsed' => 0, 'opened' => 0];
        $countable = 0;
        $proxy = 0;
        foreach ($paths as $path) {
            $r = self::read_log((string) $path, $self, $window, $max_read);
            $merged['parsed'] += $r['parsed'];
            $merged['opened'] += $r['opened'] ? 1 : 0;
            $countable += $r['countable'];
            $proxy += $r['proxy'];
            foreach ($r['ips'] as $ip => $row) {
                if (!isset($merged['ips'][$ip])) {
                    $merged['ips'][$ip] = $row;
                } else {
                    $merged['ips'][$ip]['count'] += $row['count'];
                }
            }
        }
        $merged['proxied'] = self::looks_proxied($countable, $proxy);
        return $merged;
    }

    /**
     * Read one log backwards from its end until lines are older than $window.
     *
     * @return array{parsed:int, countable:int, proxy:int, proxied:bool, ips:array}
     */
    private static function read_log($path, array $self, $window, $max_read) {
        $out = ['parsed' => 0, 'countable' => 0, 'proxy' => 0, 'proxied' => false, 'ips' => [], 'opened' => false];
        $cutoff = time() - (int) $window;

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            $buf = self::shell_tail_window($path, $cutoff, $max_read);
            if ($buf === null) {
                return $out;
            }
            $out['opened'] = true;
            return self::parse_lines($buf, $out, $self, $cutoff);
        }
        $out['opened'] = true;
        $stat = @fstat($fh);
        $pos = $stat ? (int) $stat['size'] : 0;
        $buf = '';
        $read = 0;

        while ($pos > 0 && $read < $max_read) {
            $len = (int) min(self::CHUNK, $pos);
            $pos -= $len;
            if (fseek($fh, $pos) !== 0) {
                break;
            }
            $chunk = fread($fh, $len);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $read += strlen($chunk);
            $buf = $chunk . $buf;

            // Stop once the oldest whole line read is past the window (a few
            // seconds of slack: lines are written when requests finish).
            $t = self::oldest_time($buf);
            if ($t > 0 && $t < $cutoff - 5) {
                break;
            }
        }
        fclose($fh);

        // The first line in the buffer is cut in half unless the file start was reached.
        if ($pos > 0) {
            $nl = strpos($buf, "\n");
            $buf = ($nl === false) ? '' : substr($buf, $nl + 1);
        }

        return self::parse_lines($buf, $out, $self, $cutoff);
    }

    /** Time of the first whole line in a buffer that starts mid-line; 0 if none. */
    private static function oldest_time($buf) {
        $nl = strpos($buf, "\n");
        if ($nl === false) {
            return 0;
        }
        $next = strpos($buf, "\n", $nl + 1);
        $oldest = substr($buf, $nl + 1, $next === false ? null : $next - $nl - 1);
        return preg_match(self::LINE_RE, $oldest, $m) ? self::to_time($m[3]) : 0;
    }

    /**
     * Read the end of a log with `tail` when PHP may not open it (open_basedir
     * on RunCloud and many shared hosts keeps PHP inside the site folder; shell
     * commands aren't bound by it). 256 KB first, the full cap if that wasn't
     * enough to reach back past the window.
     *
     * @return string|null Whole lines, or null when the file can't be read this way.
     */
    private static function shell_tail_window($path, $cutoff, $max_read) {
        if (!self::can_shell() || !function_exists('escapeshellarg')) {
            return null;
        }
        foreach (array_unique([min(self::PROBE_READ, $max_read), $max_read]) as $bytes) {
            $buf = @shell_exec('tail -c ' . (int) $bytes . ' ' . escapeshellarg($path) . ' 2>/dev/null');
            if (!is_string($buf) || $buf === '') {
                return null;
            }
            $short = strlen($buf) < $bytes; // whole file read
            $t = self::oldest_time($buf);
            if ($short || ($t > 0 && $t < $cutoff - 5) || $bytes >= $max_read) {
                if (!$short) {
                    $nl = strpos($buf, "\n");
                    $buf = ($nl === false) ? '' : substr($buf, $nl + 1);
                }
                return $buf;
            }
        }
        return null;
    }

    /** Count the lines of $buf inside the window into $out. */
    private static function parse_lines($buf, array $out, array $self, $cutoff) {
        foreach (explode("\n", $buf) as $line) {
            if ($line === '' || !preg_match(self::LINE_RE, $line, $m)) {
                continue;
            }
            $ip = self::valid_ip($m[1]);
            if ($ip === '') {
                $ip = self::valid_ip($m[2]); // "vhost:port IP …" formats
            }
            $t = self::to_time($m[3]);
            if ($ip === '' || $t === 0) {
                continue;
            }
            $out['parsed']++;
            if ($t < $cutoff) {
                continue;
            }

            $ua = preg_match(self::UA_RE, $line, $u) ? $u[1] : '';
            if (self::is_self($ip, $self, $ua)) {
                continue;
            }
            $out['countable']++;
            if (self::is_trusted_proxy($ip) || !self::is_public($ip)) {
                $out['proxy']++;
                continue;
            }
            if (!isset($out['ips'][$ip])) {
                $out['ips'][$ip] = ['count' => 0, 'bot' => ''];
            }
            $out['ips'][$ip]['count']++;
            if ($out['ips'][$ip]['bot'] === '' && $ua !== '') {
                $out['ips'][$ip]['bot'] = self::bot_name($ua);
            }
        }

        $out['proxied'] = self::looks_proxied($out['countable'], $out['proxy']);
        return $out;
    }

    /** A log where most requests come from proxy or private addresses doesn't carry visitor addresses. */
    private static function looks_proxied($countable, $proxy) {
        return $countable >= 5 && $proxy * 2 > $countable;
    }

    /**
     * Possible access log locations for this site, most specific first.
     * Add to them with the 'rtsm_access_log_paths' filter.
     *
     * @return array{site:string[], server:string[]}
     */
    private static function log_candidates() {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $bare = preg_replace('/^www\./', '', $host);
        $site = [];

        // Walk up from the WordPress folder: hosting panels keep logs beside
        // or above the site's files.
        $dir = rtrim(str_replace('\\', '/', ABSPATH), '/');
        for ($i = 0; $i < 4 && $dir !== '' && $dir !== '/' && $dir !== '.'; $i++) {
            $parent = dirname($dir);
            $name   = basename($dir);
            if (basename($parent) === 'webapps') {
                // RunCloud: ~/webapps/<app> → ~/logs/<server>/<app>_access.log
                $home = dirname($parent);
                foreach (['nginx', 'apache2', 'openlitespeed'] as $srv) {
                    $site[] = "$home/logs/$srv/{$name}_access.log";
                }
            }
            foreach (array_unique([$host, $bare]) as $h) {
                if ($h === '') {
                    continue;
                }
                $site[] = "$dir/access-logs/$h";          // cPanel
                $site[] = "$dir/access-logs/$h-ssl_log";  // cPanel, HTTPS
                $site[] = "$dir/logs/$h.access.log";
                $site[] = "$dir/logs/$h-access.log";
                $site[] = "$dir/logs/{$h}_access.log";
            }
            $site[] = "$dir/logs/access.log";
            $site[] = "$dir/logs/access_log";
            $site[] = "$dir/logs/nginx/access.log";
            $site[] = "$dir/logs/apache/access.log";
            $dir = $parent;
        }

        foreach (array_unique([$host, $bare]) as $h) {
            if ($h === '') {
                continue;
            }
            $site[] = "/var/www/vhosts/system/$h/logs/access_log";     // Plesk
            $site[] = "/var/www/vhosts/system/$h/logs/access_ssl_log"; // Plesk, HTTPS
            $site[] = "/var/log/nginx/$h.access.log";                  // GridPane, many VPS setups
            $site[] = "/var/log/nginx/$h-access.log";
            $site[] = "/var/log/nginx/{$h}_access.log";
            $site[] = "/var/log/nginx/$h.log";
            $site[] = "/var/log/apache2/$h-access.log";
            $site[] = "/var/log/apache2/{$h}_access.log";
            $site[] = "/var/log/apache2/$h.access.log";
            $site[] = "/var/log/httpd/$h-access_log";
            $site[] = "/usr/local/apache/domlogs/$h";                   // cPanel (root-readable on most)
            $site[] = "/usr/local/apache/domlogs/$h-ssl_log";
            $site[] = "/usr/local/lsws/logs/$h.access.log";
        }

        // Shared by every site on the server; used only when nothing site-specific is found.
        $server = [
            '/var/log/nginx/access.log',
            '/var/log/apache2/access.log',
            '/var/log/apache2/other_vhosts_access.log',
            '/var/log/httpd/access_log',
            '/usr/local/lsws/logs/access.log',
        ];

        $paths = (array) apply_filters('rtsm_access_log_paths', ['site' => $site, 'server' => $server], $host);
        return [
            'site'   => array_values(array_unique(array_map('strval', (array) ($paths['site'] ?? [])))),
            'server' => array_values(array_unique(array_map('strval', (array) ($paths['server'] ?? [])))),
        ];
    }

    /* ── Source detection ───────────────────────────────────────────── */

    /** Stored state, re-detected hourly. */
    private static function state() {
        $state = get_option(self::STATE_OPTION);
        $cli = defined('WP_CLI') && WP_CLI;
        if (is_array($state) && isset($state['source'], $state['checked']) && ($cli || time() - (int) $state['checked'] < self::RECHECK)) {
            return $state + ['paths' => [], 'scope' => 'site', 'reason' => '', 'self' => []];
        }
        return self::save_state(self::detect());
    }

    private static function save_state(array $state) {
        $state['checked'] = time();
        // WP-CLI runs without the web server's limits (open_basedir, user), so
        // what it can read says nothing about the dashboard. It reports its own
        // finding without saving it.
        if (defined('WP_CLI') && WP_CLI) {
            return $state + ['since' => time()];
        }
        $previous = get_option(self::STATE_OPTION);
        if (!is_array($previous) || ($previous['source'] ?? '') !== $state['source']) {
            $state['since'] = time();
        } else {
            $state['since'] = (int) ($previous['since'] ?? time());
        }
        update_option(self::STATE_OPTION, $state, true);
        return $state;
    }

    private static function detect() {
        $self = self::own_addresses();

        $configured = self::configured_path();
        if ($configured !== '') {
            if (self::check_path($configured) === '') {
                return ['source' => 'access_log', 'paths' => [$configured], 'scope' => 'site', 'reason' => '', 'self' => $self];
            }
            return self::request_state('configured', $self);
        }

        $proxied = false;
        $candidates = self::log_candidates();
        foreach (['site', 'server'] as $scope) {
            $usable = [];
            foreach (self::readable_logs($candidates[$scope]) as $path => $mtime) {
                // A log untouched for a week belongs to something else (or was rotated away).
                if ($mtime && time() - $mtime > 7 * DAY_IN_SECONDS) {
                    continue;
                }
                $probe = self::read_log($path, $self, self::PROBE_WINDOW, self::PROBE_READ);
                if ($probe['parsed'] === 0) {
                    continue;
                }
                if ($probe['proxied']) {
                    $proxied = true;
                    continue;
                }
                $usable[] = $path;
                if ($scope === 'server') {
                    break; // one shared log is enough
                }
            }
            if ($usable) {
                return ['source' => 'access_log', 'paths' => self::pick_logs($usable), 'scope' => $scope, 'reason' => '', 'self' => $self];
            }
        }

        return self::request_state($proxied ? 'proxied' : 'none_found', $self);
    }

    /**
     * Which of several usable logs to read. Two web servers in a chain
     * (nginx in front of Apache) log the same requests twice, so only the
     * first — the front server, listed first — is read. The exception is an
     * HTTP/HTTPS pair in one folder (cPanel "-ssl_log", Plesk "access_ssl_log"),
     * which hold different requests and are read together.
     *
     * @param string[] $usable
     * @return string[]
     */
    private static function pick_logs(array $usable) {
        $primary = $usable[0];
        $picked  = [$primary];
        $is_ssl  = static function ($path) {
            return stripos(basename($path), 'ssl') !== false;
        };
        foreach (array_slice($usable, 1) as $path) {
            if (dirname($path) === dirname($primary) && $is_ssl($path) !== $is_ssl($primary)) {
                $picked[] = $path;
                break;
            }
        }
        return $picked;
    }

    /**
     * Which of $paths can be read, in order, with their modification times
     * (0 when unknown). PHP is asked first; paths PHP may not see (open_basedir)
     * are checked in one shell call.
     *
     * @param string[] $paths
     * @return array<string, int>
     */
    private static function readable_logs(array $paths) {
        $found = [];
        $blocked = [];
        foreach ($paths as $path) {
            if (@is_file($path) && @is_readable($path)) {
                $found[$path] = (int) @filemtime($path);
            } elseif (self::outside_basedir($path)) {
                $blocked[] = $path;
            }
        }
        if ($blocked && self::can_shell() && function_exists('escapeshellarg')) {
            $cmd = 'for f in ' . implode(' ', array_map('escapeshellarg', $blocked)) . '; do'
                . ' if [ -f "$f" ] && [ -r "$f" ]; then printf \'%s|%s\\n\' "$f" "$(stat -c %Y "$f" 2>/dev/null)"; fi; done';
            $out = @shell_exec($cmd);
            foreach (is_string($out) ? explode("\n", trim($out)) : [] as $row) {
                $bar = strrpos($row, '|');
                if ($bar !== false && in_array(substr($row, 0, $bar), $blocked, true)) {
                    $found[substr($row, 0, $bar)] = (int) substr($row, $bar + 1);
                }
            }
            // keep candidate order
            $order = array_flip($paths);
            uksort($found, static function ($a, $b) use ($order) {
                return $order[$a] <=> $order[$b];
            });
        }
        return $found;
    }

    /** Whether open_basedir keeps PHP away from $path (so only a shell command could read it). */
    private static function outside_basedir($path) {
        $basedir = (string) ini_get('open_basedir');
        if ($basedir === '') {
            return false;
        }
        foreach (explode(PATH_SEPARATOR, $basedir) as $allowed) {
            $allowed = rtrim($allowed, '/');
            if ($allowed !== '' && ($path === $allowed || strpos($path, $allowed . '/') === 0)) {
                return false;
            }
        }
        return true;
    }

    private static function request_state($reason, array $self) {
        return ['source' => 'requests', 'paths' => [], 'scope' => 'site', 'reason' => $reason, 'self' => $self];
    }

    private static function configured_path() {
        if (defined('RTSM_ACCESS_LOG') && is_string(RTSM_ACCESS_LOG)) {
            return trim(RTSM_ACCESS_LOG);
        }
        if (class_exists('RTSM_Settings_Manager')) {
            return trim((string) RTSM_Settings_Manager::get_instance()->get(self::PATH_SETTING, ''));
        }
        return '';
    }

    /** This server's own addresses, so its requests to itself (cron, loopback checks) aren't visitors. */
    private static function own_addresses() {
        $ips = [];
        foreach (['SERVER_ADDR', 'LOCAL_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ips[] = (string) $_SERVER[$key];
            }
        }
        $hostname = function_exists('gethostname') ? gethostname() : false;
        if ($hostname) {
            $resolved = @gethostbynamel($hostname);
            if (is_array($resolved)) {
                $ips = array_merge($ips, $resolved);
            }
        }
        if (self::can_shell()) {
            $out = @shell_exec('hostname -I 2>/dev/null');
            if (is_string($out)) {
                $ips = array_merge($ips, preg_split('/\s+/', trim($out)));
            }
        }
        $ips = array_filter(array_map([__CLASS__, 'valid_ip'], $ips));
        return array_values(array_unique($ips));
    }

    private static function can_shell() {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        return !in_array('shell_exec', $disabled, true);
    }

    /* ── Result ─────────────────────────────────────────────────────── */

    private static function result(array $ips, $source, array $state) {
        uasort($ips, static function ($a, $b) {
            return (int) $b['count'] <=> (int) $a['count'];
        });

        $details = [];
        $bots = 0;
        foreach ($ips as $ip => $row) {
            if ($row['bot'] !== '') {
                $bots++;
            }
            if (count($details) < self::MAX_DETAILS) {
                $details[] = ['ip' => (string) $ip, 'count' => $row['count'], 'bot' => $row['bot']];
            }
        }

        if ($source === 'access_log') {
            $server_wide = ($state['scope'] ?? 'site') === 'server';
            $note = $server_wide
                ? __('Last minute · all sites on this server', 'mmi-rtsm')
                : __('Last minute · from the server log', 'mmi-rtsm');
            $explain = sprintf(
                /* translators: %s: access log file path(s) */
                __('Different visitor addresses in the web server\'s access log (%s) in the last 60 seconds. Includes cached pages and bots.', 'mmi-rtsm'),
                implode(', ', (array) $state['paths'])
            );
            if ($server_wide) {
                $explain .= ' ' . __('This log is shared by every site on the server; set this site\'s own log in Settings to count it alone.', 'mmi-rtsm');
            }
        } else {
            $note = __('Last minute · from WordPress requests', 'mmi-rtsm');
            $why = [
                'none_found' => __('No readable access log was found for this site.', 'mmi-rtsm'),
                'proxied'    => __('The access log records your proxy or CDN\'s addresses, not your visitors\'.', 'mmi-rtsm'),
                'configured' => __('The access log set in Settings could not be used.', 'mmi-rtsm'),
            ];
            $explain = __('Different visitor addresses among the requests WordPress handled in the last 60 seconds. Pages served from a cache never reach WordPress, so a cached site shows fewer visitors than it has.', 'mmi-rtsm');
            $reason = (string) ($state['reason'] ?? '');
            if (isset($why[$reason])) {
                $explain .= ' ' . $why[$reason];
            }
        }

        return [
            'count'   => count($ips),
            'details' => $details,
            'bots'    => $bots,
            'source'  => $source,
            'scope'   => (string) ($state['scope'] ?? 'site'),
            'window'  => self::WINDOW,
            'note'    => $note,
            'explain' => $explain,
        ];
    }

    /* ── Helpers ────────────────────────────────────────────────────── */

    /** Normalised IP or ''. IPv4-mapped IPv6 ("::ffff:1.2.3.4") becomes plain IPv4. */
    private static function valid_ip($ip) {
        $ip = trim((string) $ip, " \t\n\r\0\x0B[]");
        if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function is_public($ip) {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function is_trusted_proxy($ip) {
        return class_exists('RTSM_Traffic_Logger') && RTSM_Traffic_Logger::is_trusted_proxy($ip);
    }

    /** The server itself: loopback, its own addresses, or WordPress calling its own site. */
    private static function is_self($ip, array $self, $ua = '') {
        if ($ip === '::1' || strpos($ip, '127.') === 0 || in_array($ip, $self, true)) {
            return true;
        }
        if ($ua !== '' && strpos($ua, 'WordPress/') === 0) {
            static $host = null;
            if ($host === null) {
                $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            }
            return $host !== '' && stripos($ua, $host) !== false;
        }
        return false;
    }

    private static function bot_name($ua) {
        $ua = (string) $ua;
        if ($ua === '') {
            return '';
        }
        $name = class_exists('RTSM_Traffic_Logger') ? RTSM_Traffic_Logger::known_crawler($ua) : '';
        if ($name === '' && preg_match('/bot\b|crawl|spider|slurp|scrap/i', $ua)) {
            $name = __('Bot', 'mmi-rtsm');
        }
        return $name;
    }

    private static function to_time($s) {
        static $cache = [];
        if (isset($cache[$s])) {
            return $cache[$s];
        }
        if (count($cache) > 4096) {
            $cache = [];
        }
        $d = DateTime::createFromFormat('d/M/Y:H:i:s O', $s);
        return $cache[$s] = $d ? $d->getTimestamp() : 0;
    }
}
