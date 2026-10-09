=== Probe Site Doctor ===
Contributors: djouonanglandry
Tags: site health, performance, diagnostics, database, seo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress website health, performance and diagnostics with actionable reports.

== Description ==

Probe Site Doctor analyzes your WordPress site across performance, database, configuration, plugin/theme and technical SEO checks, and generates a health report with a 0–100 score, prioritised findings and clear recommendations.

**Scope:** Probe Site Doctor is a diagnostics tool. It includes a few basic hardening checks, but it is not a malware scanner, firewall or vulnerability scanner, and a good score does not mean a site is secure. Use a dedicated security product for that.

* Four severity levels: Good, Information, Warning, Critical
* Five areas: Performance, Database, Configuration, Plugins & Themes, SEO (Technical)
* Performance diagnostics: page cache, images, render-blocking and excessive assets, database query indicators, WordPress/PHP versions
* Clearly labelled server-side diagnostics: no claims about browser page-load times
* Database diagnostics: revisions, transients, size, large tables, post meta, orphaned metadata, autoloaded options, with estimated impact and optional manual cleanup steps
* Never deletes anything: cleanup steps are shown for you to run yourself
* Update recommendations for WordPress, plugins, themes and PHP, prioritised, with compatibility indicators
* Never installs updates: you stay in control
* Configuration indicators: debug mode, file editing, HTTPS, administrator accounts, XML-RPC, REST exposure, version visibility and other security-related settings
* Never changes a setting: every fix is explained for you to apply
* Technical SEO: titles, meta descriptions, broken internal links, image alt text, indexing configuration and sitemap availability
* Never edits your content: SEO findings explain what to change and where
* Developer diagnostics: environment, active theme, active plugins, PHP extensions, memory limits, cron status, REST API status, debug information and database information
* Exportable diagnostic report: copy it or download Markdown/JSON for a support ticket — keys, salts, the database password and log contents are never included
* Page Speed: analyse any page and see what is making it slow, with a fix for each cause
* Optional Core Web Vitals from real visitors (LCP, INP, CLS): first-party, no visitor identifiers, off by default
* Read-only scans with progress feedback
* Printable Website Health Report: overall diagnostic summary, priority recommendations and six sections (Performance, Database, Plugins & Themes, Configuration, Security Indicators, SEO)
* Generate a report on demand from any stored scan, print it straight to PDF, or download it as one self-contained HTML file
* The score is never presented as a security guarantee
* Scan history with score trend
* Developer API to register custom checks

== Installation ==

1. Upload the `probe-site-doctor` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Site Doctor → Dashboard** and click **Run health scan**.

== Frequently Asked Questions ==

= Does a scan change my site? =

No. Every check is read-only.

= Does it contact external services? =

No. Update checks read WordPress's own cached update data. Performance checks request only your own site. The optional Core Web Vitals collector sends data to this site's own REST route — never to a third party — and the PageSpeed Insights button is just a link you choose to click.

= Does it measure Core Web Vitals? =

Only if you switch it on, and only where it can honestly be measured: in visitors' browsers. Enabling field data adds a small first-party script to public pages that reports LCP, INP, CLS, FCP and TTFB back to your site; the Page Speed screen shows the 75th percentile per page and device. It is off by default, stores no IP address, user agent or cookie, excludes logged-in users, and can be deleted with one click. Everything else Site Doctor measures happens on the server and is labelled as such.

= Can it tell me why a page is slow? =

Yes — that is what the Page Speed screen is for. It fetches the page as a visitor would, then lists the causes it can see (server response time, missing page cache, no compression, render-blocking CSS/JS, heavy JavaScript, oversized images, missing image dimensions, third-party domains, missing browser caching, large DOM, heavy embeds), each with what was measured, the metric it usually affects, and how to fix it.

= Does it measure page speed like PageSpeed Insights? =

No. It runs on the server and diagnoses configuration, caching, server response time and the assets your homepage references. Browser metrics such as LCP, INP and CLS need a browser-based tool.

= Does it clean up my database? =

