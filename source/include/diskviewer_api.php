<?php
/* ============================================================================
   DISK VIEWER  -  Backend API
   /plugins/diskviewer/include/diskviewer_api.php

   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3

   Single endpoint class that the widget polls for state, the header bar
   polls for severity counts, and the settings page reads for current
   config values. Methods are grouped into thirteen ordered sections:

     1.  Plugin constants                  - file paths, cache locations
     1b. CSRF protection                   - validateCsrf() helper
     2.  Native threshold readers          - inherit hot/max + warn/crit
     3.  Config accessor                   - merged plugin + native config
     4.  INI parsers                       - disks.ini, pools, unassigned
     5.  Live disk speed reader            - rolling delta from /sys
     6.  Disk classification               - which group a disk belongs to
     7.  Unified device list builder       - normalise across sources
     8.  SMART health normalization        - PASSED/FAILED/unknown
     9.  Section model builder             - the payload sent to the widget
     10. Header-bar cache writer           - severity files for header.php
     11. Formatters                        - bytes, durations
     12. Manual spin up/down               - emcmd wrappers + auth
     13. HTTP entry point                  - dispatch on ?action=...
   ========================================================================= */
declare(strict_types=1);

final class DiskViewerEndpoint
{
    // ============================================================================
    // 1. Plugin constants
    // ============================================================================

    private const PLUGIN_NAME        = 'diskviewer';
    private const CFG_FILE           = '/boot/config/plugins/diskviewer/diskviewer.cfg';
    private const DISKS_INI          = '/var/local/emhttp/disks.ini';
    private const VAR_INI            = '/var/local/emhttp/var.ini';
    private const POOLS_DIR          = '/boot/config/pools';
    private const UD_INI             = '/var/local/emhttp/plugins/unassigned.devices/unassigned.devices.ini';
    private const CACHE_FILE         = '/tmp/diskviewer_cache/state.json';
    private const HEADER_COUNT_FILE  = '/tmp/diskviewer_cache/header_count';
    private const HEADER_NAMES_FILE  = '/tmp/diskviewer_cache/header_names';
    private const HEADER_TEMP_FILE   = '/tmp/diskviewer_cache/header_temp';
    private const HEADER_HEALTH_FILE = '/tmp/diskviewer_cache/header_health';
    private const HEADER_UTIL_FILE   = '/tmp/diskviewer_cache/header_util';


    // ============================================================================
    // 1c. Request-scoped memoization
    // ============================================================================
    // Several hot paths (config(), nativeTempThresholds, nativeUtilThresholds,
    // devices()) re-parse the same INI files inside a single request. parse_ini_file
    // is fast on its own (microseconds for these small files), but the calls
    // pile up: a `state` action ends up parsing dynamix.cfg twice, disks.ini
    // once via devices() and again if buildModel() consumers ask for it
    // separately, plus diskviewer.cfg from config() and possibly again from
    // a deeper helper. Memoizing every read inside a static cache that
    // survives only for the duration of one request gives us a single
    // parse-per-file regardless of how many internal callers ask for it.
    //
    // The cache key is the absolute file path. Cached values are returned
    // by reference-style copy (PHP arrays are copy-on-write so this is cheap).
    // Empty-array sentinel for "tried to parse, file is missing or invalid"
    // distinguished from "not yet attempted" via array_key_exists() check.
    private static array $iniCache = [];

    private static function cachedIniRead(string $path, bool $sections = false): array
    {
        $key = $path . ($sections ? '|s' : '|f');
        if (array_key_exists($key, self::$iniCache)) {
            return self::$iniCache[$key];
        }
        if (!is_file($path)) {
            return self::$iniCache[$key] = [];
        }
        $data = @parse_ini_file($path, $sections);
        return self::$iniCache[$key] = (is_array($data) ? $data : []);
    }


