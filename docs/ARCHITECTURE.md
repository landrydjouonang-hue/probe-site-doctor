# Architecture

```
probe-site-doctor.php          Bootstrap (old-PHP-safe), constants, activation hooks
uninstall.php               Uninstall entry → Core\Uninstaller
src/
  Plugin.php                Composition root; lazily builds and shares services
  Core/                     Requirements, Installer, Upgrader, Activator, Deactivator,
                            Uninstaller, Capabilities, Settings
  Diagnostics/              ── Diagnostic Engine ──
    Contracts/Check.php     The check interface
    AbstractCheck.php       Base class with good()/info()/warning()/critical()/skipped()
    Result.php              Immutable result value object
    Severity.php            Severity levels (string constants), rank, score weight, labels
    Measurement.php         How evidence is gathered: server | loopback (never browser)
    Contracts/ReportsMeasurement.php  Optional: a check declares its measurement method
    Support/Loopback.php    Anonymous GET of the site's own homepage (timed, no cookies)
    Support/FrontendSnapshot.php  Homepage fetched once per scan, parsed for scripts/styles
    Support/DatabaseInfo.php      Site-scoped information_schema metadata, capped counts
    Support/UpdateInfo.php        Cached update data, auto-update settings, version maths (no remote calls)
    Support/ContentScan.php       Bounded read-only scans of published content (titles, descriptions, alt text, links)
    Impact.php / ManualCleanup.php  Estimated impact; manual instructions (cleanup | update | harden)
    CategoryRegistry.php    Categories (filterable)
    CheckRegistry.php       Check registration and validation
    Engine.php              Runs a single check: timing and exception isolation
    Scanner.php             Scan lifecycle: start → run_check × N → complete; run_all(); prune()
    Checks/
      Performance/  Database/  Configuration/  Extensions/  Seo/
  Cleanup/                  ── Safety framework for any future cleanup feature ──
    Contracts/CleanupTask.php     preview() + execute( Confirmation )
    ConfirmationGuard.php   challenge → typed confirm → single-use token → consume
    Confirmation.php        Proof of confirmation; assert_for() gate inside execute()
    CleanupRunner.php       Only entry point that executes a task (audit hook)
    CleanupRegistry.php     Filter probesd_cleanup_tasks (empty by default)
  Developer/                SystemInfo (system report data),
                            SystemReport (Markdown/JSON export)
  Performance/              PageProfile (fetch + parse one page),
                            PageAnalyzer (what makes it slow + fixes),
                            Vitals (field data: metrics, ratings, collector)
  Storage/                  Schema (dbDelta), ScanRepository, ResultRepository,
                            VitalsRepository
  Reporting/                Score, ReportBuilder,  ── Reporting Engine ──
                            ReportSections (six report sections),
                            ReportDocument (health report data),
                            ReportExporter (self-contained HTML), CheckLabels
  Rest/                     ScansController, VitalsController (probe-site-doctor/v1)
  Admin/                    Admin (menus/assets), DashboardPage, ReportPage,
                            PageSpeedPage, DeveloperPage, HistoryPage,
                            ScanListTable, Actions (admin-post), Notices, View
templates/admin/            dashboard, report, pagespeed, developer screens
templates/report/           document.php — the report body (screen, print, export)
assets/                     admin.css, report.css, developer.css,
                            dashboard.js, report.js, developer.js,
                            vitals.js (public, only while field data is enabled)
```

## Layers

1. **Checks** only know how to examine one thing and return a `Result`. They never touch storage.
2. **Engine** runs one check. Any `Throwable` becomes a `Result` with status `error`, so a broken check never aborts a scan. It stamps category and duration, then applies the `probesd_check_result` filter.
3. **Scanner** manages the scan lifecycle and persistence through the repositories.
4. **ReportBuilder** turns a stored scan into the report array (summary, score, categories, results, previous score). The dashboard and `GET /scans/{id}` both use this same array.
5. **ReportDocument** turns that array into the Website Health Report: meta, overall diagnostic summary, priority recommendations and the six sections from `ReportSections`. **ReportExporter** renders the same document as one self-contained HTML file. Both are presentation-only and never run a check.

