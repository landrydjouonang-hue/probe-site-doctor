<?php
/**
 * Developer screen.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Developer\SystemReport;
use ProbeSiteDoctor\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the full system information, and offers it as an exportable
 * diagnostic report.
 */
final class DeveloperPage {

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

		$scan   = $this->plugin->scans()->latest_completed();
		$report = $scan ? $this->plugin->reports()->build( $scan ) : null;

		View::render(
			'admin/developer',
			array(
				'groups'        => SystemInfo::groups(),
				'markdown'      => SystemReport::markdown( $report ),
				'has_scan'      => (bool) $scan,
				'download_md'   => self::download_url( 'md' ),
				'download_json' => self::download_url( 'json' ),
				'dashboard_url' => admin_url( 'admin.php?page=' . Admin::SLUG_DASHBOARD ),
				'pagespeed_url' => admin_url( 'admin.php?page=' . Admin::SLUG_PAGESPEED ),
			)
		);
	}

	/**
	 * Nonced download URL for a format.
	 *
	 * @param string $format md|json.
	 * @return string
	 */
	public static function download_url( string $format ): string {
		$format = 'json' === $format ? 'json' : 'md';

		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => Actions::DIAGNOSTICS,
					'format' => $format,
				),
				admin_url( 'admin-post.php' )
			),
			Actions::DIAGNOSTICS
		);
	}
}
