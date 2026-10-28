![Drift Creative Systems](.github/banner.jpg)

# Drift: Curtain

Free WordPress plugin by **Drift Creative Systems**. Shows logged-out visitors a customisable maintenance page with a proper `503` status while you keep working. Includes scheduling, client preview links, an IP allowlist and an optional site password.

- WordPress.org slug: `drift-curtain` (submission pending)
- Licence: GPL-2.0-or-later
- Requires WordPress 6.0+, PHP 7.4+

The user-facing documentation is [readme.txt](readme.txt), which WordPress.org displays. This file is for developers.

## How it works

- `template_redirect` (priority 1) serves the page with `503`, `Retry-After` and no-cache headers, then exits.
- Not gated: wp-admin, REST, AJAX, cron, WP-CLI, feeds, previews, users with `manage_options` (filterable), valid preview link or cookie, allowlisted IPs and visitors who have entered the site password.
- Schedule times are stored as `datetime-local` strings and parsed in the site timezone (`wp_timezone()`).
- The site password is stored with `wp_hash_password()`. The access cookie is `expiry|HMAC(expiry|hash, auth salt)`, so changing the password signs everyone out. Failed attempts: 5 per IP per 15 minutes (transient keyed on an MD5 of the IP).
- Settings → Drift: Curtain has four tabs. Each tab is its own option group, because `options.php` blanks every option in a group that isn't in the submitted form. Fields come from `drift_curtain_settings_schema()`.

### Update screen override

With **Also show this page while WordPress installs updates** ticked (single site only), the plugin writes two files to `wp-content`:

| File | Contents |
|---|---|
| `maintenance.php` | Copy of `includes/maintenance-dropin.php`. Fixed code, with no user content |
| `drift-curtain.html` | Static render of the page (without the password form), which the drop-in prints |

They're rewritten at the end of any request that saves a `drift_curtain_*` option, and removed when the option is unticked, on deactivation and in `uninstall.php`. Writes go through `WP_Filesystem` only when the method is `direct`. A `maintenance.php` without the `Drift: Curtain drop-in` marker is never overwritten or deleted.

## Options

All options are prefixed `drift_curtain_`. They're stored data, so never rename them.

| Tab | Options |
|---|---|
| Status | `enabled`, `show_main_content`, `override_wp_maintenance`, `schedule_enabled`, `schedule_start`, `schedule_end` |
| Preview & access | `preview_token`, `ip_allowlist`, `site_password` (hash), `password_days` |
| Design | `logo`, `background_image`, `bg_colour`, `font_colour` |
| Content & SEO | `badge_text`, `header_text`, `main_content`, `embed_content`, `footer_text`, `linkedin`, `facebook`, `instagram`, `seo_title`, `seo_description` |

`embed_content` is printed unescaped by design (see the note at the top of `drift-curtain.php`). Changing it needs `unfiltered_html`.

Cookies: `drift_curtain_preview` (one day) and `drift_curtain_access` (password, as configured). Query parameter: `?drift_curtain_preview=TOKEN`.

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `drift_curtain_is_active` | filter | Whether maintenance mode is on |
| `drift_curtain_bypass_capability` | filter | Capability that sees the live site. Default `manage_options` |
| `drift_curtain_can_bypass` | filter | Return `true` to let the current visitor through |
| `drift_curtain_retry_after` | filter | `Retry-After` seconds |
| `drift_curtain_page_html` | filter | Page HTML: `( $html, $args )` |
| `drift_curtain_settings_after_fields` | action | Extra fields in the form: `( $tab )` |

These are the extension points for a paid add-on, sold outside WordPress.org. Nothing in this plugin is gated.

## Development

- Coding standard: `phpcs.xml.dist` (WordPress, prefixes `drift_curtain` / `DRIFT_CURTAIN`).
- Run **Plugin Check** before every release. The PHPCS-based checks don't work in WordPress Playground (its filesystem can't `flock()`), so use a real install, e.g. WP-CLI with the SQLite drop-in:
  ```sh
  wp plugin install plugin-check --activate
  wp plugin check drift-curtain --include-low-severity-errors --include-low-severity-warnings \
    --require=./wp-content/plugins/plugin-check/cli.php
  ```
  Check a copy built from `.distignore`, not the repo, so dev files don't trip the `file_type` check.
- Directory guidelines review: the `wp-plugin-directory-guidelines` Claude Code skill.

## Releasing

1. Bump the version in **three** places: the `Version:` header, `DRIFT_CURTAIN_VERSION` and `Stable tag:` in `readme.txt`. Add a changelog entry to both `readme.txt` and `CHANGELOG.md`.
2. Merge `develop` → `main`, then publish a GitHub release tagged `vX.Y.Z`.
3. `.github/workflows/release.yml` checks the three versions match, builds `drift-curtain.zip` from `.distignore` and attaches it to the release.
4. Once approved on WordPress.org, set the repo variable `WPORG_DEPLOY=true` and the secrets `SVN_USERNAME` / `SVN_PASSWORD`. Releases then deploy to SVN automatically, and readme/asset changes on `main` sync on their own (`wporg-assets.yml`).

WordPress.org assets (icon, banners, screenshots) live in `.wordpress-org/`.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

Copyright © 2026 Drift Creative Systems Ltd

Drift Creative Systems Ltd. Registered in England and Wales, company number 17548220. Registered office: 167-169 Great Portland Street, 5th Floor, London W1W 5PF