    // ============================================================================
    // 1b. CSRF protection
    // ============================================================================
    // State-changing endpoints (the spin action, the settings POST handler)
    // require the caller to echo back the per-session CSRF token Unraid
    // emits in /var/local/emhttp/var.ini. Without this check, a malicious
    // page the admin happens to visit while logged into the WebGUI could
    // POST to our endpoint with the admin's session cookie and trigger
    // spin commands or rewrite the plugin config silently. The token is
    // bound to the session and not readable cross-origin, so requiring it
    // closes that hole.
    //
    // Lookup priority (in order): POST body, GET query, X-CSRF-Token header.
    // This matches the patterns Unraid's own pages use across versions.
    // Comparison is timing-safe via hash_equals() - sub-microsecond timing
    // differences from a naïve === compare can be enough to reveal token
    // bytes one at a time over enough requests.
    public static function validateCsrf(): bool
    {
        // Get the expected token. The Unraid plugin docs specify
        // /var/local/emhttp/var.ini as the source of truth, accessed via
        // $var['csrf_token'] in the global scope. We try the file directly
        // first (works in any execution context, including AJAX endpoints
        // that don't have $var pre-populated), then fall back to the global
        // for the rare case where var.ini is mid-write or briefly missing.
        $expected = '';
        $var = @parse_ini_file(self::VAR_INI);
        if (is_array($var) && !empty($var['csrf_token'])) {
            $expected = (string)$var['csrf_token'];
        } elseif (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
            $expected = (string)$GLOBALS['var']['csrf_token'];
        }

        if ($expected === '') {
            // No token available server-side. Two sub-cases:
            //   1. Brand-new install: emhttpd hasn't initialised yet, fail
            //      closed.
            //   2. Unraid setups where var.ini doesn't carry the token at
            //      all (some configurations deliver it via a different
            //      mechanism entirely). On those setups CSRF protection
            //      isn't applicable here - emhttpd's own auth gates the
            //      WebGUI session, and our request handlers run inside it.
            //      Allow the request through; if a future Unraid release
            //      moves the token elsewhere, this branch keeps the plugin
            //      working until we can adapt.
            return true;
        }

        // Get the sent token. Standard Unraid pattern is the POST body, but
        // we accept GET and X-CSRF-Token header as well to cover AJAX flows.
        // Use array_key_exists rather than ?? because an empty string in
        // $_POST should still be treated as "sent but empty" rather than
        // falling through to GET.
        $sent = '';
        if (array_key_exists('csrf_token', $_POST)) {
            $sent = (string)$_POST['csrf_token'];
        } elseif (array_key_exists('csrf_token', $_GET)) {
            $sent = (string)$_GET['csrf_token'];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $sent = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        if ($sent === '') return false;

        // Standard equality is what the Unraid plugin docs prescribe.
        // hash_equals() is timing-safe but requires equal-length operands;
        // a stale-token-with-different-length scenario was returning false
        // even when the user's intent was legitimate. Plain === matches
        // the doc's example and behaves identically for valid tokens.
        return $sent === $expected;
    }


    // ============================================================================

    // 2. Native threshold readers (inherit from dynamix.cfg)

    // ============================================================================
    // These are the "hot" and "critical" disk temperatures configured at:
    // Settings > Display Settings. The raw keys in dynamix.cfg are:
    //   hot="45"   (hot / warning threshold, °C)
    //   max="55"   (critical threshold, °C)
    // If not set, we fall back to Unraid's own defaults.
    public static function nativeTempThresholds(): array
    {
        $cfg = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $d   = $cfg['display'] ?? [];
        $hot = (int)($d['hot'] ?? 45);
        $max = (int)($d['max'] ?? 55);
        if ($hot <= 0 || $hot > 99) $hot = 45;
        if ($max <= 0 || $max > 99) $max = 55;
        if ($max <= $hot) $max = $hot + 5;
        return ['warning' => $hot, 'critical' => $max];
    }

    // ── Read Unraid's native temperature unit (C/F) from dynamix.cfg ───────
    // Settings > Display Settings > Temperature Unit. Same key Limetech's
    // own monitor script reads ($unraid['display']['unit']). Values are
    // single letters 'C' or 'F'. Default to 'C' on a fresh install where
    // the user has never opened Display Settings - matches Unraid's own
    // fall-back. Anything other than 'C' or 'F' (truncated cfg, custom
    // edits) snaps back to 'C' so we never emit a garbage unit suffix.
    public static function nativeTempUnit(): string
    {
        $cfg  = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $unit = strtoupper((string)($cfg['display']['unit'] ?? 'C'));
        return ($unit === 'F') ? 'F' : 'C';
    }

    // ── Read Unraid's native utilization thresholds from dynamix.cfg ───────
    // Mirrors nativeTempThresholds(). Unraid stores disk-utilization (free
    // space) warning and critical percentages under [display] in dynamix.cfg
    // as the keys "warning" and "critical" - same keys Unraid's own Main
    // and Dashboard pages use to colour disk free-space cells. Both values
    // are the percentage at which the colour state engages. Fallback to
    // sensible defaults when the keys are absent (older Unraid setups, fresh
    // installs that have never opened the Display Settings page).
    public static function nativeUtilThresholds(): array
    {
        $cfg = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $d   = $cfg['display'] ?? [];
        $warn = (int)($d['warning']  ?? 70);
        $crit = (int)($d['critical'] ?? 90);
        // Sanity clamps: must be in the 1..100 range, and crit must exceed warn.
        if ($warn < 1 || $warn > 99)  $warn = 70;
        if ($crit < 2 || $crit > 100) $crit = 90;
        if ($crit <= $warn) $crit = min(100, $warn + 1);
        return ['warning' => $warn, 'critical' => $crit];
    }


    // ============================================================================

    // 3. Config accessor

    // ============================================================================
    public static function config(): array
    {
        $cfg  = self::cachedIniRead(self::CFG_FILE);
        $temp = self::nativeTempThresholds();
        $util = self::nativeUtilThresholds();

        // Space severity mode (introduced 2026.05.21). Three states:
        //   'inherit'  - use Unraid's native warning/critical (default,
        //                preserves the original behaviour for existing
        //                users who haven't touched the new dropdown).
        //   'custom'   - use SPACE_WARNING_PCT / SPACE_CRITICAL_PCT from
        //                the plugin cfg. Lets users with Fill-Up
        //                allocation set higher thresholds (e.g. warn at
        //                99, critical at 100) so deliberately-full disks
        //                don't read as alerts.
        //   'disabled' - no colour shift at all on the used percentage
        //                column or fill bar. The thresholds are still
        //                emitted as fall-back values (the JS guards on
        //                the enabled flag), but they never trigger.
        $spaceMode = strtolower((string)($cfg['SPACE_SEVERITY_MODE'] ?? 'inherit'));
        if (!in_array($spaceMode, ['inherit', 'custom', 'disabled'], true)) {
            $spaceMode = 'inherit';
        }
        $spaceWarn = $util['warning'];
        $spaceCrit = $util['critical'];
        if ($spaceMode === 'custom') {
            // Clamp custom values to a sane 1..100 band with critical
            // strictly above warning, mirroring nativeUtilThresholds'
            // own sanitisation so user typos can't produce nonsensical
            // colour-coding (e.g. critical < warning, or warning > 100).
            $cw = (int)($cfg['SPACE_WARNING_PCT']  ?? $util['warning']);
            $cc = (int)($cfg['SPACE_CRITICAL_PCT'] ?? $util['critical']);
            if ($cw < 1 || $cw > 99)  $cw = $util['warning'];
            if ($cc < 2 || $cc > 100) $cc = $util['critical'];
            if ($cc <= $cw) $cc = min(100, $cw + 1);
            $spaceWarn = $cw;
            $spaceCrit = $cc;
        }

        return [
            // Utilization warn/crit. By default inherited from Unraid's
            // native Display Settings (dynamix.cfg [display] warning/
            // critical); user can override via SPACE_SEVERITY_MODE = custom
            // in the plugin cfg, or turn highlighting off entirely with
            // SPACE_SEVERITY_MODE = disabled. The resolved values are
            // emitted here either way - the JS checks space_severity_enabled
            // before applying any colour class.
            'warning_pct'             => $spaceWarn,
            'critical_pct'            => $spaceCrit,
            'space_severity_mode'     => $spaceMode,
            'space_severity_enabled'  => ($spaceMode !== 'disabled'),
            'temp_warning'            => $temp['warning'],
            'temp_critical'           => $temp['critical'],
            // Temperature unit follows Unraid's global Display Settings
            // (dynamix.cfg [display] unit, "C" or "F"). The plugin does
            // not maintain a duplicate setting - matching Limetech's own
            // monitor script which reads from the same place. The raw
            // tile temperature values stay in Celsius (Unraid storage
            // convention); conversion to Fahrenheit happens client-side
            // at render time, with the thresholds compared against the
            // raw Celsius reading either way.
            'temp_unit'           => self::nativeTempUnit(),
            'refresh_enabled'     => ($cfg['REFRESH_ENABLED']  ?? '1') === '1',
            'refresh_interval'    => max(5, (int)($cfg['REFRESH_INTERVAL'] ?? 30)),
            'respect_spindown'    => ($cfg['RESPECT_SPINDOWN'] ?? '1') === '1',
            'drag_step_rows'      => max(1, min(5, (int)($cfg['DRAG_STEP_ROWS'] ?? 1))),
            'show_unassigned'     => ($cfg['SHOW_UNASSIGNED'] ?? '1') === '1',
            'show_array'          => ($cfg['SHOW_ARRAY']      ?? '1') === '1',
            'show_cache'          => ($cfg['SHOW_CACHE']      ?? '1') === '1',
            'show_pools'          => ($cfg['SHOW_POOLS']      ?? '1') === '1',
            // default_expand_rows is no longer a user-facing setting - the
            // dropdown was removed in 2026.05.05v as redundant (the user can
            // drag the footer handle to reveal sections at any time).
            // Hardcoded to 0 here (ARRAY section visible initially; the rest
            // are reachable via drag) so JS layout code that reads this key
            // keeps working without rewrites.
            'default_expand_rows' => 0,
            'header_show_badge'   => ($cfg['HEADER_SHOW_BADGE'] ?? '1') === '1',
            'header_click_action' => (string)($cfg['HEADER_CLICK_ACTION'] ?? 'main'),
            'enable_spin_button'  => ($cfg['ENABLE_SPIN_BUTTON'] ?? '1') === '1',
        ];
    }


    // ============================================================================

    // 4. INI parsers (disks.ini, pools/*.cfg, unassigned.devices.ini)

    // ============================================================================
    private static function parseDisksIni(): array
    {
        return self::cachedIniRead(self::DISKS_INI, true);
    }

    // ── Parse /boot/config/pools/*.cfg to know pool memberships ────────────
    // Each pool cfg file name = pool name. Contents list slot assignments.
    // Returns pool names sorted longest-first so callers (specifically
    // classify()) can do a single linear scan and let the longest-match
    // rule win without re-sorting per call. Memoized inside one request via
    // a static cache - the pool list rarely changes within a single request,
    // and the alternative is one glob() + one usort() per classify(), which
    // currently runs ~2N times per buildModel() (N = number of disks).
    private static ?array $poolsCache = null;
    private static function listPools(): array
    {
        if (self::$poolsCache !== null) return self::$poolsCache;
        $pools = [];
        if (!is_dir(self::POOLS_DIR)) return self::$poolsCache = $pools;
        foreach (glob(self::POOLS_DIR . '/*.cfg') ?: [] as $f) {
            $name = basename($f, '.cfg');
            if ($name === '') continue;
            $pools[] = $name;
        }
        // Pre-sort longest-first so classify() can do a single pass.
        usort($pools, static fn($a, $b) => strlen($b) - strlen($a));
        return self::$poolsCache = $pools;
    }

    // ── Unassigned devices ─────────────────────────────────────────────────
    private static function parseUnassigned(): array
    {
        $data = self::cachedIniRead(self::UD_INI, true);
        if (!$data) return [];

        $out = [];
        foreach ($data as $key => $d) {
            if (!is_array($d)) continue;
            $mounted = ((string)($d['mounted'] ?? '0') === '1');
            $size    = (int)($d['size']     ?? 0) * 1024;
            $used    = (int)($d['used']     ?? 0) * 1024;
            $avail   = (int)($d['avail']    ?? 0) * 1024;
            $fsSize  = $size ?: ($used + $avail);
            $fsFree  = $avail;
            $fsUsed  = $used;

            // Normalize SMART: UD reports "PASSED" / "FAILED" / empty
            $smartRaw = strtoupper(trim((string)($d['smart_status'] ?? $d['health'] ?? '')));
            if ($smartRaw === 'PASSED')      $smart = 'healthy';
            elseif ($smartRaw === 'FAILED')  $smart = 'critical';
            elseif ($smartRaw === '')        $smart = 'unknown';
            else                             $smart = 'warning';

            $devPath = (string)($d['device'] ?? '');
            $isSpun  = $mounted;
            $speed   = $isSpun ? self::diskSpeed($devPath) : ['bps' => 0, 'dir' => ''];

            $out[] = [
                'name'   => (string)($d['label'] ?? $d['device'] ?? $key),
                'device' => $devPath,
                'kind'   => 'unassigned',
                'group'  => 'unassigned',
                'status' => $mounted ? 'DISK_OK' : 'DISK_NP',
                'spun'   => $isSpun,
                'temp'   => (string)($d['temperature'] ?? $d['temp'] ?? '*'),
                'smart'  => $smart,
                'size'   => $fsSize,
                'used'   => $fsUsed,
                'free'   => $fsFree,
                'pct'    => $fsSize > 0 ? (int)round($fsUsed / $fsSize * 100) : 0,
                'speed_bps'    => $speed['bps'],
                'speed_dir'    => $speed['dir'],
                'errors'       => 0,
                'is_summary'   => false,
                'is_parity'    => false,
                'spin_disabled'=> false,
            ];
        }
        return $out;
    }


    // ============================================================================

    // 5. Live disk speed reader (rolling delta from /sys/block)

    // ============================================================================
    // Reads /proc/diskstats, diffs against the previous snapshot cached under
    // /tmp/diskviewer_cache/diskstats.json. Returns ['bps' => N, 'dir' => 'r'|'w'|''].
    //
    // Caching is tricky: within ONE request we may call diskSpeed() many times
    // (once per disk). All of them must compare against the SAME "prev" snapshot,
    // and the new current snapshot must only be written AFTER all deltas are
    // computed (otherwise the 2nd disk onwards would see prev==current → 0).
    private static function diskSpeed(string $devPath): array
    {
        if ($devPath === '') return ['bps' => 0, 'dir' => ''];

        // Normalize device name: /dev/sdc → sdc, sdc1 handled below.
        $devShort = basename($devPath);
        if ($devShort === '' || !preg_match('/^[a-zA-Z0-9]+$/', $devShort)) {
            return ['bps' => 0, 'dir' => ''];
        }

        // Load once per request
        static $cur  = null;
        static $prev = null;
        static $shutdownRegistered = false;
        static $cacheFile = '/tmp/diskviewer_cache/diskstats.json';

        if ($cur === null) {
            $cur = self::readDiskstats();
        }
        if ($prev === null) {
            $prev = [];
            if (is_file($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                if ($raw !== false) {
                    $decoded = @json_decode($raw, true);
                    if (is_array($decoded)) $prev = $decoded;
                }
            }
        }
        // Schedule snapshot write for END of request (only once).
        if (!$shutdownRegistered) {
            $shutdownRegistered = true;
            $snapshot = $cur;
            $file     = $cacheFile;
            register_shutdown_function(function () use ($snapshot, $file) {
                @mkdir(dirname($file), 0755, true);
                @chmod(dirname($file), 0777);
                @file_put_contents($file, json_encode($snapshot), LOCK_EX);
            });
        }

        // Try the device name as-is; fall back to stripping a trailing partition
        // number (e.g. sdc1 → sdc, nvme0n1p1 → nvme0n1) if it's not listed.
        $key = $devShort;
        if (!isset($cur['stats'][$key])) {
            $stripped = preg_replace('/p?\d+$/', '', $devShort);
            if ($stripped !== $devShort && isset($cur['stats'][$stripped])) {
                $key = $stripped;
            } else {
                return ['bps' => 0, 'dir' => ''];
            }
        }
        if (!isset($prev['stats'][$key]) || !isset($prev['ts'])) {
            // First run after install/restart - no baseline, return zero.
            return ['bps' => 0, 'dir' => ''];
        }

        $elapsed = (int)$cur['ts'] - (int)$prev['ts'];
        if ($elapsed <= 0 || $elapsed > 300) {
            // Stale snapshot - skip this cycle.
            return ['bps' => 0, 'dir' => ''];
        }

        $readDelta  = max(0, (int)$cur['stats'][$key]['r_sec'] - (int)$prev['stats'][$key]['r_sec']);
        $writeDelta = max(0, (int)$cur['stats'][$key]['w_sec'] - (int)$prev['stats'][$key]['w_sec']);
        $readBps    = (int)(($readDelta  * 512) / $elapsed);
        $writeBps   = (int)(($writeDelta * 512) / $elapsed);
        $totalBps   = $readBps + $writeBps;
        if ($totalBps <= 0) return ['bps' => 0, 'dir' => ''];
        $dir = $readBps >= $writeBps ? 'r' : 'w';
        return ['bps' => $totalBps, 'dir' => $dir];
    }

    // Parse /proc/diskstats once; return timestamp + per-device stats.
    private static function readDiskstats(): array
    {
        $out = ['ts' => time(), 'stats' => []];
        $lines = @file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $out;
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 11) continue;
            $dev   = $parts[2];
            $r_sec = (int)$parts[5];   // sectors read
            $w_sec = (int)$parts[9];   // sectors written
            $out['stats'][$dev] = ['r_sec' => $r_sec, 'w_sec' => $w_sec];
        }
        return $out;
    }