No. It measures and explains. Findings can include optional manual cleanup steps and example commands, but Site Doctor never runs them. Any future cleanup feature will require explicit, typed confirmation.

= Does it update my plugins or WordPress? =

No. It lists what is outdated, prioritises it and shows how to update, but you install the updates yourself. It also never contacts WordPress.org: it reads the update data WordPress has already collected.

= Does it replace Yoast, Rank Math or an SEO platform? =

No. Site Doctor checks technical things it can verify: whether content has titles, whether descriptions exist where a plugin stores them, whether internal links resolve, whether images have alt text, whether indexing is allowed and whether a sitemap responds. It does not assess content quality, keywords, rankings, backlinks or competitors, and it does not write meta tags. Keep using an SEO plugin for that.

= Is it a security scanner? =

No. The configuration checks are indicators read from your settings: debug output, file editing, HTTPS, XML-RPC, REST exposure and similar. Nothing is scanned for malware, vulnerable code or intrusions, and a clean result does not mean the site is secure. Site Doctor also never changes a setting for you.

= Can I get a PDF or a report to send to a client? =

Yes. **Site Doctor → Health Report** builds a printable report from any completed scan. Use **Print / Save as PDF** and pick your browser's "Save as PDF" destination, or **Download HTML** for a single self-contained file you can archive or email. No PDF engine is bundled, because every modern browser prints to PDF already.

= What does the health score mean? =

It is the average of the checks that could be scored (Good = 100, Warning = 50, Critical = 0), with informational, skipped and failed checks excluded. It is not a security guarantee and not a certification: it summarises only the checks in the report. A high score means those checks passed, nothing more.

== Privacy ==

Probe Site Doctor makes no third-party requests. It has no telemetry, no
licensing call and no analytics, it loads nothing from a CDN, and it never
sends your site's data anywhere. Update information is read from the cache
WordPress already keeps, and the only HTTP requests the plugin makes are to
your own site's URLs (loopback requests), so that a check can see what an
anonymous visitor would receive.

Everything the plugin stores lives in your own database, in its own tables and
options, and is removed when you delete the plugin.

**What a scan stores.** One row per scan and one per check result: severities,
titles, explanations, recommendations and the measurements a check took (file
sizes, counts, table names, version numbers, setting values). Scans do not
store post content, user data or e-mail addresses.

**What the plugin never reads.** Authentication keys and salts, the database
password, and the contents of the debug log — only its path, size and last
write time. Any constant whose name ends in KEY, SALT, PASSWORD, SECRET or
TOKEN is excluded from the developer report even when it is whitelisted.

**Core Web Vitals field data (optional, disabled by default).** This is the
only feature that involves your visitors, and it does nothing until an
administrator with the `manage_options` capability switches it on, because it
adds a script to public pages. When enabled, a small first-party script reads
the browser's own performance entries and sends one report per page view to
your own site at `POST /wp-json/probe-site-doctor/v1/vitals`.

Stored per report: the page path (query strings stripped), a device class
(`mobile` or `desktop`, from the viewport width), the metric name (one of lcp,
inp, cls, fcp, ttfb), its value, and a timestamp.

Not stored, not collected and not derived: IP address, user agent, referrer,
query strings, user ID, session or visitor identifier, geolocation, or
anything at all written to the visitor's browser — the script sets no cookie
and uses no local storage. Logged-in users are excluded from measurement.

Controls: a sampling percentage, a retention window in days (default 30, after
which samples are deleted automatically), and a one-click "delete all
collected field data" on the Page Speed screen. Disabling collection makes the
public route return 404 immediately. Developers can force it off in code with
the `probesd_web_vitals_enabled` filter.

**Exports.** The diagnostic report (Markdown or JSON) and the Website Health
Report (HTML) describe your server in detail — versions, paths, the plugin
inventory, database configuration. They contain no secrets, but treat an
exported report the way you would treat a server inventory. Exports are
generated on demand, streamed to your browser, and never written to disk on
the server.

**Links out.** The Page Speed screen offers a link to Google PageSpeed
Insights for the page you are looking at. Nothing is sent when the screen
loads; the link only opens the tool if you click it.

