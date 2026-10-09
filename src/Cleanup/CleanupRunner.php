<?php
/**
 * The only entry point for executing cleanup tasks.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Cleanup;

use ProbeSiteDoctor\Cleanup\Contracts\CleanupTask;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a cleanup task after its confirmation has been consumed.
 *
 * Nothing in the plugin calls this automatically. In this version no
 * cleanup task is registered and no UI can trigger one; the runner exists
 * so that any future cleanup feature has to go through explicit confirmation.
 */
final class CleanupRunner {

	private ConfirmationGuard $guard;

	/**
	 * Constructor.
	 *
	 * @param ConfirmationGuard $guard Guard.
	 */
	public function __construct( ConfirmationGuard $guard ) {
		$this->guard = $guard;
	}

	/**
	 * Executes a confirmed task for the current user.
	 *
	 * @param CleanupTask  $task         Task.
	 * @param Confirmation $confirmation Confirmation from ConfirmationGuard::confirm().
	 * @return int Items affected.
	 *
	 * @throws CleanupNotConfirmedException When confirmation is missing, used, expired or stale.
	 */
	public function run( CleanupTask $task, Confirmation $confirmation ): int {
		$user_id = get_current_user_id();

		$this->guard->consume( $confirmation, $task, $user_id );

		$preview  = $task->preview();
		$affected = $task->execute( $confirmation );

		/**
		 * Fires after a confirmed cleanup ran, e.g. for audit logging.
		 *
		 * @since 0.3.0
		 *
		 * @param string              $task_id  Task ID.
		 * @param int                 $user_id  User who confirmed it.
		 * @param int                 $affected Items affected.
		 * @param array<string,mixed> $preview  Preview at execution time.
		 */
		do_action( 'probesd_cleanup_executed', $task->get_id(), $user_id, $affected, $preview );

		return $affected;
	}
}
