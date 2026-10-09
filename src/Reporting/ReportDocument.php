<?php
/**
 * Website Health Report document.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Reporting;

use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a stored report into the document the Health Report renders:
 * an overall diagnostic summary, one summary per section, and the findings
 * grouped into sections.
 *
 * Presentation only. It reads a completed scan and never runs checks or
 * changes anything.
 */
final class ReportDocument {

	/**
	 * Builds the document.
	 *
	 * @param array<string,mixed> $report Report from ReportBuilder::build().
	 * @return array<string,mixed>
	 */
	public static function build( array $report ): array {
		$scan     = (array) $report['scan'];
		$results  = (array) $report['results'];
		$counts   = (array) $report['counts'];
		$sections = ReportSections::group( $results );

		foreach ( $sections as $id => $section ) {
			$sections[ $id ]['assessment'] = self::assessment( $section['counts'], (int) $section['checks'] );
		}

		$scored = $counts[ Severity::GOOD ] + $counts[ Severity::WARNING ] + $counts[ Severity::CRITICAL ];
		$user   = $scan['user_id'] ? get_userdata( (int) $scan['user_id'] ) : false;

		return array(
			'meta'     => array(
				'site_name'      => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'site_url'       => home_url( '/' ),
				'scan_id'        => (int) $scan['id'],
				'scanned_at'     => (string) ( $scan['finished_at'] ?? $scan['started_at'] ),
				'generated_at'   => current_time( 'mysql', true ),
				'generated_by'   => $user ? wp_strip_all_tags( $user->display_name ) : __( 'unknown', 'probe-site-doctor' ),
				'plugin_version' => (string) $scan['plugin_version'],
				'environment'    => (array) $scan['environment'],
			),
			'summary'  => array(
				'score'          => $scan['score'],
				'band'           => $report['band'],
				'band_label'     => $report['band_label'],
				'counts'         => $counts,
				'checks_total'   => (int) $scan['total_checks'],
				'scored_checks'  => $scored,
				'previous_score' => $report['previous_score'],
				'delta'          => ( null !== $scan['score'] && null !== $report['previous_score'] ) ? (int) $scan['score'] - (int) $report['previous_score'] : null,
				'scope'          => self::scope_notice(),
				'headline'       => self::headline( $counts, $report['band_label'] ),
				'explanation'    => Score::explanation(),
				'disclaimer'     => Score::disclaimer(),
				'priorities'     => self::priorities( $results ),
			),
			'sections' => $sections,
			'updates'  => (array) ( $report['updates'] ?? array() ),
		);
	}

	/**
	 * What the whole product does and does not cover. Shown on the dashboard
	 * and at the top of every report, including exported copies.
	 *
	 * @return string
	 */
	public static function scope_notice(): string {
		return __( 'Site Doctor reviews performance, database, configuration, plugin/theme and technical SEO settings. It includes a few basic hardening checks, but it is not a malware scanner, firewall or vulnerability scanner. A good score does not mean the site is secure. Use a dedicated security product for that.', 'probe-site-doctor' );
	}

	/**
	 * One-sentence verdict for the whole report.
	 *
	 * @param array<string,int> $counts     Severity counts.
	 * @param string            $band_label Score band label.
	 * @return string
	 */
	private static function headline( array $counts, string $band_label ): string {
		$critical = (int) $counts[ Severity::CRITICAL ];
		$warning  = (int) $counts[ Severity::WARNING ];

		if ( $critical > 0 ) {
			return sprintf(
				/* translators: 1: Number of critical findings, 2: Number of warnings. */
				_n( '%1$s finding needs attention as a priority, alongside %2$s warning.', '%1$s findings need attention as a priority, alongside %2$s warnings.', $critical, 'probe-site-doctor' ),
				number_format_i18n( $critical ),
				number_format_i18n( $warning )
			);
		}
		if ( $warning > 0 ) {
			return sprintf(
				/* translators: %s: Number of warnings. */
				_n( 'No critical findings. %s warning is worth addressing.', 'No critical findings. %s warnings are worth addressing.', $warning, 'probe-site-doctor' ),
				number_format_i18n( $warning )
			);
		}
		return sprintf(
			/* translators: %s: Score band label such as "Healthy". */
			__( 'No critical findings and no warnings. Overall assessment: %s.', 'probe-site-doctor' ),
			$band_label
		);
	}

	/**
	 * Section assessment sentence.
	 *
	 * @param array<string,int> $counts Section counts.
	 * @param int               $checks Checks in the section.
	 * @return string
	 */
	private static function assessment( array $counts, int $checks ): string {
		if ( 0 === $checks ) {
			return __( 'No checks ran in this section.', 'probe-site-doctor' );
		}

		$parts = array();
		if ( $counts[ Severity::CRITICAL ] > 0 ) {
			/* translators: %s: Number of findings. */
			$parts[] = sprintf( _n( '%s critical finding', '%s critical findings', $counts[ Severity::CRITICAL ], 'probe-site-doctor' ), number_format_i18n( $counts[ Severity::CRITICAL ] ) );
		}
		if ( $counts[ Severity::WARNING ] > 0 ) {
			/* translators: %s: Number of warnings. */
			$parts[] = sprintf( _n( '%s warning', '%s warnings', $counts[ Severity::WARNING ], 'probe-site-doctor' ), number_format_i18n( $counts[ Severity::WARNING ] ) );
		}
		if ( $counts[ Severity::INFO ] > 0 ) {
			/* translators: %s: Number of notes. */
			$parts[] = sprintf( _n( '%s informational note', '%s informational notes', $counts[ Severity::INFO ], 'probe-site-doctor' ), number_format_i18n( $counts[ Severity::INFO ] ) );
		}
		if ( $counts['skipped'] > 0 ) {
			/* translators: %s: Number of checks. */
			$parts[] = sprintf( _n( '%s check could not be evaluated', '%s checks could not be evaluated', $counts['skipped'], 'probe-site-doctor' ), number_format_i18n( $counts['skipped'] ) );
		}

		if ( ! $parts ) {
			return sprintf(
				/* translators: %s: Number of checks. */
				_n( '%s check ran and found nothing to report.', 'All %s checks ran and found nothing to report.', $checks, 'probe-site-doctor' ),
				number_format_i18n( $checks )
			);
		}

		return sprintf(
			/* translators: 1: Number of checks, 2: List such as "1 warning and 2 informational notes". */
			__( '%1$s checks ran: %2$s.', 'probe-site-doctor' ),
			number_format_i18n( $checks ),
			wp_sprintf( '%l', $parts )
		);
	}

	/**
	 * Findings to act on first: critical, then warnings, with their section.
	 *
	 * @param array<int,array<string,mixed>> $results Result rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function priorities( array $results ): array {
		$sections = ReportSections::all();
		$out      = array();

		foreach ( $results as $result ) {
			if ( 'completed' !== $result['status'] || ! in_array( $result['severity'], array( Severity::CRITICAL, Severity::WARNING ), true ) ) {
				continue;
			}
			$section = ReportSections::for_check( (string) $result['check_id'], (string) $result['category'] );
			$out[]   = array(
				'severity'       => (string) $result['severity'],
				'section'        => $sections[ $section ]['label'] ?? $section,
				'title'          => (string) $result['title'],
				'recommendation' => (string) $result['recommendation'],
				'impact'         => (string) ( $result['impact']['level'] ?? '' ),
			);
		}

		usort(
			$out,
			static fn( $a, $b ) => Severity::rank( $b['severity'] ) <=> Severity::rank( $a['severity'] )
		);

		return $out;
	}
}