== Screenshots ==

1. Dashboard: health score, severity counts and a score per area after a read-only scan.
2. Findings explain what was measured, the estimated impact, the recommended action and the manual steps — which Site Doctor never runs for you.
3. The printable Website Health Report, generated on demand from any stored scan.
4. Report findings by section, each section repeating what it can and cannot tell you.
5. Page Speed: what the server sends for one page, measured server-side.
6. What is making this page slow — each cause with what was measured, the Core Web Vital it affects, and how to fix it.
7. Core Web Vitals measured in real visitors' browsers (optional, off by default, first-party only).
8. Developer: the full system report — environment, theme, plugins, extensions, memory, cron, REST, debug and database.
9. Exportable diagnostic report: copy it, or download Markdown or JSON for a support ticket.
10. Scan history with the score trend, and a printable report for every stored scan.

== Upgrade Notice ==

= 0.9.0 =
Renamed from "WP Site Doctor": the slug, text domain, tables, options,
capabilities, REST namespace and hooks all changed. Your scans, reports and
settings migrate automatically on activation. Code using the old
`sitedoctor_*` names must switch to `probesd_*` and `probe-site-doctor/v1`.

== Changelog ==

= 0.9.0 =
* Renamed from "WP Site Doctor" to **Probe Site Doctor**: WordPress.org does not permit "wp" in a plugin name or slug.
* The slug, text domain, namespace, constants, tables, options, capabilities, REST namespace and hooks all moved to the `probe-site-doctor` / `probesd_` naming.
* Existing installs migrate automatically on activation: stored scans, results and field data are kept (the tables are renamed, not copied), settings and capabilities are carried over, and the old names are cleaned up.
* Code that used the old hook, capability or REST names needs updating; see the changelog in the repository for the full mapping.
* Fixes reported by the official Plugin Check tool: escaped exception messages, `LIKE` wildcards passed as parameters, no restricted mysqli call, no manual `load_plugin_textdomain()`, explicit sanitising of request data, and consistent LF line endings.

= 0.8.0 =
* Phase 8: developer diagnostics — environment, active theme, active plugins, PHP extensions, memory limits, cron status, REST API status, debug information and database information, with nine new checks and a full system report screen.
* Exportable diagnostic reports: copy to clipboard, download as Markdown or JSON. Keys, salts, the database password and the debug log contents are never included.
* Page Speed screen: analyse any page on the site and see what is making it slow, with a fix for each cause.
* Optional Core Web Vitals field data (LCP, INP, CLS, FCP, TTFB) measured in real visitors' browsers, first-party only and off by default.

= 0.7.0 =
* Phase 7: professional Website Health Report — overall diagnostic summary, priority recommendations, per-section summaries and findings across Performance, Database, Plugins & Themes, Configuration, Security Indicators and SEO. Generated on demand, printable to PDF, downloadable as one self-contained HTML file. The score is stated plainly as not a security guarantee.

= 0.6.0 =
* Phase 6: technical SEO checks (titles, descriptions, internal links, image alt text, indexing configuration, sitemap). Technical only; not a replacement for an SEO platform, and no content is edited.

= 0.5.0 =
* Phase 5: non-invasive configuration indicators (debug, file editing, HTTPS, administrator accounts, XML-RPC, REST exposure, version visibility, security settings). Clearly classified as indicators; nothing is changed automatically.

= 0.4.0 =
* Phase 4: outdated/inactive plugins and themes, core and PHP versions, compatibility indicators and prioritised update recommendations. Nothing is updated automatically.

= 0.3.0 =
* Phase 3: database diagnostics with estimated impact and optional manual cleanup; confirmation framework for future cleanup features. Nothing is deleted automatically.

= 0.2.0 =
* Phase 2: performance diagnostics, database query indicators, WordPress version check, measurement labelling.

= 0.1.0 =
* Diagnostic framework: engine, check interface, registry, severity levels, results storage, dashboard, scan history, REST API.
* Nine sample checks across five categories.
