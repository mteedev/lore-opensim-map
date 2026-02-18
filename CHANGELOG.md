# L.O.R.E. Changelog
### Leaflet OpenSimulator Regional Explorer

---

## Version 1.1.0 — Multiple Database Support

### New Features
- **Multi-Database Support for Load-Balanced Grids**: Connect up to 5 databases simultaneously
- Side-by-side admin UI: Primary database on left, additional databases on right
- Toggle switches to enable/disable databases 2-5 as needed
- Automatic region deduplication across databases (uses UUID)
- Sync status shows how many databases were queried
- Auto-sync works across all enabled databases

### UI Improvements
- Beautiful two-column layout in admin panel
- Primary database always visible in blue-bordered box
- Additional databases appear in expandable sections
- Smooth slide animations when toggling databases
- Compact form layouts for additional databases

### Technical
- Updated `ajax_lore_sync_batch()` to loop through all enabled databases
- Updated `cron_sync_regions()` to support multiple databases
- Uses `REPLACE` instead of `INSERT` to handle duplicate UUIDs gracefully
- Logs show which database each batch came from
- Settings stored: `lore_db2_enabled`, `lore_db2_host`, etc. (through db5)

### Use Cases
- **Load-Balanced Grids**: Regions sharded across multiple Robust databases
- **Multi-Server Setups**: Different database servers for different regions
- **Migration Scenarios**: Sync from old + new database simultaneously
- **Testing**: Combine production and test regions in one map

---

## Version 1.0.1 — Daily Auto-Sync

### New Features
- **Automatic Daily Region Sync**: Enable daily automatic synchronization in the admin panel
- Sync runs at 3:00 AM server time every day (configurable via WordPress cron)
- Shows next scheduled sync time in admin when enabled
- Logs sync results to WordPress error log for monitoring
- Checkbox toggle in admin - enable/disable anytime
- Manual sync button still available for immediate updates

### Technical
- Uses WordPress `wp_cron` for scheduling
- Runs in background without UI overhead
- Same batch processing as manual sync (50 regions per batch)
- Automatic cleanup of old schedules when disabled

---

## Version 1.0.0 — Initial Release

### Features
- Interactive Leaflet.js map with zoom, pan, and tile support
- Region search with live dropdown (top-right corner)
- Click-to-popup with region name, teleport link, and registration button
- Dynamic `hop://` teleport URL built from Grid URL setting
- Batch sync with progress bar (batches of 50, no PHP timeouts)
- WordPress color pickers for accent and button colors
- Featured region marker (pulsing glow, gold star, or pin drop)
- Dark mode support
- `[lore_map]` shortcode with optional parameters
- OpenSimulator coordinate conversion (meters ↔ region coords)
- Clean admin settings panel with usage instructions

### Technical
- Table: `{prefix}_lore_regions`
- AJAX actions: `lore_get_regions`, `lore_get_region_info`, `lore_sync_batch`
- Shortcode: `[lore_map]`
- No external dependencies beyond Leaflet.js (CDN)
- GPL v2 licensed

---

*by Gundahar Bravin — https://nerdypappy.com*
