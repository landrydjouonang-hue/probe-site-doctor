<?php
/**
 * Cleanup confirmation.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Proof that a specific user explicitly confirmed a specific cleanup preview.
 *
 * Issued by ConfirmationGuard::confirm(). It carries a single-use token that
 * is also stored server-side, so a hand-built Confirmation is worthless: the
 * guard rejects tokens it did not issue. It only becomes usable after the
 * guard has consumed it (CleanupRunner does this immediately before execution).
 */
final class Confirmation {

	private string $task_id;
	private int $user_id;
	private string $fingerprint;
	private string $token;
	private bool $consumed = false;

	/**
	 * Constructor. Use ConfirmationGuard::confirm() instead.
	 *
	 * @param string $task_id     Task ID.
	 * @param int    $user_id     Confirming user.
	 * @param string $fingerprint Preview fingerprint that was confirmed.
	 * @param string $token       Single-use token.
	 */
	public function __construct( string $task_id, int $user_id, string $fingerprint, string $token ) {
		$this->task_id     = $task_id;
		$this->user_id     = $user_id;
		$this->fingerprint = $fingerprint;
		$this->token       = $token;
	}

	public function get_task_id(): string {
		return $this->task_id;
	}

	public function get_user_id(): int {
		return $this->user_id;
	}

	public function get_fingerprint(): string {
		return $this->fingerprint;
	}

	public function get_token(): string {
		return $this->token;
	}

	/**
	 * Marks the confirmation as consumed.
	 *
	 * @internal Called by ConfirmationGuard::consume() only, after it has
	 *           validated and deleted the server-side token.
	 *
	 * @param ConfirmationGuard $guard The guard that validated it.
	 * @return void
	 */
	public function mark_consumed( ConfirmationGuard $guard ): void {
		unset( $guard );
		$this->consumed = true;
	}

	/**
	 * Throws unless this confirmation was consumed for the given task. Every
	 * CleanupTask::execute() must call this first.
	 *
	 * @param string $task_id Task ID.
	 * @return void
	 *
	 * @throws CleanupNotConfirmedException When not valid for the task.
	 */
	public function assert_for( string $task_id ): void {
		if ( ! $this->consumed || $this->task_id !== $task_id ) {
			throw new CleanupNotConfirmedException( 'Cleanup tasks can only run through CleanupRunner with a confirmed, consumed Confirmation.' );
		}
	}
}
