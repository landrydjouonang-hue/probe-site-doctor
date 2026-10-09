<?php
/**
 * Explicit confirmation protocol for cleanup tasks.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Cleanup;

use ProbeSiteDoctor\Cleanup\Contracts\CleanupTask;
use ProbeSiteDoctor\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Two-step confirmation that every cleanup must pass:
 *
 * 1. challenge(): the user requests a cleanup. The guard computes a
 *    read-only preview, fingerprints it, and returns a confirmation phrase
 *    containing the exact number of items (e.g. "DELETE 1234").
 * 2. confirm(): the user types the phrase and acknowledges a backup. The
 *    guard re-computes the preview; if the data changed, confirmation fails
 *    and a new challenge is needed. On success it issues a single-use
 *    token (valid for 5 minutes).
 *
 * consume() then validates and burns the token immediately before the task
 * runs (see CleanupRunner). Challenges and tokens are bound to the user and
 * to the task, and require the probesd_run_cleanup capability.
 */
final class ConfirmationGuard {

	public const CHALLENGE_TTL = 10 * MINUTE_IN_SECONDS;
	public const TOKEN_TTL     = 5 * MINUTE_IN_SECONDS;

	/**
	 * Starts a confirmation.
	 *
	 * @param CleanupTask $task    Task.
	 * @param int         $user_id User.
	 * @return array{task:string,label:string,description:string,preview:array<string,mixed>,phrase:string,expires:int}
	 *
	 * @throws CleanupNotConfirmedException When not allowed or nothing to clean.
	 */
	public function challenge( CleanupTask $task, int $user_id ): array {
		$this->assert_capability( $user_id );

		$preview = $this->preview( $task );
		if ( $preview['items'] < 1 ) {
			throw new CleanupNotConfirmedException( esc_html__( 'There is nothing to clean up.', 'probe-site-doctor' ) );
		}

		$phrase  = sprintf( 'DELETE %d', $preview['items'] );
		$expires = time() + self::CHALLENGE_TTL;

		set_transient(
			$this->challenge_key( $task, $user_id ),
			array(
				'fingerprint' => $this->fingerprint( $task, $preview ),
				'phrase'      => $phrase,
				'expires'     => $expires,
			),
			self::CHALLENGE_TTL
		);

		return array(
			'task'        => $task->get_id(),
			'label'       => $task->get_label(),
			'description' => $task->get_description(),
			'preview'     => $preview,
			'phrase'      => $phrase,
			'expires'     => $expires,
		);
	}

	/**
	 * Completes a confirmation.
	 *
	 * @param CleanupTask $task             Task.
	 * @param int         $user_id          User.
	 * @param string      $typed_phrase     What the user typed.
	 * @param bool        $backup_confirmed Whether the user acknowledged having a backup.
	 * @return Confirmation
	 *
	 * @throws CleanupNotConfirmedException On any failed condition.
	 */
	public function confirm( CleanupTask $task, int $user_id, string $typed_phrase, bool $backup_confirmed ): Confirmation {
		$this->assert_capability( $user_id );

		$key       = $this->challenge_key( $task, $user_id );
		$challenge = get_transient( $key );

		if ( ! is_array( $challenge ) || (int) ( $challenge['expires'] ?? 0 ) < time() ) {
			delete_transient( $key );
			throw new CleanupNotConfirmedException( esc_html__( 'The confirmation has expired. Review the preview again.', 'probe-site-doctor' ) );
		}
		if ( ! $backup_confirmed ) {
			throw new CleanupNotConfirmedException( esc_html__( 'Confirm that you have a current backup before cleaning up.', 'probe-site-doctor' ) );
		}
		if ( ! hash_equals( (string) $challenge['phrase'], trim( $typed_phrase ) ) ) {
			throw new CleanupNotConfirmedException( esc_html__( 'The confirmation phrase does not match.', 'probe-site-doctor' ) );
		}

		// The challenge is single-use whatever happens next.
		delete_transient( $key );

		$fingerprint = $this->fingerprint( $task, $this->preview( $task ) );
		if ( ! hash_equals( (string) $challenge['fingerprint'], $fingerprint ) ) {
			throw new CleanupNotConfirmedException( esc_html__( 'The data changed since the preview was shown. Review the new preview and confirm again.', 'probe-site-doctor' ) );
		}

		$token = wp_generate_password( 40, false, false );
		set_transient(
			$this->token_key( $token ),
			array(
				'task'        => $task->get_id(),
				'user'        => $user_id,
				'fingerprint' => $fingerprint,
			),
			self::TOKEN_TTL
		);

		return new Confirmation( $task->get_id(), $user_id, $fingerprint, $token );
	}

