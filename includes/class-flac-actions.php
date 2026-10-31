<?php
/**
 * Clear log and reset counters actions.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the maintenance forms on the detail page through admin-post.php.
 */
class FLAC_Actions {

	const ACTION_CLEAR_LOG      = 'flac_clear_log';
	const ACTION_RESET_COUNTERS = 'flac_reset_counters';

	const NOTICE_ARG = 'flac_notice';
	const COUNT_ARG  = 'flac_count';

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

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
		add_action( 'admin_post_' . self::ACTION_CLEAR_LOG, array( $this, 'do_clear_log' ) );
		add_action( 'admin_post_' . self::ACTION_RESET_COUNTERS, array( $this, 'do_reset_counters' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_filter( 'removable_query_args', array( $this, 'removable_query_args' ) );
	}

	/**
	 * The "older than" choices of the clear log form, in days.
	 *
	 * @return int[]
	 */
	public static function periods() {
		return array( 7, 30, 90, 365 );
	}

	/**
	 * admin-post.php handler for the clear log form.
	 */
	public function do_clear_log() {
		wp_safe_redirect( $this->handle_clear_log() );
		exit;
	}

	/**
	 * admin-post.php handler for the reset counters form.
	 */
	public function do_reset_counters() {
		wp_safe_redirect( $this->handle_reset_counters() );
		exit;
	}

	/**
	 * Clears the log according to the posted form.
	 *
	 * @return string URL to redirect to.
	 */
	public function handle_clear_log() {
		$this->authorize( self::ACTION_CLEAR_LOG );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$older_than = isset( $_POST['older_than'] ) ? sanitize_key( wp_unslash( $_POST['older_than'] ) ) : '';
		$confirmed  = ! empty( $_POST['confirm_all'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'all' === $older_than ) {
			if ( ! $confirmed ) {
				return $this->notice_url( 'confirm_required' );
			}
			$deleted = $this->repository->clear_log();
		} elseif ( in_array( (int) $older_than, self::periods(), true ) ) {
			$deleted = $this->repository->clear_log( (int) $older_than );
		} else {
			return $this->notice_url( 'invalid_period' );
		}

		return $this->notice_url( 'cleared', $deleted['success'] + $deleted['failed'] );
	}

	/**
	 * Resets the counters according to the posted form.
	 *
	 * @return string URL to redirect to.
	 */
	public function handle_reset_counters() {
		$this->authorize( self::ACTION_RESET_COUNTERS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		if ( empty( $_POST['confirm'] ) ) {
			return $this->notice_url( 'confirm_required' );
		}

		$this->repository->reset_counters();

		return $this->notice_url( 'reset' );
	}

	/**
	 * Stops the request unless the user may manage options and the nonce is valid.
	 *
	 * @param string $action Nonce action.
	 */
	private function authorize( $action ) {
		if ( ! current_user_can( FLAC_Admin_Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'failed-login-attempts-counter' ), 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = isset( $_POST['_wpnonce'] ) ? wp_unslash( $_POST['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'The link you followed has expired. Please try again.', 'failed-login-attempts-counter' ), 403 );
		}
	}

	/**
	 * Builds the detail page URL with a notice code.
	 *
	 * @param string   $notice Notice code.
	 * @param int|null $count  Number of affected rows.
	 * @return string
	 */
	private function notice_url( $notice, $count = null ) {
		$args = array( self::NOTICE_ARG => $notice );
		if ( null !== $count ) {
			$args[ self::COUNT_ARG ] = (int) $count;
		}
		return FLAC_Admin_Page::maintenance_url( $args );
	}

	/**
	 * Lets WordPress strip the notice arguments from the address bar.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public function removable_query_args( $args ) {
		$args[] = self::NOTICE_ARG;
		$args[] = self::COUNT_ARG;
		return $args;
	}

	/**
	 * Prints the notice for the last action on the detail page.
	 */
	public function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$notice = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		$count  = isset( $_GET[ self::COUNT_ARG ] ) ? (int) $_GET[ self::COUNT_ARG ] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$pages = array( FLAC_Admin_Page::SLUG, FLAC_Admin_Page::MAINTENANCE_SLUG );
		if ( ! in_array( $page, $pages, true ) || '' === $notice ) {
			return;
		}

		$messages = array(
			'cleared'          => array(
				'success',
				sprintf(
					/* translators: %s: number of removed log entries. */
					_n( '%s log entry was cleared.', '%s log entries were cleared.', $count, 'failed-login-attempts-counter' ),
					number_format_i18n( $count )
				),
			),
			'reset'            => array( 'success', __( 'The counters were reset to the entries in the log.', 'failed-login-attempts-counter' ) ),
			'confirm_required' => array( 'error', __( 'Nothing was changed. Tick the confirmation box first.', 'failed-login-attempts-counter' ) ),
			'invalid_period'   => array( 'error', __( 'Nothing was changed. Choose a valid period.', 'failed-login-attempts-counter' ) ),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}
}
