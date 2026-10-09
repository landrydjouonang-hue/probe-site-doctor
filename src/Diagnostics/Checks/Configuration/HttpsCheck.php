<?php
/**
 * HTTPS configuration check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Configuration;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews the HTTPS setup: the site and home URLs, admin-over-HTTPS, whether
 * plain HTTP redirects to HTTPS, and whether HSTS is sent.
 *
 * It does not validate the certificate chain or test TLS versions and ciphers;
 * use a dedicated SSL testing service for that.
 */
final class HttpsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.https';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'HTTPS configuration', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks the site URLs, admin-over-HTTPS, the HTTP to HTTPS redirect and the HSTS header.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$home    = (string) get_option( 'home' );
		$site    = (string) get_option( 'siteurl' );
		$on_home = 0 === stripos( $home, 'https://' );
		$on_site = 0 === stripos( $site, 'https://' );
		$force   = defined( 'FORCE_SSL_ADMIN' ) && FORCE_SSL_ADMIN;

		$data = array(
			'home_url'            => $home,
			'site_url'            => $site,
			'https_urls'          => $on_home && $on_site,
			'force_ssl_admin'     => $force,
			'supported_by_server' => function_exists( 'wp_is_https_supported' ) ? (bool) wp_is_https_supported() : null,
			'environment_type'    => wp_get_environment_type(),
		);

		$scope = __( 'The certificate chain, TLS versions and ciphers are not tested here; use an SSL testing service for that.', 'probe-site-doctor' );
		$steps = ( new ManualCleanup(
			__( 'Move the site to HTTPS. Site Doctor never changes URLs or settings.', 'probe-site-doctor' ),
			array(
				__( 'Install a certificate (most hosts offer a free one) and confirm the site loads over https:// without warnings.', 'probe-site-doctor' ),
				__( 'Back up the site, then update the WordPress Address and Site Address under Settings → General.', 'probe-site-doctor' ),
				__( 'Replace http:// links and image URLs stored in content so no page loads mixed content.', 'probe-site-doctor' ),
				__( 'Redirect all HTTP traffic to HTTPS at the server level, and force the admin over HTTPS.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'Force the admin and logins over HTTPS (wp-config.php)', 'probe-site-doctor' ), "define( 'FORCE_SSL_ADMIN', true );", ManualCleanup::TYPE_PHP );

		if ( ! $on_home || ! $on_site ) {
			$severity = $this->is_non_production() ? Severity::INFO : Severity::CRITICAL;
			return $this->result(
				$severity,
				Severity::INFO === $severity ? __( 'The site does not use HTTPS (non-production environment)', 'probe-site-doctor' ) : __( 'The site does not use HTTPS', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Home URL, 2: Site URL. */
					__( 'The site address is %1$s and the WordPress address is %2$s. Without HTTPS, logins, passwords and form data travel unencrypted, browsers mark the site as not secure, and search engines prefer secure sites.', 'probe-site-doctor' ),
					$home,
					$site
				) . ' ' . $scope . ' ' . $this->indicator_note(),
				Severity::INFO === $severity
					? __( 'Use HTTPS on the live site. A plain-HTTP local environment is normal.', 'probe-site-doctor' )
					: __( 'Install a certificate with your host and move the site to HTTPS, then redirect HTTP to HTTPS.', 'probe-site-doctor' ),
				$data
			)->with_impact(
				Severity::CRITICAL === $severity ? Impact::HIGH : Impact::LOW,
				__( 'Traffic can be read or modified in transit, and administrator passwords and session cookies are exposed on untrusted networks.', 'probe-site-doctor' )
			)->with_cleanup( $steps );
		}

		// The site claims HTTPS: check that plain HTTP does not stay on HTTP, and look for HSTS.
		$http_url = 'http://' . preg_replace( '#^https://#i', '', untrailingslashit( $home ) ) . '/';
		$probe    = Loopback::get( $http_url, 8 );
		$secure   = Loopback::get( trailingslashit( $home ), 8 );

		$hsts     = '';
		$redirect = null;
		if ( $secure['ok'] ) {
			$hsts = (string) ( $secure['headers']['strict-transport-security'] ?? '' );
		}
		if ( $probe['ok'] || $probe['status'] >= 300 ) {
			// wp_remote_get follows redirects, so the final URL tells us where HTTP ended up.
			$redirect = 0 === stripos( (string) ( $probe['headers']['location'] ?? $probe['url'] ), 'https://' ) || $probe['status'] >= 300;
		}

		$data['http_redirects_to_https'] = $redirect;
		$data['hsts_header']             = '' !== $hsts ? $hsts : null;

		$issues = array();
		if ( ! $force ) {
			$issues[] = __( 'FORCE_SSL_ADMIN is not set, so the admin and login pages are not forced onto HTTPS.', 'probe-site-doctor' );
		}
		if ( '' === $hsts ) {
			$issues[] = __( 'No Strict-Transport-Security (HSTS) header was sent, so a first visit can still start over HTTP.', 'probe-site-doctor' );
		}

		if ( ! $issues ) {
			return $this->good(
				__( 'HTTPS is configured', 'probe-site-doctor' ),
				__( 'The site uses HTTPS, the admin is forced over HTTPS and an HSTS header is sent.', 'probe-site-doctor' ) . ' ' . $scope,
				$data
			);
		}

		return $this->result(
			Severity::INFO,
			__( 'HTTPS is in use, with room to tighten it', 'probe-site-doctor' ),
			__( 'The site addresses use HTTPS.', 'probe-site-doctor' ) . ' ' . implode( ' ', $issues ) . ' ' . $scope . ' ' . $this->indicator_note(),
			__( 'Add FORCE_SSL_ADMIN to wp-config.php, and ask your host (or add to the server config) to send an HSTS header once you are sure HTTPS works everywhere.', 'probe-site-doctor' ),
			$data
		)->with_impact( Impact::LOW, __( 'HTTPS already protects traffic. These settings close smaller gaps, mainly the very first request of a visit and admin logins over a plain-HTTP link.', 'probe-site-doctor' ) )
			->with_cleanup( $steps );
	}
}
