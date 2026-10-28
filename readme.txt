=== Drift: Curtain ===
Contributors: driftcreativesystems
Tags: maintenance mode, coming soon, under construction, maintenance, 503
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Maintenance mode with a proper 503 status: a customisable page, scheduling, client preview links, an IP allowlist and a site password.

== Description ==

Drift: Curtain shows logged-out visitors a maintenance page while you work on the site. Administrators see the site as normal. Search engines get a `503 Service Unavailable` status with a `Retry-After` header, which tells them the outage is temporary, so your rankings are kept.

When you're ready to let people in early, you have three options: a preview link, an IP allowlist, or a shared site password.

Features:

* **503 status with Retry-After.** If you set an end time, Retry-After counts down to it. The page is always `noindex, nofollow`.
* **Scheduling.** Set a start and end time and maintenance mode turns itself on and off, using your site's timezone.
* **Preview link.** Generate a secret link to send to a client. Anyone with it sees the live site for a day, without needing a login.
* **IP allowlist.** Your office or a developer's IP always sees the live site.
* **Site password.** Visitors click "Have a password?" on the maintenance page and enter a shared password. It is stored hashed, failed attempts are throttled, and you choose how long access lasts.
* **Design.** Logo and background image from the media library, background and text colours, badge, heading, formatted content, embed code, social links and footer text.
* **Background-only mode.** Hide all the text and show just a full-page image or colour.
* **SEO.** Custom page title and meta description.
* **WordPress update screen (optional).** Show your page instead of WordPress's plain "Briefly unavailable for scheduled maintenance" message while updates install.
* No front-end scripts, no external requests, no credits or links back to us.
* Everything is removed when you delete the plugin.

= Privacy =

Drift: Curtain doesn't connect to any external service, track visitors or collect data.

It sets two functional cookies, and only for visitors who use the features that need them:

* `drift_curtain_preview`: set when someone opens a preview link, so the link keeps working as they browse. Lasts a day.
* `drift_curtain_access`: set when someone enters the site password correctly. Lasts as long as you choose in the settings.

Neither cookie contains personal data. To throttle failed password attempts, the plugin briefly stores a one-way hash of the visitor's IP address (for 15 minutes).

Anything you add in the "Embed code / HTML" field, such as a sign-up form or video, may load a third-party service with its own cookies. You're responsible for covering it in your privacy policy.

= For developers =

* `drift_curtain_is_active` (filter): whether maintenance mode is on.
* `drift_curtain_bypass_capability` (filter): the capability that sees the live site. Default `manage_options`.
* `drift_curtain_can_bypass` (filter): return `true` to let the current visitor through, for example on certain URLs.
* `drift_curtain_retry_after` (filter): the `Retry-After` value in seconds.
* `drift_curtain_page_html` (filter): the full maintenance page HTML.
* `drift_curtain_settings_after_fields` (action): add fields to a settings tab.

Source code, issues and pull requests: [github.com/drift-creative-systems/drift-curtain](https://github.com/drift-creative-systems/drift-curtain).

= Copyright =

Copyright © 2026 Drift Creative Systems Ltd

Drift Creative Systems Ltd. Registered in England and Wales, company number 17548220. Registered office: 167-169 Great Portland Street, 5th Floor, London W1W 5PF

== Installation ==

1. In your WordPress admin, go to Plugins → Add New, search for "Drift: Curtain", then install and activate it.
2. Go to Settings → Drift: Curtain.
3. Set up the page on the Design and Content & SEO tabs.
4. On the Status tab, tick "Show the maintenance page to visitors" and save.

Check the result in a private browser window. You're logged in as an administrator, so you'll still see the normal site.

== Frequently Asked Questions ==

= I turned it on but still see my site =

Administrators always see the live site. Open your site in a private window, or log out, to see what visitors see.

= How do I let a client see the site? =

On the Preview & access tab, click Generate next to "Preview link", save, then copy the link shown and send it to them. To stop it working, change or clear the token.

= Will this hurt my SEO? =

Not for a short outage. The page sends a `503` status, which tells search engines the site is temporarily down and to come back later. Keep maintenance periods short: if a site returns errors for days, search engines may visit it less often.

= Does it work with caching plugins? =

Yes. The page sends no-cache headers. If a visitor is shown a cached copy of a page from before you turned maintenance mode on, clear your page cache.

= What does "Also show this page while WordPress installs updates" do? =

While WordPress installs updates, it shows a plain "Briefly unavailable for scheduled maintenance" message. With this option ticked, Drift: Curtain adds two files to `wp-content`: a `maintenance.php` drop-in and `drift-curtain.html`, a static copy of your page. Visitors then see your page during updates as well. Both files are removed when you untick the option, deactivate the plugin or delete it. The plugin never replaces a `maintenance.php` it didn't add. Not available on multisite.

= Why can't I edit the embed code? =

Embed code is printed exactly as entered, so changing it needs the `unfiltered_html` capability. On multisite only network admins have it. It's also removed for everyone if `DISALLOW_UNFILTERED_HTML` is set in `wp-config.php`.

= Can I see the site through the REST API, feeds or WP-CLI? =

Yes. The REST API, AJAX, cron, feeds, WP-CLI and wp-admin aren't blocked.

== Changelog ==

= 1.0.1 =
* Copyright and company details on the settings screen footer and in the readme.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 1.0.1 =
Adds copyright and company details. No changes to how the plugin works.

= 1.0.0 =
First public release.
