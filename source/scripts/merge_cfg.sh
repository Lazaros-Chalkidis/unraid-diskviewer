#!/bin/bash
# ============================================================================
# DISK VIEWER
# Copyright (C) 2026 Lazaros Chalkidis
# License: GPLv3
# =========================================================================

# keep the user's existing values, let any new keys in this release stay at default
# usage: merge_cfg.sh <current_cfg> <backup_cfg>

set -e

CFG="$1"
BAK="$2"

if [[ -z "$CFG" || -z "$BAK" ]]; then
    echo "Usage: merge_cfg.sh <current_cfg> <backup_cfg>" 1>&2
    exit 1
fi
if [[ ! -f "$CFG" ]]; then
    echo "merge_cfg: current cfg not found: $CFG" 1>&2
    exit 1
fi
if [[ ! -f "$BAK" ]]; then
    exit 0
fi

TMP="${CFG}.$$.tmp"

awk '
    NR==FNR {
        eq = index($0, "=")
        if (eq > 0) {
            k = substr($0, 1, eq-1)
            if (k != "") { user[k] = $0; order[++n] = k }
        }
        next
    }
    # second pass over the default cfg: swap in the saved value where the key exists
    {
        eq = index($0, "=")
        if (eq > 0) {
            k = substr($0, 1, eq-1)
            if (k in user) { print user[k]; seen[k] = 1; next }
        }
        print
    }
    # without this an update drops every key the new defaults do not list
    END {
        for (i = 1; i <= n; i++) {
            k = order[i]
            if (!(k in seen)) { print user[k]; seen[k] = 1 }
        }
    }
' "$BAK" "$CFG" > "$TMP"

if [[ ! -s "$TMP" ]]; then
    rm -f "$TMP"
    echo "merge_cfg: merge produced nothing, keeping $CFG as is" 1>&2
    exit 1
fi

mv "$TMP" "$CFG"
chmod 644 "$CFG"
