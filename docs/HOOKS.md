# Hooks

Every hook the plugin fires, with what it is for. Checks, categories, report
sections, thresholds and exports are all extensible without touching the plugin.

## Checks and scanning

| Hook | Signature | Purpose |
|---|---|---|
| `probesd_register_checks` | action ( `CheckRegistry $registry` ) | Register your own checks |
| `probesd_checks` | filter ( `Check[] $checks` ) | Final say over the check list (remove, reorder, replace) |
| `probesd_categories` | filter ( `array $categories` ) | Add or rename categories |
| `probesd_check_thresholds` | filter ( `array $thresholds`, `string $check_id` ) | Tune a single check's numbers |
| `probesd_before_check` | action ( `Check $check` ) | Runs immediately before a check |
| `probesd_check_result` | filter ( `Result $result`, `Check $check` ) | Alter a result before it is stored |
| `probesd_scan_started` | action ( `int $scan_id`, `string[] $check_ids` ) | A scan began |
| `probesd_scan_completed` | action ( `array $scan` ) | A scan finished — a good place for notifications |
| `probesd_scan_retention` | filter ( `int $keep` ) | How many completed scans to keep (default 20) |
| `probesd_health_score` | filter ( `int $score`, `array $counts` ) | Adjust the calculated score |
| `probesd_php_eol_dates` | filter ( `array $dates` ) | PHP branch end-of-life dates |
| `probesd_loopback_args` | filter ( `array $args`, `string $url` ) | Loopback request arguments, e.g. Basic Auth on staging |

### Registering a check

```php
add_action( 'probesd_register_checks', function ( $registry ) {
	$registry->register( new My_Plugin\Checks\Backup_Age_Check() );
} );
```

See [WRITING-CHECKS.md](WRITING-CHECKS.md) for the interface and the result
factories.

### Tuning a threshold

```php
add_filter( 'probesd_check_thresholds', function ( $thresholds, $check_id ) {
	if ( 'developer.memory-limit' === $check_id ) {
		$thresholds['warning_mb'] = 256; // this client's sites need more headroom
	}
	return $thresholds;
}, 10, 2 );
```

Only keys that already exist, with numeric values, are accepted.

## Reports

| Hook | Signature | Purpose |
|---|---|---|
| `probesd_report_sections` | filter ( `array $sections` ) | The report's sections and their scope wording |
| `probesd_report_section_for_check` | filter ( `string $section`, `string $check_id`, `string $category` ) | Which section a finding belongs to |
| `probesd_report_export_html` | filter ( `string $html`, `array $document` ) | The downloadable self-contained report file |

```php
// Put a custom check in its own report section.
add_filter( 'probesd_report_sections', function ( $sections ) {
	$sections['backups'] = array(
		'label' => 'Backups',
		'intro' => 'Backup freshness and destination.',
		'scope' => 'Reads backup plugin metadata only; it does not verify that a restore works.',
	);
	return $sections;
} );

add_filter( 'probesd_report_section_for_check', function ( $section, $check_id ) {
	return 0 === strpos( $check_id, 'backups.' ) ? 'backups' : $section;
}, 10, 2 );
```

## Developer diagnostics and exports

| Hook | Signature | Purpose |
|---|---|---|
| `probesd_system_info` | filter ( `array $groups` ) | Add or change system-information groups |
| `probesd_system_report_markdown` | filter ( `string $markdown`, `array $data` ) | The Markdown diagnostic export |
| `probesd_system_report_data` | filter ( `array $data` ) | The JSON diagnostic export |

```php
add_filter( 'probesd_system_info', function ( $groups ) {
	$groups['hosting'] = array(
		'label'  => 'Hosting',
		'note'   => 'Values provided by the host.',
		'fields' => array( 'Plan' => getenv( 'HOST_PLAN' ), 'Region' => getenv( 'HOST_REGION' ) ),
		'rows'   => array(),
	);
	return $groups;
} );
```

Anything you add is exported too — do not add secrets.

## Field data

| Hook | Signature | Purpose |
|---|---|---|
| `probesd_web_vitals_enabled` | filter ( `bool $enabled` ) | Force collection on or off in code |

```php
// Never collect on anything but production, whatever the setting says.
add_filter( 'probesd_web_vitals_enabled', function ( $enabled ) {
	return $enabled && 'production' === wp_get_environment_type();
} );
```

## Cleanup (no tasks ship yet)

| Hook | Signature | Purpose |
|---|---|---|
| `probesd_cleanup_tasks` | filter ( `CleanupTask[] $tasks` ) | Register a cleanup task |
| `probesd_cleanup_executed` | action ( `string $task_id`, `int $user_id`, `int $affected`, `array $preview` ) | Audit hook after a confirmed cleanup |

Any cleanup task must still pass the typed confirmation described in
[CLEANUP-POLICY.md](CLEANUP-POLICY.md).

## Lifecycle

| Hook | Signature | Purpose |
|---|---|---|
| `probesd_loaded` | action ( `Plugin $plugin` ) | The plugin finished booting |
| `probesd_deactivated` | action | The plugin was deactivated |

## Core hooks Site Doctor reads

These are WordPress hooks the plugin *applies* to interpret configuration
honestly, not hooks it adds: `xmlrpc_enabled`, `wp_sitemaps_enabled`,
`https_local_ssl_verify`, `big_image_size_threshold`, `jpeg_quality`,
`wp_editor_set_quality`, `image_editor_output_format`,
`allow_major_auto_core_updates`, `allow_minor_auto_core_updates`. Site Doctor
never adds filters that change their behaviour.
