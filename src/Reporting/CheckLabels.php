<?php
/**
 * Check labels for display.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Reporting;

use ProbeSiteDoctor\Diagnostics\CheckRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Maps check IDs to the label shown next to a finding.
 *
 * Reports are kept, so a stored result can reference a check that no longer
 * exists. Retired IDs keep an explicit label here so older reports stay
 * readable instead of showing a raw ID.
 */
final class CheckLabels {

	/**
	 * Builds the map.
	 *
	 * @param CheckRegistry $checks Registry.
	 * @return array<string,string>
	 */
	public static function map( CheckRegistry $checks ): array {
		$labels = self::retired();
		foreach ( $checks->all() as $id => $check ) {
			$labels[ $id ] = $check->get_label();
		}
		return $labels;
	}

	/**
	 * Labels of checks removed in a later version.
	 *
	 * @return array<string,string>
	 */
	private static function retired(): array {
		return array(
			'extensions.pending-updates' => __( 'Plugin and theme updates (earlier version)', 'probe-site-doctor' ),
		);
	}
}
