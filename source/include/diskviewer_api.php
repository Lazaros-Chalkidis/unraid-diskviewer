<?php
/* ============================================================================
   DISK VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

declare(strict_types=1);

final class DiskViewerEndpoint
{

    private const PLUGIN_NAME        = 'diskviewer';
    private const CFG_FILE           = '/boot/config/plugins/diskviewer/diskviewer.cfg';
    private const DISKS_INI          = '/var/local/emhttp/disks.ini';

    private const DEVS_INI           = '/var/local/emhttp/devs.ini';
    private const VAR_INI            = '/var/local/emhttp/var.ini';
    private const POOLS_DIR          = '/boot/config/pools';

    private const SMART_ONE_FILE     = '/boot/config/smart-one.cfg';

    private static bool $toolMode = false;

    private const CACHE_FILE         = '/tmp/diskviewer_cache/state.json';
    private const HEADER_COUNT_FILE  = '/tmp/diskviewer_cache/header_count';
    private const HEADER_NAMES_FILE  = '/tmp/diskviewer_cache/header_names';
    private const HEADER_TEMP_FILE   = '/tmp/diskviewer_cache/header_temp';
    private const HEADER_TEMP_BLINK_FILE = '/tmp/diskviewer_cache/header_temp_blink';
    private const HEADER_HEALTH_FILE = '/tmp/diskviewer_cache/header_health';

    private const HEARTBEAT_FILE        = '/tmp/diskviewer_cache/widget_heartbeat';
    private const WIDGET_HEARTBEAT_TTL  = 172800;

    private const SMART_ATTRS_CACHE     = '/boot/config/plugins/diskviewer/smart_attrs.json';
    private const SMART_ATTRS_TTL       = 3600;
    private const SCRUB_CACHE           = '/tmp/diskviewer_cache/scrub_status.json';
    private const SCRUB_TTL             = 3600;
    private const SCRUB_SCHED_CACHE     = '/tmp/diskviewer_cache/scrub_sched.json';
    private const SCRUB_SCHED_TTL       = 600;
    private const HEADER_UTIL_FILE   = '/tmp/diskviewer_cache/header_util';
    private const HEADER_ERRORS_FILE = '/tmp/diskviewer_cache/header_errors';

    private const HEADER_ISSUES_FILE = '/tmp/diskviewer_cache/header_issues';

    private static array $iniCache = [];

    // one parse per file per request, the same ini gets read from several call paths
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

    // csrf gate for spin, token comes from unraid's var.ini
    public static function validateCsrf(): bool
    {

        $expected = '';
        $var = @parse_ini_file(self::VAR_INI);
        if (is_array($var) && !empty($var['csrf_token'])) {
            $expected = (string)$var['csrf_token'];
        } elseif (isset($GLOBALS['var']) && is_array($GLOBALS['var']) && !empty($GLOBALS['var']['csrf_token'])) {
            $expected = (string)$GLOBALS['var']['csrf_token'];
        }

        // no token server-side: fresh install or a setup that delivers it elsewhere. emhttpd auth still gates the session, so let it through
        if ($expected === '') {

            return true;
        }

        $sent = '';
        if (array_key_exists('csrf_token', $_POST)) {
            $sent = (string)$_POST['csrf_token'];
        } elseif (array_key_exists('csrf_token', $_GET)) {
            $sent = (string)$_GET['csrf_token'];
        } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $sent = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        }

        if ($sent === '') return false;

        return $sent === $expected;
    }

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

    public static function nativeTempUnit(): string
    {
        $cfg  = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $unit = strtoupper((string)($cfg['display']['unit'] ?? 'C'));
        return ($unit === 'F') ? 'F' : 'C';
    }

    // unraid "Enable NVME power monitoring" toggle (Settings > Disk Settings)
    public static function nativeShowPower(): bool
    {
        $cfg = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        return (string)($cfg['display']['power'] ?? '') === '1';
    }

    // current nvme draw in watts, same method unraid uses: active power state, then its wattage from smartctl
    public static function nvmePower(string $device): float
    {
        static $cache = [];
        $dev = preg_replace('@^/dev/@', '', trim($device));
        if ($dev === '' || strpos($dev, 'nvme') !== 0 || !preg_match('/^[a-z0-9]+$/', $dev)) return 0.0;
        if (isset($cache[$dev])) return $cache[$dev];

        $state = trim((string)@shell_exec("nvme get-feature /dev/$dev -f2 2>/dev/null | grep -Pom1 'value:.+\\K.$'"));
        if (!preg_match('/^[0-9a-fA-F]$/', $state)) return $cache[$dev] = 0.0;

        $w = trim((string)@shell_exec("smartctl -c /dev/$dev 2>/dev/null | grep -Pom1 '^ *$state [+-] +\\K[^W]+'"));
        return $cache[$dev] = (is_numeric($w) ? (float)$w : 0.0);
    }

    public static function arrayRaidProfile(int $parityCount): string
    {
        switch ($parityCount) {
            case 1:  return 'RAID 4';
            case 2:  return 'RAID 6';
            case 3:  return 'RAID 7';
            default: return '';
        }
    }

    public static function poolRaidProfile(string $pool, string $fs, string $mountpoint = ''): string
    {
        static $cache = [];
        $key = strtolower($pool) . '|' . strtolower($fs) . '|' . $mountpoint;
        if (isset($cache[$key])) return $cache[$key];

        $result = '';
        $fs     = strtolower(trim($fs));
        // the internal boot pool is mounted at /boot, not /mnt/<pool>, so honour an explicit mountpoint when given
        $mount  = ($mountpoint !== '') ? $mountpoint : '/mnt/' . $pool;
        if (!is_dir($mount)) return $cache[$key] = '';

        if ($fs === 'btrfs') {

            $out = @shell_exec('btrfs filesystem df ' . escapeshellarg($mount) . ' 2>/dev/null');
            if ($out && preg_match('/^Data,\s*([A-Z0-9]+):/mi', $out, $m)) {
                $profile = strtoupper($m[1]);
                if ($profile !== 'SINGLE') {

                    if (preg_match('/^RAID(\d+)$/', $profile, $rm)) {
                        $result = 'RAID ' . $rm[1];
                    } else {
                        $result = $profile;
                    }
                }
            }
        } elseif ($fs === 'zfs') {

            // the disks.ini name prefix is not necessarily the zpool name, so for a known mountpoint resolve the real pool from the dataset mounted there
            $zpool = $pool;
            if ($mountpoint !== '') {
                $list = @shell_exec('zfs list -H -o name,mountpoint 2>/dev/null');
                if (is_string($list)) {
                    foreach (preg_split('/\r?\n/', trim($list)) as $line) {
                        $parts = preg_split('/\t+/', $line);
                        if (count($parts) >= 2 && $parts[1] === $mountpoint) {
                            $zpool = explode('/', $parts[0])[0];
                            break;
                        }
                    }
                }
            }
            $out = @shell_exec('zpool status ' . escapeshellarg($zpool) . ' 2>/dev/null');
            if ($out && preg_match('/^\s+(mirror|raidz[0-9]?)/mi', $out, $m)) {
                $vdev = strtolower($m[1]);
                if ($vdev === 'mirror') {
                    $result = 'Mirror';
                } elseif (preg_match('/^raidz([0-9]?)$/', $vdev, $rm)) {

                    $result = 'RAIDZ' . ($rm[1] !== '' ? $rm[1] : '1');
                }
            }
        }

        return $cache[$key] = $result;
    }

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

            $use  = strtolower(trim((string)($cfg['shareUseCache']  ?? 'no'), " \"'"));
            $pool = strtolower(trim((string)($cfg['shareCachePool'] ?? ''),   " \"'"));
            if ($pool === '' || $use === 'no') continue;
            if (in_array($use, ['yes', 'prefer', 'only'], true)) {
                $targets[$pool] = true;
            }
        }
        return $cache = array_keys($targets);
    }

    // per-disk override (smart-one.cfg) wins, then disks.ini pairs, then the dynamix global
    public static function diskTempThresholds(array $d): array
    {

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

        $dev        = strtolower((string)($d['device'] ?? ''));
        $rotational = (string)($d['rotational'] ?? '');
        $isNvme     = (strpos($dev, 'nvme') !== false);
        $isSsd      = !$isNvme && ($rotational === '0');  // ssd/nvme use hotssd/maxssd, spinners use hot/max

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

            if (strpos($dev, 'nvme') === 0) {
                return preg_replace('/p\d+$/', '', $dev);
            }
            return preg_replace('/\d+$/', '', $dev);
        };

        if ($fs === 'btrfs') {
            $out = @shell_exec('btrfs device stats ' . escapeshellarg($mount) . ' 2>/dev/null');
            if ($out) {

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

    public static function scrubStatus(string $pool, string $fs): array
    {
        $res = ['last_ts' => null, 'frag' => null, 'next_ts' => null];
        $fs  = strtolower($fs);
        $mount = '/mnt/' . $pool;

        if ($fs === 'btrfs') {
            if (!is_dir($mount)) return $res;
            $out = @shell_exec('btrfs scrub status ' . escapeshellarg($mount) . ' 2>/dev/null');
            if (is_string($out) && $out !== '') {

                if (preg_match('/Scrub started:\s*(.+)$/im', $out, $m)
                    || preg_match('/scrub started at\s+(.+?)\s+and/i', $out, $m)) {
                    $ts = strtotime(trim($m[1]));
                    if ($ts) $res['last_ts'] = $ts;
                }
            }

        } elseif ($fs === 'zfs') {
            $out = @shell_exec('zpool status ' . escapeshellarg($pool) . ' 2>/dev/null');
            if (is_string($out) && $out !== '') {

                if (preg_match('/scan:.*\bon\s+(.+)$/im', $out, $m)) {
                    $ts = strtotime(trim($m[1]));
                    if ($ts) $res['last_ts'] = $ts;
                }
            }
            $frag = @shell_exec('zpool list -H -o frag ' . escapeshellarg($pool) . ' 2>/dev/null');
            if (is_string($frag)) {
                $frag = trim($frag);
                if ($frag !== '' && $frag !== '-') $res['frag'] = $frag;
            }
        }

        return $res;
    }

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

    public static function clampHeaderClickAction(string $v): string
    {
        $allowed = ['main', 'widget', 'settings'];
        return in_array($v, $allowed, true) ? $v : 'main';
    }

    public static function nativeUtilThresholds(): array
    {
        $cfg = self::cachedIniRead('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $d   = $cfg['display'] ?? [];
        $warn = (int)($d['warning']  ?? 70);
        $crit = (int)($d['critical'] ?? 90);

        if ($warn < 1 || $warn > 99)  $warn = 70;
        if ($crit < 2 || $crit > 100) $crit = 90;
        if ($crit <= $warn) $crit = min(100, $warn + 1);
        return ['warning' => $warn, 'critical' => $crit];
    }

    public static function config(): array
    {
        $cfg  = self::cachedIniRead(self::CFG_FILE);

        if (self::$toolMode) {
            static $toolDefaults = [
                'REFRESH_ENABLED'     => '1',
                'REFRESH_INTERVAL'    => '10',
                'ENABLE_SPIN_BUTTON'  => '1',
                'SHOW_UNASSIGNED'     => '0',
                'SHOW_ARRAY'          => '1',
                'SHOW_CACHE'          => '1',
                'SHOW_POOLS'          => '1',
                'HEADER_SHOW_BADGE'   => '1',
                'HEADER_CLICK_ACTION' => 'main',
                'SHOW_DECIMAL_PCT'    => '1',
                'SHOW_USED_COLUMN'    => '1',
                'SHOW_ID_TOOLTIP'     => '1',
                'SHOW_MISSING_DISKS'  => '1',
                'SHOW_BOOT_DEVICE'    => '0',
                'FONT_SIZE'           => 'default',
                'SPACE_SEVERITY_MODE' => 'inherit',
                'SPACE_WARNING_PCT'   => '70',
                'SPACE_CRITICAL_PCT'  => '90',
                'HIDDEN_DEVICES'      => '',
            ];
            foreach ($toolDefaults as $base => $tdef) {
                $tk = 'TOOL_' . $base;
                $cfg[$base] = (isset($cfg[$tk]) && $cfg[$tk] !== '') ? $cfg[$tk] : $tdef;
            }
        }

        $temp = self::nativeTempThresholds();
        $util = self::nativeUtilThresholds();

        $spaceMode = strtolower((string)($cfg['SPACE_SEVERITY_MODE'] ?? 'inherit'));
        if (!in_array($spaceMode, ['inherit', 'custom', 'disabled'], true)) {
            $spaceMode = 'inherit';
        }
        $spaceWarn = $util['warning'];
        $spaceCrit = $util['critical'];
        if ($spaceMode === 'custom') {

            $cw = (int)($cfg['SPACE_WARNING_PCT']  ?? $util['warning']);
            $cc = (int)($cfg['SPACE_CRITICAL_PCT'] ?? $util['critical']);
            if ($cw < 1 || $cw > 99)  $cw = $util['warning'];
            if ($cc < 2 || $cc > 100) $cc = $util['critical'];
            if ($cc <= $cw) $cc = min(100, $cw + 1);
            $spaceWarn = $cw;
            $spaceCrit = $cc;
        }

        return [

            'warning_pct'             => $spaceWarn,
            'critical_pct'            => $spaceCrit,
            'space_severity_mode'     => $spaceMode,
            'space_severity_enabled'  => ($spaceMode !== 'disabled'),
            'temp_warning'            => $temp['warning'],
            'temp_critical'           => $temp['critical'],

            'temp_unit'           => self::nativeTempUnit(),
            'refresh_enabled'     => ($cfg['REFRESH_ENABLED']  ?? '1') === '1',
            'refresh_interval'    => max(5, (int)($cfg['REFRESH_INTERVAL'] ?? 20)),
            'drag_step_rows'      => max(1, min(5, (int)($cfg['DRAG_STEP_ROWS'] ?? 1))),
            'show_unassigned'     => ($cfg['SHOW_UNASSIGNED'] ?? '0') === '1',
            'show_array'          => ($cfg['SHOW_ARRAY']      ?? '1') === '1',
            'show_cache'          => ($cfg['SHOW_CACHE']      ?? '1') === '1',
            'show_pools'          => ($cfg['SHOW_POOLS']      ?? '1') === '1',

            'pool_highlight_used' => ($cfg['POOL_HIGHLIGHT_USED'] ?? '0') === '1',

            'show_fs_badge'       => ($cfg['SHOW_FS_BADGE']    ?? '1') === '1',

            'show_disk_errors'    => ($cfg['SHOW_DISK_ERRORS'] ?? '1') === '1',

            'show_decimal_pct'    => ($cfg['SHOW_DECIMAL_PCT']  ?? '1') === '1',

            'show_used_column'    => ($cfg['SHOW_USED_COLUMN']  ?? '1') === '1',

            'show_id_tooltip'     => ($cfg['SHOW_ID_TOOLTIP']   ?? '1') === '1',

            'show_missing_disks'  => ($cfg['SHOW_MISSING_DISKS'] ?? '1') === '1',

            'show_boot_device'    => ($cfg['SHOW_BOOT_DEVICE']  ?? '0') === '1',

            'show_power'          => self::nativeShowPower(),

            'show_section_indicators' => ($cfg['SHOW_SECTION_INDICATORS'] ?? '1') === '1',

            'font_size'           => in_array(($cfg['FONT_SIZE'] ?? 'default'), ['default', 'large', 'small'], true)
                                        ? ($cfg['FONT_SIZE'] ?? 'default')
                                        : 'default',

            'default_expand_rows' => 2,
            'header_show_badge'   => ($cfg['HEADER_SHOW_BADGE'] ?? '1') === '1',

            'header_click_action' => self::clampHeaderClickAction((string)($cfg['HEADER_CLICK_ACTION'] ?? 'main')),
            'enable_spin_button'  => ($cfg['ENABLE_SPIN_BUTTON'] ?? '1') === '1',
            'hidden_devices'      => array_values(array_filter(preg_split('/\s+/', trim((string)($cfg['HIDDEN_DEVICES'] ?? ''))), 'strlen')),
        ];
    }

    private static function parseDisksIni(): array
    {
        return self::cachedIniRead(self::DISKS_INI, true);
    }

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

        usort($pools, static fn($a, $b) => strlen($b) - strlen($a));
        return self::$poolsCache = $pools;
    }

    // pool names marked bootable in disks.ini (type=Cache, bootPool=dedicated)
    private static function dedicatedBootPools(array $disks): array
    {
        $names = [];
        foreach ($disks as $d) {
            if (!is_array($d)) continue;
            if (strtolower((string)($d['type'] ?? '')) !== 'cache') continue;
            if (strtolower((string)($d['bootPool'] ?? '')) !== 'dedicated') continue;
            $name = preg_replace('/\d+$/', '', (string)($d['name'] ?? ''));
            if ($name !== '') $names[$name] = true;
        }
        return array_keys($names);
    }

    // total/used/free bytes of a mounted filesystem, read with df. used for zfs pools that report no fs sizes in disks.ini
    private static function mountUsage(string $mp): ?array
    {
        if ($mp === '' || $mp[0] !== '/') return null;
        $out = @shell_exec('df -kP ' . escapeshellarg($mp) . ' 2>/dev/null');
        if (!is_string($out) || $out === '') return null;
        $lines = preg_split('/\r?\n/', trim($out));
        $last  = is_array($lines) ? end($lines) : '';
        if (!is_string($last) || $last === '') return null;
        // df -kP data line: filesystem  1k-blocks  used  available  capacity%  mounted-on
        if (!preg_match('/(\d+)\s+(\d+)\s+(\d+)\s+\d+%\s+\S+$/', $last, $m)) return null;
        return [
            'size' => (int)$m[1] * 1024,
            'used' => (int)$m[2] * 1024,
            'free' => (int)$m[3] * 1024,
        ];
    }

    private static function parseUnassigned(): array
    {

        $diag = ['source' => 'live', 'method' => 'lsblk - disks.ini'];

        $assigned = [];
        $disksIni = self::cachedIniRead(self::DISKS_INI, true);
        if (is_array($disksIni)) {
            foreach ($disksIni as $d) {
                $dev = is_array($d) ? trim((string)($d['device'] ?? '')) : '';
                if ($dev !== '') $assigned['/dev/' . $dev] = true;
            }
        }

        $mounts = @file('/proc/mounts', FILE_IGNORE_NEW_LINES);
        if (is_array($mounts)) {
            foreach ($mounts as $ln) {
                $f = explode(' ', $ln);
                if (($f[1] ?? '') === '/boot' && strncmp((string)($f[0] ?? ''), '/dev/', 5) === 0) {
                    $boot = self::parentDiskName(preg_replace('@^/dev/@', '', $f[0]));
                    if ($boot !== '') $assigned['/dev/' . $boot] = true;
                    break;
                }
            }
        }
        $diag['assigned'] = count($assigned);

        $rows = self::lsblkRows();
        if (empty($rows)) {
            $diag['note'] = 'lsblk returned nothing';
            self::writeUDDiagnostic($diag);
            return [];
        }
        $disks   = [];
        $byParent = [];
        foreach ($rows as $r) {
            $name = (string)($r['NAME'] ?? '');
            $type = (string)($r['TYPE'] ?? '');
            if ($name === '') continue;
            if ($type === 'disk') {

                if (preg_match('/^(zram|loop|sr|md|dm-|nbd|ram)/', $name)) continue;
                $disks[$name] = $r;
            } elseif ($type === 'part') {
                $pk = (string)($r['PKNAME'] ?? '');
                if ($pk === '') $pk = self::parentDiskName($name);
                $byParent[$pk][] = $r;
            }
        }

        $devs = self::udDevsInfo();

        $globalUtil = self::nativeUtilThresholds();
        $skipped    = [];
        $plan       = [];
        $diskList   = [];

        foreach ($disks as $name => $disk) {
            $path = '/dev/' . $name;
            if (isset($assigned[$path])) { $skipped[] = $name . ' (assigned)'; continue; }

            $scan = $byParent[$name] ?? [];
            if ((string)($disk['MOUNTPOINT'] ?? '') !== '' || (string)($disk['FSTYPE'] ?? '') !== '') {
                $scan[] = $disk;
            }

            $mounted = false;
            $fsTotal = 0; $fsUsed = 0; $fsFree = 0;
            $fsType  = ''; $label = '';
            foreach ($scan as $pt) {
                if ($fsType === '' && (string)($pt['FSTYPE'] ?? '') !== '') $fsType = (string)$pt['FSTYPE'];
                if ($label  === '' && (string)($pt['LABEL'] ?? '') !== '') $label  = (string)$pt['LABEL'];
                $mp = trim((string)($pt['MOUNTPOINT'] ?? ''));
                if ($mp !== '' && is_dir($mp)) {
                    $tot = @disk_total_space($mp);
                    $fre = @disk_free_space($mp);
                    if ($tot !== false && $fre !== false) {
                        $mounted  = true;
                        $fsTotal += (int)$tot;
                        $fsFree  += (int)$fre;
                        $fsUsed  += (int)$tot - (int)$fre;
                    }
                }
            }

            $rawSize = (int)($disk['SIZE'] ?? 0);
            $model   = trim((string)($disk['MODEL'] ?? ''));

            $size = ($mounted && $fsTotal > 0) ? $fsTotal : $rawSize;

            $dispName = $label !== '' ? $label : ($model !== '' ? $model : $name);

            $info = $devs[$name] ?? [];
            $diskList[] = $path;
            $plan[] = [
                'path'    => $path,
                'name'    => $dispName,
                'mounted' => $mounted,
                'size'    => $size,
                'used'    => $fsUsed,
                'free'    => $fsFree,
                'devN'    => (string)($info['name'] ?? ''),
                'id'      => (string)($info['id'] ?? ''),
                'raw'     => $rawSize,
            ];
        }

        $smartMap = self::smartAttrsForDevices($diskList);

        $out = [];
        foreach ($plan as $p) {
            $attrs = $smartMap[$p['path']] ?? null;
            $temp  = (is_array($attrs) && $attrs['temp'] !== null) ? (string)$attrs['temp'] : '*';

            [, $vSev] = self::smartVerdict($attrs);
            $smart = $vSev === 'critical' ? 'critical'
                   : ($vSev === 'warning' ? 'warning'
                   : ($vSev === 'ok' ? 'healthy' : 'unknown'));

            $speed = $p['mounted'] ? self::diskSpeed($p['path']) : ['bps' => 0, 'dir' => ''];

            $tt = self::diskTempThresholds(['id' => $p['id']]);
            $ut = self::diskUtilThresholds([], $globalUtil);
            $isNvmeU = (strpos(preg_replace('@^/dev/@', '', (string)$p['path']), 'nvme') === 0);

            $out[] = [
                'name'   => $p['name'],
                'device' => $p['path'],
                'main_dev' => $p['devN'],
                'kind'   => 'unassigned',
                'group'  => 'unassigned',
                'status' => $p['mounted'] ? 'DISK_OK' : 'DISK_NP',
                'spun'   => $p['mounted'],

                'no_capacity' => !$p['mounted'],
                'temp'   => $temp,
                'temp_warning'  => $tt['warning'],
                'temp_critical' => $tt['critical'],
                'space_warning'  => $ut['warning'],
                'space_critical' => $ut['critical'],
                'smart'  => $smart,
                'size'   => $p['size'],
                'raw_size' => $p['raw'],

                'ident_id'  => ($p['id'] !== '' ? $p['id'] : $p['name']),
                'dev_short' => basename($p['path']),
                'used'   => $p['used'],
                'free'   => $p['free'],
                'pct'    => $p['size'] > 0 ? round($p['used'] / $p['size'] * 100, 2) : 0,
                'speed_bps'    => $speed['bps'],
                'speed_dir'    => $speed['dir'],
                'errors'       => 0,
                'is_summary'   => false,
                'is_parity'    => false,
                'spin_disabled'=> $isNvmeU,
                'is_nvme'      => $isNvmeU,
                'byid'         => self::byId((string)$p['path']),
            ];
        }

        $diag['enumerated'] = count($disks);
        $diag['accepted']   = count($out);
        $diag['skipped']    = $skipped;
        self::writeUDDiagnostic($diag);

        return $out;
    }

    private static ?array $byIdCache = null;
    // stable per-device identity from /dev/disk/by-id. multi-slot readers share a raw serial but get distinct usb-...-0:N links, so the lun keeps them apart
    private static function byIdMap(): array
    {
        if (self::$byIdCache !== null) return self::$byIdCache;
        $map  = [];
        $best = [];
        $dir  = '/dev/disk/by-id';
        $prio = ['wwn-' => 1, 'nvme-eui' => 2, 'nvme-' => 3, 'ata-' => 4, 'scsi-' => 5, 'usb-' => 6];
        $entries = @scandir($dir);
        if (is_array($entries)) {
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..' || preg_match('/-part\d+$/', $name)) continue;
                $tgt = @readlink($dir . '/' . $name);
                if ($tgt === false) continue;
                $path = '/dev/' . basename($tgt);
                $rank = 50;
                foreach ($prio as $pre => $r) { if (strpos($name, $pre) === 0) { $rank = $r; break; } }
                if (!isset($best[$path]) || $rank < $best[$path][0]) $best[$path] = [$rank, $name];
            }
        }
        foreach ($best as $path => $pn) $map[$path] = $pn[1];
        return self::$byIdCache = $map;
    }
    private static function byId(string $devPath): string
    {
        if ($devPath === '') return '';
        $p = (strpos($devPath, '/dev/') === 0) ? $devPath : '/dev/' . ltrim($devPath, '/');
        return self::byIdMap()[$p] ?? '';
    }
    private static function humanBytes(int $b): string
    {
        if ($b <= 0) return '0 B';
        $u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0; $v = (float)$b;
        while ($v >= 1000 && $i < 5) { $v /= 1000; $i++; }
        if ($i === 0) return $b . ' B';
        $dec = $v >= 100 ? 0 : ($v >= 10 ? 1 : 2);
        $s = number_format($v, $dec, '.', '');
        if (strpos($s, '.') !== false) $s = rtrim(rtrim($s, '0'), '.');
        return $s . ' ' . $u[$i];
    }
    private static function lsblkRows(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = [];
        $out = @shell_exec('lsblk -b -P -o NAME,TYPE,SIZE,MODEL,FSTYPE,LABEL,MOUNTPOINT,PKNAME 2>/dev/null');
        if (is_string($out) && $out !== '') {
            foreach (preg_split('/\r?\n/', trim($out)) as $line) {
                if ($line === '') continue;
                $r = [];
                if (preg_match_all('/([A-Z]+)="([^"]*)"/', $line, $m, PREG_SET_ORDER)) {
                    foreach ($m as $kv) $r[$kv[1]] = $kv[2];
                }
                if (!empty($r['NAME'])) $cached[] = $r;
            }
        }
        return $cached;
    }

    private static function parentDiskName(string $name): string
    {
        if (strpos($name, 'nvme') === 0) return preg_replace('/p\d+$/', '', $name);
        return preg_replace('/\d+$/', '', $name);
    }

    private static function udDevsInfo(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $cached = [];
        $ini = self::cachedIniRead(self::DEVS_INI, true);
        if (is_array($ini)) {
            foreach ($ini as $sec) {
                if (!is_array($sec)) continue;
                $dev = trim((string)($sec['device'] ?? ''));
                if ($dev === '') continue;
                $cached[$dev] = [
                    'name' => trim((string)($sec['name'] ?? '')),
                    'id'   => trim((string)($sec['id'] ?? '')),
                ];
            }
        }
        return $cached;
    }

    private static function writeUDDiagnostic(array $diag): void
    {
        $dir = dirname(self::CACHE_FILE);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $diag['timestamp'] = date('c');
        @file_put_contents($dir . '/ud_diag.json', json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private static function diskSpeed(string $devPath): array
    {
        if ($devPath === '') return ['bps' => 0, 'dir' => ''];

        $devShort = basename($devPath);
        if ($devShort === '' || !preg_match('/^[a-zA-Z0-9]+$/', $devShort)) {
            return ['bps' => 0, 'dir' => ''];
        }

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

        if (!$shutdownRegistered) {
            $shutdownRegistered = true;
            $snapshot = $cur;
            $file     = $cacheFile;
            // stash this poll's counters so the next request can diff against them
            register_shutdown_function(function () use ($snapshot, $file) {
                @mkdir(dirname($file), 0755, true);
                @file_put_contents($file, json_encode($snapshot), LOCK_EX);
            });
        }

        $key = $devShort;
        if (!isset($cur['stats'][$key])) {
            $stripped = preg_replace('/p?\d+$/', '', $devShort);  // no row for the partition, try the whole disk
            if ($stripped !== $devShort && isset($cur['stats'][$stripped])) {
                $key = $stripped;
            } else {
                return ['bps' => 0, 'dir' => ''];
            }
        }
        if (!isset($prev['stats'][$key]) || !isset($prev['ts'])) {

            return ['bps' => 0, 'dir' => ''];
        }

        $elapsed = (int)$cur['ts'] - (int)$prev['ts'];
        // snapshot too old to trust, skip this round
        if ($elapsed <= 0 || $elapsed > 300) {

            return ['bps' => 0, 'dir' => ''];
        }

        $readDelta  = max(0, (int)$cur['stats'][$key]['r_sec'] - (int)$prev['stats'][$key]['r_sec']);
        $writeDelta = max(0, (int)$cur['stats'][$key]['w_sec'] - (int)$prev['stats'][$key]['w_sec']);
        $readBps    = (int)(($readDelta  * 512) / $elapsed);  // diskstats counts 512-byte sectors
        $writeBps   = (int)(($writeDelta * 512) / $elapsed);
        $totalBps   = $readBps + $writeBps;
        if ($totalBps <= 0) return ['bps' => 0, 'dir' => ''];
        $dir = $readBps >= $writeBps ? 'r' : 'w';
        return ['bps' => $totalBps, 'dir' => $dir];
    }

    private static function readDiskstats(): array
    {
        $out = ['ts' => time(), 'stats' => []];
        $lines = @file('/proc/diskstats', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $out;
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 11) continue;
            $dev   = $parts[2];
            $r_sec = (int)$parts[5];
            $w_sec = (int)$parts[9];
            $out['stats'][$dev] = ['r_sec' => $r_sec, 'w_sec' => $w_sec];
        }
        return $out;
    }

    private static function classify(array $d, array $poolNames, array $bootPools = [], array $bootDevices = []): array
    {
        $name = (string)($d['name'] ?? '');
        $type = strtolower((string)($d['type'] ?? ''));

        if ($type === 'parity' || strpos($name, 'parity') === 0) {
            $rawSize = (int)($d['size']   ?? 0);
            $rawDev  = trim((string)($d['device'] ?? ''));
            $rawId   = trim((string)($d['id']   ?? ''));
            $rawIdSb = trim((string)($d['idSb'] ?? ''));

            if ($rawSize <= 0 && $rawDev === '' && $rawId === '' && $rawIdSb === '') {
                return ['kind' => 'skip', 'group' => '', 'is_parity' => true];
            }
            return ['kind' => 'array', 'group' => 'array', 'is_parity' => true];
        }

        if ($type === 'data' || preg_match('/^disk\d+$/', $name)) {

            $rawSize = (int)($d['size'] ?? 0);
            $rawDev  = trim((string)($d['device'] ?? ''));
            $rawId   = trim((string)($d['id']   ?? ''));
            $rawIdSb = trim((string)($d['idSb'] ?? ''));
            if ($rawSize <= 0 && $rawDev === '' && $rawId === '' && $rawIdSb === '') {
                return ['kind' => 'skip', 'group' => '', 'is_parity' => false];
            }
            return ['kind' => 'array', 'group' => 'array', 'is_parity' => false];
        }

        // genuine usb stick: real unraid type, never the name
        if ($type === 'flash') {
            return ['kind' => 'boot', 'group' => 'boot', 'is_parity' => false, 'boot_pool' => ''];
        }

        // internal boot pool (unraid 7.3): members carry type=Boot, grouped by name prefix, the same way unraid does in boot_filter
        if ($type === 'boot') {
            $bp = preg_replace('/\d+$/', '', $name);
            return ['kind' => 'boot', 'group' => 'boot', 'is_parity' => false, 'boot_pool' => $bp];
        }

        // a second entry for a physical disk that is already a boot member (the small bootable partition): hide it so the boot pool is not listed twice
        $dev = trim((string)($d['device'] ?? ''));
        if ($dev !== '' && in_array($dev, $bootDevices, true)) {
            return ['kind' => 'skip', 'group' => '', 'is_parity' => false];
        }

        // a cache pool flagged bootPool=dedicated is the boot pool shown elsewhere, so hide its cache view (unraid hides it too)
        $bp = preg_replace('/\d+$/', '', $name);
        if ($bp !== '' && in_array($bp, $bootPools, true)) {
            return ['kind' => 'skip', 'group' => '', 'is_parity' => false];
        }

        foreach ($poolNames as $pname) {
            if ($name === $pname || strpos($name, $pname) === 0) {
                return ['kind' => 'pool', 'group' => $pname, 'is_parity' => false];
            }
        }

        return ['kind' => 'pool', 'group' => $name, 'is_parity' => false];
    }

    public static function devices(): array
    {

        static $cache = null;
        if ($cache !== null) return $cache;

        $disks = self::parseDisksIni();
        $poolNames = self::listPools();
        $bootPools = self::dedicatedBootPools($disks);

        // physical devices that belong to the internal boot pool (type=Boot), used to hide duplicate partition entries on the same disks
        $bootDevices = [];
        foreach ($disks as $bd) {
            if (is_array($bd) && strtolower((string)($bd['type'] ?? '')) === 'boot') {
                $bdev = trim((string)($bd['device'] ?? ''));
                if ($bdev !== '') $bootDevices[] = $bdev;
            }
        }

        $devices = [];

        $classMap    = [];
        $memberCount = [];
        foreach ($disks as $key => $d) {
            if (!is_array($d)) continue;
            $c = self::classify($d, $poolNames, $bootPools, $bootDevices);
            $classMap[$key] = $c;
            if ($c['kind'] === 'skip') continue;
            $memberCount[$c['group']] = ($memberCount[$c['group']] ?? 0) + 1;
        }

        $cfg = self::config();
        $showMissing = !empty($cfg['show_missing_disks']);
        $showBoot    = !empty($cfg['show_boot_device']);
        $showPower   = !empty($cfg['show_power']);

        foreach ($disks as $key => $d) {
            if (!is_array($d)) continue;
            $cls = $classMap[$key] ?? null;
            if ($cls === null || $cls['kind'] === 'skip') continue;

            if ($cls['kind'] === 'boot' && !$showBoot) continue;

            $name    = (string)($d['name'] ?? $key);
            $status  = (string)($d['status'] ?? '');

            $temp = trim((string)($d['temp'] ?? '*'));
            if ($temp === '' || $temp === '0') $temp = '*';

            $color         = strtolower(trim((string)($d['color'] ?? '')));
            $statusLower   = strtolower($status);
            $isGreyColor   = (strpos($color, 'grey') !== false || strpos($color, 'gray') !== false);
            $spundownFlag  = ((string)($d['spundown'] ?? '0') === '1');
            $standbyStatus = ($statusLower === 'disk_ok_standby' || strpos($statusLower, 'standby') !== false);
            $noTemp        = ($temp === '*' || $temp === '-' || $temp === '');

            $spun = !($isGreyColor || $spundownFlag || $standbyStatus);

            $fsSize  = (int)($d['fsSize'] ?? 0) * 1024;
            $fsFree  = (int)($d['fsFree'] ?? 0) * 1024;
            $fsUsed  = (int)($d['fsUsed'] ?? 0) * 1024;

            // internal boot zfs pool: disks.ini carries no fs sizes for it, so read the mounted pool directly, like the native boot pool figure
            if ($cls['kind'] === 'boot' && $fsUsed === 0 && $fsFree === 0
                && strtolower((string)($d['fsStatus'] ?? '')) === 'mounted') {
                $mp = trim((string)($d['fsMountpoint'] ?? ''));
                $mu = ($mp !== '') ? self::mountUsage($mp) : null;
                if ($mu !== null) {
                    $fsSize = $mu['size'];
                    $fsUsed = $mu['used'];
                    $fsFree = $mu['free'];
                }
            }

            $rawSize = (int)($d['size'] ?? 0) * 1024;
            if ($fsSize === 0) {

                $fsSize = $rawSize;
            }
            $pct = $fsSize > 0 ? round($fsUsed / $fsSize * 100, 2) : 0;

            $devPath = (string)($d['device'] ?? '');
            $speed   = $spun ? self::diskSpeed($devPath) : ['bps' => 0, 'dir' => ''];

            $power = ($showPower && strpos(preg_replace('@^/dev/@', '', $devPath), 'nvme') === 0)
                ? self::nvmePower($devPath) : 0.0;

            $isArrayMember     = ($cls['kind'] === 'array');
            $isMultiPoolMember = ($cls['kind'] === 'pool' && (($memberCount[$cls['group']] ?? 0) >= 2));
            $isBoot            = ($cls['kind'] === 'boot');
            $isNvmeDisk        = (strpos(preg_replace('@^/dev/@', '', $devPath), 'nvme') === 0);

            $notInstalled      = ($devPath === '' && $rawSize <= 0);

            if ($notInstalled && !$showMissing) continue;
            $spinDisabled      = ($isArrayMember || $isMultiPoolMember || $notInstalled || $isBoot || $isNvmeDisk);

            $tt = self::diskTempThresholds($d);

            $ut = self::diskUtilThresholds($d, self::nativeUtilThresholds());

            $tileErrors = (int)($d['numErrors'] ?? 0);
            if ($cls['group'] !== 'array') {
                $tileFs = strtolower(trim((string)($d['fsType'] ?? '')));
                if ($tileFs === 'btrfs' || $tileFs === 'zfs') {
                    $errPool  = ($cls['kind'] === 'boot' && ($cls['boot_pool'] ?? '') !== '') ? $cls['boot_pool'] : $cls['group'];
                    $poolErrs = self::poolDeviceErrors($errPool, $tileFs);

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

                'display_name'  => ($cls['kind'] === 'boot' ? 'Boot Device' : null),
                'device'        => $devPath,
                'kind'          => $cls['kind'],
                'group'         => $cls['group'],
                'boot_pool'     => ($cls['boot_pool'] ?? ''),
                'status'        => $status,
                'spun'          => $spun,
                'temp'          => $temp,
                'power'         => $power,
                'temp_warning'  => $tt['warning'],
                'temp_critical' => $tt['critical'],
                'space_warning'  => $ut['warning'],
                'space_critical' => $ut['critical'],
                'smart'         => self::smartHealth($d),
                'size'          => $fsSize,
                'raw_size'      => $rawSize,

                'ident_id'      => (trim((string)($d['id'] ?? '')) !== '')
                                     ? trim((string)$d['id'])
                                     : trim((string)($d['idSb'] ?? '')),
                'dev_short'     => ($devPath !== '' ? basename($devPath) : ''),
                'used'          => $fsUsed,
                'free'          => $fsFree,
                'pct'           => $pct,

                'fs'            => (strtolower(trim((string)($d['type'] ?? ''))) === 'flash')
                                     ? (strtolower(trim((string)($d['fsType'] ?? ''))) ?: 'vfat')
                                     : strtolower(trim((string)($d['fsType'] ?? ''))),
                'speed_bps'     => $speed['bps'],
                'speed_dir'     => $speed['dir'],
                'errors'        => $tileErrors,
                'is_summary'    => false,
                'is_parity'     => $cls['is_parity'],
                'spin_disabled' => $spinDisabled,
                'is_nvme'       => $isNvmeDisk,
                'byid'          => self::byId($devPath),
                'not_installed' => $notInstalled,
            ];
        }

        if ($cfg['show_unassigned']) {
            foreach (self::parseUnassigned() as $ud) {
                $devices[] = $ud;
            }
        }

        return $cache = $devices;
    }

    private static function smartHealth(array $d): string
    {

        $status = (string)($d['status'] ?? '');
        if ($status === 'DISK_DSBL' || $status === 'DISK_DSBL_NEW' || $status === 'DISK_INVALID') {
            return 'critical';
        }

        $color = strtolower((string)($d['color'] ?? ''));
        if ($color === '') return 'unknown';
        if (strpos($color, 'red')    !== false) return 'critical';
        if (strpos($color, 'yellow') !== false) return 'warning';
        if (strpos($color, 'green')  !== false) return 'healthy';
        if (strpos($color, 'grey')   !== false || strpos($color, 'gray') !== false) return 'healthy';
        return 'unknown';
    }

    public static function buildModel(): array
    {
        $cfg      = self::config();
        $devices  = self::devices();
        $hideList = $cfg['hidden_devices'];

        if (empty($cfg['space_severity_enabled'])) {
            $warn = 101;
            $crit = 101;
        } else {
            $warn = $cfg['warning_pct'];
            $crit = $cfg['critical_pct'];
        }

        $byGroup = [];
        $missingDevices = 0;
        foreach ($devices as $d) {
            if (!empty($d['not_installed'])) $missingDevices++;
            $g = $d['group'];
            if (!isset($byGroup[$g])) $byGroup[$g] = [];
            $byGroup[$g][] = $d;
        }

        $sections = [];
        $critNames = [];
        $warnNames = [];
        $totalDevices = 0;

        $makeSummary = function(string $label, array $tiles, ?array $capOverride = null) use ($warn, $crit): array {
            $total = 0; $used = 0;
            $smartWorst = 'healthy';
            $rank = ['unknown' => 0, 'healthy' => 1, 'warning' => 2, 'critical' => 3];
            $anySpun = false;

            $sumR = 0; $sumW = 0;
            foreach ($tiles as $t) {
                if (!$t['is_parity']) {

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

            if ($capOverride !== null) {
                $total = (int)$capOverride['size'];
                $used  = (int)$capOverride['used'];
                $free  = (int)$capOverride['free'];
            }
            $pct  = $total > 0 ? round($used / $total * 100, 2) : 0;
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

        if (!empty($byGroup['array']) && $cfg['show_array']) {
            $arrTiles = array_map($classify, $byGroup['array']);

            $summary = $makeSummary('ARRAY', $arrTiles);

            $parities = [];
            $data     = [];
            foreach ($arrTiles as $t) {
                if ($t['is_parity']) $parities[] = $t;
                else $data[] = $t;
            }
            usort($parities, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
            usort($data,     fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

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

            $tiles = array_merge([$summary], $parities, $data);

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

        unset($byGroup['array']);

        $cacheTargets = self::cacheTargetPools();
        $multiCache   = [];
        $multiPool    = [];
        $singleAll    = [];
        foreach ($byGroup as $group => $tiles) {

            if ($group === 'unassigned' || $group === 'boot') continue;
            $tiles = array_map($classify, $tiles);
            $isCache = in_array(strtolower($group), $cacheTargets, true);
            if (count($tiles) >= 2) {
                if ($isCache) $multiCache[$group] = $tiles;
                else          $multiPool[$group]  = $tiles;
            } else {
                $bid = (string)($tiles[0]['byid'] ?? '');
                if ($bid !== '' && in_array($bid, $hideList, true)) continue;
                $singleAll[$group] = $tiles[0];
            }
        }

        $emitMultiSection = function(string $group, array $tiles) use ($makeSummary, &$totalDevices, &$critNames, &$warnNames, &$sections) {
            $label = strtoupper($group);

            $capOverride = null;
            foreach ($tiles as $ct) {
                $cfs = strtolower(trim((string)($ct['fs'] ?? '')));
                if ($cfs !== '' && $cfs !== '-' && (int)($ct['size'] ?? 0) > 0) {
                    if ($capOverride === null || (int)$ct['size'] > (int)$capOverride['size']) {
                        $capOverride = [
                            'size' => (int)$ct['size'],
                            'used' => (int)$ct['used'],
                            'free' => (int)$ct['free'],
                        ];
                    }
                }
            }
            $summary = $makeSummary($group, $tiles, $capOverride);
            $summary['name'] = $group;

            $idx = 1;
            foreach ($tiles as &$t) {
                $t['is_pool_member'] = true;
                $t['display_name']   = 'Device ' . $idx;

                if (!empty($t['raw_size'])) $t['size'] = (int)$t['raw_size'];
                $idx++;
            }
            unset($t);

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

        if ($cfg['show_cache']) {
            foreach ($multiCache as $group => $tiles) {
                $emitMultiSection($group, $tiles);
            }
            foreach ($multiPool as $group => $tiles) {
                $emitMultiSection($group, $tiles);
            }
        }

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

        if (!empty($byGroup['unassigned']) && $cfg['show_unassigned']) {
            $tiles = [];
            foreach (array_map($classify, $byGroup['unassigned']) as $t) {
                $bid = (string)($t['byid'] ?? '');
                if ($bid !== '' && in_array($bid, $hideList, true)) continue;
                $tiles[] = $t;
            }
            foreach ($tiles as $t) {
                $totalDevices++;
                if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
            }
            if (!empty($tiles)) {
                $sections[] = [
                    'id'    => 'unassigned',
                    'label' => 'UNASSIGNED',
                    'count' => count($tiles),
                    'tiles' => $tiles,
                ];
            }
        }

        if (!empty($byGroup['boot']) && $cfg['show_boot_device']) {
            $tiles = array_map($classify, $byGroup['boot']);

            if (count($tiles) >= 2) {
                // dedicated boot pool: render like a pool (summary, members, raid)
                $bootName = '';
                foreach ($tiles as $bt) {
                    if (($bt['boot_pool'] ?? '') !== '') { $bootName = $bt['boot_pool']; break; }
                }

                $capOverride = null;
                // prefer the mounted pool member that reports real usage (the boot fs), so the summary shows the pool's used/free like the native figure
                foreach ($tiles as $ct) {
                    $cfs = strtolower(trim((string)($ct['fs'] ?? '')));
                    if ($cfs !== '' && $cfs !== '-' && ((int)($ct['used'] ?? 0) > 0 || (int)($ct['free'] ?? 0) > 0)) {
                        $capOverride = ['size' => (int)$ct['size'], 'used' => (int)$ct['used'], 'free' => (int)$ct['free']];
                        break;
                    }
                }
                // fallback: the largest member with a valid filesystem
                if ($capOverride === null) {
                    foreach ($tiles as $ct) {
                        $cfs = strtolower(trim((string)($ct['fs'] ?? '')));
                        if ($cfs !== '' && $cfs !== '-' && (int)($ct['size'] ?? 0) > 0) {
                            if ($capOverride === null || (int)$ct['size'] > (int)$capOverride['size']) {
                                $capOverride = ['size' => (int)$ct['size'], 'used' => (int)$ct['used'], 'free' => (int)$ct['free']];
                            }
                        }
                    }
                }
                $summary = $makeSummary('BOOT', $tiles, $capOverride);
                $summary['name'] = 'BOOT';

                $idx = 1;
                foreach ($tiles as &$t) {
                    $t['is_pool_member'] = true;
                    $t['display_name']   = 'Device ' . $idx;
                    if (!empty($t['raw_size'])) $t['size'] = (int)$t['raw_size'];
                    $idx++;
                }
                unset($t);

                $fsSet = [];
                foreach ($tiles as $t) {
                    $fs = strtolower(trim((string)($t['fs'] ?? '')));
                    if ($fs !== '' && $fs !== '-') $fsSet[$fs] = true;
                }
                // a boot pool's real filesystem is the non-vfat one; vfat shows up only as a per-member fallback on members without their own fsType
                if (count($fsSet) > 1 && isset($fsSet['vfat'])) unset($fsSet['vfat']);
                if (count($fsSet) === 1)   $summary['fs'] = array_key_first($fsSet);
                elseif (count($fsSet) > 1) $summary['fs'] = 'mixed';
                else                       $summary['fs'] = '';

                $poolFs    = $summary['fs'];
                $raidLabel = ($bootName !== '' && $poolFs !== '' && $poolFs !== 'mixed')
                    ? self::poolRaidProfile($bootName, $poolFs, '/boot')
                    : '';

                foreach ($tiles as $t) {
                    $totalDevices++;
                    if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                    elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
                }
                $sections[] = [
                    'id'    => 'boot',
                    'label' => 'BOOT',
                    'count' => count($tiles),
                    'raid'  => $raidLabel,
                    'tiles' => array_merge([$summary], $tiles),
                ];
            } else {
                // single boot device (usb stick or one-disk boot pool)
                foreach ($tiles as $t) {
                    $totalDevices++;
                    if ($t['severity'] === 'critical') $critNames[] = $t['name'];
                    elseif ($t['severity'] === 'warning') $warnNames[] = $t['name'];
                }
                $sections[] = [
                    'id'    => 'boot',
                    'label' => 'BOOT',
                    'count' => count($tiles),
                    'tiles' => $tiles,
                ];
            }
        }

        $globalWarn = (int)$cfg['temp_warning'];
        $globalCrit = (int)$cfg['temp_critical'];
        $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
        $tempSeverity = 'ok';

        $tempBlink = false;
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
                if ($rank[$sev] > $rank[$tempSeverity]) $tempSeverity = $sev;
                if ($n >= $tCrit * 1.10) $tempBlink = true;

                if ($tempSeverity === 'critical' && $tempBlink) break 2;
            }
        }

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

                $smart = (string)($t['smart'] ?? 'unknown');
                if ($smart === 'critical') {
                    $issuesRaw[] = ['name' => $name, 'axis' => 'health', 'severity' => 'critical', 'label' => 'SMART failed'];
                } elseif ($smart === 'warning') {
                    $issuesRaw[] = ['name' => $name, 'axis' => 'health', 'severity' => 'warning', 'label' => 'SMART warning'];
                }

                $errCount = (int)($t['errors'] ?? 0);
                if ($errCount > 0) {
                    $issuesRaw[] = [
                        'name'     => $name,
                        'axis'     => 'errors',
                        'severity' => 'warning',
                        'label'    => $errCount . ' error' . ($errCount === 1 ? '' : 's'),
                    ];
                }

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
            'missing_devices' => $missingDevices,
            'critical_count'=> count($critNames),
            'warning_count' => count($warnNames),
            'critical_names'=> array_values($critNames),
            'warning_names' => array_values($warnNames),
            'disk_issues'   => $diskIssues,
            'temp_severity'    => $tempSeverity,
            'temp_blink'       => $tempBlink,
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
                'show_used_column'        => $cfg['show_used_column'],
                'show_decimal_pct'        => $cfg['show_decimal_pct'],
                'show_id_tooltip'         => $cfg['show_id_tooltip'],
                'show_power'              => $cfg['show_power'],
                'font_size'               => $cfg['font_size'],
            ],
        ];
    }

    public static function writeHeaderCache(array $model): void
    {
        @mkdir('/tmp/diskviewer_cache', 0755, true);
        @file_put_contents(self::HEADER_COUNT_FILE,  (string)$model['critical_count']);
        @file_put_contents(self::HEADER_NAMES_FILE,  implode('|', $model['critical_names']));
        @file_put_contents(self::HEADER_TEMP_FILE,    (string)($model['temp_severity']    ?? 'ok'));
        @file_put_contents(self::HEADER_TEMP_BLINK_FILE, !empty($model['temp_blink']) ? '1' : '0');
        @file_put_contents(self::HEADER_HEALTH_FILE,  (string)($model['health_severity']  ?? 'ok'));
        @file_put_contents(self::HEADER_UTIL_FILE,    (string)($model['util_severity']    ?? 'ok'));
        @file_put_contents(self::HEADER_ERRORS_FILE,  (string)($model['errors_severity']  ?? 'ok'));

        @file_put_contents(self::HEADER_ISSUES_FILE, json_encode($model['disk_issues'] ?? [], JSON_UNESCAPED_SLASHES));
    }

    private static function parseSmartctl(string $device): ?array
    {
        $dev = $device;
        if (strncmp($dev, '/dev/', 5) !== 0) $dev = '/dev/' . $dev;
        if (strpos($dev, 'nvme') !== false) {
            $dev = preg_replace('/p\d+$/', '', $dev);
        } else {
            $dev = preg_replace('/\d+$/', '', $dev);
        }

        $bin = is_executable('/usr/sbin/smartctl') ? '/usr/sbin/smartctl'
             : (is_executable('/sbin/smartctl') ? '/sbin/smartctl' : 'smartctl');
        // -n standby so we never spin a sleeping disk up just to read it
        $cmd = $bin . ' -n standby -A -i ' . escapeshellarg($dev) . ' 2>/dev/null';
        $out = @shell_exec($cmd);
        if (!is_string($out) || $out === '') return null;

        // disk was asleep, nothing useful came back
        if (stripos($out, 'STANDBY') !== false
            && stripos($out, 'Power_On_Hours') === false
            && stripos($out, 'Power On Hours') === false) {
            return null;
        }

        $attrs = ['age_hours' => null, 'realloc' => null, 'pending' => null, 'crc' => null, 'wear_pct' => null, 'temp' => null];

        // nvme health output, different layout from ata smart attributes
        if (stripos($out, 'Percentage Used') !== false || stripos($out, 'Power On Hours') !== false) {
            if (preg_match('/Power On Hours:\s*([\d,]+)/i', $out, $m)) {
                $attrs['age_hours'] = (int)str_replace(',', '', $m[1]);
            }
            if (preg_match('/Percentage Used:\s*(\d+)%/i', $out, $m)) {
                $attrs['wear_pct'] = (int)$m[1];
            }
            if (preg_match('/Media and Data Integrity Errors:\s*([\d,]+)/i', $out, $m)) {
                $attrs['pending'] = (int)str_replace(',', '', $m[1]);
            }
            if (preg_match('/Temperature:\s*(\d+)\s*Celsius/i', $out, $m)) {
                $attrs['temp'] = (int)$m[1];
            }
            return $attrs;
        }

        $rawById = [];
        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (!preg_match('/^\s*(\d+)\s+\S+\s+0x[0-9a-f]+\s+.*?(\d[\d,]*)\s*$/i', $line, $m)) continue;
            $rawById[(int)$m[1]] = (int)str_replace(',', '', $m[2]);
        }
        if (isset($rawById[9]))   $attrs['age_hours'] = $rawById[9];
        if (isset($rawById[5]))   $attrs['realloc']   = $rawById[5];
        if (isset($rawById[197])) $attrs['pending']   = $rawById[197];
        if (isset($rawById[199])) $attrs['crc']       = $rawById[199];  // 5 realloc, 197 pending, 199 crc, 9 hours

        if (preg_match('/^\s*(?:194|190)\s+.*?\s-\s+(\d+)/im', $out, $mt)) {
            $attrs['temp'] = (int)$mt[1];
        }

        if ($attrs['age_hours'] === null && $attrs['realloc'] === null
            && $attrs['pending'] === null && $attrs['crc'] === null
            && $attrs['temp'] === null) {
            return null;
        }
        return $attrs;
    }

    private static function scrubScheduleForPools(array $pools): array
    {
        if (empty($pools)) return [];
        $names = array_keys($pools);
        $now   = time();

        $raw = @file_get_contents(self::SCRUB_SCHED_CACHE);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['ts'])
                && ($now - (int)$decoded['ts']) < self::SCRUB_SCHED_TTL
                && isset($decoded['map']) && is_array($decoded['map'])) {

                $out = [];
                foreach ($names as $n) {
                    if (isset($decoded['map'][$n])) $out[$n] = (int)$decoded['map'][$n];
                }
                return $out;
            }
        }

        $cronText = '';
        foreach ((glob('/etc/cron.d/*') ?: []) as $f) {
            if (is_file($f)) { $c = @file_get_contents($f); if (is_string($c)) $cronText .= "\n" . $c; }
        }
        foreach (['/var/spool/cron/crontabs/root', '/etc/crontab'] as $f) {
            if (is_file($f)) { $c = @file_get_contents($f); if (is_string($c)) $cronText .= "\n" . $c; }
        }

        $sched = [];
        foreach (preg_split('/\r?\n/', $cronText) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            if (!preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+)$/', $line, $m)) continue;
            $fields = [$m[1], $m[2], $m[3], $m[4], $m[5]];
            $cmd    = $m[6];

            $targets = self::scrubTargetsFromCommand($cmd, $names);
            if (empty($targets)) continue;
            $next = self::cronNextTs($fields, $now);
            if ($next === null) continue;
            foreach ($targets as $pool) {
                if (!isset($sched[$pool]) || $next < $sched[$pool]) $sched[$pool] = $next;
            }
        }

        @mkdir('/tmp/diskviewer_cache', 0755, true);
        @file_put_contents(self::SCRUB_SCHED_CACHE, json_encode(['ts' => $now, 'map' => $sched], JSON_UNESCAPED_SLASHES));
        return $sched;
    }

    private static function scrubTargetsFromCommand(string $cmd, array $poolNames): array
    {
        $found = [];
        $scan = function (string $text) use (&$found, $poolNames) {

            $canon = function (string $cand) use ($poolNames) {
                $cand = trim($cand);
                if (stripos($cand, '/mnt/') === 0) $cand = substr($cand, 5);
                $cand = trim($cand, '/');
                foreach ($poolNames as $pn) {
                    if (strcasecmp((string)$pn, $cand) === 0) return $pn;
                }
                return null;
            };

            if (preg_match_all('#(?:zfs|btrfs)_scrub\s+start\s+([^\s;&|]+)#i', $text, $mm)) {
                foreach ($mm[1] as $p) { $c = $canon($p); if ($c !== null) $found[$c] = true; }
            }

            if (preg_match_all('/zpool\s+scrub\s+(?:-\S+\s+)*([^\s;&|]+)/i', $text, $mm)) {
                foreach ($mm[1] as $p) { $c = $canon($p); if ($c !== null) $found[$c] = true; }
            }

            if (preg_match_all('#btrfs\s+scrub\s+start[^\n;&|]*?/mnt/([^\s/;&|]+)#i', $text, $mm)) {
                foreach ($mm[1] as $p) { $c = $canon($p); if ($c !== null) $found[$c] = true; }
            }

            if (stripos($text, 'scrub') !== false
                && preg_match_all('#/mnt/([A-Za-z0-9_.\-]+)#', $text, $mm)) {
                foreach ($mm[1] as $p) { $c = $canon($p); if ($c !== null) $found[$c] = true; }
            }
        };

        $scan($cmd);

        if (stripos($cmd, 'user.scripts') !== false
            && preg_match('#(/boot/config/plugins/user\.scripts/scripts/[^\s"\']+/script)#', $cmd, $sm)) {
            $sf = $sm[1];
            if (is_file($sf)) { $body = @file_get_contents($sf); if (is_string($body)) $scan($body); }
        }

        return array_keys($found);
    }

    private static function cronNextTs(array $fields, int $from): ?int
    {
        if (count($fields) !== 5) return null;
        $min  = self::cronField($fields[0], 0, 59);
        $hour = self::cronField($fields[1], 0, 23);
        $dom  = self::cronField($fields[2], 1, 31);
        $mon  = self::cronField($fields[3], 1, 12);
        $dowF = $fields[4];
        $dow  = self::cronField($dowF, 0, 7);
        if ($min === null || $hour === null || $dom === null || $mon === null || $dow === null) return null;
        if (isset($dow[7])) { $dow[0] = true; }  // cron treats 0 and 7 both as sunday
        $domRestricted = (trim($fields[2]) !== '*');
        $dowRestricted = (trim($dowF) !== '*');

        $t = (intdiv($from, 60) + 1) * 60;
        $limit = $from + 366 * 86400;
        for (; $t <= $limit; $t += 60) {
            $mn = (int)date('i', $t);
            if (!isset($min[$mn])) continue;
            $hr = (int)date('G', $t);
            if (!isset($hour[$hr])) continue;
            $mo = (int)date('n', $t);
            if (!isset($mon[$mo])) continue;
            $dm = (int)date('j', $t);
            $dw = (int)date('w', $t);
            $domOk = isset($dom[$dm]);
            $dowOk = isset($dow[$dw]);
            // when both day-of-month and day-of-week are set, cron ORs them
            $dayOk = ($domRestricted && $dowRestricted) ? ($domOk || $dowOk)
                   : (($domRestricted ? $domOk : true) && ($dowRestricted ? $dowOk : true));
            if (!$dayOk) continue;
            return $t;
        }
        return null;
    }

    private static function cronField(string $f, int $lo, int $hi): ?array
    {
        $f = trim($f);
        if ($f === '') return null;
        $set = [];
        foreach (explode(',', $f) as $part) {
            $part = trim($part);
            if ($part === '') continue;
            $step = 1;
            if (strpos($part, '/') !== false) {
                [$part, $stepStr] = explode('/', $part, 2);
                $step = (int)$stepStr;
                if ($step < 1) return null;
            }
            if ($part === '*' || $part === '') {
                $start = $lo; $end = $hi;
            } elseif (strpos($part, '-') !== false) {
                [$a, $b] = explode('-', $part, 2);
                if (!is_numeric($a) || !is_numeric($b)) return null;
                $start = (int)$a; $end = (int)$b;
            } else {
                if (!is_numeric($part)) return null;
                $start = $end = (int)$part;
            }
            if ($start > $end) return null;
            for ($v = $start; $v <= $end; $v += $step) {
                if ($v >= $lo && $v <= $hi) $set[$v] = true;
            }
        }
        return $set ?: null;
    }

    private static function smartAttrsForDevices(array $devices): array
    {
        $now = time();
        $cache = [];
        $raw = @file_get_contents(self::SMART_ATTRS_CACHE);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $cache = $decoded;
        }

        $result = [];
        $dirty = false;
        foreach (array_unique($devices) as $dev) {
            if ($dev === '') continue;
            $entry = $cache[$dev] ?? null;
            if (is_array($entry) && isset($entry['ts']) && ($now - (int)$entry['ts']) < self::SMART_ATTRS_TTL) {
                $result[$dev] = $entry['attrs'] ?? null;
                continue;
            }
            $attrs = self::parseSmartctl($dev);
            if ($attrs === null) {

                $prev = (is_array($entry) && isset($entry['attrs']) && is_array($entry['attrs'])) ? $entry['attrs'] : null;
                if ($prev !== null) {

                    $cache[$dev]  = ['ts' => $now, 'attrs' => $prev, 'stale' => true];
                    $result[$dev] = $prev;
                } else {

                    $cache[$dev]  = ['ts' => 0, 'attrs' => null];
                    $result[$dev] = null;
                }
            } else {
                $cache[$dev]  = ['ts' => $now, 'attrs' => $attrs];
                $result[$dev] = $attrs;
            }
            $dirty = true;
        }

        if ($dirty) {

            $dir = dirname(self::SMART_ATTRS_CACHE);
            @mkdir($dir, 0755, true);
            $tmp = self::SMART_ATTRS_CACHE . '.tmp';
            if (@file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_SLASHES)) !== false) {
                @rename($tmp, self::SMART_ATTRS_CACHE);
            }
        }
        return $result;
    }

    private static function smartVerdict(?array $a): array
    {
        if (!is_array($a)) return ['', 'na'];
        $age  = $a['age_hours'];
        $rea  = $a['realloc'];
        $pen  = $a['pending'];
        $crc  = $a['crc'];
        $wear = $a['wear_pct'];

        if ($pen !== null && $pen > 0)     return ['Replace soon', 'critical'];
        if ($rea !== null && $rea > 10)    return ['Replace soon', 'critical'];
        if ($rea !== null && $rea > 0)     return ['Aging', 'warning'];
        if ($wear !== null && $wear >= 90) return ['Replace soon', 'critical'];
        if ($wear !== null && $wear >= 75) return ['Aging', 'warning'];
        if ($crc !== null && $crc > 100)   return ['Check cable', 'warning'];
        if ($age !== null && $age > 43800) return ['Aging', 'warning'];
        if ($age === null && $rea === null && $pen === null && $crc === null) return ['', 'na'];
        return ['Healthy', 'ok'];
    }

    private static function unraidDateFormat(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        $cfg = @parse_ini_file('/boot/config/plugins/dynamix/dynamix.cfg', true);
        $fmt = is_array($cfg) ? (string)($cfg['display']['date'] ?? '') : '';
        if ($fmt === '') return $cached = 'Y/m/d';

        if (strpos($fmt, '%') !== false) {

            $fmt = strtr($fmt, [
                '%Y' => 'Y', '%y' => 'y',
                '%m' => 'm', '%-m' => 'n',
                '%d' => 'd', '%-d' => 'j', '%e' => 'j',
                '%B' => 'F', '%b' => 'M', '%h' => 'M',
                '%A' => '', '%a' => '',
                '%j' => '', '%u' => '', '%w' => '',
            ]);
            $fmt = preg_replace('/%-?\w/', '', $fmt);
        } else {

            $fmt = preg_replace('/[lDNw]/', '', $fmt);
        }

        $fmt = trim($fmt);
        $fmt = preg_replace('/^[\s,\/.\-]+/', '', $fmt);
        $fmt = preg_replace('/[\s,]+$/', '', $fmt);

        if ($fmt === '' || !preg_match('/[dejmnYy]/', $fmt)) return $cached = 'Y/m/d';
        return $cached = $fmt;
    }

    private static function scrubStatusForPools(array $pools): array
    {
        $now = time();
        $cache = [];
        $raw = @file_get_contents(self::SCRUB_CACHE);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $cache = $decoded;
        }

        $result = [];
        $dirty = false;
        foreach ($pools as $name => $fs) {
            $key = $name . '|' . $fs;
            $entry = $cache[$key] ?? null;
            if (is_array($entry) && isset($entry['ts']) && ($now - (int)$entry['ts']) < self::SCRUB_TTL) {
                $result[$name] = $entry['data'] ?? null;
                continue;
            }
            $data = self::scrubStatus($name, $fs);
            $cache[$key] = ['ts' => $now, 'data' => $data];
            $result[$name] = $data;
            $dirty = true;
        }

        if ($dirty) {
            @mkdir('/tmp/diskviewer_cache', 0755, true);
            @file_put_contents(self::SCRUB_CACHE, json_encode($cache, JSON_UNESCAPED_SLASHES));
        }
        return $result;
    }

    public static function widgetActive(): bool
    {
        $ts = @file_get_contents(self::HEARTBEAT_FILE);
        if ($ts === false || $ts === '') return false;
        return (time() - (int)$ts) < self::WIDGET_HEARTBEAT_TTL;
    }

    public static function generateNonce(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function spinDisk(string $name, string $direction): bool
    {

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

        $devices  = self::devices();
        $match    = null;
        foreach ($devices as $d) {
            if ($d['name'] === $name) { $match = $d; break; }
        }
        if ($match === null) {
            $LOG("FAIL no match for name={$name}");
            return false;
        }

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

        // devPath comes from devices() but it lands in a shell call, so re-check
        if (!preg_match('#^/dev/[a-zA-Z0-9]+$#', $devPath)
            && !preg_match('#^[a-zA-Z0-9]+$#', $devPath)) {
            $LOG("FAIL devPath sanitize devPath={$devPath}");
            return false;
        }
        if (strpos($devPath, '/dev/') !== 0) $devPath = '/dev/' . $devPath;

        $action  = $direction === 'up' ? 'up' : 'down';
        // emcmd is the only thing that flips array spin state cleanly
        $emCmd   = 'cmdSpin' . $action . '=' . $name;
        $cmd     = '/usr/local/sbin/emcmd ' . escapeshellarg($emCmd);
        $LOG("EXEC emcmd cmd={$cmd}");
        @exec($cmd . ' >/dev/null 2>/dev/null', $o, $rc);
        $ok = ($rc === 0);
        $LOG("RESULT emcmd rc={$rc} ok=" . ($ok ? '1' : '0'));
        $LOG("EXIT name={$name} ok=" . ($ok ? '1' : '0'));
        return $ok;
    }

    // devices the user may hide from the widget and tool: unassigned and single-disk pools, keyed by stable id
    public static function hideableDevices(): array
    {
        $devices = self::devices();
        $count   = [];
        foreach ($devices as $d) { $g = (string)($d['group'] ?? ''); $count[$g] = ($count[$g] ?? 0) + 1; }
        $out = [];
        foreach ($devices as $d) {
            $byid = (string)($d['byid'] ?? '');
            if ($byid === '') continue;
            $kind  = (string)($d['kind'] ?? '');
            $group = (string)($d['group'] ?? '');
            if ($kind === 'unassigned') {
                $cat = 'unassigned';
            } elseif ($kind === 'pool' && ($count[$group] ?? 0) < 2) {
                $cat = 'pool';
            } else {
                continue;
            }
            $size = (int)($d['size'] ?? 0);
            $out[] = [
                'byid'   => $byid,
                'name'   => (string)($d['name'] ?? ''),
                'size_h' => self::humanBytes($size),
                'cat'    => $cat,
            ];
        }
        usort($out, function($a, $b) {
            if ($a['cat'] !== $b['cat']) return $a['cat'] === 'unassigned' ? -1 : 1;
            return strnatcasecmp($a['name'], $b['name']);
        });
        return $out;
    }

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

                    @mkdir('/tmp/diskviewer_cache', 0755, true);
                    @file_put_contents(self::HEARTBEAT_FILE, (string)time());
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

                case 'tool_overview':

                    self::$toolMode = true;
                    $model = self::buildModel();
                    self::$toolMode = false;

                    $devList = [];
                    $poolMap = [];
                    foreach (($model['sections'] ?? []) as $sec) {
                        foreach (($sec['tiles'] ?? []) as $t) {
                            if (!empty($t['is_summary'])) {

                                $fs = strtolower((string)($t['fs'] ?? ''));
                                if (($fs === 'btrfs' || $fs === 'zfs') && !empty($t['name'])) {
                                    $poolMap[(string)$t['name']] = $fs;
                                }
                                continue;
                            }
                            $dev = (string)($t['device'] ?? '');
                            if ($dev !== '') $devList[] = $dev;

                            if (empty($t['is_parity']) && empty($t['is_pool_member'])) {
                                $fs = strtolower((string)($t['fs'] ?? ''));
                                if (($fs === 'btrfs' || $fs === 'zfs') && !empty($t['name'])) {
                                    $poolMap[(string)$t['name']] = $fs;
                                }
                            }
                        }
                    }
                    $smartMap = self::smartAttrsForDevices($devList);
                    $scrubMap = self::scrubStatusForPools($poolMap);
                    $schedMap = self::scrubScheduleForPools($poolMap);

                    $dateFmt  = self::unraidDateFormat();

                    if (!empty($model['sections']) && is_array($model['sections'])) {
                        foreach ($model['sections'] as &$secRef) {
                            if (empty($secRef['tiles']) || !is_array($secRef['tiles'])) continue;
                            foreach ($secRef['tiles'] as &$tRef) {

                                $scrub = $scrubMap[(string)($tRef['name'] ?? '')] ?? null;
                                $tRef['scrub_last_ts'] = $scrub['last_ts'] ?? null;
                                $tRef['scrub_last_fmt'] = !empty($scrub['last_ts'])
                                    ? date($dateFmt, (int)$scrub['last_ts']) : null;
                                $tRef['scrub_frag']    = $scrub['frag'] ?? null;
                                $tRef['scrub_next_ts'] = $schedMap[(string)($tRef['name'] ?? '')] ?? null;

                                if (!empty($tRef['is_summary'])) {
                                    $tRef['smart_attrs'] = null;
                                    $tRef['verdict'] = '';
                                    $tRef['verdict_sev'] = 'na';
                                    continue;
                                }
                                $dev = (string)($tRef['device'] ?? '');
                                $attrs = $smartMap[$dev] ?? null;
                                $tRef['smart_attrs'] = $attrs;
                                [$vLabel, $vSev] = self::smartVerdict($attrs);
                                $tRef['verdict'] = $vLabel;
                                $tRef['verdict_sev'] = $vSev;
                            }
                            unset($tRef);
                        }
                        unset($secRef);
                    }

                    $totalSize = 0; $totalUsed = 0; $totalFree = 0;
                    $healthy = 0; $warning = 0; $critical = 0;
                    $hotTemp = -999; $hotName = '';
                    $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];

                    foreach (($model['sections'] ?? []) as $sec) {
                        $tiles = $sec['tiles'] ?? [];

                        $summary = null;
                        foreach ($tiles as $t) {
                            if (!empty($t['is_summary'])) { $summary = $t; break; }
                        }
                        if ($summary) {
                            $totalSize += (int)($summary['size'] ?? 0);
                            $totalUsed += (int)($summary['used'] ?? 0);
                            $totalFree += (int)($summary['free'] ?? 0);
                        } else {
                            foreach ($tiles as $t) {
                                if (!empty($t['is_parity']) || !empty($t['is_pool_member'])) continue;
                                $totalSize += (int)($t['size'] ?? 0);
                                $totalUsed += (int)($t['used'] ?? 0);
                                $totalFree += (int)($t['free'] ?? 0);
                            }
                        }

                        foreach ($tiles as $t) {
                            if (!empty($t['is_summary'])) continue;
                            $smart = (string)($t['smart'] ?? 'unknown');
                            if ($smart === 'critical')      $critical++;
                            elseif ($smart === 'warning')   $warning++;
                            elseif ($smart === 'healthy')   $healthy++;

                            $rawT = $t['temp'] ?? '';
                            if ($rawT !== '' && $rawT !== '*' && $rawT !== '-') {
                                $n = (int)$rawT;
                                if ($n > $hotTemp) { $hotTemp = $n; $hotName = (string)($t['display_name'] ?? $t['name'] ?? ''); }
                            }
                        }
                    }

                    $model['overview'] = [
                        'total_size'    => $totalSize,
                        'total_used'    => $totalUsed,
                        'total_free'    => $totalFree,
                        'used_pct'      => $totalSize > 0 ? round($totalUsed / $totalSize * 100, 1) : 0,
                        'healthy'       => $healthy,
                        'warning'       => $warning,
                        'critical'      => $critical,
                        'hottest_temp'  => $hotTemp > -999 ? $hotTemp : null,
                        'hottest_name'  => $hotName,
                        'device_count'  => (int)($model['total_devices'] ?? 0),
                        'missing_count' => (int)($model['missing_devices'] ?? 0),
                        'temp_unit'     => $model['cfg']['temp_unit'] ?? 'C',
                    ];
                    echo json_encode($model, JSON_UNESCAPED_SLASHES);
                    return;

                case 'speeds':

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

                    if (!self::validateCsrf()) {
                        http_response_code(403);
                        echo json_encode(['ok' => false, 'error' => 'bad csrf token']);
                        return;
                    }
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

                case 'hideable':
                    echo json_encode(self::hideableDevices(), JSON_UNESCAPED_SLASHES);
                    return;

                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'unknown action']);
            }
        } catch (\Throwable $e) {

            error_log('[diskviewer] ' . $e::class . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['error' => 'internal error']);
        }
    }
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'diskviewer_api.php') {
    (new DiskViewerEndpoint())->run();
}
