<?php
/**
 * Website Health Report screen.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Plugin;
use ProbeSiteDoctor\Reporting\CheckLabels;
use ProbeSiteDoctor\Reporting\ReportDocument;
use ProbeSiteDoctor\Storage\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the printable Website Health Report for a stored scan.
 *
 * Reports are generated on demand from a scan that has already run: opening the
 * page builds the document from the latest completed scan, and administrators
 * can pick any other stored scan, print it, or download it as one HTML file.
 */
final class ReportPage {

	/**
	 * How many stored scans the picker offers.
	 */
	private const PICKER_LIMIT = 25;

	private Plugin $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'probe-site-doctor' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$requested = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;

		$scan = $requested ? $this->plugin->scans()->find( $requested ) : null;

		// A report only makes sense for a finished scan; anything else falls back
		// to the most recent one, which is what the notice tells the reader.
		$not_found = $requested && ( ! $scan || ScanRepository::STATUS_COMPLETED !== $scan['status'] );

		$latest = $this->plugin->scans()->latest_completed();
		if ( ! $scan || ScanRepository::STATUS_COMPLETED !== $scan['status'] ) {
			$scan = $latest;
		}

		$document = $scan ? ReportDocument::build( $this->plugin->reports()->build( $scan ) ) : null;

		View::render(
			'admin/report',
			array(
				'document'      => $document,
				'check_labels'  => CheckLabels::map( $this->plugin->checks() ),
				'not_found'     => $not_found,
				'can_run'       => current_user_can( Capabilities::RUN_SCANS ),
				'check_count'   => count( $this->plugin->checks()->applicable() ),
				'scans'         => $this->plugin->scans()->paginate( self::PICKER_LIMIT, 1, ScanRepository::STATUS_COMPLETED ),
				'selected_id'   => $scan ? (int) $scan['id'] : 0,
				'is_latest'     => $scan && $latest && $latest['id'] === $scan['id'],
				'report_url'    => admin_url( 'admin.php?page=' . Admin::SLUG_REPORT ),
				'dashboard_url' => admin_url( 'admin.php?page=' . Admin::SLUG_DASHBOARD ),
				'history_url'   => admin_url( 'admin.php?page=' . Admin::SLUG_HISTORY ),
				'download_url'  => $scan ? self::download_url( (int) $scan['id'] ) : '',
			)
		);
	}

	/**
	 * Nonced download URL for a scan's report.
	 *
	 * @param int $scan_id Scan ID.
	 * @return string
	 */
	public static function download_url( int $scan_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => Actions::DOWNLOAD,
					'scan'   => $scan_id,
				),
				admin_url( 'admin-post.php' )
			),
			Actions::DOWNLOAD . '_' . $scan_id
		);
	}

	/**
	 * Report screen URL for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return string
	 */
	public static function url( int $scan_id ): string {
		return admin_url(
			'admin.php?' . http_build_query(
				array(
					'page' => Admin::SLUG_REPORT,
					'scan' => $scan_id,
				)
			)
		);
	}
}