## Website Health Report

```
ReportBuilder::build( $scan )        report array (categories = diagnostic categories)
  └ ReportDocument::build( $report ) document array (sections = report sections)
      ├ templates/report/document.php  one body for screen, print and export
      ├ ReportPage                     Site Doctor → Health Report (?scan=ID)
      └ ReportExporter::render()       admin-post download, stylesheet inlined
```

`ReportSections` defines the six sections in report order: `performance`, `database`, `extensions`, `configuration`, `security`, `seo`. Five follow the diagnostic categories; `security` is carved out of Configuration (the eight security-related indicator checks) so PHP and WordPress versions stay under Configuration. Each section carries its own scope wording, and the report repeats it above that section's findings. Filters: `probesd_report_sections`, `probesd_report_section_for_check`.

The score is always shown with `Score::disclaimer()` — not a security guarantee, not a certification — on the dashboard, in the report summary and in the report footer.

Printing is the PDF path: `assets/css/report.css` carries `@page`, per-section page breaks, break-inside rules for findings and tables, and hides admin chrome. The download is a single HTML file with the stylesheet inlined and no external references; it is capability-checked (`probesd_view_reports`), nonce-bound to the scan, and writes nothing to disk.

## Scan lifecycle

```
POST /scans                   → Scanner::start()     creates a "running" scan, returns applicable check IDs
POST /scans/{id}/checks/{c}   → Scanner::run_check() one request per check (progress bar, no timeouts)
POST /scans/{id}/complete     → Scanner::complete()  tallies, scores, marks "completed", prunes
```

- Only one scan may run at a time. `start()` returns HTTP 409 while another scan has been active within `Scanner::IDLE_TIMEOUT` (120 s). Scans idle for longer are marked `abandoned`.
- Without JavaScript the form posts to `admin-post.php?action=probesd_run_scan`, which calls `Scanner::run_all()` in a single request.

## Measurement methods

Each result records how its evidence was obtained (`results.measurement`):

| Method | Meaning | Used by |
|---|---|---|
| `server` | Reads settings, files, PHP state or the database | Most checks (the `AbstractCheck` default) |
| `loopback` | The server makes anonymous GET requests to its own public URLs and analyses the responses | page-cache, render-blocking-assets, asset-weight, https, xmlrpc, rest-api, version-visibility, security-indicators, indexing, sitemap, page-titles, meta-descriptions, image-alt-text, internal-links |

No check measures in a browser, and no check reports LCP, INP, CLS or a "page-load time". Loopback timings are server-to-itself only. Browser metrics exist in exactly one place — `Performance\Vitals`, the optional field-data collector — and are never mixed into scan findings or the health score. The dashboard shows a badge on every finding and a "How findings are measured" panel.

`FrontendSnapshot` caches the parsed homepage in the transient `probesd_frontend_snapshot` for 10 minutes, so the asset checks share one request. The cache is cleared on `probesd_scan_started`. It parses with core's `WP_HTML_Tag_Processor`, resolves local `.js`/`.css` URLs to files inside ABSPATH/WP_CONTENT_DIR (with traversal guarded), and reports their on-disk size. It never fetches third-party files.

## Estimated impact and manual cleanup

A result can carry two optional pieces of guidance, set by the check with `->with_impact( Impact::MEDIUM, $why )` and `->with_cleanup( ManualCleanup )`. Both are stored as JSON (`results.impact`, `results.cleanup`) and exposed through REST.

On the dashboard, a finding reads: **Finding** (title) · **Severity** · Explanation · **Estimated impact** · **Recommended action** · **Optional manual cleanup** (or **How to update manually** when the instructions are update steps, ManualCleanup::as_update()). The cleanup section is collapsed by default and shows a warning (red when destructive). Its commands can be copied but are never executed. Rules for real cleanup features are in [CLEANUP-POLICY.md](CLEANUP-POLICY.md).

