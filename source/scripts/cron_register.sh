#!/bin/bash
# ============================================================================
# DISK VIEWER
# Copyright (C) 2026 Lazaros Chalkidis
# License: GPLv3
# =========================================================================

# update_cron reads /boot/config/plugins/*/*.cron and nowhere else, so the schedule lives on flash; written only when it differs
SRC=/usr/local/emhttp/plugins/diskviewer/scripts/diskviewer.cron
DST=/boot/config/plugins/diskviewer/diskviewer.cron
if ! cmp -s "$SRC" "$DST"; then
    cp "$SRC" "$DST"
    chmod 644 "$DST"
fi

[[ -x /usr/local/sbin/update_cron ]] || exit 0

# an update keeps us registered while the install script runs; a fresh install and every boot register us only
# after it finishes, so the same call runs once more a little later. event/driver_loaded covers boot as well.
/usr/local/sbin/update_cron
( sleep 20; /usr/local/sbin/update_cron ) >/dev/null 2>&1 &
