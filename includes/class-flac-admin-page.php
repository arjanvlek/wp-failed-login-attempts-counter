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

		$counters = $this->repository->get_counters();
		$logged   = $this->repository->counts();
		$cleared  = $counters['cleared_success'] + $counters['cleared_failed'];
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
			<div class="flac-summary">
				<p class="flac-summary__counters">
					<?php
					printf(
						/* translators: 1: number of successful attempts, 2: number of failed attempts. */
						esc_html__( 'Counted: %1$s successful, %2$s failed.', 'failed-login-attempts-counter' ),
						'<strong>' . esc_html( number_format_i18n( $counters['success'] ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $counters['failed'] ) ) . '</strong>'
					);
					?>
				</p>
				<p class="flac-summary__cleared">
					<?php
					printf(
						/* translators: 1: number of cleared entries, 2: cleared successful attempts, 3: cleared failed attempts. */
						esc_html__( 'Cleared from the log: %1$s (%2$s successful, %3$s failed). The counters still include them.', 'failed-login-attempts-counter' ),
						'<strong>' . esc_html( number_format_i18n( $cleared ) ) . '</strong>',
						esc_html( number_format_i18n( $counters['cleared_success'] ) ),
						esc_html( number_format_i18n( $counters['cleared_failed'] ) )
					);
					?>
				</p>
			</div>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php if ( $table->current_status() ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $table->current_status() ); ?>" />
				<?php endif; ?>
				<?php $table->display(); ?>
			</form>

			<h2><?php esc_html_e( 'Maintenance', 'failed-login-attempts-counter' ); ?></h2>
			<div class="flac-tools">
				<div class="flac-tool">
					<h3><?php esc_html_e( 'Clear log', 'failed-login-attempts-counter' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Removes entries from the log table. The counters are not changed; cleared entries are added to the cleared count.', 'failed-login-attempts-counter' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flac-clear-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( FLAC_Actions::ACTION_CLEAR_LOG ); ?>" />
						<?php wp_nonce_field( FLAC_Actions::ACTION_CLEAR_LOG ); ?>
						<p>
							<label for="flac-older-than"><?php esc_html_e( 'Remove entries', 'failed-login-attempts-counter' ); ?></label>
							<select name="older_than" id="flac-older-than">
								<?php foreach ( FLAC_Actions::periods() as $days ) : ?>
									<option value="<?php echo esc_attr( $days ); ?>">
										<?php
										printf(
											/* translators: %s: number of days. */
											esc_html( _n( 'older than %s day', 'older than %s days', $days, 'failed-login-attempts-counter' ) ),
											esc_html( number_format_i18n( $days ) )
										);
										?>
									</option>
								<?php endforeach; ?>
								<option value="all"><?php esc_html_e( 'all entries', 'failed-login-attempts-counter' ); ?></option>
							</select>
						</p>
						<p class="flac-confirm-all">
							<label>
								<input type="checkbox" name="confirm_all" value="1" />
								<?php esc_html_e( 'Yes, remove all entries from the log. This cannot be undone.', 'failed-login-attempts-counter' ); ?>
							</label>
						</p>
						<?php submit_button( __( 'Clear log', 'failed-login-attempts-counter' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
				<div class="flac-tool">
					<h3><?php esc_html_e( 'Reset counters', 'failed-login-attempts-counter' ); ?></h3>
					<p class="description">
						<?php
						printf(
							/* translators: 1: successful attempts in the log, 2: failed attempts in the log. */
							esc_html__( 'Sets the counters back to the entries that are in the log (%1$s successful, %2$s failed) and sets the cleared count to 0.', 'failed-login-attempts-counter' ),
							esc_html( number_format_i18n( $logged['success'] ) ),
							esc_html( number_format_i18n( $logged['failed'] ) )
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="flac-reset-form">
						<input type="hidden" name="action" value="<?php echo esc_attr( FLAC_Actions::ACTION_RESET_COUNTERS ); ?>" />
						<?php wp_nonce_field( FLAC_Actions::ACTION_RESET_COUNTERS ); ?>
						<p>
							<label>
								<input type="checkbox" name="confirm" value="1" />
								<?php esc_html_e( 'Yes, reset the counters. This cannot be undone.', 'failed-login-attempts-counter' ); ?>
							</label>
						</p>
						<?php submit_button( __( 'Reset counters', 'failed-login-attempts-counter' ), 'secondary', 'submit', false ); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}

