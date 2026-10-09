<?php
/**
 * Report assembly.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Reporting;

use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\UpdateInfo;
use ProbeSiteDoctor\Storage\ResultRepository;
use ProbeSiteDoctor\Storage\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a structured health report from a stored scan. The report array
 * is the single source for the dashboard and the REST API, and is the
 * foundation later export formats will render from.
 */
final class ReportBuilder {

	private ScanRepository $scans;
	private ResultRepository $results;
	private CategoryRegistry $categories;

	/**
	 * Constructor.
	 *
	 * @param ScanRepository   $scans      Scans.
	 * @param ResultRepository $results    Results.
	 * @param CategoryRegistry $categories Categories.
	 */
	public function __construct( ScanRepository $scans, ResultRepository $results, CategoryRegistry $categories ) {
		$this->scans      = $scans;
		$this->results    = $results;
		$this->categories = $categories;
	}

	/**
	 * Builds the report for a scan.
	 *
	 * @param array<string,mixed> $scan Hydrated scan row.
	 * @return array<string,mixed>
	 */
	public function build( array $scan ): array {
		$results = $this->results->for_scan( (int) $scan['id'] );

		$categories = array();
		foreach ( $this->categories->all() as $id => $category ) {
			$categories[ $id ] = $this->empty_category( $category );
		}

		foreach ( $results as $result ) {
			$cat_id = $result['category'];
			if ( ! isset( $categories[ $cat_id ] ) ) {
				$categories[ $cat_id ] = $this->empty_category( $this->categories->get( $cat_id ) );
			}
			$categories[ $cat_id ]['results'][] = $result;
			$categories[ $cat_id ]['counts'][ $this->bucket( $result ) ]++;
		}

		foreach ( $categories as $id => $category ) {
			$categories[ $id ]['score']  = Score::calculate( $category['counts'] );
			$categories[ $id ]['band']   = Score::band( $categories[ $id ]['score'] );
			$categories[ $id ]['issues'] = $category['counts'][ Severity::CRITICAL ] + $category['counts'][ Severity::WARNING ];
		}

		$previous = $this->scans->previous_completed( (int) $scan['id'] );
		$score    = $scan['score'];

		return array(
			'scan'           => $scan,
			'score'          => $score,
			'band'           => Score::band( $score ),
			'band_label'     => Score::band_label( Score::band( $score ) ),
			'previous_score' => $previous ? $previous['score'] : null,
			'previous_id'    => $previous ? $previous['id'] : null,
			'counts'         => $this->results->tally( (int) $scan['id'] ),
			'categories'     => $categories,
			'results'        => $results,
			'updates'        => $this->update_recommendations( $results ),
		);
	}

	/**
	 * Collects the update recommendations of all findings into one list,
	 * highest priority first. Recommendations only; nothing is updated.
	 *
	 * @param array<int,array<string,mixed>> $results Result rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function update_recommendations( array $results ): array {
		$all = array();
		foreach ( $results as $result ) {
			foreach ( (array) ( $result['data']['update_recommendations'] ?? array() ) as $rec ) {
				if ( is_array( $rec ) && isset( $rec['name'], $rec['priority'] ) ) {
					$all[] = $rec + array( 'check_id' => $result['check_id'] );
				}
			}
		}
		return UpdateInfo::sort_recommendations( $all );
	}

	/**
	 * Which counter a result increments.
	 *
	 * @param array<string,mixed> $result Result row.
	 * @return string
	 */
	private function bucket( array $result ): string {
		if ( Result::STATUS_ERROR === $result['status'] ) {
			return 'error';
		}
		if ( Result::STATUS_SKIPPED === $result['status'] ) {
			return 'skipped';
		}
		return Severity::is_valid( $result['severity'] ) ? $result['severity'] : Severity::INFO;
	}

	/**
	 * Category skeleton.
	 *
	 * @param array<string,string> $category Category definition.
	 * @return array<string,mixed>
	 */
	private function empty_category( array $category ): array {
		return $category + array(
			'counts'  => array_fill_keys( Severity::all(), 0 ) + array(
				'error'   => 0,
				'skipped' => 0,
			),
			'results' => array(),
		);
	}
}