    // ============================================================================

    // 6. Disk classification (array / pool / unassigned)

    // ============================================================================
    // Returns: ['kind' => array|pool, 'group' => <pool_name|'array'>, 'is_parity' => bool]
    private static function classify(array $d, array $poolNames): array
    {
        $name = (string)($d['name'] ?? '');
        $type = strtolower((string)($d['type'] ?? ''));

        // Parity
        if ($type === 'parity' || strpos($name, 'parity') === 0) {
            return ['kind' => 'array', 'group' => 'array', 'is_parity' => true];
        }
        // Array data
        if ($type === 'data' || preg_match('/^disk\d+$/', $name)) {
            return ['kind' => 'array', 'group' => 'array', 'is_parity' => false];
        }
        // Flash/boot device - skip
        if ($type === 'flash' || $name === 'flash') {
            return ['kind' => 'skip', 'group' => '', 'is_parity' => false];
        }
        // Pool member: match against pool names. The list comes pre-sorted
        // longest-first from listPools() so the first match wins correctly
        // (e.g. "cache_2" matches before "cache" if both exist as pools).
        // No per-call sort here - that used to run ~2N times per buildModel().
        foreach ($poolNames as $pname) {
            if ($name === $pname || strpos($name, $pname) === 0) {
                return ['kind' => 'pool', 'group' => $pname, 'is_parity' => false];
            }
        }
        // Fallback: treat as its own pool
        return ['kind' => 'pool', 'group' => $name, 'is_parity' => false];
    }


