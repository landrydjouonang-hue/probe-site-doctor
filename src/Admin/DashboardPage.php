<?php
/**
 * Dashboard screen.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Plugin;
use ProbeSiteDoctor\Reporting\CheckLabels;
use ProbeSiteDoctor\Storage\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the latest (or a selected) report and the scan launcher.
 */
final class DashboardPage {

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

		// Only completed scans have a meaningful report. Anything else falls back to
		// the latest report, as the notice on screen says.
		$not_found = $requested && ( ! $scan || ScanRepository::STATUS_COMPLETED !== $scan['status'] );

		$latest = $this->plugin->scans()->latest_completed();
		if ( ! $scan || ScanRepository::STATUS_COMPLETED !== $scan['status'] ) {
			$scan = $latest;
		}

		$checks_by_category = array();
		foreach ( $this->plugin->checks()->all() as $check ) {
			$checks_by_category[ $check->get_category() ][] = $check;
		}

		View::render(
			'admin/dashboard',
			array(
				'report'             => $scan ? $this->plugin->reports()->build( $scan ) : null,
				'is_latest'          => $scan && $latest && $latest['id'] === $scan['id'],
				'latest_id'          => $latest ? $latest['id'] : 0,
				'not_found'          => $not_found,
				'can_run'            => current_user_can( Capabilities::RUN_SCANS ),
				'categories'         => $this->plugin->categories()->all(),
				'checks_by_category' => $checks_by_category,
				'check_labels'       => CheckLabels::map( $this->plugin->checks() ),
				'check_count'        => count( $this->plugin->checks()->applicable() ),
				'history_url'        => admin_url( 'admin.php?page=' . Admin::SLUG_HISTORY ),
				'dashboard_url'      => admin_url( 'admin.php?page=' . Admin::SLUG_DASHBOARD ),
				'report_url'         => $scan ? ReportPage::url( (int) $scan['id'] ) : admin_url( 'admin.php?page=' . Admin::SLUG_REPORT ),
				'pagespeed_url'      => admin_url( 'admin.php?page=' . Admin::SLUG_PAGESPEED ),
				'developer_url'      => admin_url( 'admin.php?page=' . Admin::SLUG_DEVELOPER ),
			)
		);
	}
}
