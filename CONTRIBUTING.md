# Contributing

Thanks for looking at Probe Site Doctor. This is a diagnostics plugin, so the bar
for changes is a little unusual: **a check must be honest about what it can and
cannot know**, and nothing in the plugin may change the site it is inspecting.

## Ground rules

1. **Read-only.** A scan must never write to anything outside the plugin's own
   tables. Cleanup, updates, settings changes and content edits are always shown
   as instructions for a human to run. See [docs/CLEANUP-POLICY.md](docs/CLEANUP-POLICY.md).
2. **No third-party requests.** The plugin talks to your own site and to nothing
   else. Update data comes from the transients WordPress already keeps.
3. **Say what was measured.** Every finding states its evidence and its limits.
   Server-side numbers are never presented as browser measurements, and
   configuration indicators are never presented as a security audit.
4. **No secrets.** Keys, salts, passwords and log contents are never read,
   stored or exported.

## Development setup

No build step and no Composer dependencies — the plugin ships plain PHP with its
own PSR-4 autoloader.

```bash
git clone https://github.com/<you>/probe-site-doctor.git wp-content/plugins/probe-site-doctor
```

Requirements: WordPress 6.4+, PHP 8.0+. The code targets PHP 8.0, so no enums,
`readonly`, `never` or first-class callable syntax.

## Adding a check

[docs/WRITING-CHECKS.md](docs/WRITING-CHECKS.md) has the full walkthrough. In short:

```php
final class My_Check extends AbstractCheck {
	public function get_id(): string { return 'developer.my-check'; }
	public function get_category(): string { return CategoryRegistry::DEVELOPER; }
	public function get_label(): string { return __( 'My check', 'probe-site-doctor' ); }
	public function get_description(): string { return __( 'What it looks at.', 'probe-site-doctor' ); }

	public function run(): Result {
		return $this->good( __( 'All good', 'probe-site-doctor' ), __( 'What was measured.', 'probe-site-doctor' ) );
	}
}
```

Register it with `probesd_register_checks` from your own plugin, or add it to
`Plugin::builtin_checks()` in a pull request.

A check that ships with the plugin needs:

- a severity that is defensible (obscurity items are Information, not Critical);
- an `Impact` when it reports a problem;
- manual instructions when there is something to do;
- a message that states the limits of what was measured;
- a threshold set through `thresholds()` if it compares numbers;
- tests (see below).

## Coding standards

WordPress Coding Standards, with the plugin's own conventions:

- Yoda conditions, tabs, full `__()`/`esc_*` usage, `@since` on new hooks.
- Everything user-visible is translatable with the `probe-site-doctor` text domain.
- All SQL goes through `$wpdb->prepare()` or `$wpdb->insert/update/delete`;
  table names come from `$wpdb->prefix` only.
- Escape on output, never on input.

```bash
composer global require wp-coding-standards/wpcs
phpcs --standard=WordPress wp-content/plugins/probe-site-doctor
```

## Tests

The plugin's test suites are plain PHP scripts that boot WordPress and assert
against a real install, one suite per phase of development. They cover the
registry, every check, storage, REST, the report, the exports, the page
analyser and the field-data collector.

Run them against a local install (XAMPP, Local, wp-env, …):

```bash
php sd-test.php      # framework, storage, REST, capabilities
php sd-test-p8.php   # developer diagnostics, exports, page speed, field data
```

Any change to a check must keep every suite green, and new behaviour needs new
assertions. Tests must clean up after themselves: seeded posts, options and
rows are removed, and any setting they toggle is restored.

## Pull requests

- One topic per pull request.
- Say what you measured and why the severity is what it is.
- Update `readme.txt`, `CHANGELOG.md` and the docs when behaviour changes.
- Do not bump the version in a pull request; releases are cut separately.

## Reporting bugs

Open an issue with the output of **Site Doctor → Developer → Copy report to
clipboard**. It contains the environment, the active plugins and the current
findings — and no secrets.
