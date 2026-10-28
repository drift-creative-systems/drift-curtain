# Changelog

All notable changes to this project are documented here. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.1] - 2026-10-28

### Added
- Copyright and company details (Drift Creative Systems Ltd, company number, registered office) in the admin footer on Settings → Drift: Curtain, the README, readme.txt and the main file header.

## [1.0.0] - 2026-10-22

First release as **Drift: Curtain**, a free WordPress.org plugin by Drift Creative Systems. Based on Bonsai Digital Maintenance Mode 1.22, which carries on separately for Bonsai client sites. There is no data migration between the two.

### Added
- `drift_curtain_is_active`, `drift_curtain_bypass_capability`, `drift_curtain_can_bypass`, `drift_curtain_retry_after`, `drift_curtain_page_html` and `drift_curtain_settings_after_fields` hooks for add-ons.
- No-cache headers on the maintenance page.
- `readme.txt`, `LICENSE` (GPLv2), `uninstall.php` (multisite aware), WordPress.org icon and banners, and a release workflow with optional SVN deploy.

### Changed
- Renamed to Drift: Curtain: slug and text domain `drift-curtain`, prefix `drift_curtain_` / `DRIFT_CURTAIN_`. The preview link parameter is now `?drift_curtain_preview=`.
- Settings moved to Settings → Drift: Curtain, with tabs, cards and the Drift monogram in red (`#ef4444`).
- Embed code is a plain code textarea instead of a visual editor, which mangled embed markup. It's saved as entered, but only by users with `unfiltered_html`. Anyone else gets a read-only field, and saving leaves the stored code unchanged.
- The maintenance page badge uses the chosen text and background colours instead of a fixed brand pink.
- Badge text and heading fall back to their defaults when left blank, instead of rendering empty.
- The static snapshot is only written while the update-screen override is on, and is now `wp-content/drift-curtain.html`. File writes use `WP_Filesystem`.
- Minimum WordPress raised to 6.0.

### Fixed
- The WordPress update-screen override never worked: it copied a `custom-maintenance.php` that didn't exist. It now installs a working drop-in that prints the static page with a 503 status.
- Schedule start and end times were read as UTC, not in the site timezone.
- The plugin could delete a `wp-content/maintenance.php` it didn't create. It now only touches files carrying its marker.

### Removed
- Plugin Update Checker and Composer. Updates come from WordPress.org.
- Bundled Bonsai Hub menu and suite installer.
- `load_plugin_textdomain()`: WordPress.org loads translations automatically.
- The update-screen override on multisite, where `wp-content` is shared by the whole network.
