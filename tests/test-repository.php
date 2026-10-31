<?php
/**
 * Tests for FLAC_Repository.
 *
 * @package FailedLoginAttemptsCounter
 */

/**
 * Repository tests.
 */
class Test_FLAC_Repository extends WP_UnitTestCase {

	/**
	 * Subject.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Prepares an empty table.
	 */
	public function set_up() {
		parent::set_up();
		$this->repository = new FLAC_Repository();
		$this->repository->delete_all();
		$this->repository->reset_counters();
	}

	public function test_table_is_created_on_boot() {
		global $wpdb;
		$table = FLAC_Repository::table_name();
		$this->assertSame( $wpdb->prefix . 'flac_login_attempts', $table );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertSame( FLAC_Repository::DB_VERSION, get_option( FLAC_Repository::DB_VERSION_OPTION ) );
	}

	public function test_record_stores_a_row() {
		$id = $this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'admin' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$rows = $this->repository->find();
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $id, (string) $rows[0]->id );
		$this->assertSame( 'failed', $rows[0]->status );
		$this->assertSame( '203.0.113.5', $rows[0]->ip_address );
		$this->assertSame( 'admin', $rows[0]->username );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows[0]->attempted_at );
	}

	public function test_record_uses_utc_now_by_default() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'admin' );
		$row = $this->repository->find()[0];

		$this->assertEqualsWithDelta( time(), strtotime( $row->attempted_at . ' UTC' ), 5 );
	}

	public function test_record_accepts_explicit_time() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'admin', '2020-02-03 04:05:06' );
		$this->assertSame( '2020-02-03 04:05:06', $this->repository->find()[0]->attempted_at );
	}

	public function test_record_rejects_unknown_status() {
		$this->assertFalse( $this->repository->record( 'weird', '203.0.113.5', 'admin' ) );
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_record_sanitizes_and_truncates_username() {
		$long = str_repeat( 'a', 300 );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', '<script>alert(1)</script>' . $long );

		$username = $this->repository->find()[0]->username;
		$this->assertStringNotContainsString( '<script>', $username );
		$this->assertSame( 255, mb_strlen( $username ) );
	}

	public function test_record_fires_action() {
		$captured = array();
		add_action(
			'flac_attempt_recorded',
			function ( $id, $data ) use ( &$captured ) {
				$captured = array( $id, $data );
			},
			10,
			2
		);

		$id = $this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'admin' );

		$this->assertSame( $id, $captured[0] );
		$this->assertSame( 'failed', $captured[1]['status'] );
		$this->assertSame( 'admin', $captured[1]['username'] );
	}

	public function test_count_and_counts() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'b' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'c' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'd' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'e' );

		$this->assertSame( 5, $this->repository->count() );
		$this->assertSame( 2, $this->repository->count( FLAC_Repository::STATUS_SUCCESS ) );
		$this->assertSame( 3, $this->repository->count( FLAC_Repository::STATUS_FAILED ) );
		$this->assertSame(
			array(
				'success' => 2,
				'failed'  => 3,
			),
			$this->repository->counts()
		);
	}

	public function test_count_since() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'old', '2000-01-01 00:00:00' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'new' );

		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$this->assertSame( 1, $this->repository->count( null, $since ) );
		$this->assertSame( 1, $this->repository->count( FLAC_Repository::STATUS_FAILED, $since ) );
		$this->assertSame( 0, $this->repository->count( FLAC_Repository::STATUS_SUCCESS, $since ) );
		$this->assertSame( array( 'success' => 0, 'failed' => 1 ), $this->repository->counts( $since ) );
	}

	public function test_find_orders_newest_first_and_paginates() {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', "user{$i}", sprintf( '2024-01-0%d 00:00:00', $i ) );
		}

		$page1 = $this->repository->find( array( 'per_page' => 2, 'page' => 1 ) );
		$this->assertSame( array( 'user5', 'user4' ), wp_list_pluck( $page1, 'username' ) );

		$page2 = $this->repository->find( array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( array( 'user3', 'user2' ), wp_list_pluck( $page2, 'username' ) );

		$page3 = $this->repository->find( array( 'per_page' => 2, 'page' => 3 ) );
		$this->assertSame( array( 'user1' ), wp_list_pluck( $page3, 'username' ) );

		$asc = $this->repository->find( array( 'order' => 'asc', 'per_page' => 2 ) );
		$this->assertSame( array( 'user1', 'user2' ), wp_list_pluck( $asc, 'username' ) );
	}

	public function test_find_filters_by_status_and_sorts_by_column() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'zed' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'bob' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'amy' );

		$failed = $this->repository->find( array( 'status' => FLAC_Repository::STATUS_FAILED, 'orderby' => 'username', 'order' => 'ASC' ) );
		$this->assertSame( array( 'amy', 'bob' ), wp_list_pluck( $failed, 'username' ) );
	}

	public function test_find_ignores_invalid_orderby_and_order() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'a', '2024-01-01 00:00:00' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b', '2024-01-02 00:00:00' );

		$rows = $this->repository->find( array( 'orderby' => 'id; DROP TABLE x', 'order' => 'sideways' ) );
		$this->assertSame( array( 'b', 'a' ), wp_list_pluck( $rows, 'username' ) );
	}

	public function test_counters_table_is_created_and_seeded() {
		global $wpdb;
		$table = FLAC_Repository::counters_table_name();
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertSame(
			array( 'success' => 0, 'failed' => 0, 'cleared_success' => 0, 'cleared_failed' => 0 ),
			$this->repository->get_counters()
		);
	}

	public function test_record_increments_counters() {
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'c' );

		$counters = $this->repository->get_counters();
		$this->assertSame( 1, $counters['success'] );
		$this->assertSame( 2, $counters['failed'] );
	}

	public function test_add_to_counter_ignores_unknown_names() {
		$this->repository->add_to_counter( 'bogus', 5 );
		$this->assertArrayNotHasKey( 'bogus', $this->repository->get_counters() );
	}

	public function test_seed_counters_uses_existing_log_rows_without_overwriting() {
		global $wpdb;
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'c' );

		// Simulate an upgrade from a version without the failed counter.
		$wpdb->delete( FLAC_Repository::counters_table_name(), array( 'name' => 'failed' ) );
		$this->repository->add_to_counter( 'success', 10 );

		FLAC_Repository::seed_counters();

		$counters = $this->repository->get_counters();
		$this->assertSame( 2, $counters['failed'], 'Missing counter is seeded from the log.' );
		$this->assertSame( 11, $counters['success'], 'Existing counter is left alone.' );
	}

	public function test_clear_log_older_than_keeps_recent_rows_and_counters() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'old-failed', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'old-success', gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'recent', gmdate( 'Y-m-d H:i:s', time() - 6 * DAY_IN_SECONDS ) );

		$deleted = $this->repository->clear_log( 7 );

		$this->assertSame( array( 'success' => 1, 'failed' => 1 ), $deleted );
		$this->assertSame( array( 'recent' ), wp_list_pluck( $this->repository->find(), 'username' ) );
		$this->assertSame(
			array( 'success' => 1, 'failed' => 2, 'cleared_success' => 1, 'cleared_failed' => 1 ),
			$this->repository->get_counters()
		);
	}

	public function test_clear_log_all_empties_table_and_keeps_counters() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'c' );

		$deleted = $this->repository->clear_log();

		$this->assertSame( array( 'success' => 1, 'failed' => 2 ), $deleted );
		$this->assertSame( 0, $this->repository->count() );
		$this->assertSame(
			array( 'success' => 1, 'failed' => 2, 'cleared_success' => 1, 'cleared_failed' => 2 ),
			$this->repository->get_counters()
		);
	}

	public function test_clear_log_fires_action() {
		$captured = null;
		add_action(
			'flac_log_cleared',
			function ( $deleted, $older_than_days ) use ( &$captured ) {
				$captured = array( $deleted, $older_than_days );
			},
			10,
			2
		);
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'a' );

		$this->repository->clear_log( 30 );

		$this->assertSame( array( array( 'success' => 0, 'failed' => 0 ), 30 ), $captured );
	}

	public function test_reset_counters_matches_log_rows_and_clears_cleared_counts() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'a', '2000-01-01 00:00:00' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );
		$this->repository->record( FLAC_Repository::STATUS_SUCCESS, '203.0.113.5', 'c' );
		$this->repository->clear_log( 365 );
		$this->assertSame( 2, $this->repository->get_counters()['failed'] );

		$result = $this->repository->reset_counters();

		$expected = array( 'success' => 1, 'failed' => 1, 'cleared_success' => 0, 'cleared_failed' => 0 );
		$this->assertSame( $expected, $result );
		$this->assertSame( $expected, $this->repository->get_counters() );
	}

	public function test_delete_all() {
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'a' );
		$this->repository->record( FLAC_Repository::STATUS_FAILED, '203.0.113.5', 'b' );

		$this->assertSame( 2, $this->repository->delete_all() );
		$this->assertSame( 0, $this->repository->count() );
	}
}
