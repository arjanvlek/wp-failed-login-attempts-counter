<?php
/**
 * Detail page.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists every login attempt under Dashboard > Login attempts.
 */
class FLAC_Admin_Page {

	const SLUG       = 'flac-login-attempts';
	const CAPABILITY = 'manage_options';

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Hook suffix returned by add_dashboard_page().
	 *
	 * @var string|false
	 */
	private $hook_suffix = false;

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
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Returns the URL of the detail page.
	 *
	 * @param array $args Extra query arguments, for example array( 'status' => 'failed' ).
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'index.php' ) );
	}

	/**
	 * Adds the page under the Dashboard menu.
	 */
	public function add_menu() {
		$this->hook_suffix = add_dashboard_page(
			__( 'Login attempts', 'failed-login-attempts-counter' ),
			__( 'Login attempts', 'failed-login-attempts-counter' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'add_screen_options' ) );
		}
	}

	/**
	 * Returns the hook suffix of the page.
	 *
	 * @return string|false
	 */
	public function hook_suffix() {
		return $this->hook_suffix;
	}

	/**
	 * Adds the "items per page" screen option.
	 */
	public function add_screen_options() {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Attempts per page', 'failed-login-attempts-counter' ),
				'default' => FLAC_List_Table::DEFAULT_PER_PAGE,
				'option'  => FLAC_List_Table::PER_PAGE_OPTION,
			)
		);
		add_filter( 'set-screen-option', array( __CLASS__, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_' . FLAC_List_Table::PER_PAGE_OPTION, array( __CLASS__, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Stores the "items per page" screen option.
	 *
	 * @param mixed  $status Current value.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function save_screen_option( $status, $option, $value ) {
		if ( FLAC_List_Table::PER_PAGE_OPTION === $option ) {
			return max( 1, (int) $value );
		}
		return $status;
	}

	/**
	 * Enqueues the assets on the detail page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'flac-admin', FLAC_PLUGIN_URL . 'assets/css/admin.css', array(), FLAC_VERSION );
		wp_enqueue_script( 'flac-admin', FLAC_PLUGIN_URL . 'assets/js/admin.js', array(), FLAC_VERSION, true );
		wp_localize_script(
			'flac-admin',
			'flacAdmin',
			array(
				/* translators: %s: time zone name, for example Europe/Amsterdam. */
				'timeZoneNotice' => __( 'Times are shown in your browser time zone (%s).', 'failed-login-attempts-counter' ),
			)
		);
	}

	/**
	 * Prints the page.
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'failed-login-attempts-counter' ) );
		}

		$table = new FLAC_List_Table( $this->repository );
		$table->prepare_items();
		?>
		<div class="wrap flac-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Login attempts', 'failed-login-attempts-counter' ); ?></h1>
			<p class="flac-timezone-notice description">
				<?php
				printf(
					/* translators: %s: time zone name, for example Europe/Amsterdam. */
					esc_html__( 'Times are shown in the site time zone (%s).', 'failed-login-attempts-counter' ),
					esc_html( wp_timezone_string() )
				);
				?>
			</p>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php if ( $table->current_status() ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $table->current_status() ); ?>" />
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}

