
# Disk Viewer

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