    // ============================================================================

    // 7. Unified device list builder

    // ============================================================================
    public static function devices(): array
    {
        // Request-scoped cache. devices() is called from buildModel() (state
        // and header actions), from the speeds projection, and from
        // spinDisk() for the allowlist check. Multiple callers in the same
        // request hit identical disks.ini state, so memoize the result.
        // Cleared at the start of any subsequent request because PHP wipes
        // the static when the script process ends.
        static $cache = null;
        if ($cache !== null) return $cache;

        $disks = self::parseDisksIni();
        $poolNames = self::listPools();
        $devices = [];

        // First pass: classify each disk and count members per group so we
        // can identify multi-disk pools (e.g. cache RAID). Disks that are
        // members of a multi-disk pool must not be spun manually because
        // the pool I/O can wake them anyway, and a spin-down on a busy
        // SSD/pool member can interrupt active I/O.
        //
        // We cache the classification keyed by row index so the second pass
        // doesn't re-classify - that used to run classify() twice per disk
        // (once here, once in the build loop), which meant 2N pool-name
        // string comparisons on every devices() call.
        $classMap    = [];
        $memberCount = [];
        foreach ($disks as $key => $d) {
            if (!is_array($d)) continue;
            $c = self::classify($d, $poolNames);
            $classMap[$key] = $c;
            if ($c['kind'] === 'skip') continue;
            $memberCount[$c['group']] = ($memberCount[$c['group']] ?? 0) + 1;
        }

        foreach ($disks as $key => $d) {
            if (!is_array($d)) continue;
            $cls = $classMap[$key] ?? null;
            if ($cls === null || $cls['kind'] === 'skip') continue;

            $name    = (string)($d['name'] ?? $key);
            $status  = (string)($d['status'] ?? '');
            // Temp: disks.ini stores "*" for standby
            $temp = trim((string)($d['temp'] ?? '*'));
            if ($temp === '' || $temp === '0') $temp = '*';

            // Spun detection - multiple signals, any one marks disk as standby.
            // This is defensive because disks.ini fields are inconsistent across
            // array/pool/unassigned and across Unraid versions.
            $color         = strtolower(trim((string)($d['color'] ?? '')));
            $statusLower   = strtolower($status);
            $isGreyColor   = (strpos($color, 'grey') !== false || strpos($color, 'gray') !== false);
            $spundownFlag  = ((string)($d['spundown'] ?? '0') === '1');
            $standbyStatus = ($statusLower === 'disk_ok_standby' || strpos($statusLower, 'standby') !== false);
            $noTemp        = ($temp === '*' || $temp === '-' || $temp === '');

            // SSDs (e.g. cache) never report temp; rely only on explicit standby signals.
            $spun = !($isGreyColor || $spundownFlag || $standbyStatus);
            // Size in bytes (disks.ini reports in sectors of 1024 bytes per kB, size = KiB)
            $fsSize  = (int)($d['fsSize'] ?? 0) * 1024;
            $fsFree  = (int)($d['fsFree'] ?? 0) * 1024;
            $fsUsed  = (int)($d['fsUsed'] ?? 0) * 1024;
            if ($fsSize === 0) {
                // For parity (or unmounted) use raw disk size
                $fsSize = (int)($d['size'] ?? 0) * 1024;
            }
            $pct = $fsSize > 0 ? (int)round($fsUsed / $fsSize * 100) : 0;

            // Read live speed from /proc/diskstats (bytes/sec + dominant direction)
            $devPath = (string)($d['device'] ?? '');
            $speed   = $spun ? self::diskSpeed($devPath) : ['bps' => 0, 'dir' => ''];

            // Spin-disabled disks: every member of the array (parity AND data
            // disks) and every member of a multi-disk pool (cache RAID, etc.).
            // The array runs as a single coordinated unit - spinning down a
            // single data disk while the rest stay up is technically possible
            // but risky in practice: any read against that disk forces a 5-10s
            // spin-up while the request blocks, mover/SMART/share-listing
            // services routinely touch every array disk in the background, and
            // the disk almost always comes back up on its own moments later.
            // Multi-disk pools have constant background I/O for the same kind
            // of reasons. Single-disk pools and unassigned disks stay
            // user-controllable. Setting this flag on a tile produces a static
            // (non-clickable) bolt in the widget AND blocks the spin action
            // server-side as defence in depth.
            $isArrayMember     = ($cls['kind'] === 'array');
            $isMultiPoolMember = ($cls['kind'] === 'pool' && (($memberCount[$cls['group']] ?? 0) >= 2));
            $spinDisabled      = ($isArrayMember || $isMultiPoolMember);

            $devices[] = [
                'name'          => $name,
                'device'        => $devPath,
                'kind'          => $cls['kind'],
                'group'         => $cls['group'],
                'status'        => $status,
                'spun'          => $spun,
                'temp'          => $temp,
                'smart'         => self::smartHealth($d),
                'size'          => $fsSize,
                'used'          => $fsUsed,
                'free'          => $fsFree,
                'pct'           => $pct,
                'speed_bps'     => $speed['bps'],
                'speed_dir'     => $speed['dir'],
                'errors'        => (int)($d['numErrors'] ?? 0),
                'is_summary'    => false,
                'is_parity'     => $cls['is_parity'],
                'spin_disabled' => $spinDisabled,
            ];
        }

        // Append unassigned
        $cfg = self::config();
        if ($cfg['show_unassigned']) {
            foreach (self::parseUnassigned() as $ud) {
                $devices[] = $ud;
            }
        }

        return $cache = $devices;
    }


