<?php
/**
 * Form handlers (admin-post.php).
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Developer\SystemReport;
use ProbeSiteDoctor\Diagnostics\ScanInProgressException;
use ProbeSiteDoctor\Plugin;
use ProbeSiteDoctor\Reporting\CheckLabels;
use ProbeSiteDoctor\Reporting\ReportDocument;
use ProbeSiteDoctor\Reporting\ReportExporter;
use ProbeSiteDoctor\Storage\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Non-JavaScript handlers for running and deleting scans.
 */
final class Actions {

	public const RUN         = 'probesd_run_scan';
	public const DELETE      = 'probesd_delete_scan';
	public const DOWNLOAD    = 'probesd_download_report';
	public const DIAGNOSTICS = 'probesd_download_diagnostics';
	public const SETTINGS    = 'probesd_save_settings';

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
	 * Hooks handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::RUN, array( $this, 'run_scan' ) );
		add_action( 'admin_post_' . self::DELETE, array( $this, 'delete_scan' ) );
		add_action( 'admin_post_' . self::DOWNLOAD, array( $this, 'download_report' ) );
		add_action( 'admin_post_' . self::DIAGNOSTICS, array( $this, 'download_diagnostics' ) );
		add_action( 'admin_post_' . self::SETTINGS, array( $this, 'save_settings' ) );
	}

	/**
	 * Runs a full scan synchronously, then shows its report.
	 *
	 * @return void
	 */
	public function run_scan(): void {
		if ( ! current_user_can( Capabilities::RUN_SCANS ) ) {
			wp_die( esc_html__( 'You are not allowed to run scans.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::RUN );

		// The scan can be started from the dashboard or from the report screen.
		$target = isset( $_POST['probesd_redirect'] ) && 'report' === sanitize_key( wp_unslash( $_POST['probesd_redirect'] ) )
			? Admin::SLUG_REPORT
			: Admin::SLUG_DASHBOARD;

		if ( function_exists( 'set_time_limit' ) ) {
			// A full scan in one request is the no-JavaScript fallback, so it needs the headroom.
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		try {
			$scan = $this->plugin->scanner()->run_all( 'fallback', get_current_user_id() );
		} catch ( ScanInProgressException $e ) {
			$this->redirect( $target, array( 'probesd_notice' => 'in_progress' ) );
		} catch ( \Throwable $e ) {
			$this->redirect( $target, array( 'probesd_notice' => 'scan_failed' ) );
		}

		$this->redirect(
			$target,
			array(
				'scan'              => $scan['id'],
				'probesd_notice' => 'scan_done',
			)
		);
	}

	/**
	 * Sends one report as a self-contained HTML file.
	 *
	 * Read-only: the report is rendered from the stored scan, nothing is written
	 * and no file is left on the server.
	 *
	 * @return void
	 */
	public function download_report(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You are not allowed to download reports.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;
		check_admin_referer( self::DOWNLOAD . '_' . $id );

		$scan = $id ? $this->plugin->scans()->find( $id ) : $this->plugin->scans()->latest_completed();
		if ( ! $scan || ScanRepository::STATUS_COMPLETED !== $scan['status'] ) {
			$this->redirect( Admin::SLUG_REPORT, array( 'probesd_notice' => 'not_found' ) );
		}

		$document = ReportDocument::build( $this->plugin->reports()->build( $scan ) );
		$html     = ReportExporter::render( $document, CheckLabels::map( $this->plugin->checks() ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . ReportExporter::filename( $document ) . '"' );
		header( 'Content-Length: ' . strlen( $html ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Complete document, escaped while rendering.
		exit;
	}

	/**
	 * Sends the developer diagnostic report as a text or JSON file.
	 *
	 * @return void
	 */
	public function download_diagnostics(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You are not allowed to download diagnostics.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::DIAGNOSTICS );

		$format = isset( $_GET['format'] ) && 'json' === sanitize_key( wp_unslash( $_GET['format'] ) ) ? 'json' : 'md';
		$scan   = $this->plugin->scans()->latest_completed();
		$report = $scan ? $this->plugin->reports()->build( $scan ) : null;

		$body = 'json' === $format ? SystemReport::json( $report ) : SystemReport::markdown( $report );

		nocache_headers();
		header( 'Content-Type: ' . ( 'json' === $format ? 'application/json' : 'text/markdown' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . SystemReport::filename( $format ) . '"' );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow', true );

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text/JSON document.
		exit;
	}

	/**
	 * Saves the plugin settings from the Page Speed screen.
	 *
	 * Changing them affects the public site (the field-data collector adds a
	 * script), so this needs manage_options rather than a report capability.
	 *
	 * @return void
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SETTINGS );

		$enabled = isset( $_POST['web_vitals'] );

		$this->plugin->settings()->save(
			array(
				'web_vitals'          => $enabled,
				'web_vitals_sampling' => isset( $_POST['web_vitals_sampling'] ) ? absint( wp_unslash( $_POST['web_vitals_sampling'] ) ) : 100,
				'web_vitals_days'     => isset( $_POST['web_vitals_days'] ) ? absint( wp_unslash( $_POST['web_vitals_days'] ) ) : 30,
			)
		);

		// Deleting the collected samples is a separate, explicit choice.
		$notice = 'settings_saved';
		if ( isset( $_POST['clear_field_data'] ) ) {
			$this->plugin->vitals()->delete_all();
			$notice = 'field_data_cleared';
		}

		$this->redirect( Admin::SLUG_PAGESPEED, array( 'probesd_notice' => $notice ) );
	}

	/**
	 * Deletes one scan.
	 *
	 * @return void
	 */
	public function delete_scan(): void {
		if ( ! current_user_can( Capabilities::RUN_SCANS ) ) {
			wp_die( esc_html__( 'You are not allowed to delete reports.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}

		$id = isset( $_GET['scan'] ) ? absint( wp_unslash( $_GET['scan'] ) ) : 0;
		check_admin_referer( self::DELETE . '_' . $id );

		$notice = $id && $this->plugin->scans()->find( $id ) && $this->plugin->scanner()->delete( $id ) ? 'deleted' : 'not_found';

		$this->redirect( Admin::SLUG_HISTORY, array( 'probesd_notice' => $notice ) );
	}

	/**
	 * Redirects to a plugin page and exits.
	 *
	 * @param string              $page Page slug.
	 * @param array<string,mixed> $args Query args.
	 * @return void Never returns.
	 */
	private function redirect( string $page, array $args ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