## Update recommendations

A check can add prioritised entries under `data['update_recommendations']`:

```php
array(
    'priority'  => 'high',   // high | medium | blocked | low
    'component' => 'plugin', // core | plugin | theme | php
    'name'      => 'Some Plugin',
    'installed' => '1.2.0',
    'available' => '1.2.1',
    'change'    => 'patch',  // major | minor | patch, or '' when the scale does not apply
    'note'      => 'Tested up to WordPress 7.1',
)
```

`ReportBuilder` collects them from every finding into `report['updates']` (sorted by priority, via `UpdateInfo::sort_recommendations()`), which the dashboard renders as one panel and REST exposes as `updates`.

**No check installs updates**, contacts WordPress.org, or triggers an update check. `UpdateInfo` only reads the `update_*` site transients, plugin/theme headers and local readme files.

## Configuration indicators

The Configuration category holds settings-level checks, including security-related ones (debug output, file editing, HTTPS, administrator accounts, XML-RPC, REST exposure, version visibility, keys/salts and similar).

Rules for these checks:

- **Indicators, not an audit.** Every finding includes `AbstractCheck::indicator_note()`: settings are read, nothing is scanned for malware, vulnerable code or intrusions, and a clean result does not prove the site is secure. The category description says the same and is rendered above the findings.
- **Non-invasive.** Server-side checks read constants, options and file metadata. Probe-based checks make anonymous GET requests to the site's own endpoints (`xmlrpc.php`, the REST root and users endpoint, `readme.html`, the uploads folder) and are declared `Measurement::LOOPBACK`. No POST, no authentication, no XML-RPC method call, and nothing is uploaded — which is why PHP execution in the uploads folder is deliberately *not* tested and is listed as such in the finding.
- **Never changed automatically.** Fixes are `ManualCleanup::as_hardening()` instructions, rendered as "How to change this manually" with a notice that Site Doctor never changes settings. The plugin registers no filter that alters XML-RPC, REST or registration behaviour; the test suite asserts this.
- **Honest weighting.** Obscurity-level items (version visibility, default table prefix, display names) are Information with Low impact and say so. Only real exposure (plain HTTP in production, placeholder salts, world-writable wp-config.php, registrations becoming administrators, on-screen errors) is Critical.

## Technical SEO checks

The SEO category checks technical markup and settings only. Rules:

- **Bounded work.** `Support\ContentScan` caps every scan (default 200–300 published items, 300 distinct links) and reports `posts_scanned` / `capped` so findings can say what was examined. Broken-link verification issues at most `verify_requests` (default 20) loopback GETs; the rest are reported as "not verified" rather than assumed working.
- **No false positives on links.** A link is only called broken when it resolves to a post that is not published (database, no request needed) or when a request returns 404/410. External links are never requested.
- **Honest alt-text rule.** Only a completely missing `alt` attribute counts; `alt=""` is correct markup for decorative images and is never reported.
- **Detectability is stated.** Meta descriptions exist per page only when a supported SEO plugin stores them in post meta (Yoast, Rank Math, SEOPress, The SEO Framework). Otherwise the finding says the per-page part is not detectable instead of guessing.
- **Scope wording.** Every finding appends `AbstractCheck::seo_scope_note()`: technical check only, no content quality, keywords, rankings, backlinks or competitors, and no replacement for an SEO platform.
- **Nothing is edited.** Fixes use `ManualCleanup::as_content_fix()`, rendered as "How to fix this manually" with the notice that Site Doctor never edits content.

## Thresholds

`AbstractCheck::thresholds( $defaults )` runs the `probesd_check_thresholds` filter (`$thresholds, $check_id`). Only known keys with numeric values are accepted.

## Result status vs severity