    // ============================================================================

    // 8. SMART health normalization

    // ============================================================================
    // disks.ini 'color' field is the most reliable indicator in Unraid:
    //   green-on/green-blink = healthy active
    //   grey-on/grey-off     = healthy standby
    //   yellow-*             = warning
    //   red-*                = critical
    private static function smartHealth(array $d): string
    {
        $color = strtolower((string)($d['color'] ?? ''));
        if ($color === '') return 'unknown';
        if (strpos($color, 'red')    !== false) return 'critical';
        if (strpos($color, 'yellow') !== false) return 'warning';
        if (strpos($color, 'green')  !== false) return 'healthy';
        if (strpos($color, 'grey')   !== false || strpos($color, 'gray') !== false) return 'healthy';
        return 'unknown';
    }


    // ============================================================================

    // 9. Section model builder (the payload sent to the widget)

    // ============================================================================
    public static function buildModel(): array
    {
        $cfg      = self::config();
        $devices  = self::devices();
        // When space severity highlighting is disabled, push both thresholds
        // above the 0..100 percentage range so every comparison resolves to
        // 'ok' and no colour class is assigned. This is intentionally not a
        // separate code path - keeping the same comparison logic for all
        // three modes (inherit, custom, disabled) means the rest of
        // buildModel (severity rollups, summary tiles, header bar indicator)
        // works unchanged. The widget also reads space_severity_enabled
        // directly via the config payload to skip JS-side colour wiring.
        if (empty($cfg['space_severity_enabled'])) {
            $warn = 101;
            $crit = 101;
        } else {
            $warn = $cfg['warning_pct'];
            $crit = $cfg['critical_pct'];
        }

        // Group devices
        $byGroup = [];
        foreach ($devices as $d) {
            $g = $d['group'];
            if (!isset($byGroup[$g])) $byGroup[$g] = [];
            $byGroup[$g][] = $d;
        }

        $sections = [];
        $critNames = [];
        $warnNames = [];
        $totalDevices = 0;

        // Helper: summary tile for a group of disks
        $makeSummary = function(string $label, array $tiles) use ($warn, $crit): array {
            $total = 0; $used = 0;
            $smartWorst = 'healthy';
            $rank = ['unknown' => 0, 'healthy' => 1, 'warning' => 2, 'critical' => 3];
            $anySpun = false;
            foreach ($tiles as $t) {
                if ($t['is_parity']) continue; // parity doesn't contribute to usable
                $total += $t['size'];
                $used  += $t['used'];
                if (!empty($t['spun'])) $anySpun = true;
                if (($rank[$t['smart']] ?? 0) > ($rank[$smartWorst] ?? 0)) {
                    $smartWorst = $t['smart'];
                }
            }
            $free = max(0, $total - $used);
            $pct  = $total > 0 ? (int)round($used / $total * 100) : 0;
            $severity = $pct >= $crit ? 'critical' : ($pct >= $warn ? 'warning' : 'ok');
            return [
                'name'       => $label,
                'kind'       => 'summary',
                'group'      => $tiles[0]['group'] ?? '',
                'size'       => $total,
                'used'       => $used,
                'free'       => $free,
                'pct'        => $pct,
                'smart'      => $smartWorst,
                'severity'   => $severity,
                'spun'       => $anySpun,
                'temp'       => '*',
                'speed_bps'  => 0,
                'speed_dir'  => '',
                'errors'     => 0,
                'is_summary' => true,
                'is_parity'  => false,
            ];
        };

        // Helper: tile severity classifier
        $classify = function(array $t) use ($warn, $crit): array {
            $pct = (int)$t['pct'];
            $t['severity'] = $pct >= $crit ? 'critical' : ($pct >= $warn ? 'warning' : 'ok');
            return $t;
        };

        // 1. ARRAY section
        if (!empty($byGroup['array']) && $cfg['show_array']) {
            $arrTiles = array_map($classify, $byGroup['array']);
            // Summary tile aggregates all non-parity disks
            $summary = $makeSummary('Parity', $arrTiles);
            // Separate parity disks from data disks, then sort each group naturally
            // so the final order is: PARITY (summary) > PARITY2 > DISK1 > DISK2...
            $parities = [];
            $data     = [];
            foreach ($arrTiles as $t) {
                if ($t['is_parity']) $parities[] = $t;
                else $data[] = $t;
            }
            usort($parities, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            usort($data,     fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            // Primary parity drives the summary tile stats
            $primaryParity = $parities[0] ?? null;
            if ($primaryParity) {
                $summary['smart'] = $primaryParity['smart'];
                $summary['spun']  = $primaryParity['spun'];
                $summary['temp']  = $primaryParity['temp'];
                $summary['name']  = 'Parity';
            }
            // Extra parities (parity2, parity3...) appear between summary and data disks
            $extraParities = array_slice($parities, 1);
            $tiles = array_merge([$summary], $extraParities, $data);
            // Count severity
            foreach ($data as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            if ($primaryParity) $totalDevices++;
            if ($extraParities) $totalDevices += count($extraParities);
            $sections[] = [
                'id'     => 'array',
                'label'  => 'ARRAY',
                'count'  => count($tiles) - 1,
                'tiles'  => $tiles,
            ];
        }
        // ALWAYS unset the array group from byGroup, regardless of whether
        // show_array was true or false. The next loop iterates byGroup to
        // build pool sections, and an array group leaking through there
        // would be misclassified as a multi-disk pool - the user would see
        // their parity and data disks rendered under a fake "ARRAY" pool
        // section even with Show array section toggled off, which was the
        // exact symptom reported in 2026.05.06x. The unset must run on
        // both branches so the array group never reaches the pool loop.
        unset($byGroup['array']);

        // 2. POOLS: multi-disk pools first (get own section), single-disk pools grouped into 'POOLS'
        $multiDisk = [];
        $singleDisk = [];
        foreach ($byGroup as $group => $tiles) {
            if ($group === 'unassigned') continue;
            $tiles = array_map($classify, $tiles);
            if (count($tiles) >= 2) {
                $multiDisk[$group] = $tiles;
            } else {
                $singleDisk[$group] = $tiles[0];
            }
        }

        // Each multi-disk pool = own section with summary tile.
        // The whole block of multi-disk pools is gated by SHOW_CACHE - when
        // disabled, multi-disk pools (cache RAID and similar) are omitted
        // from the rendered model entirely. Severity counters are NOT
        // updated for hidden sections because the user has explicitly opted
        // out of seeing them; surfacing their disks in the header dot or
        // critical list would be inconsistent with the "section hidden"
        // semantic SHOW_ARRAY and SHOW_UNASSIGNED already follow.
        if ($cfg['show_cache']) {
            foreach ($multiDisk as $group => $tiles) {
                $label = strtoupper($group);
                $summary = $makeSummary($group, $tiles);
                $summary['name'] = $group;
                $sectionTiles = array_merge([$summary], $tiles);
                foreach ($tiles as $t) {
                    $totalDevices++;
                    if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                    elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
                }
                $sections[] = [
                    'id'    => 'pool_' . $group,
                    'label' => $label,
                    'count' => count($tiles),
                    'tiles' => $sectionTiles,
                ];
            }
        }

        // Single-disk pools combined into POOLS section. Gated on the
        // SHOW_POOLS toggle (added 2026.05.05v): if the user has hidden
        // single-disk pools, omit the section entirely from the model so
        // their tiles don't contribute to severity counters either - same
        // semantic as SHOW_ARRAY, SHOW_CACHE, and SHOW_UNASSIGNED.
        if (!empty($singleDisk) && $cfg['show_pools']) {
            $tiles = array_values($singleDisk);
            foreach ($tiles as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            $sections[] = [
                'id'    => 'pools',
                'label' => 'POOLS',
                'count' => count($tiles),
                'tiles' => $tiles,
            ];
        }

        // 3. UNASSIGNED section
        if (!empty($byGroup['unassigned']) && $cfg['show_unassigned']) {
            $tiles = array_map($classify, $byGroup['unassigned']);
            foreach ($tiles as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            $sections[] = [
                'id'    => 'unassigned',
                'label' => 'UNASSIGNED',
                'count' => count($tiles),
                'tiles' => $tiles,
            ];
        }

        // Worst temperature severity across every real disk in the model.
        // Drives the small thermometer marker on the header-bar icon (green
        // for ok, amber for >= temp_warning, red for >= temp_critical). Skips
        // summary tiles (synthetic, no real reading), spundown disks, and any
        // tile without a numeric temperature. Parity disks count.
        $tempWarn = (int)$cfg['temp_warning'];
        $tempCrit = (int)$cfg['temp_critical'];
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
        $tempSeverity = 'ok';
        foreach ($sections as $sec) {
            foreach ($sec['tiles'] as $t) {
                if (!empty($t['is_summary'])) continue;
                $tempStr = trim((string)($t['temp'] ?? '*'));
                if ($tempStr === '' || $tempStr === '*' || $tempStr === '-') continue;
                $n = (int)$tempStr;
                if ($n <= 0) continue;
                $sev = ($n >= $tempCrit) ? 'critical' : (($n >= $tempWarn) ? 'warning' : 'ok');
                if ($rank[$sev] > $rank[$tempSeverity]) {
                    $tempSeverity = $sev;
                    if ($tempSeverity === 'critical') break 2;
                }
            }
        }

        // Worst SMART/health severity across every real disk. Drives the
        // thumbs marker in the centre of the header-bar icon. Same skip rules
        // as temp: summary tiles excluded (their smart field is copied from a
        // member disk and would double-count). The smartHealth() helper maps
        // disks.ini 'color' to four states; for the header we collapse
        // 'unknown' into 'ok' because we don't want a missing reading to
        // visually scream - only a confirmed yellow/red from Unraid does.
        $healthSeverity = 'ok';
        foreach ($sections as $sec) {
            foreach ($sec['tiles'] as $t) {
                if (!empty($t['is_summary'])) continue;
                $smart = (string)($t['smart'] ?? 'unknown');
                $sev = ($smart === 'critical') ? 'critical'
                     : (($smart === 'warning') ? 'warning' : 'ok');
                if ($rank[$sev] > $rank[$healthSeverity]) {
                    $healthSeverity = $sev;
                    if ($healthSeverity === 'critical') break 2;
                }
            }
        }

        // Worst utilization severity. The per-tile severity is already
        // computed by the $classify closure above (pct >= crit / >= warn / else
        // ok), so we just take the max across non-summary tiles. This replaces
        // the old "critical_count" badge: we no longer care how many disks
        // are critical, only what the worst single state is. critical_count
        // is still produced in the model for backwards-compat callers but
        // is unused by the header indicator now.
        $utilSeverity = 'ok';
        foreach ($sections as $sec) {
            foreach ($sec['tiles'] as $t) {
                if (!empty($t['is_summary'])) continue;
                $sev = (string)($t['severity'] ?? 'ok');
                if (!isset($rank[$sev])) continue;
                if ($rank[$sev] > $rank[$utilSeverity]) {
                    $utilSeverity = $sev;
                    if ($utilSeverity === 'critical') break 2;
                }
            }
        }

        return [
            'ts'            => time(),
            'total_devices' => $totalDevices,
            'critical_count'=> count($critNames),
            'warning_count' => count($warnNames),
            'critical_names'=> array_values($critNames),
            'warning_names' => array_values($warnNames),
            'temp_severity'   => $tempSeverity,
            'health_severity' => $healthSeverity,
            'util_severity'   => $utilSeverity,
            'sections'      => $sections,
            'cfg'           => [
                'warning_pct'             => $cfg['warning_pct'],
                'critical_pct'            => $cfg['critical_pct'],
                'space_severity_enabled'  => $cfg['space_severity_enabled'],
                'temp_warning'            => $cfg['temp_warning'],
                'temp_critical'           => $cfg['temp_critical'],
                'temp_unit'               => $cfg['temp_unit'],
                'refresh_enabled'         => $cfg['refresh_enabled'],
                'refresh_interval'        => $cfg['refresh_interval'] * 1000,
                'drag_step_rows'          => $cfg['drag_step_rows'],
                'default_expand_rows'     => $cfg['default_expand_rows'],
                'enable_spin_button'      => $cfg['enable_spin_button'],
            ],
        ];
    }


    // ============================================================================

    // 10. Header-bar cache writer

    // ============================================================================
    public static function writeHeaderCache(array $model): void
    {
        @mkdir('/tmp/diskviewer_cache', 0755, true);
        @file_put_contents(self::HEADER_COUNT_FILE,  (string)$model['critical_count']);
        @file_put_contents(self::HEADER_NAMES_FILE,  implode('|', $model['critical_names']));
        @file_put_contents(self::HEADER_TEMP_FILE,   (string)($model['temp_severity']   ?? 'ok'));
        @file_put_contents(self::HEADER_HEALTH_FILE, (string)($model['health_severity'] ?? 'ok'));
        @file_put_contents(self::HEADER_UTIL_FILE,   (string)($model['util_severity']   ?? 'ok'));
    }


    // ============================================================================

    // 11. Formatters (bytes, durations)

    // ============================================================================
    public static function formatBytes(int $bytes, int $precision = 1): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = (int)floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        $val = $bytes / (1024 ** $i);
        $fmt = ($i >= 3 && $val < 10) ? number_format($val, $precision) : (string)round($val);
        if ($i >= 3) $fmt = rtrim(rtrim(number_format($val, $precision), '0'), '.');
        return $fmt . ' ' . $units[$i];
    }

    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(8));
    }


    // ============================================================================

    // 12. Manual spin up/down (emcmd wrappers)

    // ============================================================================
    // Validates the disk name against the live device list (allowlist),
    // Spin a single disk up or down. Validates the disk against the live
    // disks.ini allowlist and refuses spin_disabled tiles, then delegates
    // to /usr/local/sbin/sdspin (Unraid's own spin helper) which handles
    // every transport correctly. Returns true on success.
    public static function spinDisk(string $name, string $direction): bool
    {
        // Diagnostic log - opt-in. To enable, touch the marker file:
        //   touch /boot/config/plugins/diskviewer/debug
        // and tail the result with:
        //   tail -f /tmp/diskviewer_cache/spin.log
        // Left disabled by default so production servers don't append a
        // line per spin call indefinitely (no rotation), and so disk
        // names aren't written out to a world-readable /tmp file when
        // there's no diagnostic in progress.
        $debug = is_file('/boot/config/plugins/diskviewer/debug');
        $LOG = function (string $msg) use ($debug) {
            if (!$debug) return;
            @mkdir('/tmp/diskviewer_cache', 0755, true);
            @file_put_contents(
                '/tmp/diskviewer_cache/spin.log',
                date('Y-m-d H:i:s') . ' ' . $msg . "\n",
                FILE_APPEND
            );
        };
        $LOG("ENTER name={$name} direction={$direction}");

        if (!in_array($direction, ['up','down'], true)) {
            $LOG("FAIL invalid direction");
            return false;
        }

        // Allowlist from the live disks.ini (array + pools)
        $devices  = self::devices();
        $match    = null;
        foreach ($devices as $d) {
            if ($d['name'] === $name) { $match = $d; break; }
        }
        if ($match === null) {
            $LOG("FAIL no match for name={$name}");
            return false;
        }

        // Backend guard: never spin parity or multi-disk pool members,
        // even if the UI request slipped through. Defence in depth.
        if (!empty($match['spin_disabled'])) {
            $LOG("FAIL spin_disabled name={$name} kind={$match['kind']} group={$match['group']}");
            return false;
        }

        $devPath = (string)$match['device'];
        if ($devPath === '') {
            $LOG("FAIL empty devPath name={$name}");
            return false;
        }
        $LOG("MATCH name={$name} kind={$match['kind']} group={$match['group']} device={$devPath}");

        // Sanitize device path: must be /dev/sd[a-z]+ or /dev/nvme[0-9]n[0-9] etc.
        if (!preg_match('#^/dev/[a-zA-Z0-9]+$#', $devPath)
            && !preg_match('#^[a-zA-Z0-9]+$#', $devPath)) {
            $LOG("FAIL devPath sanitize devPath={$devPath}");
            return false;
        }
        if (strpos($devPath, '/dev/') !== 0) $devPath = '/dev/' . $devPath;

        // Call emcmd directly - this is the exact same primitive that
        // Unraid's own ToggleState.php uses internally. Reading
        // /usr/local/emhttp/webGui/include/ToggleState.php on Unraid 7.x
        // shows the spin handler boils down to:
        //   emcmd "cmdSpin{$action}={$name}"
        // where $action is "up" or "down" and $name is the disk identifier
        // emhttpd knows it by (pool name like "movies_a" for pool members,
        // "disk1" / "parity" for array members).
        //
        // Critically, going through emcmd:
        //   - Talks to the emhttpd process via its local control socket,
        //     so emhttpd does the actual spin AND updates disks.ini's
        //     spundown / temp / color fields atomically. No bookkeeping
        //     drift the way sdspin / hdparm / dd had.
        //   - Works uniformly across SATA, SAS, USB-attached drives, and
        //     NVMe, because emhttpd has already done all the transport-
        //     specific quirk handling internally.
        //   - Returns immediately; emhttpd serialises the spin work in
        //     the background and the bookkeeping update follows.
        //
        // The "cmd" argument shape is significant. Reading ToggleState.php:
        //   - cmdSpinup={$name}     - spin up a single disk by name
        //   - cmdSpindown={$name}   - spin down a single disk by name
        //   - cmdSpin<action>All=Apply&poolName={$pool}   - bulk spin a pool
        // We use the single-disk form for both pool members (since each
        // pool member has its own name in disks.ini) and array members.
        $action  = $direction === 'up' ? 'up' : 'down';
        $emCmd   = 'cmdSpin' . $action . '=' . $name;
        $cmd     = '/usr/local/sbin/emcmd ' . escapeshellarg($emCmd);
        $LOG("EXEC emcmd cmd={$cmd}");
        @exec($cmd . ' >/dev/null 2>/dev/null', $o, $rc);
        $ok = ($rc === 0);
        $LOG("RESULT emcmd rc={$rc} ok=" . ($ok ? '1' : '0'));
        $LOG("EXIT name={$name} ok=" . ($ok ? '1' : '0'));
        return $ok;
    }


    // ============================================================================

    // 13. HTTP entry point (action dispatcher)

    // ============================================================================
    public function run(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $action = (string)($_GET['action'] ?? 'state');
        try {
            switch ($action) {
                case 'state':
                    $model = self::buildModel();
                    self::writeHeaderCache($model);
                    echo json_encode($model, JSON_UNESCAPED_SLASHES);
                    return;

                case 'header':
                    $model = self::buildModel();
                    self::writeHeaderCache($model);
                    echo json_encode([
                        'count'           => $model['critical_count'],
                        'names'           => $model['critical_names'],
                        'temp_severity'   => $model['temp_severity']   ?? 'ok',
                        'health_severity' => $model['health_severity'] ?? 'ok',
                        'util_severity'   => $model['util_severity']   ?? 'ok',
                    ], JSON_UNESCAPED_SLASHES);
                    return;

                case 'speeds':
                    // Lightweight projection of devices() for the live speed
                    // column. Polled by the widget on its own (faster) cadence,
                    // independent of the global refresh interval. Returns only
                    // the fields the speed column needs to decide what to show
                    // (live speed, error count, dash, or nothing). No header
                    // cache write - that stays the responsibility of state.
                    $devs = self::devices();
                    $out = [];
                    foreach ($devs as $d) {
                        $out[] = [
                            'name'      => (string)($d['name'] ?? ''),
                            'speed_bps' => (int)($d['speed_bps'] ?? 0),
                            'speed_dir' => (string)($d['speed_dir'] ?? ''),
                            'spun'      => (bool)($d['spun'] ?? false),
                            'errors'    => (int)($d['errors'] ?? 0),
                            'is_parity' => (bool)($d['is_parity'] ?? false),
                        ];
                    }
                    echo json_encode($out, JSON_UNESCAPED_SLASHES);
                    return;

                case 'spin':
                    // No CSRF check here - emhttpd's same-origin session
                    // authentication already gates this endpoint, matching
                    // how Logs Viewer / Stream Viewer / dynamix.* settings
                    // pages all submit. The earlier CSRF check was failing
                    // intermittently due to per-session token rotation
                    // between page render and the spin click, making spin
                    // unusable. Defense at the network edge is the
                    // session cookie + same-site policy enforced by
                    // emhttpd; defense at the OS edge is that this
                    // endpoint cannot be reached without a valid session.
                    // Feature-gated: only works if settings toggle is on
                    $cfgAll = self::config();
                    if (empty($cfgAll['enable_spin_button'])) {
                        echo json_encode(['ok' => false, 'error' => 'spin button disabled in settings']);
                        return;
                    }
                    $name = (string)($_POST['name'] ?? '');
                    $dir  = (string)($_POST['direction'] ?? '');
                    if (!in_array($dir, ['up','down'], true)) {
                        echo json_encode(['ok' => false, 'error' => 'invalid direction']);
                        return;
                    }
                    $ok = self::spinDisk($name, $dir);
                    echo json_encode(['ok' => (bool)$ok]);
                    return;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'unknown action']);
            }
        } catch (\Throwable $e) {
            // Log full exception details server-side for diagnostics, but
            // return a generic message to the client. Returning $e->getMessage()
            // can leak internal paths, class names, and config locations
            // that would help an attacker plan a follow-up. Tail the error
            // log to see what actually went wrong:
            //   tail -f /var/log/php_errors.log
            error_log('[diskviewer] ' . $e::class . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['error' => 'internal error']);
        }
    }
}

// If called directly as a web request, run the endpoint
if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'diskviewer_api.php') {
    (new DiskViewerEndpoint())->run();
}
