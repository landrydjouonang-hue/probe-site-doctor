<?php
/**
 * Severity levels.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * The four severity levels a check result can carry.
 *
 * Implemented as string constants (PHP 8.0 has no native enums). The string
 * values are what is stored in the database and exposed through the REST API,
 * so they must never change.
 */
final class Severity {

	/** Nothing to do: the check passed. */
	public const GOOD = 'good';

	/** Neutral finding worth knowing about; does not affect the health score. */
	public const INFO = 'info';

	/** Should be addressed; degrades health, performance or maintainability. */
	public const WARNING = 'warning';

	/** Needs attention as a priority. */
	public const CRITICAL = 'critical';

	/**
	 * All levels, ordered from most to least severe.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::CRITICAL, self::WARNING, self::INFO, self::GOOD );
	}

	/**
	 * Whether a value is a known severity.
	 *
	 * @param mixed $severity Value to test.
	 * @return bool
	 */
	public static function is_valid( $severity ): bool {
		return is_string( $severity ) && in_array( $severity, self::all(), true );
	}

	/**
	 * Numeric rank; higher is more severe. Used for sorting.
	 *
	 * @param string $severity Severity.
	 * @return int
	 */
	public static function rank( string $severity ): int {
		switch ( $severity ) {
			case self::CRITICAL:
				return 3;
			case self::WARNING:
				return 2;
			case self::INFO:
				return 1;
			default:
				return 0;
		}
	}

	/**
	 * Contribution of a result to the health score (0–100), or null when the
	 * severity is neutral and excluded from scoring.
	 *
	 * @param string $severity Severity.
	 * @return int|null
	 */
	public static function score_weight( string $severity ): ?int {
		switch ( $severity ) {
			case self::GOOD:
				return 100;
			case self::WARNING:
				return 50;
			case self::CRITICAL:
				return 0;
			default:
				return null;
		}
	}

	/**
	 * Human-readable label.
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	public static function label( string $severity ): string {
		switch ( $severity ) {
			case self::GOOD:
				return __( 'Good', 'probe-site-doctor' );
			case self::INFO:
				return __( 'Information', 'probe-site-doctor' );
			case self::WARNING:
				return __( 'Warning', 'probe-site-doctor' );
			case self::CRITICAL:
				return __( 'Critical', 'probe-site-doctor' );
			default:
				return __( 'Unknown', 'probe-site-doctor' );
		}
	}

	/**
	 * Dashicon used to represent the level (always paired with a text label).
	 *
	 * @param string $severity Severity.
	 * @return string
	 */
	public static function icon( string $severity ): string {
		switch ( $severity ) {
			case self::GOOD:
				return 'dashicons-yes-alt';
			case self::INFO:
				return 'dashicons-info';
			case self::WARNING:
				return 'dashicons-warning';
			case self::CRITICAL:
				return 'dashicons-dismiss';
			default:
				return 'dashicons-marker';
		}
	}
}
