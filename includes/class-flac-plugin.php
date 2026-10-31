<?php
/**
 * Plugin bootstrap.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin components together.
 */
final class FLAC_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var FLAC_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Storage for login attempts.
	 *
	 * @var FLAC_Repository
	 */
	public $repository;

	/**
	 * Listens to login events.
	 *
	 * @var FLAC_Listener
	 */
	public $listener;

	/**
	 * Dashboard widget.
	 *
	 * @var FLAC_Dashboard_Widget
	 */
	public $dashboard_widget;

	/**
	 * Detail page.
	 *
	 * @var FLAC_Admin_Page
	 */
	public $admin_page;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Returns the shared plugin instance.
	 *
	 * @return FLAC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Builds the component graph.
	 */
	private function __construct() {
		$this->repository       = new FLAC_Repository();
		$this->listener         = new FLAC_Listener( $this->repository );
		$this->admin_page       = new FLAC_Admin_Page( $this->repository );
		$this->dashboard_widget = new FLAC_Dashboard_Widget( $this->repository );
	}

	/**
	 * Activation hook: creates the database table.
	 */
	public static function activate() {
		FLAC_Repository::install();
	}

	/**
	 * Boots the plugin on `plugins_loaded`.
	 */
	public static function boot() {
		self::instance()->register();
	}

	/**
	 * Registers all hooks once.
	 */
	public function register() {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		$this->maybe_install();

		$this->listener->register();
		$this->dashboard_widget->register();
		$this->admin_page->register();
	}

	/**
	 * Creates or upgrades the table when the stored schema version is outdated.
	 */
	private function maybe_install() {
		if ( get_option( FLAC_Repository::DB_VERSION_OPTION ) !== FLAC_Repository::DB_VERSION ) {
			FLAC_Repository::install();
		}
	}
}
