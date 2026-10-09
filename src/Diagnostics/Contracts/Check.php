<?php
/**
 * Diagnostic check contract.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Contracts;

use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * A single, self-contained diagnostic.
 *
 * Rules every check must follow:
 * - Read-only: never change site state, options or files.
 * - Fast: complete in well under a few seconds; no remote HTTP requests
 *   unless the check explicitly exists to test connectivity.
 * - Safe: never expose secrets (passwords, keys, salts) in its result.
 * - Honest: report what was measured; do not claim coverage it lacks.
 *
 * Checks may throw; the engine converts exceptions into error results.
 */
interface Check {

	/**
	 * Unique, stable identifier (lowercase, a-z0-9 and dashes/underscores),
	 * e.g. "database.autoloaded-options". Stored with results.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * ID of the category the check belongs to (see CategoryRegistry).
	 *
	 * @return string
	 */
	public function get_category(): string;

	/**
	 * Short human-readable name.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * One-sentence explanation of what is examined.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether the check makes sense in the current environment. Non-applicable
	 * checks are left out of the scan entirely.
	 *
	 * @return bool
	 */
	public function is_applicable(): bool;

	/**
	 * Performs the diagnostic.
	 *
	 * @return Result
	 */
	public function run(): Result;
}
