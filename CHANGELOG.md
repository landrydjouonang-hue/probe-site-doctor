# Changelog

All notable changes to Probe Site Doctor are documented here. Format follows [Keep a Changelog](https://keepachangelog.com/); versions follow SemVer.

## [0.9.0] — 2026-10-01 — Renamed to Probe Site Doctor

The plugin was called "WP Site Doctor". WordPress.org does not allow "wp" in a
plugin name or slug, which the official Plugin Check tool reports as a
restricted term, so the name, the slug and the internal identifiers changed
together.

### Changed
- **Name**: WP Site Doctor → **Probe Site Doctor**. Admin menu, page titles and the plugin header follow.
- **Slug and text domain**: `wp-site-doctor` → `probe-site-doctor`. The folder and main file are `probe-site-doctor/probe-site-doctor.php`.
- **Namespace**: `SiteDoctor\` → `ProbeSiteDoctor\`.
- **Constants**: `SITEDOCTOR_*` → `PROBESD_*`. Accessor `sitedoctor()` → `probe_site_doctor()`.
- **Tables**: `{prefix}sitedoctor_scans|results|vitals` → `{prefix}probesd_*`.
- **Options**: `sitedoctor_settings|version|db_version` → `probesd_*`.
- **Capabilities**: `sitedoctor_view_reports|run_scans|run_cleanup` → `probesd_*`.
- **REST namespace**: `site-doctor/v1` → `probe-site-doctor/v1`.
- **Hooks**: every `sitedoctor_*` filter and action is now `probesd_*` (`probesd_register_checks`, `probesd_health_score`, `probesd_report_sections`, `probesd_web_vitals_enabled`, and the rest).
- **admin-post actions**, script handles, CSS classes and DOM ids follow the same prefix.

### Added
- `Core\Migration`: carries an existing install over in one step — the three tables are **renamed, not copied**, so stored scans, results and field data survive intact; options keep their values; every role's capabilities are swapped; legacy transients are dropped. It runs before the schema install, once, and is a no-op afterwards. Action: `probesd_migrated`.
- The uninstaller also removes legacy tables, options, capabilities and transients, for a site restored from a backup that never migrated.

### Fixed (from the Plugin Check run)
- Exception messages are escaped (`WordPress.Security.EscapeOutput.ExceptionNotEscaped`, 14 occurrences).
- `LIKE` wildcards are passed as replacement parameters in the image queries, not interpolated.
- The mysqli client-library read was replaced with the mysqli extension version, which is not a restricted function.
- `load_plugin_textdomain()` removed: WordPress.org loads translations automatically, and the manual call is what triggers the "translation loading too early" notice.
- `$_POST`, `$_GET` and `$_SERVER` reads are explicitly sanitised at the point of use.
- Line endings normalised to LF across every file.
- Template-local variables are prefixed, and the WordPress core filters the plugin reads carry a documented `phpcs:ignore` instead of looking like unprefixed hooks.

### Upgrading
Nothing to do: activating 0.9.0 migrates the data. Code that integrated with the
old hook, capability or REST names needs updating — the table above maps each
one. The plugin keeps its own data under the new names only.

## [0.8.0] — 2026-09-25 — Phase 8: Developer diagnostics, exports and page speed

### Added
- **Developer screen** (`Site Doctor → Developer`): the full system report, read live — environment, active theme, active plugins (with must-use plugins and drop-ins), PHP extensions, PHP configuration and memory limit, WordPress memory limits, cron status, REST API status, debug information, database information and whitelisted constants. `Developer\SystemInfo`, filter `probesd_system_info`.
- **Exportable diagnostic reports**: copy to clipboard, download as Markdown or JSON, including the latest scan's outstanding findings. `Developer\SystemReport`, filters `probesd_system_report_markdown` and `probesd_system_report_data`, download action `probesd_download_diagnostics` (capability + nonce, nothing written to disk).
- Nine developer checks, new `developer` category and a seventh report section: `developer.environment`, `developer.active-components`, `developer.php-extensions`, `developer.memory-limit`, `developer.wp-memory-limit`, `developer.cron`, `developer.rest-api`, `developer.debug-log`, `developer.database-configuration`.
- **Page Speed screen** (`Site Doctor → Page Speed`): analyses one page at a time (`Performance\PageProfile`, `Performance\PageAnalyzer`) and reports what the server sends plus a prioritised list of what is making that page slow — each cause with what was measured, the Core Web Vital it usually affects, and how to improve it. Also lists the heaviest files and the third-party domains referenced, and links the page to PageSpeed Insights.
- **Core Web Vitals field data** (`Performance\Vitals`, `Storage\VitalsRepository`, `Rest\VitalsController`, `assets/js/vitals.js`): optional first-party collection of LCP, INP, CLS, FCP and TTFB from real visitors' browsers, summarised as 75th percentiles per page, per device and site-wide. Off by default; enabling it, sampling, retention and deleting the data require `manage_options`. No visitor identifier, IP address, user agent or browser storage is used, logged-in users are excluded, and the public route exists only while collection is enabled (same-origin only, range-checked, rate limited).
- Settings gained `web_vitals`, `web_vitals_sampling` and `web_vitals_days` with `Settings::save()`; filter `probesd_web_vitals_enabled`.

### Changed
- Schema version 4: new `{prefix}probesd_vitals` table (path, device, metric, value, timestamp). Dropped on uninstall with the others.
- The performance scope wording now distinguishes three sources — server-side analysis, browser field data and external lab tools — instead of stating that browser metrics are out of scope entirely. Scan findings still never report LCP, INP or CLS.
- Dashboard links to the Page Speed and Developer screens.

### Notes
- The WordPress memory-limit check verifies whether PHP allows the limit to be raised at runtime by attempting it in its own process and restoring the original value, because WordPress applies its constants with `ini_set()`.
- Cron is never triggered: `wp-cron.php` is not requested, since that request would run the due tasks.

## [0.7.0] — 2026-09-25 — Phase 7: Website Health Report

### Added
- **Website Health Report** screen (`Site Doctor → Health Report`, capability `probesd_view_reports`): a professional report generated on demand from any completed scan, with the latest scan shown by default and a picker for stored scans.
- Report structure: header (site, URLs, scan and generation dates, requester, scan reference, plugin version, environment), overall diagnostic summary (score, band, one-sentence verdict, severity counts, scored-check count, movement since the previous scan, how the score is calculated and what it does not mean), priority recommendations (critical first, then warnings, with section, action and impact), section summaries table, the six sections with their findings, environment at scan time, and a footer repeating the disclaimer.
- `Reporting\ReportSections`: the six report sections — Performance, Database, Plugins & Themes, Configuration, Security Indicators, SEO (Technical) — each with its own scope statement. The Configuration category is split so the security-related indicators form their own section while the PHP and WordPress version checks stay under Configuration. Filters: `probesd_report_sections`, `probesd_report_section_for_check`.
- `Reporting\ReportDocument`: assembles the document (meta, summary, section summaries and assessments, priority recommendations) from a stored report. Read-only.
- `Reporting\ReportExporter`: renders the report as one self-contained HTML file with its stylesheet inline and no external references; downloaded through `admin-post.php` with a capability check and a scan-bound nonce, `Content-Disposition: attachment`, and nothing written to disk. Filter: `probesd_report_export_html`.
- `Score::disclaimer()` and `Score::explanation()`: the score is stated as not a security guarantee and not a certification, printed next to the score on the dashboard, in the report summary and in the report footer.
- Print stylesheet (`assets/css/report.css`): each section starts on a new page, findings and tables are kept whole where possible, and admin chrome is excluded — so the browser's own "Save as PDF" produces the PDF. No PDF engine is bundled.
- Each finding in the report shows severity, check name, measurement type, explanation, estimated impact, recommended action and manual steps; checks that passed and checks that could not be evaluated are listed in compact tables per section.
- Dashboard: "View the full health report" button and a Health Report link in the title bar. Scan History: "Printable report" and "Download" row actions on completed scans.
- `Reporting\CheckLabels` (shared label map including retired check IDs) and `Admin\View::capture()`.

### Fixed
- Requesting a report for a scan that does not exist or has not finished now falls back to the latest report, which is what the notice on screen already claimed.

### Notes
- No database change: schema version stays at 3, and the report layer stores nothing.

## [0.6.0] — 2026-09-25 — Phase 6: Technical SEO

### Added
- New technical SEO checks: page titles (untitled published content, duplicate titles, homepage title element, default tagline), meta descriptions (homepage tag plus per-page values where a supported SEO plugin stores them in post meta), broken internal links (links to unpublished content from the database, plus a capped sample verified by request), image alt text (media library, published content and homepage markup), indexing configuration indicators (robots.txt rules, homepage robots meta, canonical link, per-post noindex flags) and sitemap availability (core and plugin sitemap URLs, robots.txt reference).
- `Support\ContentScan`: bounded read-only scans of published content (titles, duplicates, description meta, images without alt, internal links) with SEO-plugin detection.
- The homepage snapshot now also records the title, meta description, robots meta, canonical link, image alt coverage and internal links.
- Content instructions (`ManualCleanup::as_content_fix()`) render as "How to fix this manually" with the notice that Site Doctor never edits content.
- Shared scope wording on every SEO finding: a technical check that does not assess content quality, keywords, rankings, backlinks or competitors, and does not replace an SEO platform.

### Fixed
- The canonical `<link>` was never detected, because the stylesheet branch of the HTML parser returned before reaching it.

## [0.5.0] — 2026-09-23 — Phase 5: Configuration Indicators

### Added
- New non-invasive configuration checks: file editing settings (DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS), HTTPS configuration (URLs, FORCE_SSL_ADMIN, HTTP-to-HTTPS redirect, HSTS), administrator account indicators (count, guessable logins, registration role), XML-RPC status (reachability and pingbacks), REST API exposure indicators (anonymous access and user listing), WordPress version visibility (generator tag, ?ver= strings, readme.html) and security-related configuration indicators (keys/salts, wp-config.php permissions, uploads directory listing, table prefix, application passwords over HTTP).
- Shared classification wording on every configuration finding: a configuration indicator, not a security audit; no malware, vulnerable-code or intrusion scanning; a clean result does not mean the site is secure.
- Category scope notes are now shown above the findings of each area on the dashboard.
- Hardening instructions (`ManualCleanup::as_hardening()`) render as "How to change this manually" with the notice that Site Doctor never changes settings automatically.
- The debug-mode check gained manual steps, an impact estimate and SCRIPT_DEBUG reporting.
- The homepage snapshot now also records generator meta tags and ?ver= asset counts.

### Fixed
- XML-RPC detection: WordPress answers a GET to xmlrpc.php with HTTP 405, which was previously read as "blocked". Reachability is now determined by the response body, so an open XML-RPC endpoint is no longer reported as unavailable.

## [0.4.0] — 2026-09-23 — Phase 4: Plugins, Themes & Versions

### Added
- New checks: Outdated plugins, Outdated themes, Inactive themes and Plugin compatibility indicators (requirements, "Tested up to", plugin dependencies, updates blocked by PHP/WordPress).
- Update recommendations: each finding can contribute prioritised entries (High / Medium / Low / Blocked) with installed and recommended versions, change type (major/minor/patch) and notes. The dashboard shows them in one "Update recommendations" panel; the report and REST expose them as `updates`.
- Manual instructions can now be update steps (`ManualCleanup::as_update()`), shown as "How to update manually" with the notice that Site Doctor never updates anything automatically.
- `Support\UpdateInfo`: normalised read-only access to WordPress's cached update data, automatic-update settings, version-change classification and "Tested up to" from local readme files. No request ever goes to WordPress.org.
- WordPress core version check now also reports whether automatic maintenance/security releases are enabled, and warns when they are off.
- PHP version check now reports updates blocked by the running PHP version and recommends a target branch that satisfies every installed component.
- Inactive plugins check now flags inactive plugins that are also outdated.

### Changed
- The combined `extensions.pending-updates` check is retired; plugins and themes have separate checks. Older stored reports still show a label for it.
- Category description for Plugins & Themes states that updates are recommended, never installed.

## [0.3.0] — 2026-09-22 — Phase 3: Database Diagnostics

### Added
- New checks: Post revisions, Transients (expired, autoloaded, cleanup event), Database size (with growth since the previous scan), Large tables (log/session/stats detection), Post meta volume (top keys, index check), Orphaned metadata (post/comment/term/user meta, term relationships, revisions; capped counts; post ID 0 reported separately).
- Estimated impact (Low / Medium / High, with reasoning) and optional manual cleanup (steps plus example WP-CLI/SQL/PHP commands) on database findings. Both are stored with results and exposed through REST.
- Dashboard: "Estimated impact" line, "Optional manual cleanup" disclosure with destructive-data warning and copy buttons (copy only, never executed).
- Cleanup safety framework for future features (`ProbeSiteDoctor\Cleanup`): `CleanupTask` contract, `ConfirmationGuard` (preview fingerprint, typed phrase with item count, backup acknowledgement, expiry, single-use user/task-bound token), `CleanupRunner`, `CleanupRegistry` (filter `probesd_cleanup_tasks`, empty by default), audit hook `probesd_cleanup_executed`. No cleanup task, route or UI exists.
- Capability `probesd_run_cleanup` (administrators).
- `Support\DatabaseInfo` (site-scoped information_schema metadata, capped counts).
- docs/CLEANUP-POLICY.md.

### Changed
- Autoloaded options check: top 10 options, hints for options of inactive plugins, impact and manual cleanup.
- "Database tables" now covers only storage engines ("Table storage engines"); size moved to the Database size and Large tables checks.
- "Database clutter" is now "Trash, spam and auto-drafts"; revisions, transients and orphans moved to their own checks.
- Schema version 3: `results.impact` and `results.cleanup` columns.

## [0.2.0] — 2026-09-22 — Phase 2: Performance Diagnostics

### Added
- Performance checks: page cache (cache headers, plugin HTML markers, advanced-cache drop-in, median loopback response time), large images, image optimization indicators, script/style loading (render-blocking assets), and excessive asset loading.
- Database query indicators: DB round trip, typical query time and SAVEQUERIES overhead; table engine and size; clutter counts.
- WordPress version check (pending maintenance/security release = Critical; new major = Warning).
- Measurement methods (`Measurement::SERVER`, `Measurement::LOOPBACK`), the optional `Contracts\ReportsMeasurement` interface, and a measurement badge on each finding.
- A "How findings are measured" panel on the dashboard explaining that no browser metrics are measured.
- `Support\Loopback` (anonymous self-request; filter `probesd_loopback_args`) and `Support\FrontendSnapshot` (homepage fetched once per scan and parsed with `WP_HTML_Tag_Processor`).
- Filterable per-check thresholds: `probesd_check_thresholds`.
- Findings are now labelled Finding / Severity / Explanation / Recommended action.

### Changed
- Schema version 2 adds `measurement` to the results table (existing rows default to `server`).
- The pending-updates check now covers plugins and themes only; core is handled by the WordPress version check.
- The PHP version check now includes performance guidance.

## [0.1.0] — 2026-09-22 — Phase 1: Diagnostic Framework

### Added
- Plugin foundation: requirements guard (PHP 8.0 / WP 6.4), PSR-4 autoloader, lazy service container, activation/deactivation/uninstall, version-driven upgrader.
- Diagnostic engine: `Contracts\Check` interface, `AbstractCheck` base, immutable `Result`, exception isolation and timing per check.
- Check registration system: `CheckRegistry`, validation of IDs and categories, `probesd_register_checks` action and `probesd_checks` filter.
- Categories: Performance, Database, Configuration, Plugins & Themes, SEO (filter `probesd_categories`).
- Severity levels: Good, Information, Warning, Critical.
- Results storage: `{prefix}probesd_scans` and `{prefix}probesd_results` tables, retention (default 20 completed scans), and cleanup of interrupted scans.
- Reporting engine: health score, per-category scores, trend against the previous scan.
- Dashboard, Scan History (list table with bulk delete), REST API `probe-site-doctor/v1`, and a no-JS fallback for running scans.
- Capabilities `probesd_view_reports` and `probesd_run_scans`.
- Nine sample checks.
