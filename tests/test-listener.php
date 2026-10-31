<?php
/**
 * Tests for FLAC_Listener.
 *
 * @package FailedLoginAttemptsCounter
 */

/**
 * Listener tests.
 */
class Test_FLAC_Listener extends WP_UnitTestCase {

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Subject, not registered on any hook.
	 *
	 * @var FLAC_Listener
	 */
	private $listener;

	/**
	 * Simulates a POST to wp-login.php from 203.0.113.5.
	 */
	public function set_up() {
		parent::set_up();
		$this->repository = new FLAC_Repository();
		$this->repository->delete_all();

		$this->listener = new FLAC_Listener( $this->repository );
		$this->listener->mark_login_page();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.5';
		$_POST                     = array();
	}

	/**
	 * Restores the request state.
	 */
	public function tear_down() {
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REMOTE_ADDR'] );
		$_POST = array();
		FLAC_Plugin::instance()->listener->set_is_login_page( false );
		parent::tear_down();
	}

	public function test_failed_login_is_recorded() {
		$id = $this->listener->record_failed( 'admin', new WP_Error( 'incorrect_password' ) );

		$this->assertIsInt( $id );
		$row = $this->repository->find()[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( 'admin', $row->username );
		$this->assertSame( '203.0.113.5', $row->ip_address );
	}

	public function test_successful_login_is_recorded() {
		$user = self::factory()->user->create_and_get();
		$id   = $this->listener->record_success( $user->user_login, $user );

		$this->assertIsInt( $id );
		$row = $this->repository->find()[0];
		$this->assertSame( 'success', $row->status );
		$this->assertSame( $user->user_login, $row->username );
	}

	public function test_nothing_is_recorded_outside_wp_login_php() {
		$this->listener->set_is_login_page( false );

		$this->assertFalse( $this->listener->record_failed( 'admin' ) );
		$this->assertFalse( $this->listener->record_success( 'admin' ) );
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_nothing_is_recorded_for_get_requests() {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertFalse( $this->listener->record_failed( 'admin' ) );
		$this->assertFalse( $this->listener->record_success( 'admin' ) );
		$this->assertFalse( $this->listener->record_empty_credentials() );
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_post_with_empty_password_is_recorded_as_failed() {
		$_POST = array( 'log' => 'admin', 'pwd' => '', 'wp-submit' => 'Log In' );

		$this->assertIsInt( $this->listener->record_empty_credentials() );
		$row = $this->repository->find()[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( 'admin', $row->username );
	}

	public function test_post_with_whitespace_password_is_recorded_as_failed() {
		$_POST = array( 'log' => 'admin', 'pwd' => '   ' );

		$this->assertIsInt( $this->listener->record_empty_credentials() );
		$this->assertSame( 1, $this->repository->count( FLAC_Repository::STATUS_FAILED ) );
	}

	public function test_post_with_empty_username_is_recorded_as_failed() {
		$_POST = array( 'log' => '', 'pwd' => 'secret' );

		$this->assertIsInt( $this->listener->record_empty_credentials() );
		$row = $this->repository->find()[0];
		$this->assertSame( 'failed', $row->status );
		$this->assertSame( '', $row->username );
	}

	public function test_post_with_complete_credentials_is_left_to_core() {
		$_POST = array( 'log' => 'admin', 'pwd' => 'secret' );

		$this->assertFalse( $this->listener->record_empty_credentials() );
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_post_without_login_fields_is_ignored() {
		$_POST = array( 'something' => 'else' );

		$this->assertFalse( $this->listener->record_empty_credentials() );
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_invalid_remote_addr_is_stored_as_empty() {
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';

		$this->listener->record_failed( 'admin' );
		$this->assertSame( '', $this->repository->find()[0]->ip_address );
	}

	public function test_ipv6_remote_addr_is_stored() {
		$_SERVER['REMOTE_ADDR'] = '2001:db8::1';

		$this->listener->record_failed( 'admin' );
		$this->assertSame( '2001:db8::1', $this->repository->find()[0]->ip_address );
	}

	public function test_client_ip_filter_overrides_remote_addr() {
		add_filter(
			'flac_client_ip',
			function () {
				return '198.51.100.7';
			}
		);

		$this->listener->record_failed( 'admin' );
		$this->assertSame( '198.51.100.7', $this->repository->find()[0]->ip_address );
	}

	public function test_should_record_filter_can_allow_other_entry_points() {
		$this->listener->set_is_login_page( false );
		add_filter( 'flac_should_record_attempt', '__return_true' );

		$this->assertIsInt( $this->listener->record_failed( 'admin' ) );
	}

	public function test_should_record_filter_can_block() {
		add_filter( 'flac_should_record_attempt', '__return_false' );

		$this->assertFalse( $this->listener->record_failed( 'admin' ) );
		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_listener_is_registered_on_core_hooks() {
		$listener = FLAC_Plugin::instance()->listener;

		$this->assertSame( 10, has_action( 'login_init', array( $listener, 'mark_login_page' ) ) );
		$this->assertSame( 10, has_action( 'login_form_login', array( $listener, 'record_empty_credentials' ) ) );
		$this->assertSame( 10, has_action( 'wp_login_failed', array( $listener, 'record_failed' ) ) );
		$this->assertSame( 10, has_action( 'wp_login', array( $listener, 'record_success' ) ) );
	}

	public function test_registered_listener_reacts_to_core_actions() {
		$user = self::factory()->user->create_and_get();
		// login_init itself is not fired here: core sends HTTP headers on it.
		FLAC_Plugin::instance()->listener->mark_login_page();

		do_action( 'wp_login_failed', 'admin', new WP_Error( 'incorrect_password' ) );
		do_action( 'wp_login', $user->user_login, $user );

		$this->assertSame( array( 'success' => 1, 'failed' => 1 ), $this->repository->counts() );
	}

	public function test_registered_listener_ignores_actions_outside_wp_login_php() {
		$user = self::factory()->user->create_and_get();

		do_action( 'wp_login_failed', 'admin', new WP_Error( 'incorrect_password' ) );
		do_action( 'wp_login', $user->user_login, $user );

		$this->assertSame( 0, $this->repository->count() );
	}

	public function test_real_wp_signon_flow_on_wp_login_php() {
		add_filter( 'send_auth_cookies', '__return_false' );
		$user = self::factory()->user->create_and_get( array( 'user_pass' => 'correct horse' ) );
		FLAC_Plugin::instance()->listener->mark_login_page();

		// Wrong password: core fires wp_login_failed.
		$_POST = array( 'log' => $user->user_login, 'pwd' => 'wrong' );
		do_action( 'login_form_login' );
		$result = wp_signon();
		$this->assertWPError( $result );

		// Empty password: core fires nothing, the listener records on login_form_login.
		$_POST = array( 'log' => $user->user_login, 'pwd' => '' );
		do_action( 'login_form_login' );
		$result = wp_signon();
		$this->assertWPError( $result );
		$this->assertSame( 'empty_password', $result->get_error_code() );

		// Correct password: core fires wp_login.
		$_POST = array( 'log' => $user->user_login, 'pwd' => 'correct horse' );
		do_action( 'login_form_login' );
		$result = wp_signon();
		$this->assertInstanceOf( 'WP_User', $result );

		$this->assertSame( array( 'success' => 1, 'failed' => 2 ), $this->repository->counts() );

		$rows = $this->repository->find( array( 'orderby' => 'id', 'order' => 'ASC' ) );
		$this->assertSame( array( 'failed', 'failed', 'success' ), wp_list_pluck( $rows, 'status' ) );
		$this->assertSame( $user->user_login, $rows[2]->username );
	}
}
