<?php
/**
 * Scan history list table.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Reporting\Score;
use ProbeSiteDoctor\Storage\ScanRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists stored scans.
 */
final class ScanListTable extends \WP_List_Table {

	private ScanRepository $scans;

	/**
	 * Constructor.
	 *
	 * @param ScanRepository $scans Scans.
	 */
	public function __construct( ScanRepository $scans ) {
		$this->scans = $scans;
		parent::__construct(
			array(
				'singular' => 'scan',
				'plural'   => 'scans',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$columns = array(
			'started_at' => __( 'Date', 'probe-site-doctor' ),
			'score'      => __( 'Score', 'probe-site-doctor' ),
			'findings'   => __( 'Findings', 'probe-site-doctor' ),
			'status'     => __( 'Status', 'probe-site-doctor' ),
			'user'       => __( 'Run by', 'probe-site-doctor' ),
			'duration'   => __( 'Duration', 'probe-site-doctor' ),
		);
		if ( current_user_can( Capabilities::RUN_SCANS ) ) {
			$columns = array( 'cb' => '<input type="checkbox" />' ) + $columns;
		}
		return $columns;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return current_user_can( Capabilities::RUN_SCANS ) ? array( 'delete' => __( 'Delete', 'probe-site-doctor' ) ) : array();
	}

	/**
	 * Loads items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'probesd_scans_per_page', 20 );
		$total    = $this->scans->count();

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'started_at' );
		$this->items           = $this->scans->paginate( $per_page, $this->get_pagenum() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No scans yet.', 'probe-site-doctor' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="probesd-scan-%1$d">%2$s</label><input type="checkbox" id="probesd-scan-%1$d" name="scan[]" value="%1$d" />',
			(int) $item['id'],
			/* translators: %d: Scan ID. */
			esc_html( sprintf( __( 'Select scan #%d', 'probe-site-doctor' ), (int) $item['id'] ) )
		);
	}

	/**
	 * Date column with row actions.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_started_at( $item ) {
		$label = esc_html( View::datetime( $item['started_at'] ) );
		$id    = (int) $item['id'];

		$actions = array();
		if ( ScanRepository::STATUS_COMPLETED === $item['status'] ) {
			$url     = add_query_arg(
				array(
					'page' => Admin::SLUG_DASHBOARD,
					'scan' => $id,
				),
				admin_url( 'admin.php' )
			);
			$label             = sprintf( '<strong><a href="%s">%s</a></strong>', esc_url( $url ), $label );
			$actions['view']   = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'View report', 'probe-site-doctor' ) );
			$actions['report'] = sprintf( '<a href="%s">%s</a>', esc_url( ReportPage::url( $id ) ), esc_html__( 'Printable report', 'probe-site-doctor' ) );
			$actions['export'] = sprintf( '<a href="%s">%s</a>', esc_url( ReportPage::download_url( $id ) ), esc_html__( 'Download', 'probe-site-doctor' ) );
		} else {
			$label = '<strong>' . $label . '</strong>';
		}

		if ( current_user_can( Capabilities::RUN_SCANS ) && ScanRepository::STATUS_RUNNING !== $item['status'] ) {
			$delete_url        = wp_nonce_url(
				add_query_arg(
					array(
						'action' => Actions::DELETE,
						'scan'   => $id,
					),
					admin_url( 'admin-post.php' )
				),
				Actions::DELETE . '_' . $id
			);
			$actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return window.confirm(this.dataset.confirm);" data-confirm="%s">%s</a>',
				esc_url( $delete_url ),
				esc_attr__( 'Delete this report permanently?', 'probe-site-doctor' ),
				esc_html__( 'Delete', 'probe-site-doctor' )
			);
		}

		return $label . $this->row_actions( $actions );
	}

	/**
	 * Score column.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_score( $item ) {
		if ( null === $item['score'] ) {
			return '—';
		}
		$band = Score::band( $item['score'] );
		return sprintf(
			'<span class="probesd-pill probesd-band--%1$s">%2$s</span> <span class="screen-reader-text">%3$s</span>',
			esc_attr( $band ),
			esc_html( number_format_i18n( $item['score'] ) ),
			esc_html( Score::band_label( $band ) )
		);
	}

	/**
	 * Severity counts column.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_findings( $item ) {
		if ( ScanRepository::STATUS_COMPLETED !== $item['status'] ) {
			return '—';
		}
		$parts = array();
		foreach ( Severity::all() as $severity ) {
			$parts[] = sprintf(
				'<span class="probesd-mini probesd-sev--%1$s"><span class="dashicons %2$s" aria-hidden="true"></span>%3$s <span class="screen-reader-text">%4$s</span></span>',
				esc_attr( $severity ),
				esc_attr( Severity::icon( $severity ) ),
				esc_html( number_format_i18n( (int) $item['counts'][ $severity ] ) ),
				esc_html( Severity::label( $severity ) )
			);
		}
		return implode( ' ', $parts );
	}

	/**
	 * Status column.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_status( $item ) {
		$labels = array(
			ScanRepository::STATUS_COMPLETED => __( 'Completed', 'probe-site-doctor' ),
			ScanRepository::STATUS_RUNNING   => __( 'Running', 'probe-site-doctor' ),
			ScanRepository::STATUS_ABANDONED => __( 'Interrupted', 'probe-site-doctor' ),
		);
		return esc_html( $labels[ $item['status'] ] ?? $item['status'] );
	}

	/**
	 * User column.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_user( $item ) {
		$user = $item['user_id'] ? get_userdata( (int) $item['user_id'] ) : false;
		return $user ? esc_html( $user->display_name ) : '—';
	}

	/**
	 * Duration column.
	 *
	 * @param array<string,mixed> $item Scan.
	 * @return string
	 */
	protected function column_duration( $item ) {
		return esc_html( View::duration( $item['started_at'], $item['finished_at'] ) );
	}
}
