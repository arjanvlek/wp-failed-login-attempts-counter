=== Failed Login Attempts Counter ===
Contributors: arjanvlek
Tags: login, security, brute force, dashboard, widget
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Counts successful and failed login attempts on wp-login.php and shows them in a dashboard widget with a detail page.

== Description ==

The plugin records every login attempt made through wp-login.php:

* A dashboard widget shows the number of successful and failed attempts, in total and in the last 24 hours. Click the widget to open the detail page.
* The detail page lists each attempt with the date and time in your browser time zone, the IP address with a link to AbuseIPDB, the username that was attempted, and the status.

Only POST requests to wp-login.php are recorded. Empty username or password submissions, which WordPress core does not report through a hook, are recorded as failed attempts too.

The counters are stored apart from the log. The detail page has a "Clear log" form to remove entries older than 7, 30, 90 or 365 days, or all entries, and a "Reset counters" form. Cleared entries stay in the counters and are shown as a separate count.

Developers can use the `flac_client_ip` filter to supply the real client IP behind a reverse proxy, and the `flac_should_record_attempt` filter to record attempts from other entry points.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open the Dashboard to see the "Login attempts" widget.

== Changelog ==

= 1.1.0 =
* Counters are stored in their own table, so clearing the log keeps the totals.
* Clear log form: remove entries older than 7, 30, 90 or 365 days, or all entries (with confirmation).
* Reset counters form (with confirmation).
* The detail page shows the counters and the number of cleared entries.

= 1.0.0 =
* Initial release.
