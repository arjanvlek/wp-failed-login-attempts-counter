<?php
/**
 * Database access for login attempts.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and reads login attempts in a custom table.
 */
class FLAC_Repository {

	const STATUS_SUCCESS = 'success';
	const STATUS_FAILED  = 'failed';

	const DB_VERSION        = '1';
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
	 * Returns the prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'flac_login_attempts';
	}

	/**
	 * Creates or updates the table with dbDelta().
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attempted_at datetime NOT NULL,
			ip_address varchar(45) NOT NULL DEFAULT '',
			username varchar(255) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY attempted_at (attempted_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drops the table and removes the version option.
	 */
	public static function uninstall() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( self::DB_VERSION_OPTION );
	}

	/**
	 * Stores one login attempt.
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
	 * Counts attempts.
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
	 * Returns the number of successful and failed attempts.
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
	 * Deletes every stored attempt.
	 *
	 * @return int|false Number of deleted rows, or false on error.
	 */
	public function delete_all() {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
