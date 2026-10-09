<?php
/**
 * Estimated impact levels.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * How much a finding is estimated to affect the site. It complements
 * severity (how urgently it should be handled) with how much fixing it is
 * likely to help. It is always an estimate, and the summary text explains
 * the reasoning.
 */
final class Impact {

	public const LOW    = 'low';
	public const MEDIUM = 'medium';
	public const HIGH   = 'high';

	/**
	 * All levels, highest first.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::HIGH, self::MEDIUM, self::LOW );
	}

	/**
	 * Whether a value is a known level.
	 *
	 * @param mixed $level Value.
	 * @return bool
	 */
	public static function is_valid( $level ): bool {
		return is_string( $level ) && in_array( $level, self::all(), true );
	}

	/**
	 * Label.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function label( string $level ): string {
		switch ( $level ) {
			case self::HIGH:
				return __( 'High', 'probe-site-doctor' );
			case self::MEDIUM:
				return __( 'Medium', 'probe-site-doctor' );
			default:
				return __( 'Low', 'probe-site-doctor' );
		}
	}
}
