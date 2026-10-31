<?php
/**
 * Dashboard widget.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows the attempt counts on the admin dashboard.
 */
class FLAC_Dashboard_Widget {

	const ID = 'flac_login_attempts';

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
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the widget for users who may manage options.
	 */
	public function add_widget() {
		if ( ! current_user_can( FLAC_Admin_Page::CAPABILITY ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::ID,
			__( 'Login attempts', 'failed-login-attempts-counter' ),
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues the stylesheet on the dashboard.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'index.php' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'flac-admin', FLAC_PLUGIN_URL . 'assets/css/admin.css', array(), FLAC_VERSION );
	}

	/**
	 * Prints the widget. The whole widget body links to the detail page.
	 */
	public function render() {
		$total  = $this->repository->counts();
		$recent = $this->repository->counts( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		?>
		<a class="flac-widget" href="<?php echo esc_url( FLAC_Admin_Page::url() ); ?>">
			<div class="flac-widget__stats">
				<div class="flac-stat flac-stat--success">
					<span class="flac-stat__number"><?php echo esc_html( number_format_i18n( $total['success'] ) ); ?></span>
					<span class="flac-stat__label"><?php esc_html_e( 'Successful', 'failed-login-attempts-counter' ); ?></span>
				</div>
				<div class="flac-stat flac-stat--failed">
					<span class="flac-stat__number"><?php echo esc_html( number_format_i18n( $total['failed'] ) ); ?></span>
					<span class="flac-stat__label"><?php esc_html_e( 'Failed', 'failed-login-attempts-counter' ); ?></span>
				</div>
			</div>
			<p class="flac-widget__recent">
				<?php
				printf(
					/* translators: 1: number of successful attempts, 2: number of failed attempts. */
					esc_html__( 'Last 24 hours: %1$s successful, %2$s failed.', 'failed-login-attempts-counter' ),
					esc_html( number_format_i18n( $recent['success'] ) ),
					esc_html( number_format_i18n( $recent['failed'] ) )
				);
				?>
			</p>
			<p class="flac-widget__more"><?php esc_html_e( 'View all attempts', 'failed-login-attempts-counter' ); ?> &rarr;</p>
		</a>
		<?php
	}
}
