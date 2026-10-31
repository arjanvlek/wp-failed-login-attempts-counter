<?php
/**
 * Tests for FLAC_Actions.
 *
 * @package FailedLoginAttemptsCounter
 */

/**
 * Clear log and reset counters tests.
 */
class Test_FLAC_Actions extends WP_UnitTestCase {

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Subject.
	 *
	 * @var FLAC_Actions
	 */
	private $actions;

	/**
	 * Prepares an empty log, an administrator and three attempts.
	 */
	public function set_up() {
		parent::set_up();
		$this->repository = new FLAC_Repository();
		$this->repository->delete_all();
		$this->repository->reset_counters();
		$this->actions = FLAC_Plugin::instance()->actions;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST = array();
		$_GET  = array();

		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'old', gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'recent' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'ok' );
	}

	/**
	 * Restores globals.
	 */
	public function tear_down() {
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	/**
	 * Fills $_POST like the form would.
	 *
	 * @param string $action Nonce action.
	 * @param array  $fields Extra fields.
	 */
	private function post( $action, array $fields = array() ) {
		$_POST = array_merge( array( '_wpnonce' => wp_create_nonce( $action ) ), $fields );
	}

	public function test_hooks_are_registered() {
		$this->assertSame( 10, has_action( 'admin_post_flac_clear_log', array( $this->actions, 'do_clear_log' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_flac_reset_counters', array( $this->actions, 'do_reset_counters' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $this->actions, 'render_notices' ) ) );
		$this->assertContains( 'flac_notice', apply_filters( 'removable_query_args', array() ) );
	}

	public function test_periods() {
		$this->assertSame( array( 7, 30, 90, 365 ), FLAC_Actions::periods() );
	}

	public function test_clear_log_older_than_30_days() {
		$this->post( FLAC_Actions::ACTION_CLEAR_LOG, array( 'older_than' => '30' ) );

		$url = $this->actions->handle_clear_log();

		$this->assertSame( FLAC_Admin_Page::maintenance_url( array( 'flac_notice' => 'cleared', 'flac_count' => 1 ) ), $url );
		$this->assertSame( array( 'recent', 'ok' ), wp_list_pluck( $this->repository->find( array( 'orderby' => 'id', 'order' => 'ASC' ) ), 'username' ) );
		$this->assertSame( array( 'success' => 1, 'failed' => 2, 'cleared_success' => 0, 'cleared_failed' => 1 ), $this->repository->get_counters() );
	}

	public function test_clear_log_older_than_7_days_removes_nothing_recent() {
		$this->post( FLAC_Actions::ACTION_CLEAR_LOG, array( 'older_than' => '7' ) );

		$url = $this->actions->handle_clear_log();

		$this->assertStringContainsString( 'flac_count=1', $url );
		$this->assertSame( 2, $this->repository->count() );
	}

	public function test_clear_all_requires_confirmation() {
		$this->post( FLAC_Actions::ACTION_CLEAR_LOG, array( 'older_than' => 'all' ) );

		$url = $this->actions->handle_clear_log();

		$this->assertSame( FLAC_Admin_Page::maintenance_url( array( 'flac_notice' => 'confirm_required' ) ), $url );
		$this->assertSame( 3, $this->repository->count() );
	}

	public function test_clear_all_with_confirmation() {
		$this->post( FLAC_Actions::ACTION_CLEAR_LOG, array( 'older_than' => 'all', 'confirm_all' => '1' ) );

		$url = $this->actions->handle_clear_log();

		$this->assertStringContainsString( 'flac_notice=cleared', $url );
		$this->assertStringContainsString( 'flac_count=3', $url );
		$this->assertSame( 0, $this->repository->count() );
		$this->assertSame( array( 'success' => 1, 'failed' => 2, 'cleared_success' => 1, 'cleared_failed' => 2 ), $this->repository->get_counters() );
	}

	public function test_clear_log_rejects_unknown_period() {
		$this->post( FLAC_Actions::ACTION_CLEAR_LOG, array( 'older_than' => '12' ) );

		$url = $this->actions->handle_clear_log();

		$this->assertSame( FLAC_Admin_Page::maintenance_url( array( 'flac_notice' => 'invalid_period' ) ), $url );
		$this->assertSame( 3, $this->repository->count() );
	}

	public function test_clear_log_rejects_invalid_nonce() {
		$_POST = array( '_wpnonce' => 'bogus', 'older_than' => 'all', 'confirm_all' => '1' );

		$this->expectException( 'WPDieException' );
		$this->actions->handle_clear_log();
	}

	public function test_clear_log_rejects_users_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->post( FLAC_Actions::ACTION_CLEAR_LOG, array( 'older_than' => '7' ) );

		$this->expectException( 'WPDieException' );
		$this->actions->handle_clear_log();
	}

	public function test_reset_counters_requires_confirmation() {
		$this->repository->clear_log( 30 );
		$this->post( FLAC_Actions::ACTION_RESET_COUNTERS );

		$url = $this->actions->handle_reset_counters();

		$this->assertSame( FLAC_Admin_Page::maintenance_url( array( 'flac_notice' => 'confirm_required' ) ), $url );
		$this->assertSame( 2, $this->repository->get_counters()['failed'] );
	}

	public function test_reset_counters_with_confirmation() {
		$this->repository->clear_log( 30 );
		$this->post( FLAC_Actions::ACTION_RESET_COUNTERS, array( 'confirm' => '1' ) );

		$url = $this->actions->handle_reset_counters();

		$this->assertSame( FLAC_Admin_Page::maintenance_url( array( 'flac_notice' => 'reset' ) ), $url );
		$this->assertSame( array( 'success' => 1, 'failed' => 1, 'cleared_success' => 0, 'cleared_failed' => 0 ), $this->repository->get_counters() );
	}

	public function test_reset_counters_rejects_invalid_nonce() {
		$_POST = array( '_wpnonce' => 'bogus', 'confirm' => '1' );

		$this->expectException( 'WPDieException' );
		$this->actions->handle_reset_counters();
	}

	/**
	 * Renders the admin notices and returns the HTML.
	 *
	 * @return string
	 */
	private function notices() {
		ob_start();
		$this->actions->render_notices();
		return ob_get_clean();
	}

	public function test_notices_only_show_on_the_plugin_pages() {
		$_GET = array( 'flac_notice' => 'reset' );
		$this->assertSame( '', $this->notices() );

		$_GET = array( 'page' => 'edit', 'flac_notice' => 'reset' );
		$this->assertSame( '', $this->notices() );

		$_GET = array( 'page' => FLAC_Admin_Page::MAINTENANCE_SLUG, 'flac_notice' => 'reset' );
		$this->assertStringContainsString( 'notice-success', $this->notices() );

		$_GET = array( 'page' => FLAC_Admin_Page::SLUG );
		$this->assertSame( '', $this->notices() );

		$_GET = array( 'page' => FLAC_Admin_Page::SLUG, 'flac_notice' => 'unknown' );
		$this->assertSame( '', $this->notices() );
	}

	public function test_notice_messages() {
		$_GET = array( 'page' => FLAC_Admin_Page::SLUG, 'flac_notice' => 'cleared', 'flac_count' => '1' );
		$this->assertStringContainsString( '1 log entry was cleared.', $this->notices() );
		$this->assertStringContainsString( 'notice-success', $this->notices() );

		$_GET['flac_count'] = '12';
		$this->assertStringContainsString( '12 log entries were cleared.', $this->notices() );

		$_GET = array( 'page' => FLAC_Admin_Page::SLUG, 'flac_notice' => 'reset' );
		$this->assertStringContainsString( 'The counters were reset', $this->notices() );

		$_GET = array( 'page' => FLAC_Admin_Page::SLUG, 'flac_notice' => 'confirm_required' );
		$this->assertStringContainsString( 'notice-error', $this->notices() );
		$this->assertStringContainsString( 'Tick the confirmation box', $this->notices() );

		$_GET = array( 'page' => FLAC_Admin_Page::SLUG, 'flac_notice' => 'invalid_period' );
		$this->assertStringContainsString( 'Choose a valid period', $this->notices() );
	}
}
