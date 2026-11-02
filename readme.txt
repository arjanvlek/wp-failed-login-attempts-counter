=== Failed Login Attempts Counter ===
Contributors: arjanvlek
Tags: login, security, brute force, dashboard, widget
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Counts successful and failed login attempts on wp-login.php and shows them in a dashboard widget with a detail page.

== Description ==

The plugin records every login attempt made through wp-login.php:

* A dashboard widget shows the number of successful and failed attempts, in total and in the last 24 hours. Click the widget to open the detail page.
* The "Login attempts" menu lists each attempt with the date and time in your browser time zone, the IP address with a link to AbuseIPDB, the username that was attempted, and the status.

Only POST requests to wp-login.php are recorded. Empty username or password submissions, which WordPress core does not report through a hook, are recorded as failed attempts too.

The counters are stored apart from the log. The Maintenance page under the menu has a "Clear log" form to remove entries older than 7, 30, 90 or 365 days, or all entries, and a "Reset counters" form. Cleared entries stay in the counters and are shown as a separate count.

Developers can use the `flac_client_ip` filter to supply the real client IP behind a reverse proxy, and the `flac_should_record_attempt` filter to record attempts from other entry points.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen.
3. Open the Dashboard to see the "Login attempts" widget.

== Changelog ==

= 1.4.1 =
* Dashboard widget: the tiles keep their green or red border on hover and focus.

= 1.4.0 =
* Dashboard widget: the green tile opens the table with successful attempts, the red tile with failed attempts, and "View all attempts" the full table. The rest of the widget is not clickable.

= 1.3.1 =
* Small screens: the status badge is shown in the collapsed row, a row expands when any part of it is tapped, and the expanded fields are shown on their own lines.

= 1.3.0 =
* Added a Dutch (nl_NL) translation. Translations are loaded from the languages directory.

= 1.2.0 =
* "Login attempts" is a top-level admin menu, placed after Settings, with the submenu items Login attempts and Maintenance.
* The clear log and reset counters forms moved to the Maintenance page.

= 1.1.0 =
* Counters are stored in their own table, so clearing the log keeps the totals.
* Clear log form: remove entries older than 7, 30, 90 or 365 days, or all entries (with confirmation).
* Reset counters form (with confirmation).
* The detail page shows the counters and the number of cleared entries.

= 1.0.0 =
* Initial release.
