<?php
/**
 * WordPress version visibility check.
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
use ProbeSiteDoctor\Diagnostics\Support\FrontendSnapshot;
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Reports where the WordPress version is visible to visitors: the generator
 * meta tag, ?ver= query strings on assets, and readme.html.
 *
 * Hiding the version is obscurity, not protection. Automated attacks try
 * exploits regardless of the advertised version, so keeping WordPress
 * updated is what matters. This check is informational by design.
 */
final class VersionVisibilityCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.version-visibility';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'WordPress version visibility', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether the WordPress version is visible in the page source, asset URLs or readme.html.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$snapshot = FrontendSnapshot::get();
		if ( empty( $snapshot['ok'] ) ) {
			return $this->skipped(
				sprintf(
					/* translators: %s: Error message. */
					__( 'The homepage HTML could not be retrieved through a loopback request (%s).', 'probe-site-doctor' ),
					(string) $snapshot['error']
				)
			);
		}

		$version    = (string) get_bloginfo( 'version' );
		$generators = (array) ( $snapshot['generators'] ?? array() );
		$wp_meta    = array_values( array_filter( $generators, static fn( $g ) => false !== stripos( (string) $g, 'wordpress' ) ) );
		$versioned  = (int) ( $snapshot['versioned_assets'] ?? 0 );

		$readme     = Loopback::get( site_url( '/readme.html' ), 8 );
		$readme_ok  = ! empty( $readme['ok'] ) && 200 === (int) $readme['status'] && false !== stripos( (string) $readme['body'], 'wordpress' );

		$data = array(
			'installed_version'       => $version,
			'generator_meta_tag'      => $wp_meta ? (string) $wp_meta[0] : null,
			'assets_with_ver_strings' => $versioned,
			'readme_html_public'      => $readme_ok,
		);

		$found = array();
		if ( $wp_meta ) {
			/* translators: %s: Content of the generator meta tag. */
			$found[] = sprintf( __( 'the generator meta tag in the page source (%s)', 'probe-site-doctor' ), $wp_meta[0] );
		}
		if ( $versioned > 0 ) {
			/* translators: %s: Number of assets. */
			$found[] = sprintf( _n( '%s script or stylesheet URL carrying a ?ver= number', '%s script and stylesheet URLs carrying ?ver= numbers', $versioned, 'probe-site-doctor' ), number_format_i18n( $versioned ) );
		}
		if ( $readme_ok ) {
			$found[] = __( 'the public readme.html file', 'probe-site-doctor' );
		}

		$reality = __( 'Hiding the version is obscurity, not protection: automated attacks try exploits regardless, and the version can still be inferred from core file fingerprints. Keeping WordPress updated is what actually protects the site.', 'probe-site-doctor' );

		if ( ! $found ) {
			return $this->good(
				__( 'The WordPress version is not advertised', 'probe-site-doctor' ),
				__( 'No generator meta tag, version query strings or public readme.html were found on the homepage.', 'probe-site-doctor' ) . ' ' . $reality,
				$data
			);
		}

		return $this->info(
			__( 'The WordPress version is visible to visitors', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: List of places the version appears. */
				__( 'The version can be read from %s.', 'probe-site-doctor' ),
				wp_sprintf( '%l', $found )
			) . ' ' . $reality . ' ' . $this->indicator_note(),
			__( 'Prioritise staying up to date. If you also want to remove the version from the page source, do it with a small snippet or an optimization plugin; delete readme.html only if your deployment restores it on update, since core update files replace it.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			Impact::LOW,
			__( 'Very low: it saves an attacker a lookup and nothing else. Do not treat removing it as a security measure.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Remove version hints from the page source, if you want to. This is cosmetic hardening; Site Doctor never changes your theme or files.', 'probe-site-doctor' ),
				array(
					__( 'Add the snippet below in a small plugin (not directly in a theme you update).', 'probe-site-doctor' ),
					__( 'Leave ?ver= strings in place if you rely on cache busting for assets.', 'probe-site-doctor' ),
					__( 'Check the site still loads correctly afterwards.', 'probe-site-doctor' ),
				),
				false
			) )->as_hardening()
				->with_command( __( 'Remove the generator meta tag (PHP)', 'probe-site-doctor' ), "remove_action( 'wp_head', 'wp_generator' );", ManualCleanup::TYPE_PHP )
		);
	}
}
