<?php
/* ============================================================================
   DISK VIEWER
   Copyright (C) 2026 Lazaros Chalkidis
   License: GPLv3
   ========================================================================= */

declare(strict_types=1);

// cron entry point, see diskviewer.cron next to this file
require_once __DIR__ . '/../include/diskviewer_api.php';

DiskViewerEndpoint::collectSmart();
