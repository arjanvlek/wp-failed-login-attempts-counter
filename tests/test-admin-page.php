<?php
/**
 * Tests for FLAC_Admin_Page and FLAC_List_Table.
 *
 * @package FailedLoginAttemptsCounter
 */

/**
 * Detail page tests.
 */
class Test_FLAC_Admin_Page extends WP_UnitTestCase {

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Subject.
	 *
	 * @var FLAC_Admin_Page
	 */
	private $page;

	/**
	 * Prepares an empty table, an administrator and the page screen.
	 */
	public function set_up() {
		parent::set_up();
		$this->repository = new FLAC_Repository();
		$this->repository->delete_all();
		$this->page = FLAC_Plugin::instance()->admin_page;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard_page_' . FLAC_Admin_Page::SLUG );
		wp_dequeue_style( 'flac-admin' );
		wp_dequeue_script( 'flac-admin' );
		$_GET = array();
	}

	/**
	 * Restores globals.
	 */
	public function tear_down() {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Renders the page and returns the HTML.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		$this->page->render();
		return ob_get_clean();
	}

	public function test_page_is_added_under_dashboard_menu() {
		global $submenu;
		$submenu = array();

		do_action( 'admin_menu' );

		$slugs = wp_list_pluck( $submenu['index.php'], 2 );
		$this->assertContains( FLAC_Admin_Page::SLUG, $slugs );
		// Outside a real admin request the menu parent is unknown, so the prefix differs.
		$this->assertStringEndsWith( '_page_' . FLAC_Admin_Page::SLUG, $this->page->hook_suffix() );
	}

	public function test_url() {
		$this->assertSame( admin_url( 'index.php?page=flac-login-attempts' ), FLAC_Admin_Page::url() );
		$this->assertSame( admin_url( 'index.php?page=flac-login-attempts&status=failed' ), FLAC_Admin_Page::url( array( 'status' => 'failed' ) ) );
	}

	public function test_render_lists_attempts_with_time_ip_username_and_status() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'admin', '2026-10-31 12:34:56' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '2001:db8::1', 'editor', '2026-10-30 08:00:00' );

		$html = $this->render();

		$this->assertStringContainsString( '<time class="flac-time" datetime="2026-10-31T12:34:56Z"', $html );
		$this->assertStringContainsString( 'href="https://www.abuseipdb.com/check/203.0.113.5"', $html );
		$this->assertStringContainsString( 'href="https://www.abuseipdb.com/check/2001%3Adb8%3A%3A1"', $html );
		$this->assertStringContainsString( '<code>admin</code>', $html );
		$this->assertStringContainsString( '<code>editor</code>', $html );
		$this->assertStringContainsString( 'flac-status--failed', $html );
		$this->assertStringContainsString( 'flac-status--success', $html );
		$this->assertStringContainsString( 'class="flac-timezone-notice', $html );
	}

	public function test_render_shows_placeholders_for_missing_ip_and_username() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '', '' );

		$html = $this->render();

		$this->assertStringContainsString( '(none)', $html );
		$this->assertStringNotContainsString( 'abuseipdb.com', $html );
	}

	public function test_render_escapes_stored_html() {
		global $wpdb;
		$wpdb->insert(
			FLAC_Repository::table_name(),
			array(
				'attempted_at' => '2026-01-01 00:00:00',
				'ip_address'   => '203.0.113.5',
				'username'     => '<img src=x onerror=alert(1)>',
				'status'       => 'failed',
			)
		);

		$html = $this->render();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
	}

	public function test_render_filters_by_status_and_shows_view_counts() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'failed-user' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'success-user' );
		$_GET['status'] = 'failed';

		$html = $this->render();

		$this->assertStringContainsString( '<code>failed-user</code>', $html );
		$this->assertStringNotContainsString( '<code>success-user</code>', $html );
		$this->assertStringContainsString( 'All <span class="count">(2)</span>', $html );
		$this->assertStringContainsString( 'Successful <span class="count">(1)</span>', $html );
		$this->assertStringContainsString( 'Failed <span class="count">(1)</span>', $html );
		$this->assertStringContainsString( '<input type="hidden" name="status" value="failed" />', $html );
	}

	public function test_render_ignores_unknown_status_filter() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'failed-user' );
		$_GET['status'] = 'bogus';

		$this->assertStringContainsString( '<code>failed-user</code>', $this->render() );
	}

	public function test_render_shows_empty_message() {
		$this->assertStringContainsString( 'No login attempts recorded yet.', $this->render() );
	}

	public function test_render_denies_users_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		try {
			$this->page->render();
			$this->fail( 'Expected a WPDieException.' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		} finally {
			ob_end_clean();
		}
	}

	public function test_format_time_html_uses_site_timezone_as_fallback() {
		update_option( 'timezone_string', 'Europe/Amsterdam' );

		$html = FLAC_List_Table::format_time_html( '2026-07-01 12:00:00' );

		$this->assertStringContainsString( 'datetime="2026-07-01T12:00:00Z"', $html );
		$this->assertStringContainsString( '2:00 pm', $html );
	}

	public function test_format_time_html_with_invalid_input() {
		$this->assertSame( 'not a date', FLAC_List_Table::format_time_html( 'not a date' ) );
	}

	public function test_abuseipdb_url() {
		$this->assertSame( 'https://www.abuseipdb.com/check/203.0.113.5', FLAC_List_Table::abuseipdb_url( '203.0.113.5' ) );
	}

	public function test_assets_are_enqueued_on_detail_page_only() {
		do_action( 'admin_menu' );

		$this->page->enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( 'flac-admin', 'enqueued' ) );

		$this->page->enqueue_assets( $this->page->hook_suffix() );
		$this->assertTrue( wp_script_is( 'flac-admin', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'flac-admin', 'enqueued' ) );
	}
}
