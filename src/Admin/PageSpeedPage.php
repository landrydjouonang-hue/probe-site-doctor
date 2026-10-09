<?php
/**
 * Page speed screen.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Performance\PageAnalyzer;
use ProbeSiteDoctor\Performance\PageProfile;
use ProbeSiteDoctor\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Analyses one page at a time: what the server sends, what makes that page
 * slow, and — when field data collection is on — what visitors' browsers
 * actually measured for it.
 */
final class PageSpeedPage {

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

		$candidates = PageProfile::candidates();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only analysis of this site's own URL.
		$probesd_url = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( $_GET['url'] ) ) : '';
		$requested      = '' === $probesd_url ? '' : PageProfile::normalize( $probesd_url );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$analyse = isset( $_GET['analyze'] ) || '' !== $requested;

		$rejected = false;
		if ( isset( $_GET['url'] ) && '' === $requested ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$rejected = true;
			$analyse  = false;
		}

		$url      = '' !== $requested ? $requested : (string) ( $candidates[0]['url'] ?? home_url( '/' ) );
		$analysis = $analyse ? PageAnalyzer::analyze( $url ) : null;

		$settings = $this->plugin->settings();
		$days     = $settings->web_vitals_days();
		$enabled  = $settings->web_vitals_enabled();

		View::render(
			'admin/pagespeed',
			array(
				'candidates'     => $candidates,
				'url'            => $url,
				'analysis'       => $analysis,
				'rejected'       => $rejected,
				'field_enabled'  => $enabled,
				'field_days'     => $days,
				'field_page'     => $enabled ? $this->plugin->vitals()->summary( PageProfile::path( $url ), null, $days ) : array(),
				'field_site'     => $enabled ? $this->plugin->vitals()->summary( null, null, $days ) : array(),
				'field_mobile'   => $enabled ? $this->plugin->vitals()->summary( null, 'mobile', $days ) : array(),
				'field_desktop'  => $enabled ? $this->plugin->vitals()->summary( null, 'desktop', $days ) : array(),
				'field_paths'    => $enabled ? $this->plugin->vitals()->paths( 20, $days ) : array(),
				'field_total'    => $enabled ? $this->plugin->vitals()->total() : 0,
				'sampling'       => $settings->web_vitals_sampling(),
				'can_manage'     => current_user_can( 'manage_options' ),
				'page_url'       => admin_url( 'admin.php?page=' . Admin::SLUG_PAGESPEED ),
				'psi_url'        => 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $url ),
			)
		);
	}
}
