<?php
/**
 * Tests for FLAC_Dashboard_Widget.
 *
 * @package FailedLoginAttemptsCounter
 */

/**
 * Dashboard widget tests.
 */
class Test_FLAC_Dashboard_Widget extends WP_UnitTestCase {

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Subject.
	 *
	 * @var FLAC_Dashboard_Widget
	 */
	private $widget;

	/**
	 * Prepares an empty table and the dashboard screen.
	 */
	public function set_up() {
		parent::set_up();
		$this->repository = new FLAC_Repository();
		$this->repository->delete_all();
		$this->repository->reset_counters();
		$this->widget = FLAC_Plugin::instance()->dashboard_widget;

		set_current_screen( 'dashboard' );
		wp_dequeue_style( 'flac-admin' );
		$GLOBALS['wp_meta_boxes'] = array();
	}

	/**
	 * Restores globals.
	 */
	public function tear_down() {
		$GLOBALS['wp_meta_boxes'] = array();
		parent::tear_down();
	}

	public function test_widget_is_registered_for_administrators() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		do_action( 'wp_dashboard_setup' );

		$this->assertArrayHasKey( FLAC_Dashboard_Widget::ID, $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'] );
	}

	public function test_widget_is_not_registered_for_subscribers() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		do_action( 'wp_dashboard_setup' );

		$this->assertFalse( isset( $GLOBALS['wp_meta_boxes']['dashboard']['normal']['core'][ FLAC_Dashboard_Widget::ID ] ) );
	}

	public function test_render_shows_counts_and_links_to_detail_page() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'c' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'old', '2000-01-01 00:00:00' );

		ob_start();
		$this->widget->render();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/flac-stat--success.*?flac-stat__number">1</s', $html );
		$this->assertMatchesRegularExpression( '/flac-stat--failed.*?flac-stat__number">3</s', $html );
		$this->assertStringContainsString( 'Last 24 hours: 1 successful, 2 failed.', $html );
	}

	public function test_render_links_tiles_and_view_all_separately() {
		ob_start();
		$this->widget->render();
		$html = ob_get_clean();

		$success_url = esc_url( FLAC_Admin_Page::url( array( 'status' => 'success' ) ) );
		$failed_url  = esc_url( FLAC_Admin_Page::url( array( 'status' => 'failed' ) ) );
		$all_url     = esc_url( FLAC_Admin_Page::url() );

		$this->assertStringContainsString( '<a class="flac-stat flac-stat--success" href="' . $success_url . '">', $html );
		$this->assertStringContainsString( '<a class="flac-stat flac-stat--failed" href="' . $failed_url . '">', $html );
		$this->assertStringContainsString( '<a href="' . $all_url . '">View all attempts', $html );
		$this->assertSame( 3, substr_count( $html, '<a ' ), 'Only the two tiles and the view-all link are links.' );
		$this->assertStringContainsString( '<div class="flac-widget">', $html );
		$this->assertMatchesRegularExpression( '#<p class="flac-widget__recent">\s*Last 24 hours[^<]*</p>#', $html, 'The last-24-hours line is plain text.' );
	}

	public function test_render_keeps_totals_after_clearing_the_log() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );
		$this->repository->clear_log();

		ob_start();
		$this->widget->render();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/flac-stat--success.*?flac-stat__number">1</s', $html );
		$this->assertMatchesRegularExpression( '/flac-stat--failed.*?flac-stat__number">1</s', $html );
		$this->assertStringContainsString( 'Last 24 hours: 0 successful, 0 failed.', $html );
	}

	public function test_stylesheet_is_enqueued_on_dashboard_only() {
		$this->widget->enqueue_assets( 'edit.php' );
		$this->assertFalse( wp_style_is( 'flac-admin', 'enqueued' ) );

		$this->widget->enqueue_assets( 'index.php' );
		$this->assertTrue( wp_style_is( 'flac-admin', 'enqueued' ) );
	}
}
