# Cleanup policy

Probe Site Doctor diagnoses. **It never deletes or changes site data on its own, never installs updates, and never changes configuration or security settings.**

Updates follow the same principle as cleanup: findings recommend and explain them (see README), but installing WordPress, plugin, theme or PHP updates is always the site owner's action. No code path in the plugin calls the WordPress upgrader or the update APIs.

## What the plugin does today (v0.5.0)

- Every check is read-only. The test suite intercepts every SQL statement the checks issue and fails if any of them writes outside Site Doctor's own tables (its result storage and its homepage-snapshot cache).
- Update findings can include **How to update manually**: steps and example WP-CLI commands. Nothing is installed, no update check is triggered, and WordPress.org is never contacted; the tests assert both.
- Configuration findings can include **How to change this manually**: steps and snippets for wp-config.php, PHP or the server. No setting, file permission, user, role or server configuration is ever changed, and the plugin adds no filter that alters XML-RPC, REST or registration behaviour. The tests assert that the configuration checks issue no write query and leave the security-related options untouched.
- Database findings can include **Optional manual cleanup**: a summary, ordered steps, and example WP-CLI, SQL or PHP commands.
  - These are text only. The plugin never runs them, and the dashboard can only copy them to the clipboard.
  - Destructive steps show a red notice ("These steps permanently delete data… Make and test a full backup first").
  - Example commands never name real data that could still be in use. Templates use placeholders such as `REPLACE_WITH_UNUSED_META_KEY` and `REPLACE_WITH_LEFTOVER_TABLE_NAME`.
  - An example names a real option only when a name-based hint links that option to an installed but inactive plugin.
- No cleanup task is registered, and there is no REST route, admin action or cron event that performs cleanup.

## Rules for any future cleanup feature

Any automated cleanup must use `ProbeSiteDoctor\Cleanup` and follow every rule below. Not running automatically is a hard requirement.

1. **Never automatic.** Not during scans, not on a schedule, not on activation or upgrade, and not as a side effect of another action.
2. **Capability.** The user needs `probesd_run_cleanup` (granted to administrators).
3. **Preview first.** `ConfirmationGuard::challenge()` computes a read-only `CleanupTask::preview()` (item count and bytes) and fingerprints it.
4. **Explicit, typed confirmation.** The user must type the exact phrase shown, which contains the number of items (e.g. `DELETE 1234`), and must tick an "I have a current backup" acknowledgement. `ConfirmationGuard::confirm()` rejects a wrong phrase, a missing acknowledgement, or an expired challenge (10 minutes).
5. **No stale confirmations.** If the preview changes between challenge and confirm, or between confirm and run, the operation is refused and the user must review the new preview.
6. **Single use and bound to user and task.** `confirm()` issues a token (valid for 5 minutes) that `CleanupRunner::run()` consumes. It cannot be reused, used by another user, or used for another task.
7. **Only through the runner.** `CleanupTask::execute( Confirmation )` must call `$confirmation->assert_for( $this->get_id() )` first. That call fails unless the guard consumed the confirmation for this task.
8. **Audit.** `CleanupRunner` fires `probesd_cleanup_executed( $task_id, $user_id, $affected, $preview )`.
9. **UI and transport.** A future screen must show the preview and the consequences in plain language, and must not pre-fill the phrase. It must submit with a nonce over POST and treat nothing as confirmed until the server-side guard agrees.

## Registering a task (future)

```php
add_filter( 'probesd_cleanup_tasks', function ( array $tasks ) {
	$tasks[] = new My_Expired_Transients_Task(); // implements ProbeSiteDoctor\Cleanup\Contracts\CleanupTask
	return $tasks;
} );
```

Registering a task does not expose it anywhere. It still needs a UI that follows the rules above.
