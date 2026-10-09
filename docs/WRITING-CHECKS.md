# Writing a check

A check is a class implementing `ProbeSiteDoctor\Diagnostics\Contracts\Check`. Extending `ProbeSiteDoctor\Diagnostics\AbstractCheck` is recommended.

```php
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;

final class Cron_Lag_Check extends AbstractCheck {

	public function get_id(): string       { return 'myplugin.cron-lag'; }         // unique, lowercase, a-z0-9 . _ -
	public function get_category(): string { return CategoryRegistry::CONFIGURATION; }
	public function get_label(): string    { return __( 'Cron lag', 'my-plugin' ); }
	public function get_description(): string {
		return __( 'Checks whether scheduled events run on time.', 'my-plugin' );
	}

	// Optional: leave the check out of scans where it makes no sense.
	public function is_applicable(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	public function run(): Result {
		$late = 0; // ... measure ...

		if ( $late > 10 ) {
			return $this->warning(
				__( 'Scheduled events are running late', 'my-plugin' ),         // title
				sprintf( __( '%d events are overdue.', 'my-plugin' ), $late ),   // message
				__( 'Set up a real server cron job.', 'my-plugin' ),            // recommendation
				array( 'late' => $late )                                         // details (JSON-serialisable)
			);
		}
		return $this->good( __( 'Scheduled events run on time', 'my-plugin' ) );
	}
}

add_action( 'probesd_register_checks', function ( $registry ) {
	$registry->register( new Cron_Lag_Check() );
} );
```

## Choosing a severity

| Severity | Use when | Score |
|---|---|---|
| Good | The check passed. | 100 |
| Information | Neutral or context-dependent, e.g. only relevant for busy sites, or expected in development. | not scored |
| Warning | Should be fixed; it hurts performance, maintainability or visibility, but nothing is broken yet. | 50 |
| Critical | Needs attention as a priority: active exposure, unsupported software, or the site is hidden from search. | 0 |

Use `$this->skipped( $reason )` when the check cannot evaluate (missing data). Use `$this->is_non_production()` to soften findings on `local`, `development` and `staging` environments.

## Measurement and thresholds

Checks are server-side by default. A check that requests the site's own homepage should declare it:

```php
public function get_measurement(): string { return Measurement::LOOPBACK; }
```

Reuse `Support\FrontendSnapshot::get()` for homepage HTML and asset data rather than making another request. Use `Support\Loopback::get()` only when you need raw timings or headers.

Read thresholds with `$this->thresholds( array( 'max_ms' => 500 ) )` so site owners can tune them through the `probesd_check_thresholds` filter. Use `$this->worst( $a, $b )` to combine severities, and `$this->result( $severity, … )` to build a result with a computed severity.

**Never present server-side or loopback numbers as browser page-load performance.** Say what was measured and what was not.

## Estimated impact and manual cleanup

For findings that can be fixed by removing or changing data, add guidance:

```php
return $this->warning( $title, $explanation, $action, $data )
	->with_impact( Impact::MEDIUM, __( 'Why fixing this helps, and how much.', 'my-plugin' ) )
	->with_cleanup(
		( new ManualCleanup( __( 'What the cleanup does and whether it can be undone.', 'my-plugin' ), array( __( 'Back up the database.', 'my-plugin' ) ) ) )
			->with_command( __( 'Label', 'my-plugin' ), 'wp transient delete --expired', ManualCleanup::TYPE_WP_CLI )
	);
```

- A check must **never** perform the cleanup itself. Commands are shown for the user to run manually.
- Example commands must not name data that could still be in use; use placeholders such as `REPLACE_WITH_…`.
- Pass `false` as the third `ManualCleanup` argument only when nothing is deleted (e.g. `OPTIMIZE TABLE`).
- Automated cleanup is only allowed through `ProbeSiteDoctor\Cleanup` with explicit confirmation ([CLEANUP-POLICY.md](CLEANUP-POLICY.md)).
- For configuration and security-related findings, append `$this->indicator_note()` to the message (indicators, not an audit) and offer fixes with `->as_hardening()`. Never change a setting from a check, and never claim a site is secure.
- For update guidance, call `->as_update()` on the `ManualCleanup` and add entries to `data['update_recommendations']` (see ARCHITECTURE.md). Never install updates from a check: read `Support\UpdateInfo` instead of calling the WordPress upgrader or update APIs.

## Rules

- **Read-only.** Never modify options, files or the database.
- **Fast.** Aim for well under a second. Never contact third-party services. Loopback requests to the site's own homepage are allowed when the check needs them, and must be declared as `Measurement::LOOPBACK`.
- **No secrets** in titles, messages or `data`.
- **Honest scope.** Report only what was measured. Do not imply security coverage the check does not provide.
- Exceptions are caught by the engine and reported as a failed check, but avoid relying on that.
- `data` is rendered generically: scalars as text, lists as comma lists, lists of records as tables. Keys named `bytes` or `*_bytes` are formatted as sizes, and `*_ms` keys as milliseconds.
