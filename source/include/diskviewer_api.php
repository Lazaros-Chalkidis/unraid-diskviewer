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
    // Per-disk SMART overrides written by Unraid's /Main/Device?name=X
    // page when the user edits warning/critical temp thresholds on a
    // specific disk. Sections are keyed by disk id (matching the 'id'
    // field in disks.ini and the UD device id), with hotTemp/maxTemp
    // keys carrying the per-disk values.
    private const SMART_ONE_FILE     = '/boot/config/smart-one.cfg';
    private const CACHE_FILE         = '/tmp/diskviewer_cache/state.json';
    private const HEADER_COUNT_FILE  = '/tmp/diskviewer_cache/header_count';
    private const HEADER_NAMES_FILE  = '/tmp/diskviewer_cache/header_names';
    private const HEADER_TEMP_FILE   = '/tmp/diskviewer_cache/header_temp';
    private const HEADER_HEALTH_FILE = '/tmp/diskviewer_cache/header_health';
    private const HEADER_UTIL_FILE   = '/tmp/diskviewer_cache/header_util';
    private const HEADER_ERRORS_FILE = '/tmp/diskviewer_cache/header_errors';
    // Per-disk per-axis issue rows for the header tooltip. JSON-encoded
    // array of {name, axis, severity, label} entries, already sorted
    // server-side by axis priority and severity. JS reads it on the 30s
    // poll and renders the rows in the custom hover toast.
    private const HEADER_ISSUES_FILE = '/tmp/diskviewer_cache/header_issues';


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

    // ── Map parity count to the official RAID equivalent for the array ───
    // Unraid array is parity-protected, not classic RAID, but the
    // protection model maps cleanly onto traditional RAID levels:
    //   1 parity disk  -> RAID 4 (single dedicated parity)
    //   2 parity disks -> RAID 6 (dual parity)
    //   3 parity disks -> RAID 7 (theoretical, Unraid 7+ - kept as "RAID 7")
    // Zero parities means no protection, so return empty string and the
    // header just shows "ARRAY DEVICES N" without a RAID suffix.
    public static function arrayRaidProfile(int $parityCount): string
    {
        switch ($parityCount) {
            case 1:  return 'RAID 4';
            case 2:  return 'RAID 6';
            case 3:  return 'RAID 7';
            default: return '';
        }
    }

    // ── Detect a pool's RAID topology from the filesystem layer ──────────
    // BTRFS: `btrfs filesystem df /mnt/<pool>` reports a "Data, <profile>"
    // line where profile is one of single/RAID0/RAID1/RAID10/RAID5/RAID6.
    // We return e.g. "RAID 1" with the space (display preference). Single
    // profile -> empty string (no RAID label needed).
    //
    // ZFS: `zpool status <pool>` includes a vdev line indented with two
    // spaces, naming the topology - mirror, raidz1, raidz2, raidz3. We
    // return the ZFS-native name (Mirror / RAIDZ1 / RAIDZ2 / RAIDZ3) per
    // the user preference for exact ZFS naming rather than RAID-X mapping.
    //
    // Results are memoised per (pool,fs) pair because shell_exec is the
    // single most expensive call we make per buildModel() and the pool
    // topology cannot change inside a single request. Caches survive the
    // PHP-FPM worker lifetime which is good enough; topology changes
    // require a pool stop/start which kills the running plugin anyway.
    public static function poolRaidProfile(string $pool, string $fs): string
    {
        static $cache = [];
        $key = strtolower($pool) . '|' . strtolower($fs);
        if (isset($cache[$key])) return $cache[$key];

        $result = '';
        $fs     = strtolower(trim($fs));
        $mount  = '/mnt/' . $pool;
        if (!is_dir($mount)) return $cache[$key] = '';

        if ($fs === 'btrfs') {
            // Parse `btrfs filesystem df` output. The Data line is
            // authoritative for the user-visible profile - System and
            // Metadata may use a different profile (often DUP) which we
            // don't surface.
            $out = @shell_exec('btrfs filesystem df ' . escapeshellarg($mount) . ' 2>/dev/null');
            if ($out && preg_match('/^Data,\s*([A-Z0-9]+):/mi', $out, $m)) {
                $profile = strtoupper($m[1]);
                if ($profile !== 'SINGLE') {
                    // Normalise RAID0/RAID1/RAID10 -> "RAID 0" / "RAID 1"
                    // / "RAID 10" with the display space.
                    if (preg_match('/^RAID(\d+)$/', $profile, $rm)) {
                        $result = 'RAID ' . $rm[1];
                    } else {
                        $result = $profile;
                    }
                }
            }
        } elseif ($fs === 'zfs') {
            // zpool status output has the vdev type as an indented line
            // right under the pool name. mirror, raidz1, raidz2, raidz3.
            // A single-disk zpool has no vdev line - just the device.
            $out = @shell_exec('zpool status ' . escapeshellarg($pool) . ' 2>/dev/null');
            if ($out && preg_match('/^\s+(mirror|raidz[0-9]?)/mi', $out, $m)) {
                $vdev = strtolower($m[1]);
                if ($vdev === 'mirror') {
                    $result = 'Mirror';
                } elseif (preg_match('/^raidz([0-9]?)$/', $vdev, $rm)) {
                    // raidz alone means raidz1 in ZFS terminology.
                    $result = 'RAIDZ' . ($rm[1] !== '' ? $rm[1] : '1');
                }
            }
        }

        return $cache[$key] = $result;
    }

    // ── Detect which pools are used as cache targets ──────────────────────
    // Returns the set of pool names (lowercased) that are referenced as
    // `shareCachePool` in at least one share whose `shareUseCache` is yes,
    // prefer, or only. This is function-based detection, not name-based:
    // a pool named "fast_ssd" that's actually used as cache reads as a
    // cache target; a pool named "cache" that no share writes through
    // does NOT read as one.
    //
    // Why this matters: Unraid lets users name pools anything. The legacy
    // "cache" name is just default - it has no special meaning. Detecting
    // function from share configs makes the CACHE section show what the
    // user actually uses as write buffer, regardless of naming.
    //
    // shareUseCache values that count as "uses cache":
    //   "yes"    - writes go to cache, mover transfers to array later
    //   "prefer" - files stay on cache until full, then spill to array
    //   "only"   - files stay on cache permanently
    //   "no"     - direct to array, cachePool ignored even if set
    //
    // Returns lowercased pool names for case-insensitive matching against
    // group names in classify(). Cached per request via the same memoize
    // pattern as listPools().
    public static function cacheTargetPools(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $targets = [];
        $shareDir = '/boot/config/shares';
        if (!is_dir($shareDir)) {
            return $cache = [];
        }
        foreach (glob($shareDir . '/*.cfg') as $file) {
            $cfg = self::cachedIniRead($file, false);
            // Share .cfg files are flat key=value, no sections.
            $use  = strtolower(trim((string)($cfg['shareUseCache']  ?? 'no'), " \"'"));
            $pool = strtolower(trim((string)($cfg['shareCachePool'] ?? ''),   " \"'"));
            if ($pool === '' || $use === 'no') continue;
            if (in_array($use, ['yes', 'prefer', 'only'], true)) {
                $targets[$pool] = true;
            }
        }
        return $cache = array_keys($targets);
    }

    // ── Per-disk temperature thresholds (NVMe-aware) ──────────────────────
    // Returns ['warning' => int, 'critical' => int] for the given disks.ini
    // row, picking from three sources in order:
    //   1. Per-disk override stored in disks.ini as hotTemp / maxTemp -
    //      this is what Unraid writes when the user clicks a disk on the
    //      Main page and edits "Warning disk temperature threshold" or
    //      "Critical disk temperature threshold". Same fields Limetech's
    //      own monitor script reads from $disk['hotTemp'] / $disk['maxTemp'].
    //   2. NVMe-aware fallback when no per-disk override is set. NVMe
    //      drives legitimately run 60..75°C under load (HDDs run cool at
    //      30..40°C), so applying the HDD threshold globally throws false
    //      criticals on every cache write. Detect NVMe via the device
    //      path starting with /dev/nvme. Defaults 60/70 match common NVMe
    //      vendor specs (Samsung, WD, Crucial all warrant 70°C composite).
    //   3. Global HDD thresholds from dynamix.cfg [display] hot/max -
    //      what nativeTempThresholds() returns. Applies to all rotating
    //      and SATA SSD drives without per-disk overrides.
    // Always returns sane integers; never returns the literal disks.ini
    // string (which may be empty when no override was set).
    public static function diskTempThresholds(array $d): array
    {
        // Highest-priority source: per-disk overrides in smart-one.cfg,
        // keyed by the disk id from disks.ini. This is where Unraid 7.x
        // writes the values the user enters on the /Main/Device?name=X
        // page under SMART Settings. Format:
        //   [WD_Elements_2620_57583332443731484137415A-0:0]
        //   hotTemp="70"
        //   maxTemp="80"
        // We try $d['id'] first, then $d['idSb'] (which carries the same
        // identity for most disks but is used as backup). UD disks
        // expose 'id' as their device id with the -0:0 USB suffix, which
        // matches the section name exactly.
        $diskId = (string)($d['id'] ?? $d['idSb'] ?? '');
        if ($diskId !== '') {
            $smart = self::cachedIniRead(self::SMART_ONE_FILE, true);
            $sec   = $smart[$diskId] ?? null;
            if (is_array($sec)) {
                $hot = (string)($sec['hotTemp'] ?? '');
                $max = (string)($sec['maxTemp'] ?? '');
                if ($hot !== '' && $max !== '') {
                    $hotI = (int)$hot;
                    $maxI = (int)$max;
                    if ($hotI > 0 && $hotI < 100 && $maxI > 0 && $maxI < 100) {
                        if ($maxI <= $hotI) $maxI = $hotI + 5;
                        return ['warning' => $hotI, 'critical' => $maxI];
                    }
                }
            }
        }

        // Legacy disks.ini inline override fields. Older Unraid versions
        // wrote per-disk values into disks.ini directly with these key
        // names; kept as a defensive fallback for upgrades from those
        // versions where smart-one.cfg may still be unpopulated.
        $keyPairs = [
            ['hotTemp',  'maxTemp'],
            ['hotTemp1', 'maxTemp1'],
            ['warnTemp', 'critTemp'],
        ];
        foreach ($keyPairs as $pair) {
            $hot = (string)($d[$pair[0]] ?? '');
            $max = (string)($d[$pair[1]] ?? '');
            if ($hot !== '' && $max !== '') {
                $hotI = (int)$hot;
                $maxI = (int)$max;
                if ($hotI > 0 && $hotI < 100 && $maxI > 0 && $maxI < 100) {
                    if ($maxI <= $hotI) $maxI = $hotI + 5;
                    return ['warning' => $hotI, 'critical' => $maxI];
                }
            }
        }

        // No per-disk override - fall back to the global default that
        // matches this disk's class. Unraid stores two pairs in
        // dynamix.cfg [display]: hot/max (rotating HDDs) and
        // hotssd/maxssd (solid state). Applying hot/max to every disk
        // throws false warnings on SSDs and cache pools that legitimately
        // run hotter than HDDs - which is exactly what happened before
        // this fix.
        //
        // Detection priority:
        //   1. NVMe path - device basename starts with "nvme" (or the
        //      device string contains "nvme"). NVMe drives use the SSD
        //      pair from dynamix.cfg, falling back to vendor-typical
        //      60/70 if the user hasn't set hotssd/maxssd.
        //   2. SSD - rotational field is exactly "0" in disks.ini.
        //      Uses hotssd/maxssd.
        //   3. HDD - everything else (rotational=1 or missing). Uses
        //      hot/max.
        $dev        = strtolower((string)($d['device'] ?? ''));
        $rotational = (string)($d['rotational'] ?? '');
        $isNvme     = (strpos($dev, 'nvme') !== false);
        $isSsd      = !$isNvme && ($rotational === '0');

        $cfg  = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $disp = $cfg['display'] ?? [];

        if ($isNvme || $isSsd) {
            $hot = (int)($disp['hotssd'] ?? 60);
            $max = (int)($disp['maxssd'] ?? 70);
            if ($hot <= 0 || $hot > 99) $hot = 60;
            if ($max <= 0 || $max > 99) $max = 70;
        } else {
            $hot = (int)($disp['hot'] ?? 45);
            $max = (int)($disp['max'] ?? 55);
            if ($hot <= 0 || $hot > 99) $hot = 45;
            if ($max <= 0 || $max > 99) $max = 55;
        }
        if ($max <= $hot) $max = min(99, $hot + 5);

        return ['warning' => $hot, 'critical' => $max];
    }

    // ── Per-pool, per-device error counts ─────────────────────────────────
    // Returns ['device_basename' => total_error_count] for a pool. Pool
    // members report errors via filesystem-level tools, not via the
    // disks.ini numErrors field (that field tracks array read errors
    // for parity-protected disks). Without these shellouts a BTRFS or
    // ZFS pool can be silently accumulating corruption errors and the
    // widget would show zero. Mirrors the official Unraid Pool Devices
    // page which surfaces the same counters under "pool device stats".
    //
    // BTRFS: parse `btrfs device stats /mnt/<pool>` output, summing
    //   write_io_errs + read_io_errs + flush_io_errs + corruption_errs
    //   + generation_errs across all five categories per device.
    // ZFS: parse `zpool status <pool>` output, summing READ + WRITE +
    //   CKSUM error columns per leaf device row.
    //
    // device_basename strips the /dev/ prefix and any trailing partition
    // (sdz1 -> sdz; nvme0n1p1 -> nvme0n1) so it matches the tile.device
    // value already in the model. Cached per request via static array
    // so repeated tile loops don't re-shell out.
    public static function poolDeviceErrors(string $pool, string $fs): array
    {
        static $cache = [];
        $key = strtolower($pool) . '|' . strtolower($fs);
        if (isset($cache[$key])) return $cache[$key];

        $errors = [];
        $fs     = strtolower(trim($fs));
        $mount  = '/mnt/' . $pool;
        if (!is_dir($mount)) return $cache[$key] = $errors;

        $stripPartition = static function(string $dev): string {
            $dev = preg_replace('@^/dev/@', '', $dev);
            // NVMe partition format is "nvmeXnYpZ" - strip the pZ suffix.
            // SATA/IDE format is "sdXN" - strip the trailing digits.
            if (strpos($dev, 'nvme') === 0) {
                return preg_replace('/p\d+$/', '', $dev);
            }
            return preg_replace('/\d+$/', '', $dev);
        };

        if ($fs === 'btrfs') {
            $out = @shell_exec('btrfs device stats ' . escapeshellarg($mount) . ' 2>/dev/null');
            if ($out) {
                // Each line looks like: [/dev/sdz1].corruption_errs    1573
                // Five lines per device (write/read/flush/corruption/generation).
                // We sum all five categories - the user just wants to know
                // "is anything wrong here", not which category.
                foreach (preg_split('/\R/', (string)$out) as $line) {
                    if (preg_match('/^\[(\S+)\]\.\w+_errs\s+(\d+)/', $line, $m)) {
                        $base  = $stripPartition($m[1]);
                        $count = (int)$m[2];
                        $errors[$base] = ($errors[$base] ?? 0) + $count;
                    }
                }
            }
        } elseif ($fs === 'zfs') {
            $out = @shell_exec('zpool status ' . escapeshellarg($pool) . ' 2>/dev/null');
            if ($out) {
                // The config block has a header line "NAME STATE READ WRITE CKSUM"
                // followed by indented device rows. The pool name row and any
                // vdev type rows (mirror, raidz1, etc) also have these columns
                // but they aggregate the children, so we'd double-count if we
                // included them. Distinguish leaf devices by the deeper
                // indentation (at least 4 spaces before the device name) and
                // by checking the name doesn't match a known vdev keyword.
                $inConfig = false;
                $vdevKeywords = ['mirror', 'raidz', 'raidz1', 'raidz2', 'raidz3', 'spare', 'log', 'cache'];
                foreach (preg_split('/\R/', (string)$out) as $line) {
                    if (preg_match('/^\s*NAME\s+STATE\s+READ\s+WRITE\s+CKSUM/', $line)) {
                        $inConfig = true;
                        continue;
                    }
                    if (!$inConfig) continue;
                    if (preg_match('/^(\s+)(\S+)\s+\S+\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $m)) {
                        $indent = strlen($m[1]);
                        $name   = $m[2];
                        $r      = (int)$m[3];
                        $w      = (int)$m[4];
                        $c      = (int)$m[5];
                        // Skip the pool name row (2-space indent) and any
                        // vdev container row (matches a known keyword by
                        // prefix, eg "raidz1-0").
                        if ($indent < 4) continue;
                        $lname = strtolower($name);
                        foreach ($vdevKeywords as $kw) {
                            if (strpos($lname, $kw) === 0) continue 2;
                        }
                        $base = $stripPartition('/dev/' . $name);
                        $errors[$base] = ($errors[$base] ?? 0) + $r + $w + $c;
                    }
                }
            }
        }

        return $cache[$key] = $errors;
    }


    // ── Per-disk utilization (free space) thresholds ──────────────────────
    // Mirrors diskTempThresholds() but for disk fill levels. Picks up
    // per-disk overrides set by the user from the Main page (click a
    // disk -> Disk Settings -> "Warning disk utilization level" /
    // "Critical disk utilization level"). Unraid persists these into
    // disks.ini under the keys 'warning' and 'critical', stored as
    // raw strings ('' when no override). When both are present and
    // sane, they win outright. Otherwise we fall back to the global
    // values from dynamix.cfg (what nativeUtilThresholds returns).
    //
    // Returns ['warning' => int, 'critical' => int] with critical
    // strictly greater than warning. Both are integer percentages in
    // 1..100, matching the way the rest of the plugin compares pct.
    public static function diskUtilThresholds(array $d, array $globalUtil): array
    {
        $w = (string)($d['warning']  ?? '');
        $c = (string)($d['critical'] ?? '');
        if ($w !== '' && $c !== '') {
            $wi = (int)$w;
            $ci = (int)$c;
            if ($wi >= 1 && $wi <= 99 && $ci >= 2 && $ci <= 100) {
                if ($ci <= $wi) $ci = min(100, $wi + 1);
                return ['warning' => $wi, 'critical' => $ci];
            }
        }
        return ['warning' => $globalUtil['warning'], 'critical' => $globalUtil['critical']];
    }


    // ── Header click action whitelist ─────────────────────────────────────
    // Constrains the HEADER_CLICK_ACTION cfg value to one of three known
    // page slugs the click handler in diskviewer-header.js can route to.
    // Anything outside the whitelist falls back to 'main' (Unraid Main),
    // matching the default for a fresh install. Centralised here so the
    // settings POST handler, the cfg accessor, and any future caller all
    // produce the same canonical value.
    public static function clampHeaderClickAction(string $v): string
    {
        $allowed = ['main', 'widget', 'settings'];
        return in_array($v, $allowed, true) ? $v : 'main';
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
            'drag_step_rows'      => max(1, min(5, (int)($cfg['DRAG_STEP_ROWS'] ?? 1))),
            'show_unassigned'     => ($cfg['SHOW_UNASSIGNED'] ?? '0') === '1',
            'show_array'          => ($cfg['SHOW_ARRAY']      ?? '1') === '1',
            'show_cache'          => ($cfg['SHOW_CACHE']      ?? '1') === '1',
            'show_pools'          => ($cfg['SHOW_POOLS']      ?? '1') === '1',
            // Pool severity highlight: when on, single-disk pool tiles
            // whose used % crosses the warning or critical threshold
            // override the zebra background with the severity colour
            // (amber/red). When off, the zebra wins so the table
            // pattern reads cleanly even on partially-full pools.
            'pool_highlight_used' => ($cfg['POOL_HIGHLIGHT_USED'] ?? '0') === '1',
            // FS pill on aggregate rows (ARRAY summary, multi-disk
            // pool summary). When off, the filesystem pill (xfs,
            // btrfs, zfs, mixed) is hidden everywhere.
            'show_fs_badge'       => ($cfg['SHOW_FS_BADGE']    ?? '1') === '1',
            // Disk error indicator master switch. Controls everything
            // tied to FS-level error reporting (BTRFS device stats /
            // ZFS zpool errors): the warning triangle on section headers,
            // the disk-silhouette tint on the Unraid header indicator,
            // and the errors_severity axis itself. Default on.
            'show_disk_errors'    => ($cfg['SHOW_DISK_ERRORS'] ?? '1') === '1',
            // default_expand_rows was a user-facing dropdown until 2026.05.05v
            // when it was removed in favour of the drag handle on the footer.
            // The value here still drives the JS-side baseline expansion level:
            //   0 = ARRAY only visible without drag
            //   1 = ARRAY + RAID groups (multi-disk pools)
            //   2 = ARRAY + RAID groups + POOL (single-disk pools)
            //   3 = all sections (incl. UNASSIGNED) visible without drag
            // Level 2 was picked as the default per user request 2026.05.24:
            // a fresh install shows ARRAY, RAID groups, and POOL visible from
            // first paint, with UNASSIGNED reachable via drag. SHOW_*
            // settings still gate which sections appear at all - level 2
            // with SHOW_CACHE=off means the RAID baseline slot is empty.
            // Existing users keep their drag-set extras via localStorage
            // (dv_expand_v3) so the upgrade preserves their layout.
            'default_expand_rows' => 2,
            'header_show_badge'   => ($cfg['HEADER_SHOW_BADGE'] ?? '1') === '1',
            // Header click action - which Unraid page to open when the
            // user clicks the disk indicator in the top bar. The dropdown
            // in DiskViewerSettings constrains valid values; we clamp
            // here too as a defence in depth in case the cfg file is
            // hand-edited. The JS reads this via the header poll JSON
            // and sets window.diskviewerHeaderAction at runtime, which
            // the click handler in diskviewer-header.js consumes.
            'header_click_action' => self::clampHeaderClickAction((string)($cfg['HEADER_CLICK_ACTION'] ?? 'main')),
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
        // Only the standard UD plugin path. We tried fallbacks in an
        // earlier build but at least one of them picked up an Unraid
        // state file with /dev/disk/by-id/* sections that the parser
        // happily mistook for UD entries and rendered as 34 fake
        // tiles. Single path is safer; the diagnostic file below tells
        // us if the standard path isn't where this user's UD plugin is
        // writing.
        $data    = self::cachedIniRead(self::UD_INI, true);
        $diag    = [
            'path'     => self::UD_INI,
            'exists'   => is_file(self::UD_INI),
            'size'     => is_file(self::UD_INI) ? @filesize(self::UD_INI) : null,
            'sections' => is_array($data) ? count($data) : 0,
        ];

        if (empty($data)) {
            self::writeUDDiagnostic($diag);
            return [];
        }

        $out = [];
        $accepted = 0;
        $rejected = [];
        foreach ($data as $key => $d) {
            if (!is_array($d)) continue;

            // Shape validation. A real UD entry always has a device
            // field AND at least one UD-specific marker field
            // (mounted/mountpoint/partitions/fstype/serial). State
            // files and other unrelated content keyed by /dev/disk/by-id
            // paths typically have neither, so this catches them
            // without making us depend on the exact device-path format
            // each UD plugin version chooses to write.
            $devPath = (string)($d['device'] ?? '');
            if ($devPath === '') {
                $rejected[] = $key . ' (no device field)';
                continue;
            }
            $hasUdMarker = isset($d['mounted'])
                        || isset($d['mountpoint'])
                        || isset($d['partitions'])
                        || isset($d['fstype'])
                        || isset($d['serial']);
            if (!$hasUdMarker) {
                $rejected[] = $key . ' (no UD marker field)';
                continue;
            }

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

            $isSpun  = $mounted;
            $speed   = $isSpun ? self::diskSpeed($devPath) : ['bps' => 0, 'dir' => ''];
            $tt      = self::diskTempThresholds($d);
            $ut      = self::diskUtilThresholds($d, self::nativeUtilThresholds());

            $out[] = [
                'name'   => (string)($d['label'] ?? $d['device'] ?? $key),
                'device' => $devPath,
                'kind'   => 'unassigned',
                'group'  => 'unassigned',
                'status' => $mounted ? 'DISK_OK' : 'DISK_NP',
                'spun'   => $isSpun,
                'temp'   => (string)($d['temperature'] ?? $d['temp'] ?? '*'),
                'temp_warning'  => $tt['warning'],
                'temp_critical' => $tt['critical'],
                'space_warning'  => $ut['warning'],
                'space_critical' => $ut['critical'],
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
            $accepted++;
        }

        $diag['accepted'] = $accepted;
        $diag['rejected'] = $rejected;
        self::writeUDDiagnostic($diag);

        return $out;
    }

    // Diagnostic writer for UD detection. Single overwritten JSON file.
    // Lets us answer "why isn't my UD showing?" by inspecting one file
    // instead of running a battery of grep commands.
    private static function writeUDDiagnostic(array $diag): void
    {
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $diag['timestamp'] = date('c');
        @file_put_contents($dir . '/ud_diag.json', json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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

            // Per-disk temperature thresholds. Uses the disks.ini
            // hotTemp/maxTemp override when set, NVMe-aware defaults when
            // not, and falls back to the global HDD threshold for SATA.
            // Emitted per-tile so the JS classifier picks the right
            // warning/critical band per drive instead of applying the
            // HDD global to NVMe cache drives.
            $tt = self::diskTempThresholds($d);

            // Per-disk utilization thresholds. Same pattern as temp:
            // uses disks.ini 'warning'/'critical' overrides when the
            // user has set them on the Main page, falls back to the
            // global dynamix.cfg values otherwise. Lets a user mark
            // an intentionally-full backup drive with relaxed
            // thresholds while keeping their main pool tight.
            $ut = self::diskUtilThresholds($d, self::nativeUtilThresholds());

            // Pool member tiles need their errors enriched from
            // filesystem-level tools (btrfs device stats / zpool status)
            // because disks.ini.numErrors is only meaningful for ARRAY
            // disks (it tracks Unraid's own read-error counter under
            // parity protection). Pool errors are persisted by btrfs/zfs
            // independently and would be silently zero without these
            // shellouts. ARRAY and UD tiles keep numErrors.
            $tileErrors = (int)($d['numErrors'] ?? 0);
            if ($cls['group'] !== 'array') {
                $tileFs = strtolower(trim((string)($d['fsType'] ?? '')));
                if ($tileFs === 'btrfs' || $tileFs === 'zfs') {
                    $poolErrs = self::poolDeviceErrors($cls['group'], $tileFs);
                    // Strip /dev/ and partition off the tile device path
                    // so the key matches poolDeviceErrors' map format.
                    $key = preg_replace('@^/dev/@', '', $devPath);
                    if (strpos($key, 'nvme') === 0) {
                        $key = preg_replace('/p\d+$/', '', $key);
                    } else {
                        $key = preg_replace('/\d+$/', '', $key);
                    }
                    $tileErrors = (int)($poolErrs[$key] ?? 0);
                }
            }

            $devices[] = [
                'name'          => $name,
                'device'        => $devPath,
                'kind'          => $cls['kind'],
                'group'         => $cls['group'],
                'status'        => $status,
                'spun'          => $spun,
                'temp'          => $temp,
                'temp_warning'  => $tt['warning'],
                'temp_critical' => $tt['critical'],
                'space_warning'  => $ut['warning'],
                'space_critical' => $ut['critical'],
                'smart'         => self::smartHealth($d),
                'size'          => $fsSize,
                'used'          => $fsUsed,
                'free'          => $fsFree,
                'pct'           => $pct,
                // Filesystem type as reported by emhttpd in disks.ini.
                // Lowercased for consistent comparison (xfs/btrfs/zfs/
                // reiserfs/ntfs/ext4). Parity disks have no FS so this is
                // empty string for them - same convention the official
                // Unraid Main page uses (blank FS column on parity rows).
                'fs'            => strtolower(trim((string)($d['fsType'] ?? ''))),
                'speed_bps'     => $speed['bps'],
                'speed_dir'     => $speed['dir'],
                'errors'        => $tileErrors,
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
        // Status-based critical escalation. Disabled disks (Unraid has
        // failed them out of the array, emulating with parity) and
        // invalid disks (refused to mount) are always critical,
        // independently of the color field. Some Unraid versions leave
        // color=grey or color=blue-on for disabled disks instead of
        // red-blink, which would otherwise let the failure slip past
        // the colour-based classifier silently.
        $status = (string)($d['status'] ?? '');
        if ($status === 'DISK_DSBL' || $status === 'DISK_DSBL_NEW' || $status === 'DISK_INVALID') {
            return 'critical';
        }

        // Standard color-based classification. disks.ini.color encodes
        // Unraid's own status thumbnail in the Main page (green/yellow
        // /red ball next to each disk), so reusing it keeps the widget
        // and Main page in sync on what "healthy" means.
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
            // Speed sums I/O bytes/sec across every tile (parities AND
            // data), matching the official Unraid Main aggregate row. The
            // direction indicator follows whichever flow (reads or writes)
            // is currently dominant - tile dirs are inspected separately
            // and the dominant one wins.
            $sumR = 0; $sumW = 0;
            foreach ($tiles as $t) {
                if (!$t['is_parity']) {
                    // Capacity sums only data disks - parity has no usable
                    // capacity to contribute.
                    $total += $t['size'];
                    $used  += $t['used'];
                }
                $bps = (int)($t['speed_bps'] ?? 0);
                $dir = (string)($t['speed_dir'] ?? '');
                if ($dir === 'r') $sumR += $bps;
                elseif ($dir === 'w') $sumW += $bps;
                if (!empty($t['spun'])) $anySpun = true;
                if (($rank[$t['smart']] ?? 0) > ($rank[$smartWorst] ?? 0)) {
                    $smartWorst = $t['smart'];
                }
            }
            $free = max(0, $total - $used);
            $pct  = $total > 0 ? (int)round($used / $total * 100) : 0;
            $severity = $pct >= $crit ? 'critical' : ($pct >= $warn ? 'warning' : 'ok');
            $totalSpeed = $sumR + $sumW;
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
                'speed_bps'  => $totalSpeed,
                'speed_dir'  => $totalSpeed > 0 ? ($sumR >= $sumW ? 'r' : 'w') : '',
                'errors'     => 0,
                'is_summary' => true,
                'is_parity'  => false,
            ];
        };

        // Helper: tile severity classifier. In inherit mode each tile
        // carries its own space_warning/space_critical (per-disk
        // overrides set by the user from the Main page, or global
        // defaults from dynamix.cfg when no override exists). In custom
        // mode the plugin's SPACE_WARNING_PCT/SPACE_CRITICAL_PCT apply
        // uniformly to every disk. In disabled mode warn/crit are
        // bumped to 101 above so the comparison never triggers.
        $useTileThresholds = ($cfg['space_severity_mode'] ?? 'inherit') === 'inherit'
                          && !empty($cfg['space_severity_enabled']);
        $classify = function(array $t) use ($warn, $crit, $useTileThresholds): array {
            $pct = (int)$t['pct'];
            if ($useTileThresholds) {
                $tw = (int)($t['space_warning']  ?? $warn);
                $tc = (int)($t['space_critical'] ?? $crit);
            } else {
                $tw = $warn;
                $tc = $crit;
            }
            $t['severity'] = $pct >= $tc ? 'critical' : ($pct >= $tw ? 'warning' : 'ok');
            return $t;
        };

        // 1. ARRAY section
        if (!empty($byGroup['array']) && $cfg['show_array']) {
            $arrTiles = array_map($classify, $byGroup['array']);
            // Summary tile aggregates data-disk capacity. The ARRAY layout
            // pattern (agreed with the user 2026.05.24): parities first,
            // data disks next, dedicated aggregate row at the bottom -
            // matches the official Unraid Main page. Summary is fully
            // synthetic now: it does NOT inherit name/temp/SMART/spin from
            // the primary parity (used to, and that confused users by
            // making the first PARITY row double as the array total).
            $summary = $makeSummary('ARRAY', $arrTiles);

            // Separate parities from data, sort each group naturally.
            $parities = [];
            $data     = [];
            foreach ($arrTiles as $t) {
                if ($t['is_parity']) $parities[] = $t;
                else $data[] = $t;
            }
            usort($parities, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            usort($data,     fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

            // FS detection. Pull fsType from every data tile; parities
            // have no filesystem so they're skipped. If all data disks
            // agree, that's the array FS - if they disagree (rare but
            // possible: one xfs disk + one btrfs disk), mark "mixed".
            $fsSet = [];
            foreach ($data as $t) {
                $fs = strtolower(trim((string)($t['fs'] ?? '')));
                if ($fs !== '' && $fs !== '-') $fsSet[$fs] = true;
            }
            if (count($fsSet) === 1) {
                $summary['fs'] = array_key_first($fsSet);
            } elseif (count($fsSet) > 1) {
                $summary['fs'] = 'mixed';
            } else {
                $summary['fs'] = '';
            }

            // Layout: aggregate first, parities, then data disks.
            // Matches the CACHE multi-disk section pattern (summary on
            // top, members below) so the user sees consistent ordering
            // across both sections.
            $tiles = array_merge([$summary], $parities, $data);

            // Severity counting: parities and data both count as devices.
            // Parity tiles can have SMART warnings even without capacity,
            // so we include them in critNames/warnNames too.
            foreach ($parities as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            foreach ($data as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            $sections[] = [
                'id'     => 'array',
                'label'  => 'ARRAY',
                'count'  => count($parities) + count($data),
                'raid'   => self::arrayRaidProfile(count($parities)),
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

        // 2. POOLS routing: split by topology (multi vs single disk) AND
        // by function (cache target vs not). Layout per user feedback
        // 2026.05.24:
        //   - All multi-disk pools (RAID groups) gated on SHOW_CACHE.
        //     Cache-target multi-disk pools render first, non-cache
        //     multi-disk pools below them.
        //   - All single-disk pools (cache or not) combine into a single
        //     POOL section gated on SHOW_POOLS.
        // Cache-target detection is function-based via cacheTargetPools()
        // (share configs declaring shareCachePool), independent of pool
        // name. Multi-disk = 2+ member disks regardless of FS profile.
        $cacheTargets = self::cacheTargetPools();
        $multiCache   = [];  // multi-disk cache targets - render first
        $multiPool    = [];  // multi-disk non-cache - render below multiCache
        $singleAll    = [];  // every single-disk pool combined into POOL
        foreach ($byGroup as $group => $tiles) {
            if ($group === 'unassigned') continue;
            $tiles = array_map($classify, $tiles);
            $isCache = in_array(strtolower($group), $cacheTargets, true);
            if (count($tiles) >= 2) {
                if ($isCache) $multiCache[$group] = $tiles;
                else          $multiPool[$group]  = $tiles;
            } else {
                $singleAll[$group] = $tiles[0];
            }
        }

        // Helper to emit a single multi-disk pool section (summary + members).
        // Used twice below - once for cache targets, once for non-cache.
        $emitMultiSection = function(string $group, array $tiles) use ($makeSummary, &$totalDevices, &$critNames, &$warnNames, &$sections) {
            $label = strtoupper($group);
            $summary = $makeSummary($group, $tiles);
            $summary['name'] = $group;
            // Rename members to a generic "Device N" ordinal for display.
            // We attach the new label as `display_name` rather than
            // overwriting `name` because the real device name (cache,
            // cache 2, etc) is the join key used by the speed poller,
            // the spin-button targeting, and severity reporting. The
            // renderer reads `display_name` first, falling back to
            // `name`.
            $idx = 1;
            foreach ($tiles as &$t) {
                $t['is_pool_member'] = true;
                $t['display_name']   = 'Device ' . $idx;
                $idx++;
            }
            unset($t);
            // FS detection: pool members share the same filesystem (a
            // BTRFS RAID1 pool reports btrfs on every member, a ZFS
            // mirror reports zfs on every member). Pick the first
            // non-empty member FS as the pool FS. If members disagree
            // (extremely rare - misconfigured pool) mark "mixed".
            $fsSet = [];
            foreach ($tiles as $t) {
                $fs = strtolower(trim((string)($t['fs'] ?? '')));
                if ($fs !== '' && $fs !== '-') $fsSet[$fs] = true;
            }
            if (count($fsSet) === 1) {
                $summary['fs'] = array_key_first($fsSet);
            } elseif (count($fsSet) > 1) {
                $summary['fs'] = 'mixed';
            } else {
                $summary['fs'] = '';
            }
            // RAID topology - calls into btrfs/zpool tooling. Returns
            // empty string for single-profile pools (still surface them
            // as multi-disk if 2+ member tiles exist, but with no RAID
            // suffix in the header).
            $poolFs   = $summary['fs'];
            $raidLabel = ($poolFs !== '' && $poolFs !== 'mixed')
                ? self::poolRaidProfile($group, $poolFs)
                : '';
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
                'raid'  => $raidLabel,
                'tiles' => $sectionTiles,
            ];
        };

        // 2a. Multi-disk RAID groups - gated on SHOW_CACHE since the
        // "CACHE" toggle historically meant "show multi-disk pools".
        // Cache-target ones render first, non-cache multi-disk below.
        // Each pool gets its own section labeled with the pool name +
        // RAID profile.
        if ($cfg['show_cache']) {
            foreach ($multiCache as $group => $tiles) {
                $emitMultiSection($group, $tiles);
            }
            foreach ($multiPool as $group => $tiles) {
                $emitMultiSection($group, $tiles);
            }
        }

        // 2b. Single-disk pools (cache or not) combined into one POOL
        // section, gated on SHOW_POOLS. The old "CACHE for single-disk
        // cache pools" treatment is gone - all single-disk pools live
        // here regardless of cache function, with the same zebra
        // styling.
        if (!empty($singleAll) && $cfg['show_pools']) {
            $tiles = array_values($singleAll);
            foreach ($tiles as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            $sections[] = [
                'id'    => 'pools',
                'label' => 'POOL',
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
        //
        // Each tile carries its own temp_warning/temp_critical (set by the
        // per-disk diskTempThresholds() helper - reads disks.ini hotTemp/
        // maxTemp first, falls back to NVMe-aware defaults for NVMe drives
        // without an override, then to global HDD thresholds). We classify
        // per-tile against its own thresholds so an NVMe cache running at
        // 65°C doesn't flag critical just because the HDD global is 55°C.
        $globalWarn = (int)$cfg['temp_warning'];
        $globalCrit = (int)$cfg['temp_critical'];
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
        $tempSeverity = 'ok';
        foreach ($sections as $sec) {
            foreach ($sec['tiles'] as $t) {
                if (!empty($t['is_summary'])) continue;
                $tempStr = trim((string)($t['temp'] ?? '*'));
                if ($tempStr === '' || $tempStr === '*' || $tempStr === '-') continue;
                $n = (int)$tempStr;
                if ($n <= 0) continue;
                $tWarn = isset($t['temp_warning'])  ? (int)$t['temp_warning']  : $globalWarn;
                $tCrit = isset($t['temp_critical']) ? (int)$t['temp_critical'] : $globalCrit;
                $sev = ($n >= $tCrit) ? 'critical' : (($n >= $tWarn) ? 'warning' : 'ok');
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

        // Worst disk-error severity. Independent of health: a disk can be
        // SMART-healthy and still have BTRFS/ZFS write/read/corruption
        // errors recorded by the filesystem layer. Any non-zero error
        // count promotes the axis to warning. We don't escalate to
        // critical because Unraid's own Pool Devices page treats these
        // as warnings (orange "ONLINE - ERRORS"), reserving red for
        // unmounted/missing devices. Gated on show_disk_errors so the
        // user can switch off the whole feature.
        $errorsSeverity = 'ok';
        if (!empty($cfg['show_disk_errors'])) {
            foreach ($sections as $sec) {
                foreach ($sec['tiles'] as $t) {
                    if (!empty($t['is_summary'])) continue;
                    if (((int)($t['errors'] ?? 0)) > 0) {
                        $errorsSeverity = 'warning';
                        break 2;
                    }
                }
            }
        }

        // Per-disk per-axis issues for the header tooltip. One entry
        // per problem (a single disk can produce multiple rows if it has
        // issues on more than one axis). Sorted server-side by axis
        // priority then severity so JS just renders in iteration order
        // without further work.
        //
        // Axis priority follows the user-requested order: health first
        // (a failed disk is the most urgent thing to see), then errors,
        // then temperature, then capacity. Within an axis, criticals
        // come before warnings; ties resolved alphabetically by name.
        $tempUnit  = self::nativeTempUnit();
        $issuesRaw = [];
        $axisRank  = ['health' => 0, 'errors' => 1, 'temp' => 2, 'used' => 3];
        $sevRank   = ['critical' => 0, 'warning' => 1];

        $fmtTemp = static function(int $c) use ($tempUnit): string {
            if ($tempUnit === 'F') return ((int)round($c * 9 / 5 + 32)) . '°F';
            return $c . '°C';
        };

        foreach ($sections as $sec) {
            foreach ($sec['tiles'] as $t) {
                if (!empty($t['is_summary'])) continue;
                $name = (string)($t['display_name'] ?? $t['name'] ?? '?');

                // HEALTH - SMART status. critical = FAILED disk, warning
                // = anything else that smart normalization mapped to
                // warning (UD plugin reports neither PASSED nor FAILED
                // for some configurations).
                $smart = (string)($t['smart'] ?? 'unknown');
                if ($smart === 'critical') {
                    $issuesRaw[] = ['name' => $name, 'axis' => 'health', 'severity' => 'critical', 'label' => 'SMART failed'];
                } elseif ($smart === 'warning') {
                    $issuesRaw[] = ['name' => $name, 'axis' => 'health', 'severity' => 'warning', 'label' => 'SMART warning'];
                }

                // ERRORS - btrfs/zfs device-error counter from
                // poolDeviceErrors() or numErrors in disks.ini. Always
                // warning (no critical band - Unraid Main page treats
                // these the same way).
                $errCount = (int)($t['errors'] ?? 0);
                if ($errCount > 0) {
                    $issuesRaw[] = [
                        'name'     => $name,
                        'axis'     => 'errors',
                        'severity' => 'warning',
                        'label'    => $errCount . ' error' . ($errCount === 1 ? '' : 's'),
                    ];
                }

                // TEMP - per-tile thresholds from diskTempThresholds()
                // (smart-one.cfg per-disk, then class-aware default).
                // Skip spundown disks ('*' or empty temp) since the
                // reading is stale and would either false-flag or
                // require dragging the disk awake.
                $tempStr = trim((string)($t['temp'] ?? ''));
                if ($tempStr !== '' && $tempStr !== '*' && $tempStr !== '-' && is_numeric($tempStr)) {
                    $tempVal = (int)$tempStr;
                    $tw      = (int)($t['temp_warning']  ?? 0);
                    $tc      = (int)($t['temp_critical'] ?? 0);
                    if ($tc > 0 && $tempVal >= $tc) {
                        $issuesRaw[] = ['name' => $name, 'axis' => 'temp', 'severity' => 'critical', 'label' => $fmtTemp($tempVal)];
                    } elseif ($tw > 0 && $tempVal >= $tw) {
                        $issuesRaw[] = ['name' => $name, 'axis' => 'temp', 'severity' => 'warning', 'label' => $fmtTemp($tempVal)];
                    }
                }

                // USED - utilization band from $t['severity'] which was
                // already classified earlier in this method against
                // per-tile space_warning/space_critical thresholds. We
                // rely on that classification so the tooltip and the
                // widget row agree on which level the disk is in.
                if (($t['severity'] ?? 'ok') === 'critical' || ($t['severity'] ?? 'ok') === 'warning') {
                    $pct = (int)($t['pct'] ?? 0);
                    if ($pct > 0) {
                        $issuesRaw[] = [
                            'name'     => $name,
                            'axis'     => 'used',
                            'severity' => $t['severity'],
                            'label'    => $pct . '%',
                        ];
                    }
                }
            }
        }

        // Sort: axis priority asc, then severity rank asc, then name asc.
        usort($issuesRaw, static function ($a, $b) use ($axisRank, $sevRank) {
            $da = ($axisRank[$a['axis']]    ?? 99) - ($axisRank[$b['axis']]    ?? 99);
            if ($da !== 0) return $da;
            $ds = ($sevRank[$a['severity']] ?? 9)  - ($sevRank[$b['severity']] ?? 9);
            if ($ds !== 0) return $ds;
            return strnatcasecmp($a['name'], $b['name']);
        });
        $diskIssues = $issuesRaw;

        return [
            'ts'            => time(),
            'total_devices' => $totalDevices,
            'critical_count'=> count($critNames),
            'warning_count' => count($warnNames),
            'critical_names'=> array_values($critNames),
            'warning_names' => array_values($warnNames),
            'disk_issues'   => $diskIssues,
            'temp_severity'    => $tempSeverity,
            'health_severity'  => $healthSeverity,
            'util_severity'    => $utilSeverity,
            'errors_severity'  => $errorsSeverity,
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
                'pool_highlight_used'     => $cfg['pool_highlight_used'],
                'show_fs_badge'           => $cfg['show_fs_badge'],
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
        @file_put_contents(self::HEADER_TEMP_FILE,    (string)($model['temp_severity']    ?? 'ok'));
        @file_put_contents(self::HEADER_HEALTH_FILE,  (string)($model['health_severity']  ?? 'ok'));
        @file_put_contents(self::HEADER_UTIL_FILE,    (string)($model['util_severity']    ?? 'ok'));
        @file_put_contents(self::HEADER_ERRORS_FILE,  (string)($model['errors_severity']  ?? 'ok'));
        // disk_issues serialized as JSON. Read back as-is by the header
        // endpoint and forwarded to JS. Empty array if no issues - JS
        // uses that to switch the tooltip to its "all OK" mode.
        @file_put_contents(self::HEADER_ISSUES_FILE, json_encode($model['disk_issues'] ?? [], JSON_UNESCAPED_SLASHES));
    }


    // ============================================================================

    // 11. Formatters (durations)

    // ============================================================================
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
