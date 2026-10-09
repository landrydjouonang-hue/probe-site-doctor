<?php
/**
 * Optional contract: declares how a check measures.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Checks implementing this declare their measurement method (see
 * ProbeSiteDoctor\Diagnostics\Measurement). Checks that do not are treated as
 * server-side. Kept separate from Check so existing checks stay compatible.
 */
interface ReportsMeasurement {

	/**
	 * One of the Measurement constants.
	 *
	 * @return string
	 */
	public function get_measurement(): string;
}
