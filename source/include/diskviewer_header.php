<?php
/* ============================================================================
   DISK VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

$cfg = @parse_ini_file('/boot/config/plugins/diskviewer/diskviewer.cfg') ?: [];

// whitelist: this value becomes a location.href in the js, don't let it be anything else
$clickAllowed = ['main', 'widget', 'tool', 'settings'];
$clickAction  = (string)($cfg['HEADER_CLICK_ACTION'] ?? 'main');
if (!in_array($clickAction, $clickAllowed, true)) $clickAction = 'main';

// badge turned off, hand back an all-off payload and bail
if (((string)($cfg['HEADER_SHOW_BADGE'] ?? '1')) === '0') {
    echo '{"count":0,"names":[],"temp_severity":"off","temp_blink":false,"health_severity":"off","util_severity":"off","errors_severity":"off","disk_issues":[],"click_action":"' . $clickAction . '"}';
    exit;
}

$countFile  = '/tmp/diskviewer_cache/header_count';
$namesFile  = '/tmp/diskviewer_cache/header_names';
$tempFile   = '/tmp/diskviewer_cache/header_temp';
$tempBlinkFile = '/tmp/diskviewer_cache/header_temp_blink';
$healthFile = '/tmp/diskviewer_cache/header_health';
$utilFile   = '/tmp/diskviewer_cache/header_util';
$errorsFile = '/tmp/diskviewer_cache/header_errors';
$issuesFile = '/tmp/diskviewer_cache/header_issues';

$readSev = function (string $path): string {
    if (!is_file($path)) return 'ok';
    $raw = trim((string)@file_get_contents($path));
    return ($raw === 'warning' || $raw === 'critical' || $raw === 'ok') ? $raw : 'ok';
};

$readIssues = function (string $path): array {
    if (!is_file($path)) return [];
    $raw = trim((string)@file_get_contents($path));
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
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
$tempBlink = is_file($tempBlinkFile) && trim((string)@file_get_contents($tempBlinkFile)) === '1';
$health = $readSev($healthFile);
$util   = $readSev($utilFile);
$errors = $readSev($errorsFile);
$issues = $readIssues($issuesFile);

// cache older than 2 min (or missing), rebuild it inline rather than serve stale markers
$age = is_file($countFile) ? (time() - (int)filemtime($countFile)) : 9999;
if ($age > 120) {
    require_once __DIR__ . '/diskviewer_api.php';
    $model  = DiskViewerEndpoint::buildModel();
    DiskViewerEndpoint::writeHeaderCache($model);
    $count  = (int)$model['critical_count'];
    $names  = (array)$model['critical_names'];
    $temp   = (string)($model['temp_severity']    ?? 'ok');
    $tempBlink = !empty($model['temp_blink']);
    $health = (string)($model['health_severity']  ?? 'ok');
    $util   = (string)($model['util_severity']    ?? 'ok');
    $errors = (string)($model['errors_severity']  ?? 'ok');
    $issues = (array)($model['disk_issues']       ?? []);
}

echo json_encode([
    'count'           => $count,
    'names'           => $names,
    'temp_severity'   => $temp,
    'temp_blink'      => $tempBlink,
    'health_severity' => $health,
    'util_severity'   => $util,
    'errors_severity' => $errors,
    'disk_issues'     => $issues,
    'click_action'    => $clickAction,
], JSON_UNESCAPED_SLASHES);
