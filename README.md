# Failed Login Attempts Counter

A WordPress plugin that counts the successful and failed login attempts made through `wp-login.php`.

- A **dashboard widget** shows the totals and the counts of the last 24 hours. Click the widget to open the detail page.
- The **detail page** (Dashboard › Login attempts) lists every attempt with date/time in the browser time zone, the IP address (linked to AbuseIPDB), the username that was attempted, and the status.

## Requirements

- WordPress 6.0 or later
- PHP 7.4 or later

## Installation

1. Build the zip (see below) or copy this directory to `wp-content/plugins/failed-login-attempts-counter`.
2. Activate the plugin in **Plugins**. Activation creates the table `{prefix}_flac_login_attempts`.
3. Open the **Dashboard**. The widget "Login attempts" is visible to users with the `manage_options` capability.

Deleting the plugin from the Plugins screen drops the table and removes its options (`uninstall.php`).

## How attempts are detected

The plugin follows the behavior of `wp-login.php` in WordPress core:

| Request | Core behavior | Recorded as |
| --- | --- | --- |
| POST with a wrong username or password | `wp_authenticate()` fires `wp_login_failed` | failed |
| POST with a correct username and password | `wp_signon()` fires `wp_login` | success |
| POST with an empty username or password | Core fires no hook. The error codes `empty_username` and `empty_password` are ignored by `wp_authenticate()` | failed (detected on `login_form_login`) |
| GET requests | `wp_signon()` runs, but there are no credentials. A logged-in user can still trigger `wp_login` through the auth cookie | not recorded |
| XML-RPC, REST or custom login forms | `wp_login_failed` can fire, but the request is not a POST to `wp-login.php` | not recorded (see filters) |

Only `REMOTE_ADDR` is trusted as the client IP address. Times are stored in UTC.

### Filters and actions

| Hook | Purpose |
| --- | --- |
| `flac_client_ip` (filter) | Return the real client IP when the site runs behind a reverse proxy. |
| `flac_should_record_attempt` (filter) | Record attempts from other entry points, or block recording. Receives `$should_record, $status, $username, $listener`. |
| `flac_attempt_recorded` (action) | Fires after a row is stored. Receives `$id, $data`. |

Example, trust the `X-Forwarded-For` header from a known proxy:

```php
add_filter( 'flac_client_ip', function ( $ip ) {
    if ( '10.0.0.1' === $ip && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        return trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
    }
    return $ip;
} );
```

## Development

The plugin has no runtime dependencies. The development dependencies are PHPUnit and the Yoast PHPUnit Polyfills, which the WordPress test suite requires.

### Running the tests with Docker

No PHP or MySQL is needed on the host:

```bash
bin/test-docker.sh
```

This builds a PHP 8.3 image, installs the Composer dependencies, installs WordPress and its test suite into a Docker volume, and runs PHPUnit. Extra arguments are passed to PHPUnit, for example `bin/test-docker.sh --filter Listener`.

### Running the tests on the host

With PHP, Composer, MySQL (or MariaDB) and Subversion installed:

```bash
composer install
bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer test
```

`bin/install-wp-tests.sh` is the standard script from `wp scaffold plugin-tests`. It installs WordPress into `$TMPDIR/wordpress` and the test suite into `$TMPDIR/wordpress-tests-lib`. Set `WP_CORE_DIR` and `WP_TESTS_DIR` to change these locations.

### Building the plugin zip

```bash
bin/build.sh
```

This writes `dist/failed-login-attempts-counter-<version>.zip`. The version is read from the plugin header. Files listed in `.distignore` are left out, which is the same file `wp dist-archive` uses.

### Project layout

```
failed-login-attempts-counter.php   Plugin header, constants and bootstrap
uninstall.php                       Drops the table when the plugin is deleted
includes/class-flac-plugin.php      Wires the components together
includes/class-flac-repository.php  Table schema and queries
includes/class-flac-listener.php    Hooks into the login flow
includes/class-flac-dashboard-widget.php
includes/class-flac-admin-page.php  Detail page under Dashboard
includes/class-flac-list-table.php  WP_List_Table for the detail page
assets/                             Admin stylesheet and the time zone script
tests/                              PHPUnit tests (WordPress test suite)
bin/                                Install, test and build scripts
docker/, docker-compose.yml         PHP and MariaDB containers for the tests
```

## License

GPL-2.0-or-later.
