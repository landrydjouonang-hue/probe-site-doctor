<?php
/**
 * Health score calculation.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Reporting;

use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Turns severity counts into a 0–100 health score.
 *
 * Each completed Good result is worth 100, Warning 50 and Critical 0; the
 * score is the average. Information results, errors and skipped checks are
 * neutral and excluded. A scan with no scorable results has no score (null).
 */
final class Score {

	/**
	 * Calculates the score.
	 *
	 * @param array<string,int> $counts Counts keyed by severity.
	 * @return int|null
	 */
	public static function calculate( array $counts ): ?int {
		$total  = 0;
		$scored = 0;

		foreach ( Severity::all() as $severity ) {
			$weight = Severity::score_weight( $severity );
			$count  = max( 0, (int) ( $counts[ $severity ] ?? 0 ) );
			if ( null === $weight || 0 === $count ) {
				continue;
			}
			$total  += $weight * $count;
			$scored += $count;
		}

		if ( 0 === $scored ) {
			return null;
		}

		$score = (int) round( $total / $scored );

		/**
		 * Filters the calculated health score.
		 *
		 * @since 0.1.0
		 *
		 * @param int               $score  Score 0–100.
		 * @param array<string,int> $counts Severity counts.
		 */
		return max( 0, min( 100, (int) apply_filters( 'probesd_health_score', $score, $counts ) ) );
	}

	/**
	 * Band for a score: good (≥ 80), fair (≥ 50) or poor.
	 *
	 * @param int|null $score Score.
	 * @return string good|fair|poor|none
	 */
	public static function band( ?int $score ): string {
		if ( null === $score ) {
			return 'none';
		}
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'fair' : 'poor';
	}

	/**
	 * How the score is calculated, in plain words.
	 *
	 * @return string
	 */
	public static function explanation(): string {
		return __( 'The score is the average of the checks that can be scored: each Good counts as 100, each Warning as 50 and each Critical as 0. Informational findings, skipped checks and checks that could not run are excluded, so they neither help nor hurt the score.', 'probe-site-doctor' );
	}

	/**
	 * What the score does not mean. Shown wherever the score is presented.
	 *
	 * @return string
	 */
	public static function disclaimer(): string {
		return __( 'The score summarises only the checks in this report. It is not a security guarantee and not a certification: Site Doctor does not scan for malware, modified files, known vulnerabilities or intrusions, and it cannot measure what visitors experience in a browser. A high score means these checks passed, nothing more.', 'probe-site-doctor' );
	}

	/**
	 * Label for a score band.
	 *
	 * @param string $band Band.
	 * @return string
	 */
	public static function band_label( string $band ): string {
		switch ( $band ) {
			case 'good':
				return __( 'Healthy', 'probe-site-doctor' );
			case 'fair':
				return __( 'Needs attention', 'probe-site-doctor' );
			case 'poor':
				return __( 'Needs urgent attention', 'probe-site-doctor' );
			default:
				return __( 'Not scored', 'probe-site-doctor' );
		}
	}
}
