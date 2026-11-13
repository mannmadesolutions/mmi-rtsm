<?php
/**
 * Visitor activity over recent hours, and what it says about each address.
 *
 * A single minute is too short to judge a visitor: a scanner or a crawler
 * spread over hundreds of addresses makes one or two requests a minute from
 * each and looks like anyone else. So every address's requests are kept in
 * 5-minute buckets for 24 hours (table {prefix}rtsm_visitor_activity), and a
 * visitor is labelled from what it did over the last few hours:
 *
 *  - Likely bad: asked for paths no visitor asks for (.env, .git, phpunit,
 *    wp-config backups, shells, directory listings under wp-includes or
 *    wp-content), five or more login attempts (POST wp-login.php or
 *    xmlrpc.php), or claims to be a search engine's crawler and isn't
 *    (forward-confirmed reverse DNS fails).
 *  - Watch: a script rather than a browser (curl, python, Go …) or no user
 *    agent, a crawler that names itself (AI and SEO crawlers), or a heavy
 *    page rate.
 *  - Verified: Google, Bing, Apple, Yandex or Baidu, confirmed by reverse DNS.
 *
 * Errors alone are not held against anyone: a site's own broken links send
 * crawlers to 404s too.
 *
 * Fed from the access log (new bytes since the last read, at most every 30 s,
 * whenever the count is shown) or, without a usable log, from the requests
 * WordPress handles (RTSM_Active_Visitors::track_request).
 *
 * @package MMI_RTSM
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTSM_Visitor_Profiles {

    const BUCKET       = 300;                  // seconds per stored bucket
    const KEEP         = DAY_IN_SECONDS;       // history kept
    const JUDGE        = 6 * HOUR_IN_SECONDS;  // history a label is based on
    const DB_VERSION   = 1;
    const DB_OPTION    = 'rtsm_visitor_db';
    const CURSOR       = 'rtsm_visitor_cursor';    // { path: { ino, off } , _at: last read }
    const CHECKS       = 'rtsm_crawler_checks';    // { ip: [ ok(1|-1), host, at ] }
    const LOCK         = 'rtsm_visitor_refresh_lock';
    const EVERY        = 30;                   // seconds between log reads
    const READ_CAP     = 8388608;              // 8 MB of new log per read
    const FIRST_READ   = 12582912;             // 12 MB of history on the first read
    const VERIFY_MAX   = 40;                   // reverse DNS checks per read …
    const VERIFY_SECS  = 3;                    // … within this many seconds
    const LOGIN_BAD    = 5;                    // login attempts that make an address likely bad

    /** Paths no visitor asks for. */
    const PROBE = '#(?:^|/)\.(?:env|git|svn|hg|aws|ssh|htpasswd|DS_Store)(?:[/?.]|$)'
        . '|wp-config\.php[.~_-]|wp-config\.(?:bak|old|orig|save|txt|zip)'
        . '|phpmyadmin|/pma/|adminer\.php|/vendor/phpunit|eval-stdin|/cgi-bin/'
        . '|\.(?:sql|bak|swp)(?:\?|$)'
        . '|/(?:backup|backups|db|database|dump|site|www|wordpress|public_html)\.(?:zip|tar|tgz|gz|rar|7z)(?:\?|$)'
        . '|/(?:shell|cmd|c99|r57|wso|alfa[\w-]*|up|uploader|xx|wp-info|phpinfo|info)\.php(?:\?|$)'
        . '|^/wp-admin/(?:setup-config|install)\.php'
        . '|^/wp-(?:includes|content)/(?:[^?]*/)?(?:\?|$)'
        . '|^/wp-content/plugins/[^/]+/readme\.txt'
        . '|/server-status|/actuator/#i';

    const ASSET  = '#\.(?:js|css|png|jpe?g|webp|avif|gif|svg|woff2?|ttf|eot|ico|map|mp4|webm)(?:\?|$)#i';
    const NONPAGE = '#^/(?:wp-admin/|wp-json/|wp-cron\.php|\?rest_route=)|admin-ajax\.php#';
    const LOGIN  = '#^/(?:wp-login|xmlrpc)\.php#';
    const SCRIPT = '#^(?:-?$|curl|wget|python|go-http-client|okhttp|axios|node-fetch|undici|scrapy|java/|libwww|php/|guzzlehttp|ruby|perl|masscan|zgrab|nuclei|nikto|sqlmap|httpie|postmanruntime|apache-httpclient|aiohttp|http\.rb|fasthttp|colly)#i';
    const LINE   = '/^(\S+)\s+(\S+).*?\[(\d{2}\/[A-Za-z]{3}\/\d{4}:\d{2}:\d{2}:\d{2} [+-]\d{4})\]\s+"(\S+)\s+(\S+)[^"]*"\s+(\d{3})\s+\S+(?:\s+"[^"]*"\s+"([^"]*)")?/';

    /** Crawlers that can be confirmed by reverse DNS: UA token → host suffixes. */
    const CLAIMS = [
        'Google' => ['re' => '/googlebot|google-inspectiontool|googleother|adsbot-google|mediapartners-google|storebot-google/i', 'hosts' => ['.googlebot.com', '.google.com']],
        'Bing'   => ['re' => '/bingbot|bingpreview|msnbot|adidxbot/i', 'hosts' => ['.search.msn.com']],
        'Apple'  => ['re' => '/applebot/i', 'hosts' => ['.applebot.apple.com']],
        'Yandex' => ['re' => '/yandex(?:bot|images|mobilebot)/i', 'hosts' => ['.yandex.ru', '.yandex.net', '.yandex.com']],
        'Baidu'  => ['re' => '/baiduspider/i', 'hosts' => ['.baidu.com', '.baidu.jp']],
    ];

    const WINDOWS  = [900, 3600, 21600, 86400]; // table views: 15 min, 1 h, 6 h, 24 h
    const ROWS_MAX = 1000;                      // rows sent to the table
    const LEVELS   = ['all', 'bad', 'watch', 'verified', 'none'];

    public static function boot() {
        add_action('wp_ajax_rtsm_get_visitors', [__CLASS__, 'ajax_table']);
        add_action('rtsm_visitor_networks', [__CLASS__, 'lookup_networks']);
    }

    /* ── Admin table ────────────────────────────────────────────────── */

    /** Rows for the Dashboard tab's Visitors table. */
    public static function ajax_table() {
        if (!wp_verify_nonce(isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '', 'rtsm_nonce')) {
            wp_send_json_error(['message' => 'Security verification failed. Please refresh the page.', 'code' => 'nonce_verification_failed'], 403);
        }
        if (!rtsm_user_can()) {
            wp_send_json_error(['message' => 'Insufficient permissions', 'code' => 'forbidden'], 403);
        }
        $window = isset($_POST['window']) ? (int) $_POST['window'] : 3600;
        $window = in_array($window, self::WINDOWS, true) ? $window : 3600;
        $level  = isset($_POST['level']) ? sanitize_key(wp_unslash($_POST['level'])) : 'all';
        $level  = in_array($level, self::LEVELS, true) ? $level : 'all';

        RTSM_Active_Visitors::refresh_activity();
        $all = self::profiles(null, $window, 20000);

        $counts = array_fill_keys(self::LEVELS, 0);
        $counts['all'] = count($all);
        foreach ($all as $row) {
            $counts[$row['level'] !== '' ? $row['level'] : 'none']++;
        }
        $rank = ['bad' => 0, 'watch' => 1, '' => 2, 'verified' => 3];
        $rows = array_filter($all, static function ($row) use ($level) {
            return $level === 'all' || ($row['level'] !== '' ? $row['level'] : 'none') === $level;
        });
        uasort($rows, static function ($a, $b) use ($rank) {
            return ($rank[$a['level']] <=> $rank[$b['level']]) ?: ($b['req'] <=> $a['req']);
        });
        $matched = count($rows);
        $rows = array_slice($rows, 0, self::ROWS_MAX, true);

        $networks = self::networks_for(array_keys($rows), $all);
        $now = time();
        $out = [];
        foreach ($rows as $ip => $row) {
            $out[] = [
                'ip'      => (string) $ip,
                'level'   => $row['level'],
                'label'   => $row['label'],
                'reasons' => $row['reasons'],
                'req'     => $row['req'],
                'pages'   => $row['pages'],
                'errors'  => $row['errors'],
                'probes'  => $row['probes'],
                'logins'  => $row['logins'],
                'ago'     => max(0, $now - $row['last_ts']),
                'ua'      => $row['ua'],
                'network' => $networks[$ip] ?? null,
            ];
        }
        $source = RTSM_Active_Visitors::describe();
        wp_send_json_success([
            'rows'      => $out,
            'counts'    => $counts,
            'matched'   => $matched,
            'window'    => $window,
            'networks'  => $networks !== null && self::gs_available(),
            'source'    => $source['source'],
            'explain'   => $source['source'] === 'access_log'
                ? __('From the web server\'s access log. Labels come from each address\'s last 6 hours.', 'mmi-rtsm')
                : __('From the requests WordPress handled (pages served from a cache are not seen, and request counts are approximate). Labels come from each address\'s last 6 hours.', 'mmi-rtsm'),
        ]);
    }

    /* ── Networks (from MMI Google Services, when installed) ────────── */

    private static function gs_available() {
        return class_exists('MMI_GS_Bot_Networks') && method_exists('MMI_GS_Bot_Networks', 'cached');
    }

    /**
     * Network names Google Services already knows for $ips; addresses with a
     * label but no network yet are looked up in the background (WP-Cron, at
     * most every 10 minutes) through Google Services' own free lookup.
     *
     * @return array<string, array>|null null when Google Services isn't there.
     */
    private static function networks_for(array $ips, array $all) {
        if (!self::gs_available() || !$ips) {
            return null;
        }
        $known = MMI_GS_Bot_Networks::cached(array_map('strval', $ips));
        $missing = [];
        foreach ($ips as $ip) {
            if (!isset($known[$ip]) && ($all[$ip]['level'] ?? '') !== '') {
                $missing[] = (string) $ip;
            }
        }
        if ($missing && !get_transient('rtsm_visitor_networks_asked') && !wp_next_scheduled('rtsm_visitor_networks')) {
            set_transient('rtsm_visitor_networks_asked', 1, 10 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time() + 5, 'rtsm_visitor_networks', [array_slice($missing, 0, 300)]);
        }
        return $known;
    }

    /** WP-Cron: have Google Services look up these addresses' networks. */
    public static function lookup_networks($ips) {
        if (self::gs_available() && method_exists('MMI_GS_Bot_Networks', 'networks')) {
            MMI_GS_Bot_Networks::networks(array_map('strval', (array) $ips));
        }
    }

    /* ── Storage ────────────────────────────────────────────────────── */

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'rtsm_visitor_activity';
    }

    /** Create or update the activity table. Cheap to call: checks an autoloaded option first. */
    public static function install() {
        if ((int) get_option(self::DB_OPTION, 0) >= self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();
        dbDelta("CREATE TABLE {$table} (
            ip varchar(45) NOT NULL,
            bucket int(10) unsigned NOT NULL,
            req int(10) unsigned NOT NULL DEFAULT 0,
            pages int(10) unsigned NOT NULL DEFAULT 0,
            errors int(10) unsigned NOT NULL DEFAULT 0,
            probes smallint(5) unsigned NOT NULL DEFAULT 0,
            logins smallint(5) unsigned NOT NULL DEFAULT 0,
            first_ts int(10) unsigned NOT NULL DEFAULT 0,
            last_ts int(10) unsigned NOT NULL DEFAULT 0,
            ua varchar(255) NOT NULL DEFAULT '',
            probe_path varchar(191) NOT NULL DEFAULT '',
            PRIMARY KEY  (ip,bucket),
            KEY bucket (bucket)
        ) {$charset};");
        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    /**
     * Add rows: { "ip|bucket": [ip, bucket, req, pages, errors, probes, logins, first, last, ua, probe] }.
     */
    private static function write(array $rows) {
        if (!$rows) {
            return;
        }
        global $wpdb;
        $table = self::table();
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            $values = [];
            $args   = [];
            foreach ($chunk as $r) {
                $values[] = '(%s,%d,%d,%d,%d,%d,%d,%d,%d,%s,%s)';
                array_push($args, $r[0], $r[1], $r[2], $r[3], $r[4], min(65535, $r[5]), min(65535, $r[6]), $r[7], $r[8], substr($r[9], 0, 255), substr($r[10], 0, 191));
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (ip,bucket,req,pages,errors,probes,logins,first_ts,last_ts,ua,probe_path) VALUES "
                . implode(',', $values)
                . ' ON DUPLICATE KEY UPDATE req=req+VALUES(req), pages=pages+VALUES(pages), errors=errors+VALUES(errors),'
                . ' probes=LEAST(65535,probes+VALUES(probes)), logins=LEAST(65535,logins+VALUES(logins)),'
                . ' first_ts=LEAST(first_ts,VALUES(first_ts)), last_ts=GREATEST(last_ts,VALUES(last_ts)),'
                . " ua=IF(VALUES(ua)<>'',VALUES(ua),ua), probe_path=IF(probe_path='',VALUES(probe_path),probe_path)",
                $args
            ));
        }
    }

    /** Drop history older than KEEP, at most hourly. */
    public static function maintain() {
        $last = (int) MMI_Settings::get('rtsm_visitor_pruned', 0);
        if ($last > time() - HOUR_IN_SECONDS || (int) get_option(self::DB_OPTION, 0) < self::DB_VERSION) {
            return;
        }
        MMI_Settings::set('rtsm_visitor_pruned', time());
        self::prune();
    }

    private static function prune() {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE bucket < %d", (int) floor((time() - self::KEEP) / self::BUCKET)));
    }

    /* ── Feeding from the access log ────────────────────────────────── */

    /**
     * Read what the log gained since the last read, at most every EVERY
     * seconds and never two at once.
     *
     * @param string[] $paths Logs in use (RTSM_Active_Visitors state).
     * @param string[] $self  The server's own addresses.
     */
    public static function refresh(array $paths, array $self) {
        $cursor = get_option(self::CURSOR, []);
        $cursor = is_array($cursor) ? $cursor : [];
        if (time() - (int) ($cursor['_at'] ?? 0) < self::EVERY || get_transient(self::LOCK)) {
            return;
        }
        set_transient(self::LOCK, 1, 120);
        self::install();

        $rows = [];
        $claims = [];
        foreach ($paths as $path) {
            $path = (string) $path;
            $at   = isset($cursor[$path]) && is_array($cursor[$path]) ? $cursor[$path] : null;
            [$chunk, $next] = self::read_new($path, $at);
            if ($next !== null) {
                $cursor[$path] = $next;
            }
            if ($chunk !== '') {
                self::aggregate($chunk, $self, $rows, $claims);
            }
        }
        self::write($rows);

        $cursor['_at'] = time();
        update_option(self::CURSOR, $cursor, false);
        self::maintain();

        self::verify_claims($claims);
        delete_transient(self::LOCK);
    }

    /**
     * New whole lines since $at, and the cursor to store.
     *
     * @return array{0:string, 1:array|null}
     */
    private static function read_new($path, $at) {
        [$ino, $size] = self::file_id($path);
        if ($size === null) {
            return ['', null];
        }
        $first = !$at || (int) $at['ino'] !== $ino || (int) $at['off'] > $size; // new or rotated
        $off   = $first ? max(0, $size - self::FIRST_READ) : (int) $at['off'];
        if ($size - $off > self::READ_CAP && !$first) {
            $off = $size - self::READ_CAP; // far behind: keep the recent part
        }
        $want = min($size - $off, $first ? self::FIRST_READ : self::READ_CAP);
        if ($want <= 0) {
            return ['', ['ino' => $ino, 'off' => $off]];
        }

        $buf = self::read_range($path, $off, $want);
        if ($buf === null) {
            return ['', null];
        }
        // A read starting mid-file may begin mid-line; it always ends at the last whole line.
        $start = 0;
        if ($off > 0 && $first) {
            $nl = strpos($buf, "\n");
            $start = ($nl === false) ? strlen($buf) : $nl + 1;
        }
        $end = strrpos($buf, "\n");
        $end = ($end === false || $end < $start) ? $start : $end + 1;
        return [substr($buf, $start, $end - $start), ['ino' => $ino, 'off' => $off + $end]];
    }

    /** [inode, size] of a file, through PHP or a shell command when open_basedir hides it. */
    private static function file_id($path) {
        $fh = @fopen($path, 'rb');
        if ($fh) {
            $st = fstat($fh);
            fclose($fh);
            return [(int) ($st['ino'] ?? 0), (int) ($st['size'] ?? 0)];
        }
        if (!RTSM_Active_Visitors::can_shell()) {
            return [0, null];
        }
        $p = escapeshellarg($path);
        $out = @shell_exec("stat -c '%i %s' $p 2>/dev/null || stat -f '%i %z' $p 2>/dev/null");
        if (is_string($out) && preg_match('/^(\d+)\s+(\d+)/', trim($out), $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        return [0, null];
    }

    private static function read_range($path, $off, $len) {
        $fh = @fopen($path, 'rb');
        if ($fh) {
            fseek($fh, $off);
            $buf = (string) fread($fh, $len);
            fclose($fh);
            return $buf;
        }
        if (!RTSM_Active_Visitors::can_shell()) {
            return null;
        }
        $buf = @shell_exec('tail -c +' . ((int) $off + 1) . ' ' . escapeshellarg($path) . ' 2>/dev/null | head -c ' . (int) $len);
        return is_string($buf) ? $buf : null;
    }

    /** Fold log lines into 5-minute rows per address. */
    private static function aggregate($chunk, array $self, array &$rows, array &$claims) {
        foreach (explode("\n", $chunk) as $line) {
            if ($line === '' || !preg_match(self::LINE, $line, $m)) {
                continue;
            }
            $ip = RTSM_Active_Visitors::valid_ip($m[1]);
            if ($ip === '') {
                $ip = RTSM_Active_Visitors::valid_ip($m[2]);
            }
            $t = RTSM_Active_Visitors::to_time($m[3]);
            if ($ip === '' || $t === 0) {
                continue;
            }
            $ua = $m[7] ?? '';
            if (!RTSM_Active_Visitors::counts_as_visitor($ip, $self, $ua)) {
                continue;
            }
            self::add($rows, $ip, $t, strtoupper($m[4]), $m[5], (int) $m[6], $ua);
            if ($ua !== '' && self::claim($ua) !== '') {
                $claims[$ip] = $ua;
            }
        }
    }

    /** One request into the rows being built. */
    private static function add(array &$rows, $ip, $t, $method, $path, $status, $ua) {
        $bucket = (int) floor($t / self::BUCKET);
        $key    = $ip . '|' . $bucket;
        if (!isset($rows[$key])) {
            $rows[$key] = [$ip, $bucket, 0, 0, 0, 0, 0, $t, $t, '', ''];
        }
        $r = &$rows[$key];
        $r[2]++;
        $is_probe = (bool) preg_match(self::PROBE, $path);
        if (!$is_probe && !preg_match(self::ASSET, $path) && !preg_match(self::NONPAGE, $path)) {
            $r[3]++;
        }
        if ($status >= 400) {
            $r[4]++;
        }
        if ($is_probe) {
            $r[5]++;
            if ($r[10] === '') {
                $r[10] = $path;
            }
        }
        if ($method === 'POST' && preg_match(self::LOGIN, $path)) {
            $r[6]++;
        }
        $r[7] = min($r[7], $t);
        $r[8] = max($r[8], $t);
        if ($ua !== '') {
            $r[9] = $ua;
        }
    }

    /* ── Feeding from WordPress requests (no usable log) ────────────── */

    /**
     * Record one request WordPress is handling. Called by
     * RTSM_Active_Visitors::track_request for the first request from an
     * address in each throttle period, and for every request that matters
     * (a probe path or a login attempt), so counts are approximate.
     */
    public static function record_request($ip, $ua, $method, $path) {
        self::install();
        $rows = [];
        self::add($rows, $ip, time(), $method, $path, 200, $ua);
        self::write($rows);
    }

    /** Whether a request must be recorded even inside the throttle period. */
    public static function request_matters($method, $path) {
        return preg_match(self::PROBE, $path) || ($method === 'POST' && preg_match(self::LOGIN, $path));
    }

    /* ── Reading and judging ────────────────────────────────────────── */

    /**
     * Activity per address over the last $seconds, judged.
     *
     * @param string[]|null $ips  Only these addresses (null: every address).
     * @param int           $seconds
     * @param int           $limit Most active first.
     * @return array<string, array> ip → row with level/label/reasons.
     */
    public static function profiles($ips, $seconds, $limit = 5000) {
        if ((int) get_option(self::DB_OPTION, 0) < self::DB_VERSION) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        $from  = (int) floor((time() - $seconds) / self::BUCKET);
        $where = $wpdb->prepare('bucket >= %d', $from);
        if (is_array($ips)) {
            if (!$ips) {
                return [];
            }
            $where .= ' AND ip IN (' . implode(',', array_fill(0, count($ips), '%s')) . ')';
            $where = $wpdb->prepare($where, array_values($ips)); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ip, SUM(req) req, SUM(pages) pages, SUM(errors) errors, SUM(probes) probes, SUM(logins) logins,
                    MIN(first_ts) first_ts, MAX(last_ts) last_ts,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(ua,'') ORDER BY last_ts DESC SEPARATOR '\n'), '\n', 1) ua,
                    SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(probe_path,'') ORDER BY first_ts SEPARATOR '\n'), '\n', 1) probe_path
             FROM {$table} WHERE {$where} GROUP BY ip ORDER BY SUM(req) DESC LIMIT %d",
            (int) $limit
        ), ARRAY_A);

        $checks = self::checks();
        $out = [];
        foreach ((array) $rows as $row) {
            $row['ua'] = (string) $row['ua'];
            $row['probe_path'] = (string) $row['probe_path'];
            foreach (['req', 'pages', 'errors', 'probes', 'logins', 'first_ts', 'last_ts'] as $k) {
                $row[$k] = (int) $row[$k];
            }
            $out[$row['ip']] = $row + self::judge($row, $checks[$row['ip']] ?? null);
        }
        return $out;
    }

    /**
     * Label for one address's activity.
     *
     * @return array{level:string, label:string, reasons:string[]}
     */
    public static function judge(array $row, $check = null) {
        $ua      = (string) ($row['ua'] ?? '');
        $bad     = [];
        $watch   = [];
        $notes   = [];
        $verified = '';

        if ($row['probes'] > 0) {
            /* translators: 1: number of requests, 2: example path */
            $bad[] = sprintf(_n('Asked for a path no visitor asks for: %2$s', 'Asked for %1$d paths no visitor asks for, e.g. %2$s', $row['probes'], 'mmi-rtsm'), $row['probes'], self::short($row['probe_path']));
        }
        if ($row['logins'] >= self::LOGIN_BAD) {
            /* translators: %d: number of login attempts */
            $bad[] = sprintf(__('%d login attempts (wp-login.php or xmlrpc.php)', 'mmi-rtsm'), $row['logins']);
        } elseif ($row['logins'] > 0) {
            /* translators: %d: number of login attempts */
            $notes[] = sprintf(_n('%d login attempt', '%d login attempts', $row['logins'], 'mmi-rtsm'), $row['logins']);
        }

        $claim = self::claim($ua);
        if ($claim !== '') {
            if (is_array($check) && (int) $check[0] === 1) {
                $verified = $claim;
                /* translators: 1: search engine, 2: host name */
                $notes[] = sprintf(__('Confirmed %1$s crawler (%2$s)', 'mmi-rtsm'), $claim, $check[1]);
            } elseif (is_array($check) && (int) $check[0] === -1) {
                /* translators: 1: search engine, 2: what reverse DNS returned */
                $bad[] = sprintf(__('Says it is %1$s\'s crawler, but its address belongs to %2$s', 'mmi-rtsm'), $claim, $check[1] !== '' ? $check[1] : __('no matching host', 'mmi-rtsm'));
            } else {
                /* translators: %s: search engine */
                $watch[] = sprintf(__('Says it is %s\'s crawler; not checked yet', 'mmi-rtsm'), $claim);
            }
        } elseif (preg_match(self::SCRIPT, trim($ua))) {
            $watch[] = trim($ua) === '' || trim($ua) === '-'
                ? __('No user agent: a script, not a browser', 'mmi-rtsm')
                /* translators: %s: user agent */
                : sprintf(__('A script, not a browser (%s)', 'mmi-rtsm'), self::short($ua, 40));
        } elseif (($name = self::crawler_name($ua)) !== '') {
            /* translators: %s: crawler name */
            $watch[] = sprintf(__('Crawler that names itself: %s', 'mmi-rtsm'), $name);
        }

        $minutes = max(1, ($row['last_ts'] - $row['first_ts']) / 60);
        if ($row['pages'] >= 30 && $row['pages'] / $minutes >= 10 && $verified === '') {
            /* translators: 1: pages, 2: minutes */
            $watch[] = sprintf(__('Heavy: %1$d pages in %2$d min', 'mmi-rtsm'), $row['pages'], (int) ceil($minutes));
        }
        if ($row['errors'] > 0) {
            /* translators: 1: errors, 2: requests */
            $notes[] = sprintf(__('%1$d of %2$d requests failed (404s can come from the site\'s own broken links)', 'mmi-rtsm'), $row['errors'], $row['req']);
        }

        if ($bad) {
            return ['level' => 'bad', 'label' => __('Likely bad', 'mmi-rtsm'), 'reasons' => array_merge($bad, $watch, $notes)];
        }
        if ($verified !== '') {
            return ['level' => 'verified', 'label' => $verified . ' ✓', 'reasons' => array_merge($notes, $watch)];
        }
        if ($watch) {
            $name = self::crawler_name($ua);
            $label = $claim !== '' ? sprintf(/* translators: %s: search engine */ __('%s?', 'mmi-rtsm'), $claim)
                : (preg_match(self::SCRIPT, trim($ua)) ? __('Script', 'mmi-rtsm')
                : ($name !== '' ? $name : __('Heavy', 'mmi-rtsm')));
            return ['level' => 'watch', 'label' => $label, 'reasons' => array_merge($watch, $notes)];
        }
        return ['level' => '', 'label' => '', 'reasons' => $notes];
    }

    /** Search engine a user agent claims to be, or ''. */
    public static function claim($ua) {
        foreach (self::CLAIMS as $name => $c) {
            if (preg_match($c['re'], (string) $ua)) {
                return $name;
            }
        }
        return '';
    }

    /** A self-declared crawler's name from its user agent ("ElevenlabsCrawler", "GPTBot"), or ''. */
    public static function crawler_name($ua) {
        $known = class_exists('RTSM_Traffic_Logger') ? RTSM_Traffic_Logger::known_crawler((string) $ua) : '';
        if ($known !== '') {
            return $known;
        }
        return preg_match('/\b([A-Za-z][\w.-]*?(?:bot|crawler|spider|slurp))\b/i', (string) $ua, $m) ? $m[1] : '';
    }

    /* ── Crawler checks (forward-confirmed reverse DNS) ─────────────── */

    private static function checks() {
        $c = get_option(self::CHECKS, []);
        return is_array($c) ? $c : [];
    }

    /**
     * Check claimants not checked in the last day: those in the lines just
     * read first, then earlier ones from the stored activity. Stops after
     * VERIFY_MAX checks or VERIFY_SECS seconds. Results are kept a day.
     */
    private static function verify_claims(array $claims) {
        $checks = self::checks();
        $stale  = time() - DAY_IN_SECONDS;
        $fresh  = static function ($ip) use (&$checks, $stale) {
            return isset($checks[$ip]) && (int) $checks[$ip][2] > $stale;
        };
        $claims = array_filter($claims, static function ($ip) use ($fresh) {
            return !$fresh($ip);
        }, ARRAY_FILTER_USE_KEY);
        if (count($claims) < self::VERIFY_MAX) {
            global $wpdb;
            $table = self::table();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $earlier = $wpdb->get_results($wpdb->prepare(
                "SELECT ip, MAX(ua) ua FROM {$table} WHERE bucket >= %d AND (ua LIKE %s OR ua LIKE %s OR ua LIKE %s OR ua LIKE %s) GROUP BY ip LIMIT 1000",
                (int) floor((time() - self::JUDGE) / self::BUCKET), '%bot%', '%spider%', '%google%', '%yandex%'
            ), ARRAY_A);
            foreach ((array) $earlier as $row) {
                if (!isset($claims[$row['ip']]) && !$fresh($row['ip']) && self::claim($row['ua']) !== '') {
                    $claims[$row['ip']] = $row['ua'];
                }
            }
        }
        if (!$claims) {
            return;
        }
        $until = microtime(true) + self::VERIFY_SECS;
        $done  = 0;
        foreach ($claims as $ip => $ua) {
            if ($done++ >= self::VERIFY_MAX || microtime(true) > $until) {
                break;
            }
            $checks[$ip] = array_merge(self::verify($ip, self::CLAIMS[self::claim($ua)]['hosts']), [time()]);
        }
        $checks = array_filter($checks, static function ($c) use ($stale) {
            return (int) ($c[2] ?? 0) > $stale;
        });
        if (count($checks) > 2000) {
            uasort($checks, static function ($a, $b) {
                return $b[2] <=> $a[2];
            });
            $checks = array_slice($checks, 0, 2000, true);
        }
        update_option(self::CHECKS, $checks, false);
    }

    /**
     * Reverse DNS must end in one of $hosts, and that host must resolve back to $ip.
     *
     * @return array{0:int, 1:string} [1 confirmed | -1 not, host or '']
     */
    public static function verify($ip, array $hosts) {
        $host = @gethostbyaddr($ip);
        if (!is_string($host) || $host === '' || $host === $ip) {
            return [-1, ''];
        }
        $match = false;
        foreach ($hosts as $suffix) {
            if (substr(strtolower($host), -strlen($suffix)) === $suffix) {
                $match = true;
            }
        }
        if (!$match) {
            return [-1, $host];
        }
        $want = @inet_pton($ip);
        $back = [];
        if (strpos($ip, ':') === false) {
            $back = (array) @gethostbynamel($host);
        } elseif (function_exists('dns_get_record')) {
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $rec) {
                $back[] = $rec['ipv6'] ?? '';
            }
        }
        foreach ($back as $addr) {
            if ($addr !== '' && @inet_pton($addr) === $want) {
                return [1, $host];
            }
        }
        return [-1, $host];
    }

    private static function short($s, $len = 60) {
        $s = (string) $s;
        return strlen($s) > $len ? substr($s, 0, $len - 1) . '…' : $s;
    }
}
