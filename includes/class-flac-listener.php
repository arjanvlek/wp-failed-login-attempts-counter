<?php
/**
 * Listens to login events on wp-login.php.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records login attempts made through wp-login.php.
 *
 * How wp-login.php behaves (WordPress core):
 *
 * - `login_init` fires for every request to wp-login.php, GET and POST.
 * - For the default "login" action, wp-login.php calls wp_signon() on every
 *   request. wp_signon() reads the POST fields `log` and `pwd` and calls
 *   wp_authenticate().
 * - wp_authenticate() fires `wp_login_failed` when authentication fails,
 *   except when the error code is `empty_username` or `empty_password`.
 *   A POST with an empty username or password therefore fires no hook.
 * - wp_signon() fires `wp_login` on success. Because wp_signon() also runs
 *   on GET requests, a logged-in user who opens wp-login.php can trigger
 *   `wp_login` through the auth cookie without submitting credentials.
 *
 * This listener therefore:
 *
 * 1. Marks the request as a login-page request on `login_init`.
 * 2. Records a failed attempt on `login_form_login` when a POST has an empty
 *    username or password, because core fires no hook for that case.
 * 3. Records `wp_login_failed` and `wp_login`, but only for POST requests to
 *    wp-login.php. Other entry points (XML-RPC, custom login forms) are
 *    ignored unless the `flac_should_record_attempt` filter allows them.
 */
class FLAC_Listener {

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Whether the current request is handled by wp-login.php.
	 *
	 * @var bool
	 */
	private $is_login_page = false;

	/**
	 * Constructor.
	 *
	 * @param FLAC_Repository $repository Storage.
	 */
	public function __construct( FLAC_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Registers the hooks.
	 */
	public function register() {
		add_action( 'login_init', array( $this, 'mark_login_page' ) );
		add_action( 'login_form_login', array( $this, 'record_empty_credentials' ) );
		add_action( 'wp_login_failed', array( $this, 'record_failed' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'record_success' ), 10, 2 );
	}

	/**
	 * Remembers that wp-login.php handles this request.
	 */
	public function mark_login_page() {
		$this->set_is_login_page( true );
	}

	/**
	 * Sets whether the current request is a wp-login.php request.
	 *
	 * @param bool $is_login_page Value.
	 */
	public function set_is_login_page( $is_login_page ) {
		$this->is_login_page = (bool) $is_login_page;
	}

	/**
	 * Whether the current request is a wp-login.php request.
	 *
	 * @return bool
	 */
	public function is_login_page() {
		return $this->is_login_page;
	}

	/**
	 * Records a failed attempt for a POST with an empty username or password.
	 *
	 * Core ignores the `empty_username` and `empty_password` errors and does
	 * not fire `wp_login_failed` for them. This method fills that gap.
	 *
	 * @return int|false Row id or false when nothing was recorded.
	 */
	public function record_empty_credentials() {
		if ( ! $this->is_post_request() || ! $this->has_login_fields() ) {
			return false;
		}

		$username = $this->posted_username();
		$password = $this->posted_password();

		// Same checks as wp_authenticate() and wp_authenticate_username_password().
		if ( ! empty( sanitize_user( $username ) ) && ! empty( trim( $password ) ) ) {
			return false; // Core fires wp_login_failed or wp_login for this request.
		}

		return $this->record( FLAC_Repository::STATUS_FAILED, $username );
	}

	/**
	 * Handles `wp_login_failed`.
	 *
	 * @param string        $username Username or email address that was attempted.
	 * @param WP_Error|null $error    Error, available since WordPress 5.4.
	 * @return int|false Row id or false when nothing was recorded.
	 */
	public function record_failed( $username, $error = null ) {
		unset( $error );
		return $this->record( FLAC_Repository::STATUS_FAILED, (string) $username );
	}

	/**
	 * Handles `wp_login`.
	 *
	 * @param string       $user_login Username.
	 * @param WP_User|null $user       User object.
	 * @return int|false Row id or false when nothing was recorded.
	 */
	public function record_success( $user_login, $user = null ) {
		unset( $user );
		return $this->record( FLAC_Repository::STATUS_SUCCESS, (string) $user_login );
	}

	/**
	 * Records an attempt when the request qualifies.
	 *
	 * @param string $status   Status constant.
	 * @param string $username Attempted username.
	 * @return int|false
	 */
	private function record( $status, $username ) {
		$should_record = $this->is_login_page && $this->is_post_request();

		/**
		 * Filters whether a login attempt is recorded.
		 *
		 * By default only POST requests to wp-login.php are recorded.
		 *
		 * @param bool          $should_record Whether to record the attempt.
		 * @param string        $status        'success' or 'failed'.
		 * @param string        $username      Attempted username.
		 * @param FLAC_Listener $listener      The listener.
		 */
		$should_record = apply_filters( 'flac_should_record_attempt', $should_record, $status, $username, $this );

		if ( ! $should_record ) {
			return false;
		}

		return $this->repository->record( $status, self::client_ip(), $username );
	}

	/**
	 * Returns the client IP address.
	 *
	 * Only REMOTE_ADDR is trusted. Sites behind a reverse proxy can use the
	 * `flac_client_ip` filter to supply the real address.
	 *
	 * @return string Valid IPv4 or IPv6 address, or an empty string.
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		/**
		 * Filters the client IP address that is stored with an attempt.
		 *
		 * @param string $ip IP address from REMOTE_ADDR.
		 */
		$ip = apply_filters( 'flac_client_ip', $ip );
		$ip = trim( (string) $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Whether the current request is a POST request.
	 *
	 * @return bool
	 */
	private function is_post_request() {
		return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Whether the POST body contains login form fields.
	 *
	 * @return bool
	 */
	private function has_login_fields() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['log'] ) || isset( $_POST['pwd'] ) || isset( $_POST['wp-submit'] );
	}

	/**
	 * Returns the posted username, like wp_signon() reads it.
	 *
	 * @return string
	 */
	private function posted_username() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return isset( $_POST['log'] ) && is_string( $_POST['log'] ) ? wp_unslash( $_POST['log'] ) : '';
	}

	/**
	 * Returns the posted password, like wp_signon() reads it.
	 *
	 * @return string
	 */
	private function posted_password() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return isset( $_POST['pwd'] ) && is_string( $_POST['pwd'] ) ? (string) $_POST['pwd'] : '';
	}
}
