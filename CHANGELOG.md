
# Disk Viewer

## Version 2026.06.02

### Fixed
- Disk sizes now match the Unraid Main page (a 2 TB drive reads as 2 TB).
- Disk Viewer page column headers now stay pinned while scrolling.
- Live read/write speed updates again on its own fast cadence.
- Used column and decimal percentage now display correctly right after a fresh install.
- Empty Parity 2 slot no longer appears on single-parity arrays.

### New Features
- Disk Viewer page: a new full-page view under Tools > Disk Viewer with a wide table of every disk, including detailed SMART, temperature, capacity, scrub and health columns.
- Separate Widget and Tool settings: Settings now has Widget and Tool tabs, each configured independently with its own Apply and Reset.
- USED column now shows the used bytes next to the percentage, with optional one-decimal precision.
- Section header indicators: small icons next to ARRAY, CACHE, RAID and POOL show how many disks have errors, high temperature, or bad health, with a tooltip listing them.
- Font size option to shrink the disk rows and fit more on screen.

### Improvements
- Critical temperatures now stand out: a separate red indicator sits next to the amber one and pulses when a disk runs well over its limit, in both the widget and the header badge.
- Used bytes and one-decimal percentage are now shown by default.
- Refreshed zebra striping for a cleaner look across all themes.

## Version 2026.05.28

### Fixed
- Pool disk errors are now actually detected and flagged.
- SSD and NVMe drives use their own temperature thresholds instead of the HDD ones.
- Per-disk temperature overrides set in Unraid SMART settings are honoured.
- Failed or disabled array disks are now flagged as critical.
- Unassigned drives are visible by default.
- Header click action setting now works.
- Array data disks show their correct capacity.
- Parity disks now show their I/O activity, and aggregate rows show real totals.
- Hover tooltip works reliably across all themes.
- Filesystem badge stays readable on light themes.

### New Features
- Hover the header icon to see exactly which disks have issues and why.
- Warning triangle next to a section header when any disk in that group has errors.
- RAID type shown in section headers (RAID 6, BTRFS RAID 1, ZFS Mirror, and so on).
- Click action setting for the header icon: Open Main, Dashboard, or Settings.
- New toggles: highlight pool disks by used %, show filesystem badge, show disk errors.
- Per-disk free-space overrides honoured (set from the Main page).

### Improvements
- ARRAY section redesigned: parity, data disks, and aggregate row.
- CACHE section now shows the pools actually used as cache, not just the one named "cache".
- Multi-disk pool members shown as DEVICE 1, 2, ... with the totals on the summary row.
- Cleaner zebra striping in combined sections.
- Default refresh interval is now 20 seconds.
- Show Unassigned defaults to off.
- Hover tooltip colours match your active Unraid theme.
- Bigger, clearer speed arrows without bouncing the row height.

## Version 2026.05.21

### Feature additions
- Space Severity Highlighting: New dropdown in Display with three modes (Inherit, Custom, Disabled).
- Temperature Unit Awareness: Plugin now follows Unraid's global Temperature Unit setting (°C / °F) automatically.

### Improvements
- Wider Settings Panel
- Refreshed Credits Modal


## Version 2026.05.08

### First release
- Dashboard Widget: Monitor every disk on your Unraid server in real-time from the dashboard
- Section Organisation: Disks grouped under ARRAY, CACHE, POOLS, and UNASSIGNED with independent show/hide toggles
- At-a-Glance Stats: Capacity, free space, used percentage, temperature, current read/write speed, and SMART health per disk
- Drag-to-Resize: Footer handle to reveal more rows or shrink the widget back down without leaving the dashboard
- Manual Spin Control: Per-disk bolt button to spin up or spin down on demand, with bulk controls at section headers for groups
- Spindown Respect: Skips waking disks to read temperature or SMART when they are asleep, configurable
- Header Bar Indicator: Thumb-up icon in the WebGUI top bar showing worst current state across all disks, click-action configurable
- Severity Highlighting: Used percentage and temperature colour-shift to warning or critical based on Unraid's native Display thresholds
- Auto Refresh: Configurable polling interval from 5 to 300 seconds for live disk state updates
- Theme-Aware: Inherits the active Unraid theme (black, white, azure, gray) without override hacks
- Mobile Responsive: Works on all screen sizes including the Unraid 7.2+ responsive WebGUI
- Performance Friendly: Per-request memoisation, request-frame-throttled drag, CSS containment on the tile, lightweight polling
- Settings Page: Standalone settings at Settings > Disk Viewer Settings with browser-native form submission for instant Apply