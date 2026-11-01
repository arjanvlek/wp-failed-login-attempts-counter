<?php
/**
 * List table for the detail page.
 *
 * @package FailedLoginAttemptsCounter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Table with one row per login attempt.
 */
class FLAC_List_Table extends WP_List_Table {

	const DEFAULT_PER_PAGE = 50;
	const PER_PAGE_OPTION  = 'flac_attempts_per_page';

	/**
	 * Storage.
	 *
	 * @var FLAC_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param FLAC_Repository $repository Storage.
	 * @param array           $args       Arguments for WP_List_Table.
	 */
	public function __construct( FLAC_Repository $repository, array $args = array() ) {
		$this->repository = $repository;
		parent::__construct(
			wp_parse_args(
				$args,
				array(
					'singular' => 'login-attempt',
					'plural'   => 'login-attempts',
					'ajax'     => false,
				)
			)
		);
	}

	/**
	 * Returns the status filter from the query string.
	 *
	 * @return string|null 'success', 'failed' or null for all.
	 */
	public function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return in_array( $status, FLAC_Repository::statuses(), true ) ? $status : null;
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'attempted_at' => __( 'Date / time', 'failed-login-attempts-counter' ),
			'ip_address'   => __( 'IP address', 'failed-login-attempts-counter' ),
			'username'     => __( 'Username', 'failed-login-attempts-counter' ),
			'status'       => __( 'Status', 'failed-login-attempts-counter' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'attempted_at' => array( 'attempted_at', true ),
			'ip_address'   => array( 'ip_address', false ),
			'username'     => array( 'username', false ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Status filter links above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = $this->current_status();
		$counts  = $this->repository->counts();
		$all     = $counts['success'] + $counts['failed'];

		$views = array(
			'all' => $this->view_link( null, __( 'All', 'failed-login-attempts-counter' ), $all, null === $current ),
		);
		foreach ( FLAC_Repository::statuses() as $status ) {
			$label            = FLAC_Repository::STATUS_SUCCESS === $status
				? __( 'Successful', 'failed-login-attempts-counter' )
				: __( 'Failed', 'failed-login-attempts-counter' );
			$views[ $status ] = $this->view_link( $status, $label, $counts[ $status ], $status === $current );
		}

		$counters = $this->repository->get_counters();
		$cleared  = $counters['cleared_success'] + $counters['cleared_failed'];
		if ( $cleared > 0 ) {
			$views['cleared'] = sprintf(
				'<span class="flac-view-cleared">%s <span class="count">(%s)</span></span>',
				esc_html__( 'Cleared', 'failed-login-attempts-counter' ),
				esc_html( number_format_i18n( $cleared ) )
			);
		}

		return $views;
	}

	/**
	 * Builds one view link.
	 *
	 * @param string|null $status  Status or null for all.
	 * @param string      $label   Label.
	 * @param int         $count   Count.
	 * @param bool        $current Whether this view is active.
	 * @return string
	 */
	private function view_link( $status, $label, $count, $current ) {
		$url = FLAC_Admin_Page::url( $status ? array( 'status' => $status ) : array() );
		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
			esc_url( $url ),
			$current ? ' class="current" aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Loads the rows for the current page.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, self::DEFAULT_PER_PAGE );
		$status   = $this->current_status();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'attempted_at';
		$order   = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! array_key_exists( $orderby, $this->get_sortable_columns() ) ) {
			$orderby = 'attempted_at';
		}

		$total = $this->repository->count( $status );

		$this->items = $this->repository->find(
			array(
				'status'   => $status,
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
				'orderby'  => $orderby,
				'order'    => $order,
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'attempted_at' );
	}

	/**
	 * Date / time column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_attempted_at( $item ) {
		// The badge is repeated here for small screens, where only the primary column is visible when a row is collapsed.
		return self::format_time_html( $item->attempted_at )
			. '<span class="flac-mobile-status">' . self::status_badge( $item->status ) . '</span>';
	}

	/**
	 * IP address column: links to AbuseIPDB.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_ip_address( $item ) {
		if ( '' === $item->ip_address ) {
			return '<span class="flac-empty">&mdash;</span>';
		}
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::abuseipdb_url( $item->ip_address ) ),
			esc_html( $item->ip_address )
		);
	}

	/**
	 * Username column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_username( $item ) {
		if ( '' === $item->username ) {
			return '<span class="flac-empty">' . esc_html__( '(none)', 'failed-login-attempts-counter' ) . '</span>';
		}
		return '<code>' . esc_html( $item->username ) . '</code>';
	}

	/**
	 * Status column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_status( $item ) {
		return self::status_badge( $item->status );
	}

	/**
	 * Returns the status badge HTML.
	 *
	 * @param string $status 'success' or 'failed'.
	 * @return string
	 */
	public static function status_badge( $status ) {
		$label = FLAC_Repository::STATUS_SUCCESS === $status
			? __( 'Success', 'failed-login-attempts-counter' )
			: __( 'Failed', 'failed-login-attempts-counter' );
		return sprintf(
			'<span class="flac-status flac-status--%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Fallback for columns without a dedicated method.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Message for an empty table.
	 */
	public function no_items() {
		esc_html_e( 'No login attempts recorded yet.', 'failed-login-attempts-counter' );
	}

	/**
	 * Returns the AbuseIPDB lookup URL for an IP address.
	 *
	 * @param string $ip_address IP address.
	 * @return string
	 */
	public static function abuseipdb_url( $ip_address ) {
		return 'https://www.abuseipdb.com/check/' . rawurlencode( $ip_address );
	}

	/**
	 * Renders a UTC MySQL datetime as a <time> element.
	 *
	 * The text content is the site time zone fallback. The admin script
	 * replaces it with the browser time zone.
	 *
	 * @param string $mysql_utc Datetime in 'Y-m-d H:i:s' format, UTC.
	 * @return string
	 */
	public static function format_time_html( $mysql_utc ) {
		$date = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) $mysql_utc, new DateTimeZone( 'UTC' ) );
		if ( ! $date ) {
			return esc_html( (string) $mysql_utc );
		}

		$timestamp = $date->getTimestamp();
		$iso       = gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$fallback  = wp_date( $format, $timestamp );

		return sprintf(
			'<time class="flac-time" datetime="%1$s" title="%1$s">%2$s</time>',
			esc_attr( $iso ),
			esc_html( $fallback )
		);
	}
}
