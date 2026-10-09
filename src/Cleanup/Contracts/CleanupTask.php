<?php
/**
 * Cleanup task contract.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Cleanup\Contracts;

use ProbeSiteDoctor\Cleanup\Confirmation;

defined( 'ABSPATH' ) || exit;

/**
 * A potentially destructive maintenance operation (e.g. deleting expired
 * transients).
 *
 * Policy (see docs/CLEANUP-POLICY.md):
 * - Tasks never run automatically: not during scans, not on a schedule,
 *   and not on activation or upgrade.
 * - A task only runs through CleanupRunner::run(), which requires a
 *   Confirmation obtained from ConfirmationGuard. The user must have seen
 *   the preview, typed the confirmation phrase and acknowledged a backup.
 * - execute() must begin with $confirmation->assert_for( $this->get_id() ).
 */
interface CleanupTask {

	/**
	 * Unique ID, e.g. "transients.expired".
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Human-readable name.
	 *
	 * @return string
	 */
	public function get_label(): string;

	/**
	 * What will be deleted or changed, and whether it can be undone.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Read-only preview of what execute() would affect right now.
	 *
	 * @return array{items:int,bytes:int|null,summary:string}
	 */
	public function preview(): array;

	/**
	 * Performs the operation. Only CleanupRunner may call this.
	 *
	 * @param Confirmation $confirmation Consumed confirmation for this task.
	 * @return int Number of items affected.
	 */
	public function execute( Confirmation $confirmation ): int;
}
