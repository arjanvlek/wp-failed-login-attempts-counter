<?php
/**
 * Database access for login attempts and counters.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and reads login attempts in a log table, and keeps running
 * counters in a second table so that clearing the log does not reset
 * the totals.
 */
class FLAC_Repository {

	const STATUS_SUCCESS = 'success';
	const STATUS_FAILED  = 'failed';

	const COUNTER_SUCCESS         = 'success';
	const COUNTER_FAILED          = 'failed';
	const COUNTER_CLEARED_SUCCESS = 'cleared_success';
	const COUNTER_CLEARED_FAILED  = 'cleared_failed';

	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'flac_db_version';

	/**
	 * Returns the valid status values.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::STATUS_SUCCESS, self::STATUS_FAILED );
	}

	/**
	 * Returns the counter names.
	 *
	 * @return string[]
	 */
	public static function counter_names() {
		return array(
			self::COUNTER_SUCCESS,
			self::COUNTER_FAILED,
			self::COUNTER_CLEARED_SUCCESS,
			self::COUNTER_CLEARED_FAILED,
		);
	}

	/**
	 * Returns the prefixed log table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'flac_login_attempts';
	}

	/**
	 * Returns the prefixed counters table name.
	 *
	 * @return string
	 */
	public static function counters_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'flac_counters';
	}

	/**
	 * Creates or updates the tables with dbDelta() and seeds the counters.
	 */
	public static function install() {
		global $wpdb;

		$log             = self::table_name();
		$counters        = self::counters_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = array(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				attempted_at datetime NOT NULL,
				ip_address varchar(45) NOT NULL DEFAULT '',
				username varchar(255) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY attempted_at (attempted_at)
			) {$charset_collate};",
			"CREATE TABLE {$counters} (
				name varchar(32) NOT NULL,
				value bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (name)
			) {$charset_collate};",
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::seed_counters();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Inserts missing counters. Existing values are left alone.
	 *
	 * The success and failed counters start at the number of rows in the
	 * log, so an upgrade from a version without counters keeps its totals.
	 */
	public static function seed_counters() {
		global $wpdb;

		$repository = new self();
		$seed       = array(
			self::COUNTER_SUCCESS         => $repository->count( self::STATUS_SUCCESS ),
			self::COUNTER_FAILED          => $repository->count( self::STATUS_FAILED ),
			self::COUNTER_CLEARED_SUCCESS => 0,
			self::COUNTER_CLEARED_FAILED  => 0,
		);

		$table = self::counters_table_name();
		foreach ( $seed as $name => $value ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (name, value) VALUES (%s, %d)", $name, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Drops the tables and removes the version option.
	 */
	public static function uninstall() {
		global $wpdb;
		$log      = self::table_name();
		$counters = self::counters_table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$log}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$counters}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Stores one login attempt and increments its counter.
	 *
	 * @param string      $status       One of the STATUS_* constants.
	 * @param string      $ip_address   Client IP address, may be empty.
	 * @param string      $username     Username that was attempted, may be empty.
	 * @param string|null $attempted_at MySQL datetime in UTC. Defaults to now.
	 * @return int|false Row id, or false when nothing was stored.
	 */
	public function record( $status, $ip_address, $username = '', $attempted_at = null ) {
		global $wpdb;

		if ( ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}

		$data = array(
			'attempted_at' => $attempted_at ? $attempted_at : current_time( 'mysql', true ),
			'ip_address'   => substr( (string) $ip_address, 0, 45 ),
			'username'     => mb_substr( sanitize_text_field( (string) $username ), 0, 255 ),
			'status'       => $status,
		);

		$inserted = $wpdb->insert( self::table_name(), $data, array( '%s', '%s', '%s', '%s' ) );
		if ( ! $inserted ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;

		$this->add_to_counter( $status, 1 );

		/**
		 * Fires after a login attempt has been stored.
		 *
		 * @param int   $id   Row id.
		 * @param array $data Stored columns.
		 */
		do_action( 'flac_attempt_recorded', $id, $data );

		return $id;
	}

	/**
	 * Counts rows in the log.
	 *
	 * @param string|null $status Restrict to one status, or null for all.
	 * @param string|null $since  MySQL datetime in UTC. Only count attempts at or after this moment.
	 * @return int
	 */
	public function count( $status = null, $since = null ) {
		global $wpdb;

		$table  = self::table_name();
		$where  = array();
		$params = array();

		if ( $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( $since ) {
			$where[]  = 'attempted_at >= %s';
			$params[] = $since;
		}

		$sql = "SELECT COUNT(*) FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
			$sql  = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Returns the number of successful and failed rows in the log.
	 *
	 * @param string|null $since MySQL datetime in UTC, see count().
	 * @return array{success:int,failed:int}
	 */
	public function counts( $since = null ) {
		return array(
			self::STATUS_SUCCESS => $this->count( self::STATUS_SUCCESS, $since ),
			self::STATUS_FAILED  => $this->count( self::STATUS_FAILED, $since ),
		);
	}

	/**
	 * Returns the running counters.
	 *
	 * The success and failed counters include attempts that were cleared
	 * from the log. The cleared_* counters hold how many rows were cleared.
	 *
	 * @return array{success:int,failed:int,cleared_success:int,cleared_failed:int}
	 */
	public function get_counters() {
		global $wpdb;

		$table = self::counters_table_name();
		$rows  = $wpdb->get_results( "SELECT name, value FROM {$table}", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counters = array();
		foreach ( self::counter_names() as $name ) {
			$counters[ $name ] = isset( $rows[ $name ] ) ? (int) $rows[ $name ]->value : 0;
		}

		return $counters;
	}

	/**
	 * Adds an amount to a counter atomically.
	 *
	 * @param string $name   Counter name.
	 * @param int    $amount Amount to add.
	 */
	public function add_to_counter( $name, $amount ) {
		global $wpdb;

		if ( ! in_array( $name, self::counter_names(), true ) ) {
			return;
		}

		$table = self::counters_table_name();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (name, value) VALUES (%s, %d) ON DUPLICATE KEY UPDATE value = value + %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$name,
				$amount,
				$amount
			)
		);
	}

	/**
	 * Removes rows from the log and adds them to the cleared counters.
	 *
	 * @param int|null $older_than_days Only remove rows older than this many days, or null for all rows.
	 * @return array{success:int,failed:int} Number of removed rows per status.
	 */
	public function clear_log( $older_than_days = null ) {
		global $wpdb;

		$table   = self::table_name();
		$deleted = array();

		foreach ( self::statuses() as $status ) {
			if ( null === $older_than_days ) {
				$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE status = %s", $status ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$before = gmdate( 'Y-m-d H:i:s', time() - max( 0, (int) $older_than_days ) * DAY_IN_SECONDS );
				$sql    = $wpdb->prepare( "DELETE FROM {$table} WHERE status = %s AND attempted_at < %s", $status, $before ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$deleted[ $status ] = (int) $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( $deleted[ $status ] > 0 ) {
				$this->add_to_counter( 'cleared_' . $status, $deleted[ $status ] );
			}
		}

		/**
		 * Fires after rows were cleared from the log.
		 *
		 * @param array    $deleted         Removed rows per status.
		 * @param int|null $older_than_days Age limit that was used, or null for all rows.
		 */
		do_action( 'flac_log_cleared', $deleted, $older_than_days );

		return $deleted;
	}

	/**
	 * Sets the counters back to the rows that are in the log and sets the
	 * cleared counters to zero.
	 *
	 * @return array{success:int,failed:int,cleared_success:int,cleared_failed:int} The new counters.
	 */
	public function reset_counters() {
		global $wpdb;

		$values = array(
			self::COUNTER_SUCCESS         => $this->count( self::STATUS_SUCCESS ),
			self::COUNTER_FAILED          => $this->count( self::STATUS_FAILED ),
			self::COUNTER_CLEARED_SUCCESS => 0,
			self::COUNTER_CLEARED_FAILED  => 0,
		);

		$table = self::counters_table_name();
		foreach ( $values as $name => $value ) {
			$wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (name, value) VALUES (%s, %d)", $name, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return $values;
	}

	/**
	 * Returns a page of attempts.
	 *
	 * @param array $args {
	 *     @type string|null $status   Restrict to one status.
	 *     @type int         $per_page Rows per page. Default 50.
	 *     @type int         $page     1-based page number. Default 1.
	 *     @type string      $orderby  Column name. Default 'attempted_at'.
	 *     @type string      $order    'ASC' or 'DESC'. Default 'DESC'.
	 * }
	 * @return object[] Rows with id, attempted_at, ip_address, username and status.
	 */
	public function find( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => null,
				'per_page' => 50,
				'page'     => 1,
				'orderby'  => 'attempted_at',
				'order'    => 'DESC',
			)
		);

		$allowed_orderby = array( 'id', 'attempted_at', 'ip_address', 'username', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'attempted_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page        = max( 1, (int) $args['per_page'] );
		$offset          = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$table  = self::table_name();
		$where  = '';
		$params = array();

		if ( $args['status'] ) {
			$where    = 'WHERE status = %s';
			$params[] = $args['status'];
		}

		$params[] = $per_page;
		$params[] = $offset;

		$sql = "SELECT id, attempted_at, ip_address, username, status FROM {$table} {$where} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";

		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Deletes every row from the log without touching the counters.
	 *
	 * Use clear_log() for user-facing clearing.
	 *
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function delete_all() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
