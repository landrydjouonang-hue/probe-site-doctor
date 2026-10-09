<?php
/**
 * Report section layout.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Reporting;

use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Groups findings into the sections of the Website Health Report.
 *
 * The report has six sections. Five follow the diagnostic categories; the
 * Configuration category is split, so the security-related indicators get a
 * section of their own while PHP and WordPress versions stay under
 * Configuration. Each section carries its own scope wording, because what a
 * section can and cannot tell differs.
 */
final class ReportSections {

	public const PERFORMANCE   = 'performance';
	public const DATABASE      = 'database';
	public const EXTENSIONS    = 'extensions';
	public const CONFIGURATION = 'configuration';
	public const SECURITY      = 'security';
	public const SEO           = 'seo';
	public const DEVELOPER     = 'developer';

	/**
	 * Check IDs that belong to the Security Indicators section.
	 *
	 * @var string[]
	 */
	private const SECURITY_CHECKS = array(
		'configuration.security-indicators',
		'configuration.file-editing',
		'configuration.https',
		'configuration.admin-accounts',
		'configuration.xmlrpc',
		'configuration.rest-api',
		'configuration.version-visibility',
		'configuration.debug-mode',
	);

	/**
	 * Section definitions in report order.
	 *
	 * @return array<string,array{id:string,label:string,intro:string,scope:string}>
	 */
	public static function all(): array {
		$sections = array(
			self::PERFORMANCE   => array(
				'label' => __( 'Performance', 'probe-site-doctor' ),
				'intro' => __( 'Caching, server response time, images and the assets the homepage loads.', 'probe-site-doctor' ),
				'scope' => __( 'Measured on the server. These are not browser page-load measurements: metrics such as Largest Contentful Paint need a browser-based tool like PageSpeed Insights or Lighthouse.', 'probe-site-doctor' ),
			),
			self::DATABASE      => array(
				'label' => __( 'Database', 'probe-site-doctor' ),
				'intro' => __( 'Size, large tables, revisions, transients, metadata and autoloaded options.', 'probe-site-doctor' ),
				'scope' => __( 'Findings may include optional manual cleanup steps. Nothing was deleted or changed to produce this report.', 'probe-site-doctor' ),
			),
			self::EXTENSIONS    => array(
				'label' => __( 'Plugins & Themes', 'probe-site-doctor' ),
				'intro' => __( 'Available updates, unused extensions and compatibility indicators.', 'probe-site-doctor' ),
				'scope' => __( 'Update data comes from WordPress’s own last update check; WordPress.org was not contacted and nothing was installed.', 'probe-site-doctor' ),
			),
			self::CONFIGURATION => array(
				'label' => __( 'Configuration', 'probe-site-doctor' ),
				'intro' => __( 'PHP and WordPress versions and their support status.', 'probe-site-doctor' ),
				'scope' => __( 'Read from the server and WordPress settings. No setting was changed.', 'probe-site-doctor' ),
			),
			self::SECURITY      => array(
				'label' => __( 'Security Indicators', 'probe-site-doctor' ),
				'intro' => __( 'Debug output, file editing, HTTPS, administrator accounts, XML-RPC, REST exposure, version disclosure and related settings.', 'probe-site-doctor' ),
				'scope' => __( 'These are configuration indicators, not a security audit. Nothing here scans for malware, modified files, known vulnerabilities or intrusions, and passwords are never examined. A clean section does not mean the site is secure.', 'probe-site-doctor' ),
			),
			self::SEO           => array(
				'label' => __( 'SEO (Technical)', 'probe-site-doctor' ),
				'intro' => __( 'Indexing configuration, sitemap, titles, descriptions, image alt text and internal links.', 'probe-site-doctor' ),
				'scope' => __( 'Technical checks only. Content quality, keywords, rankings and backlinks are not assessed, and this does not replace an SEO platform.', 'probe-site-doctor' ),
			),
			self::DEVELOPER     => array(
				'label' => __( 'Developer', 'probe-site-doctor' ),
				'intro' => __( 'Environment, PHP extensions and limits, cron, REST availability, the debug log and database configuration.', 'probe-site-doctor' ),
				'scope' => __( 'The technical state of the install, for whoever maintains it. The full system report — active theme, every active plugin, extensions and constants — is on the Developer screen and can be exported as a diagnostic report.', 'probe-site-doctor' ),
			),
		);

		/**
		 * Filters the report sections.
		 *
		 * @since 0.7.0
		 *
		 * @param array<string,array<string,string>> $sections Sections keyed by ID, in report order.
		 */
		$filtered = (array) apply_filters( 'probesd_report_sections', $sections );

		$out = array();
		foreach ( $filtered as $id => $section ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $section ) || empty( $section['label'] ) ) {
				continue;
			}
			$out[ $id ] = array(
				'id'    => $id,
				'label' => (string) $section['label'],
				'intro' => (string) ( $section['intro'] ?? '' ),
				'scope' => (string) ( $section['scope'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Section a finding belongs to.
	 *
	 * @param string $check_id Check ID.
	 * @param string $category Category ID stored with the result.
	 * @return string
	 */
	public static function for_check( string $check_id, string $category ): string {
		$defined = self::all();
		$section = in_array( $check_id, self::SECURITY_CHECKS, true ) ? self::SECURITY : $category;

		/**
		 * Filters which report section a finding belongs to.
		 *
		 * @since 0.7.0
		 *
		 * @param string $section  Section ID.
		 * @param string $check_id Check ID.
		 * @param string $category Category ID.
		 */
		$section = (string) apply_filters( 'probesd_report_section_for_check', $section, $check_id, $category );

		if ( isset( $defined[ $section ] ) ) {
			return $section;
		}
		return isset( $defined[ $category ] ) ? $category : CategoryRegistry::CONFIGURATION;
	}

	/**
	 * Groups a report's findings into sections, with a score and counts each.
	 *
	 * @param array<int,array<string,mixed>> $results Result rows from the report.
	 * @return array<string,array<string,mixed>>
	 */
	public static function group( array $results ): array {
		$sections = array();
		foreach ( self::all() as $id => $section ) {
			$sections[ $id ] = $section + array(
				'counts'  => array_fill_keys( Severity::all(), 0 ) + array(
					'error'   => 0,
					'skipped' => 0,
				),
				'results' => array(),
			);
		}

		foreach ( $results as $result ) {
			$id = self::for_check( (string) $result['check_id'], (string) $result['category'] );
			if ( ! isset( $sections[ $id ] ) ) {
				continue;
			}
			$sections[ $id ]['results'][] = $result;

			if ( 'error' === $result['status'] ) {
				$sections[ $id ]['counts']['error']++;
			} elseif ( 'skipped' === $result['status'] ) {
				$sections[ $id ]['counts']['skipped']++;
			} elseif ( Severity::is_valid( $result['severity'] ) ) {
				$sections[ $id ]['counts'][ $result['severity'] ]++;
			}
		}

		foreach ( $sections as $id => $section ) {
			$score                    = Score::calculate( $section['counts'] );
			$sections[ $id ]['score'] = $score;
			$sections[ $id ]['band']  = Score::band( $score );
			$sections[ $id ]['issues'] = $section['counts'][ Severity::CRITICAL ] + $section['counts'][ Severity::WARNING ];
			$sections[ $id ]['checks'] = count( $section['results'] );
		}

		return $sections;
	}
}
