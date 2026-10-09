<?php
/**
 * Diagnostic engine.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

use ProbeSiteDoctor\Diagnostics\Contracts\Check;
use ProbeSiteDoctor\Diagnostics\Contracts\ReportsMeasurement;

defined( 'ABSPATH' ) || exit;

/**
 * Executes checks in isolation. Storage-agnostic: it only turns a Check into
 * a Result, measuring time and containing failures.
 */
final class Engine {

	/**
	 * Runs one check.
	 *
	 * Any exception or PHP error raised by the check becomes an error result,
	 * so one broken check never aborts a scan.
	 *
	 * @param Check $check Check.
	 * @return Result
	 */
	public function execute( Check $check ): Result {
		$start = microtime( true );

		/**
		 * Fires before a check runs.
		 *
		 * @since 0.1.0
		 *
		 * @param Check $check Check about to run.
		 */
		do_action( 'probesd_before_check', $check );

		try {
			$result = $check->run();

			if ( $result->get_check_id() !== $check->get_id() ) {
				$result = Result::error( $check->get_id(), __( 'The check returned a result for a different check ID.', 'probe-site-doctor' ) );
			}
		} catch ( \Throwable $e ) {
			$result = Result::error(
				$check->get_id(),
				sprintf(
					/* translators: %s: Error message. */
					__( 'Error while running the check: %s', 'probe-site-doctor' ),
					$e->getMessage()
				)
			);
		}

		$measurement = $check instanceof ReportsMeasurement ? $check->get_measurement() : Measurement::SERVER;
		$result      = $result->with_meta( $check->get_category(), (int) round( ( microtime( true ) - $start ) * 1000 ), $measurement );

		/**
		 * Filters a check result before it is stored.
		 *
		 * @since 0.1.0
		 *
		 * @param Result $result Result.
		 * @param Check  $check  Check that produced it.
		 */
		$filtered = apply_filters( 'probesd_check_result', $result, $check );

		return $filtered instanceof Result ? $filtered : $result;
	}
}
