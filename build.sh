#!/bin/bash
# DiskViewer - build.sh
# Copyright (C) 2026 Lazaros Chalkidis - License: GPLv3
# Packages the plugin source into a .txz and generates the .plg file.
#
# Usage:
#   ./build.sh                        → release build (today's date, main branch)
#   ./build.sh a                      → versioned suffix: 2026.01.15a
#   ./build.sh a dev                  → dev build (dev branch)
#   ./build.sh "" local               → local build (embeds .txz in .plg, no URL)
#   ./build.sh a dev local            → dev + local
#
# Output:
#   packages/diskviewer-<version>.txz
#   diskviewer.plg

# ── Configuration ─────────────────────────────────────────────────────────────
PLUGIN_NAME="diskviewer"
AUTHOR="Lazaros Chalkidis"
GITHUB_USER="Lazaros-Chalkidis"
GIT_URL="https://github.com/Lazaros-Chalkidis/unraid-diskviewer"
PACKAGE_DIR_FINAL="packages"
PACKAGE_DIR_TEMP="package-temp"

# ── Versioning ────────────────────────────────────────────────────────────────
BASE_VERSION=$(date +'%Y.%m.%d')
LETTER_SUFFIX="${1}"
STAGE_INPUT="${2}"
LOCAL_INSTALL="${3:-}"

# Accept "local" as either the 2nd or 3rd positional so both documented forms
# work: ./build.sh "" local  and  ./build.sh a dev local. Without this the
# 2nd-arg form fell through to a release build with a bogus "-local" version.
if [[ "$STAGE_INPUT" == "local" ]]; then
    LOCAL_INSTALL="local"
    STAGE_INPUT=""
fi

STAGE_SUFFIX=""
if [[ -n "$STAGE_INPUT" && "$STAGE_INPUT" != "release" ]]; then
    STAGE_SUFFIX="-${STAGE_INPUT}"
fi
VERSION="${BASE_VERSION}${LETTER_SUFFIX}${STAGE_SUFFIX}"

# ── Branch & URL ──────────────────────────────────────────────────────────────
if [[ "$LOCAL_INSTALL" == "local" ]]; then
    BRANCH="local"
    PLUGIN_URL_STRUCTURE=""
    CHANGES_TEXT="- Local build (embedded package; no URL download)."
elif [[ "$STAGE_INPUT" == "dev" ]]; then
    BRANCH="dev"
    PLUGIN_URL_STRUCTURE="&gitURL;/raw/&branch;/packages/&name;-&version;.txz"
    CHANGES_TEXT="- Development build from the 'dev' branch. For testing only."
else
    BRANCH="main"
    PLUGIN_URL_STRUCTURE="&gitURL;/releases/download/&version;/&name;-&version;.txz"
    CHANGES_TEXT="- Automated release build."
fi

# ── Changelog ─────────────────────────────────────────────────────────────────
CHANGELOG_MD_FILE="CHANGELOG.md"
if [[ -f "$CHANGELOG_MD_FILE" ]]; then
    CHANGES_BLOCK="$(cat "$CHANGELOG_MD_FILE")"
else
    CHANGES_BLOCK="### ${VERSION}
${CHANGES_TEXT}"
fi

# ── Build ─────────────────────────────────────────────────────────────────────
echo "=============================================="
echo " DiskViewer build"
echo " Version : ${VERSION}"
echo " Branch  : ${BRANCH}"
echo "=============================================="

rm -rf "${PACKAGE_DIR_TEMP}" "${PACKAGE_DIR_FINAL}"
mkdir -p "${PACKAGE_DIR_TEMP}" "${PACKAGE_DIR_FINAL}"