| status | meaning | scored |
|---|---|---|
| `completed` | check ran; `severity` is the finding | Good / Warning / Critical yes, Information no |
| `skipped` | check ran but could not evaluate (e.g. no update data yet) | no |
| `error` | check threw | no |

## Hooks

| Hook | Type | Purpose |
|---|---|---|
| `probesd_register_checks` | action (`CheckRegistry`) | Register custom checks |
| `probesd_checks` | filter | Final say over the check list |
| `probesd_categories` | filter | Add/rename categories |
| `probesd_before_check` | action (`Check`) | Before a check runs |
| `probesd_check_result` | filter (`Result`, `Check`) | Alter a result before storage |
| `probesd_scan_started` | action (`int $scan_id`, `string[] $checks`) | Scan started |
| `probesd_scan_completed` | action (`array $scan`) | Scan completed (future: notifications) |
| `probesd_health_score` | filter (`int`, `array $counts`) | Adjust the score |
| `probesd_scan_retention` | filter (`int`) | Completed scans to keep |
| `probesd_php_eol_dates` | filter (`array`) | PHP branch EOL dates |
| `probesd_check_thresholds` | filter (`array`, `string $check_id`) | Per-check thresholds |
| `probesd_loopback_args` | filter (`array $args`, `string $url`) | Loopback request args (e.g. Basic Auth on staging) |
| `probesd_report_sections` | filter (`array`) | Report sections and their scope wording |
| `probesd_report_section_for_check` | filter (`string $section`, `string $check_id`, `string $category`) | Which report section a finding belongs to |
| `probesd_report_export_html` | filter (`string $html`, `array $document`) | The downloaded report file |
| `probesd_system_info` | filter (`array $groups`) | Developer system-information groups |
| `probesd_system_report_markdown` | filter (`string $markdown`, `array $data`) | The Markdown diagnostic export |
| `probesd_system_report_data` | filter (`array $data`) | The JSON diagnostic export |
| `probesd_web_vitals_enabled` | filter (`bool`) | Whether browser field data is collected |
| `probesd_cleanup_tasks` | filter (`CleanupTask[]`) | Register cleanup tasks (still need explicit confirmation) |
| `probesd_cleanup_executed` | action (`string $task_id`, `int $user_id`, `int $affected`, `array $preview`) | Audit hook after a confirmed cleanup |
| `probesd_loaded` | action (`Plugin`) | Plugin booted |
| `probesd_deactivated` | action | Plugin deactivated |

## Security

- Every REST route has a `permission_callback` (custom capabilities). REST uses cookie auth plus the `wp_rest` nonce through `wp.apiFetch`.
- `admin-post` handlers check the capability and `check_admin_referer()`. There are no `nopriv` handlers.
- All SQL goes through `$wpdb->prepare()` or `$wpdb->insert/update/delete`. Table names come from `$wpdb->prefix` only.
- All output is escaped. Notices use whitelisted codes, never raw query text.
- Checks must not put secrets in results. The environment snapshot contains only versions and counts.
- `POST probe-site-doctor/v1/vitals` is the only public route in the plugin. It is registered unconditionally but refuses everything with HTTP 404 unless field data collection is enabled, then requires a same-origin `Origin`/`Referer`, accepts only the five known metrics within plausible ranges, stores no visitor identifier, and is rate limited per address (the address is hashed into a transient key, never stored). `GET` on the same route requires `probesd_view_reports`.
- Enabling the collector, changing sampling/retention or deleting collected data requires `manage_options`, because it changes what runs on the public site.
- The Developer screen and its exports never read authentication keys, salts, the database password or the debug log's contents, and only whitelisted constants are reported (names ending in KEY/SALT/PASSWORD/SECRET/TOKEN are refused regardless). A diagnostic export still describes the server in detail — treat the file as sensitive.
- The report download requires `probesd_view_reports` and a nonce bound to that scan ID, is sent with `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` and `X-Robots-Tag: noindex`, and writes no file. A report contains server and plugin details, so treat the downloaded file as sensitive.