	/**
	 * Validates and burns a confirmation immediately before execution.
	 *
	 * @param Confirmation $confirmation Confirmation.
	 * @param CleanupTask  $task         Task about to run.
	 * @param int          $user_id      Current user.
	 * @return void
	 *
	 * @throws CleanupNotConfirmedException On any failed condition.
	 */
	public function consume( Confirmation $confirmation, CleanupTask $task, int $user_id ): void {
		$key    = $this->token_key( $confirmation->get_token() );
		$stored = get_transient( $key );
		delete_transient( $key ); // Single use, even when validation fails below.

		$this->assert_capability( $user_id );

		if ( ! is_array( $stored )
			|| $stored['task'] !== $task->get_id()
			|| $confirmation->get_task_id() !== $task->get_id()
			|| (int) $stored['user'] !== $user_id
			|| $confirmation->get_user_id() !== $user_id
			|| ! hash_equals( (string) $stored['fingerprint'], $confirmation->get_fingerprint() ) ) {
			throw new CleanupNotConfirmedException( esc_html__( 'This cleanup was not confirmed, or the confirmation was already used.', 'probe-site-doctor' ) );
		}

		if ( ! hash_equals( $confirmation->get_fingerprint(), $this->fingerprint( $task, $this->preview( $task ) ) ) ) {
			throw new CleanupNotConfirmedException( esc_html__( 'The data changed after confirmation. Review the new preview and confirm again.', 'probe-site-doctor' ) );
		}

		$confirmation->mark_consumed( $this );
	}

	/**
	 * Normalised preview.
	 *
	 * @param CleanupTask $task Task.
	 * @return array{items:int,bytes:int|null,summary:string}
	 */
	private function preview( CleanupTask $task ): array {
		$preview = $task->preview();
		return array(
			'items'   => max( 0, (int) ( $preview['items'] ?? 0 ) ),
			'bytes'   => isset( $preview['bytes'] ) ? (int) $preview['bytes'] : null,
			'summary' => (string) ( $preview['summary'] ?? '' ),
		);
	}

	/**
	 * Fingerprint of what the user saw.
	 *
	 * @param CleanupTask         $task    Task.
	 * @param array<string,mixed> $preview Preview.
	 * @return string
	 */
	private function fingerprint( CleanupTask $task, array $preview ): string {
		return hash_hmac( 'sha256', $task->get_id() . '|' . (int) $preview['items'] . '|' . (string) $preview['bytes'], wp_salt( 'nonce' ) );
	}

	/**
	 * Throws unless the user may run cleanups.
	 *
	 * @param int $user_id User.
	 * @return void
	 *
	 * @throws CleanupNotConfirmedException When not allowed.
	 */
	private function assert_capability( int $user_id ): void {
		if ( $user_id < 1 || ! user_can( $user_id, Capabilities::RUN_CLEANUP ) ) {
			throw new CleanupNotConfirmedException( esc_html__( 'You are not allowed to run cleanup operations.', 'probe-site-doctor' ) );
		}
	}

	private function challenge_key( CleanupTask $task, int $user_id ): string {
		return 'probesd_cln_c_' . md5( $user_id . '|' . $task->get_id() );
	}

	private function token_key( string $token ): string {
		return 'probesd_cln_t_' . hash( 'sha256', $token );
	}
}
