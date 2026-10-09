<?php
/**
 * Cleanup task registry.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Cleanup;

use ProbeSiteDoctor\Cleanup\Contracts\CleanupTask;

defined( 'ABSPATH' ) || exit;

/**
 * Collects cleanup tasks. The plugin registers none in this version:
 * database findings only describe optional manual cleanup.
 */
final class CleanupRegistry {

	/**
	 * Registered tasks keyed by ID.
	 *
	 * @return array<string,CleanupTask>
	 */
	public function all(): array {
		/**
		 * Filters the available cleanup tasks. Tasks registered here still
		 * require explicit confirmation through ConfirmationGuard.
		 *
		 * @since 0.3.0
		 *
		 * @param CleanupTask[] $tasks Tasks.
		 */
		$filtered = apply_filters( 'probesd_cleanup_tasks', array() );

		$tasks = array();
		foreach ( (array) $filtered as $task ) {
			if ( $task instanceof CleanupTask ) {
				$tasks[ $task->get_id() ] = $task;
			}
		}
		return $tasks;
	}

	/**
	 * One task, or null.
	 *
	 * @param string $id Task ID.
	 * @return CleanupTask|null
	 */
	public function get( string $id ): ?CleanupTask {
		return $this->all()[ $id ] ?? null;
	}
}
