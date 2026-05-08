<?php
// DiskViewer for Unraid - Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$cfg = @parse_ini_file('/boot/config/plugins/diskviewer/diskviewer.cfg') ?: [];
if (((string)($cfg['HEADER_SHOW_BADGE'] ?? '1')) === '0') {
    echo '{"count":0,"names":[],"temp_severity":"off","health_severity":"off","util_severity":"off"}';
    exit;
}

$countFile  = '/tmp/diskviewer_cache/header_count';
$namesFile  = '/tmp/diskviewer_cache/header_names';
$tempFile   = '/tmp/diskviewer_cache/header_temp';
$healthFile = '/tmp/diskviewer_cache/header_health';
$utilFile   = '/tmp/diskviewer_cache/header_util';

$readSev = function (string $path): string {
    if (!is_file($path)) return 'ok';
    $raw = trim((string)@file_get_contents($path));
    return ($raw === 'warning' || $raw === 'critical' || $raw === 'ok') ? $raw : 'ok';
};

$count  = 0;
$names  = [];
if (is_file($countFile)) {
    $count = max(0, (int)trim((string)@file_get_contents($countFile)));
}
if (is_file($namesFile)) {
    $raw = trim((string)@file_get_contents($namesFile));
    if ($raw !== '') $names = explode('|', $raw);
}
$temp   = $readSev($tempFile);
$health = $readSev($healthFile);
$util   = $readSev($utilFile);

// If cache is stale (> 2 min) or missing, regenerate inline
$age = is_file($countFile) ? (time() - (int)filemtime($countFile)) : 9999;
if ($age > 120) {
    require_once __DIR__ . '/diskviewer_api.php';
    $model  = DiskViewerEndpoint::buildModel();
    DiskViewerEndpoint::writeHeaderCache($model);
    $count  = (int)$model['critical_count'];
    $names  = (array)$model['critical_names'];
    $temp   = (string)($model['temp_severity']   ?? 'ok');
    $health = (string)($model['health_severity'] ?? 'ok');
    $util   = (string)($model['util_severity']   ?? 'ok');
}

echo json_encode([
    'count'           => $count,
    'names'           => $names,
    'temp_severity'   => $temp,
    'health_severity' => $health,
    'util_severity'   => $util,
], JSON_UNESCAPED_SLASHES);