PLUGIN_DEST="${PACKAGE_DIR_TEMP}/usr/local/emhttp/plugins/${PLUGIN_NAME}"
mkdir -p "${PLUGIN_DEST}"
cp -R source/* "${PLUGIN_DEST}/"

# Stamp the live build version into a VERSION file at the canonical
# installed location. The Settings page reads this first when deciding
# which version string to show in the footer badge and the credits modal
# pill, so the displayed version always matches the running build even
# when the .plg metadata went stale (manual installs, repackaging during
# testing). Mirrors how StreamViewer ships its VERSION file.
echo "${VERSION}" > "${PLUGIN_DEST}/VERSION"

# Branch metadata (readable by PHP for self-identification)
cat > "${PLUGIN_DEST}/branch.meta" << METAEOF
BRANCH="${BRANCH}"
IS_MAIN_BRANCH=$([[ "$BRANCH" == "main" ]] && echo "1" || echo "0")
METAEOF

# ── Permissions ───────────────────────────────────────────────────────────────
find "${PLUGIN_DEST}" -type d                          -exec chmod 755 {} \;
find "${PLUGIN_DEST}" -type f                          -exec chmod 644 {} \;
find "${PLUGIN_DEST}" -name "*.sh"                     -exec chmod 755 {} \;
find "${PLUGIN_DEST}/event" -type f                    -exec chmod 755 {} \; 2>/dev/null

# ── Create .txz ───────────────────────────────────────────────────────────────
FILENAME="${PLUGIN_NAME}-${VERSION}"
PACKAGE_PATH="${PACKAGE_DIR_FINAL}/${FILENAME}.txz"

echo "Creating package: ${FILENAME}.txz ..."
tar -C "${PACKAGE_DIR_TEMP}" -cJf "${PACKAGE_PATH}" usr

if [[ ! -f "${PACKAGE_PATH}" ]]; then
    echo "❌ Package creation failed!"
    exit 1
fi
echo "✅ Package: $(du -h "${PACKAGE_PATH}" | cut -f1)  →  ${PACKAGE_PATH}"

# ── MD5 ───────────────────────────────────────────────────────────────────────
if command -v md5sum &>/dev/null; then
    PACKAGE_MD5="$(md5sum "${PACKAGE_PATH}" | cut -d' ' -f1)"
elif command -v md5 &>/dev/null; then
    PACKAGE_MD5="$(md5 -q "${PACKAGE_PATH}")"
else
    echo "⚠️  md5sum/md5 not found - MD5 will be empty in PLG!"
    PACKAGE_MD5=""
fi
echo "🔑 MD5: ${PACKAGE_MD5}"

# ── Base64 helper (portable) ──────────────────────────────────────────────────
b64_nolf() {
    if base64 --help 2>/dev/null | grep -q -- "-w"; then
        base64 -w 0 "$1"
    else
        base64 "$1" | tr -d '\n'
    fi
}

# ── Default config (written to flash on first install only) ───────────────────
read -r -d '' DEFAULT_CFG << 'CFGEOF'
REFRESH_ENABLED="1"
REFRESH_INTERVAL="20"
DRAG_STEP_ROWS="1"
SHOW_UNASSIGNED="0"
SHOW_ARRAY="1"
SHOW_CACHE="1"
DEFAULT_EXPAND_ROWS="0"
HEADER_SHOW_BADGE="1"
HEADER_CLICK_ACTION="main"
ENABLE_SPIN_BUTTON="1"
CFGEOF

# ── Shared PLG sections ───────────────────────────────────────────────────────
PLG_DESCRIPTION="A compact dashboard widget that replaces Unraid's per-pool disk widgets with a single grid of all array, pool, and unassigned devices. Adds a full-page Tools view with detailed SMART, temperature, and capacity columns. Ideal for servers with many disks."

PLG_INSTALL_SCRIPT='# Fix ownership and permissions
chown -R root:root /usr/local/emhttp/plugins/&name;
find /usr/local/emhttp/plugins/&name; -type d -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name; -type f -exec chmod 644 {} \;
find /usr/local/emhttp/plugins/&name; -name "*.sh"   -exec chmod 755 {} \;
find /usr/local/emhttp/plugins/&name;/event -type f -exec chmod 755 {} \; 2>/dev/null

# Init cache dir. The web API runs as root under emhttpd, so 755 (root
# write, world read) is enough - this lets diagnostic tools tail the
# spin.log without root, while keeping unrelated processes from writing
# to it. Was 1777 (world-writable with sticky bit) which violated the
# least-privilege principle: any other plugin or user-script could have
# clobbered our cache files.
mkdir -p /tmp/diskviewer_cache
chmod 755 /tmp/diskviewer_cache

# diskviewer.cfg lives at /boot/config/plugins/&name;/&name;.cfg. The file
# holds user-facing preferences only (no credentials), so 0644 is the
# correct mode per the plugin-docs guidance for non-sensitive config. An
# earlier 0600 attempt caused the settings page to display defaults after
# every Apply on systems where the PHP request handler ran with an
# effective uid that was not root: the read-back of the freshly written
# cfg returned an empty array because a user-mode process cannot open a
# root-only-readable file. Network-edge defence is the CSRF token;
# OS-edge defence is that /boot/config is only writable by root. So
# world-readable here is acceptable.
if [[ -f /boot/config/plugins/&name;/&name;.cfg ]]; then
    chmod 644 /boot/config/plugins/&name;/&name;.cfg
fi

# Reset PHP opcache so the upgraded PHP files are picked up immediately
# instead of being served from the previous compiled bytecode.
php -r "if (function_exists(\"opcache_reset\")) opcache_reset();" >/dev/null 2>/dev/null

# Merge existing user config with new defaults. New keys stay at default,
# existing keys preserve user values. Done via an external script to keep
# special characters (ampersands, redirects) out of the PLG XML body.
CFG=/boot/config/plugins/&name;/&name;.cfg
BAK=/boot/config/plugins/&name;/&name;.cfg.bak
if [[ -f "$BAK" ]]; then
    /usr/local/emhttp/plugins/&name;/scripts/merge_cfg.sh "$CFG" "$BAK"
    rm -f "$BAK"
fi

# Warm cache so the header badge has something to show
if mountpoint -q /mnt/user 2>/dev/null; then
    php -r "require_once '\''/usr/local/emhttp/plugins/&name;/include/diskviewer_api.php'\''; DiskViewerEndpoint::writeHeaderCache(DiskViewerEndpoint::buildModel());" >/dev/null 2>/dev/null
fi

echo ""
echo "----------------------------------------------------"
echo " &name; (&branch; build) installed successfully."
echo " Version : &version;"
echo " Settings: Settings > Disk Viewer"
echo "----------------------------------------------------"
echo ""'

PLG_REMOVE_SCRIPT='removepkg &name;-&version;
rm -rf /usr/local/emhttp/plugins/&name;
rm -rf /boot/config/plugins/&name;
rm -rf /tmp/diskviewer_cache

echo ""
echo "----------------------------------------------------"
echo " &name; has been removed."
echo "----------------------------------------------------"
echo ""'

# ── Generate .plg ─────────────────────────────────────────────────────────────
echo "Generating ${PLUGIN_NAME}.plg (${BRANCH} target)..."

if [[ "$LOCAL_INSTALL" == "local" ]]; then
    PACKAGE_B64="$(b64_nolf "${PACKAGE_PATH}")"

    cat > "${PLUGIN_NAME}.plg" << EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
 <!ENTITY name    "${PLUGIN_NAME}">
 <!ENTITY author  "${AUTHOR}">
 <!ENTITY version "${VERSION}">
 <!ENTITY branch  "${BRANCH}">
 <!ENTITY gitURL  "${GIT_URL}">
 <!ENTITY selfURL "&gitURL;/raw/&branch;/&name;.plg">
 <!ENTITY launch  "Settings/DiskViewerSettings">
]>

<PLUGIN name="&name;" Title="Disk Viewer" author="&author;" version="&version;"
        pluginURL="&selfURL;" launch="&launch;"
        icon="img/diskviewerplugin.png"
        min="7.2.0"
        support="https://forums.unraid.net/topic/198667-plugin-disk-viewer/">

<DESCRIPTION>
<![CDATA[
${PLG_DESCRIPTION}
]]>
</DESCRIPTION>

<CHANGES>
<![CDATA[
${CHANGES_BLOCK}
]]>
</CHANGES>

<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz.b64">
  <INLINE>${PACKAGE_B64}</INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
mkdir -p /boot/config/plugins/&name;
base64 -d /boot/config/plugins/&name;/&name;-&version;.txz.b64 \
    > /boot/config/plugins/&name;/&name;-&version;.txz 2>/dev/null || \
  base64 -D /boot/config/plugins/&name;/&name;-&version;.txz.b64 \
    > /boot/config/plugins/&name;/&name;-&version;.txz
rm -f /boot/config/plugins/&name;/&name;-&version;.txz.b64
upgradepkg --install-new /boot/config/plugins/&name;/&name;-&version;.txz
</INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
if [[ -f /boot/config/plugins/&name;/&name;.cfg ]]; then cp /boot/config/plugins/&name;/&name;.cfg /boot/config/plugins/&name;/&name;.cfg.bak; fi
</INLINE>
</FILE>

<FILE Name="/boot/config/plugins/&name;/&name;.cfg" Mode="0644">
  <INLINE>
${DEFAULT_CFG}
  </INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
${PLG_INSTALL_SCRIPT}
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
${PLG_REMOVE_SCRIPT}
</INLINE>
</FILE>

</PLUGIN>
EOF

else

    cat > "${PLUGIN_NAME}.plg" << EOF
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
 <!ENTITY name      "${PLUGIN_NAME}">
 <!ENTITY author    "${AUTHOR}">
 <!ENTITY version   "${VERSION}">
 <!ENTITY branch    "${BRANCH}">
 <!ENTITY gitURL    "${GIT_URL}">
 <!ENTITY pluginURL "${PLUGIN_URL_STRUCTURE}">
 <!ENTITY selfURL   "&gitURL;/raw/&branch;/&name;.plg">
 <!ENTITY md5       "${PACKAGE_MD5}">
 <!ENTITY launch    "Settings/DiskViewerSettings">
]>

<PLUGIN name="&name;" Title="Disk Viewer" author="&author;" version="&version;"
        pluginURL="&selfURL;" launch="&launch;"
        icon="img/diskviewerplugin.png"
        min="7.2.0"
        support="https://forums.unraid.net/topic/198667-plugin-disk-viewer/">

<DESCRIPTION>
<![CDATA[
${PLG_DESCRIPTION}
]]>
</DESCRIPTION>

<CHANGES>
<![CDATA[
${CHANGES_BLOCK}
]]>
</CHANGES>

<FILE Name="/boot/config/plugins/&name;/&name;-&version;.txz" Run="upgradepkg --install-new">
  <URL>&pluginURL;</URL>
  <MD5>&md5;</MD5>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
if [[ -f /boot/config/plugins/&name;/&name;.cfg ]]; then cp /boot/config/plugins/&name;/&name;.cfg /boot/config/plugins/&name;/&name;.cfg.bak; fi
</INLINE>
</FILE>

<FILE Name="/boot/config/plugins/&name;/&name;.cfg" Mode="0644">
  <INLINE>
${DEFAULT_CFG}
  </INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
${PLG_INSTALL_SCRIPT}
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
${PLG_REMOVE_SCRIPT}
</INLINE>
</FILE>

</PLUGIN>
EOF

fi

# ── Cleanup ───────────────────────────────────────────────────────────────────
rm -rf "${PACKAGE_DIR_TEMP}"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "🎉 Build complete!"
echo "   📦 Package : ${PACKAGE_PATH}  ($(du -h "${PACKAGE_PATH}" | cut -f1))"
echo "   📄 PLG     : ${PLUGIN_NAME}.plg"
echo "   🔑 MD5     : ${PACKAGE_MD5}"
echo "   🏷  Version : ${VERSION}"
echo "   🌿 Branch  : ${BRANCH}"
echo ""
