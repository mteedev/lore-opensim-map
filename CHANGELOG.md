# L.O.R.E. Changelog
### Leaflet OpenSimulator Regional Explorer

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
