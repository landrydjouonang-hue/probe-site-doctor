# Check catalogue

49 checks in 6 categories, generated from the plugin registry at version 0.9.0. Every check is read-only: a scan never changes the site.

**How** describes where the evidence comes from. **Server** means settings, files, PHP state or the database. **Loopback** means the server requests one of its own public URLs as an anonymous visitor and analyses the response. No check measures anything in a browser.

## Performance

Server-side indicators of speed: caching, server response time, images and the scripts and styles the homepage loads. These are not browser page-load measurements.

| Check | ID | How | What it looks at |
|---|---|---|---|
| Excessive asset loading | `performance.asset-weight` | Loopback | Counts the JavaScript and CSS files the homepage loads, their size on disk, and the number of third-party hosts. |
| Image optimization indicators | `performance.image-optimization` | Server | Looks for signs of image optimization: modern formats (WebP/AVIF), an optimization plugin, JPEG quality and lazy loading. |
| Large images | `performance.large-images` | Server | Looks for media-library images with a large file size or very large dimensions. |
| Page cache | `performance.page-cache` | Loopback | Detects full-page caching and measures how long the server takes to answer a request for the homepage from itself. |
| Persistent object cache | `performance.object-cache` | Server | Checks whether WordPress stores cached data in a persistent backend such as Redis or Memcached. |
| PHP OPcache | `performance.opcache` | Server | Checks whether PHP caches compiled scripts in memory. |
| Script and style loading | `performance.render-blocking-assets` | Loopback | Counts scripts and stylesheets in the homepage &lt;head&gt; that block rendering, based on the HTML the server delivers. |

## Database

Database size, large tables, revisions, transients, metadata and autoloaded options. Findings may include optional manual cleanup steps; Site Doctor never deletes anything itself.

| Check | ID | How | What it looks at |
|---|---|---|---|
| Autoloaded options size | `database.autoloaded-options` | Server | Measures how much data from the options table is loaded into memory on every page request. |
| Database query indicators | `database.query-performance` | Server | Times the database round trip and a typical read-only query, and checks for query logging overhead. |
| Database size | `database.size` | Server | Measures the total size of the site’s tables, reclaimable space, and growth since the previous scan. |
| Large tables | `database.large-tables` | Server | Finds the largest tables and flags log, session or statistics tables that keep growing. |
| Orphaned metadata | `database.orphaned-meta` | Server | Counts post, comment, term and user metadata, term relationships and revisions whose parent object no longer exists. |
| Post meta volume | `database.postmeta` | Server | Measures the size of the post meta table, the keys that dominate it, and its indexes. |
| Post revisions | `database.revisions` | Server | Counts stored post revisions and their size, and checks the revision limit. |
| Table storage engines | `database.tables` | Server | Checks that the site’s tables use InnoDB rather than MyISAM. |
| Transients | `database.transients` | Server | Measures transients in the database: expired entries, autoloaded transients and overall volume. |
| Trash, spam and auto-drafts | `database.bloat` | Server | Counts auto-drafts, trashed posts and spam or trashed comments that are waiting to be emptied. |

## Configuration

PHP, server, wp-config.php and WordPress settings, including security-related indicators such as debug output, file editing, HTTPS, XML-RPC and REST exposure. These are configuration indicators, not a security audit: nothing here scans for malware, vulnerable code or intrusions, and Site Doctor never changes a setting.

| Check | ID | How | What it looks at |
|---|---|---|---|
| Administrator account indicators | `configuration.admin-accounts` | Server | Counts administrator accounts and checks guessable logins and the registration role. Passwords are never examined. |
| Debug settings | `configuration.debug-mode` | Server | Checks WP_DEBUG, on-screen error display and whether the debug log is written to a publicly reachable location. |
| File editing settings | `configuration.file-editing` | Server | Checks the built-in plugin/theme file editor and the DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS constants. |
| HTTPS configuration | `configuration.https` | Loopback | Checks the site URLs, admin-over-HTTPS, the HTTP to HTTPS redirect and the HSTS header. |
| PHP version support | `configuration.php-version` | Server | Checks whether the PHP version still receives security updates, and whether a newer, faster PHP branch is available. |
| REST API exposure indicators | `configuration.rest-api` | Loopback | Checks whether the REST API answers anonymous requests and whether the users endpoint lists accounts. |
| Security-related configuration indicators | `configuration.security-indicators` | Loopback | Reviews security keys and salts, wp-config.php permissions, uploads directory listing, the table prefix and application passwords. |
| WordPress core version | `configuration.wordpress-version` | Server | Checks whether WordPress core is up to date and whether automatic security releases are enabled. |
| WordPress version visibility | `configuration.version-visibility` | Loopback | Checks whether the WordPress version is visible in the page source, asset URLs or readme.html. |
| XML-RPC status | `configuration.xmlrpc` | Loopback | Checks whether xmlrpc.php is reachable, whether it is disabled by a filter, and whether pingbacks are on. |

## Plugins & Themes

Installed plugins and themes: available updates, unused extensions and compatibility indicators. Site Doctor recommends updates but never installs them.

| Check | ID | How | What it looks at |
|---|---|---|---|
| Inactive plugins | `extensions.inactive-plugins` | Server | Lists plugins that are installed but not active, and flags those that are also outdated. |
| Inactive themes | `extensions.inactive-themes` | Server | Counts unused themes, keeping one default theme as a recommended fallback. |
| Outdated plugins | `extensions.outdated-plugins` | Server | Lists plugins with an available update, the size of each update, and anything that blocks it. |
| Outdated themes | `extensions.outdated-themes` | Server | Lists themes with an available update, with extra attention to the active theme and its parent theme. |
| Plugin compatibility indicators | `extensions.compatibility` | Server | Checks plugin and theme requirements, “Tested up to” versions and plugin dependencies against this site. |

## SEO (Technical)

Technical settings and markup that affect how search engines can crawl and index the site: indexing configuration, sitemap, titles, descriptions, image alt text and internal links. Content quality, keywords, rankings and backlinks are not assessed, and this does not replace an SEO platform.

| Check | ID | How | What it looks at |
|---|---|---|---|
| Broken internal links | `seo.internal-links` | Loopback | Checks internal links in published content: those pointing at unpublished posts, plus a capped sample verified by request. |
| Image alt text | `seo.image-alt-text` | Loopback | Counts media-library images without alt text and content images with no alt attribute at all. |
| Indexing configuration indicators | `seo.indexing` | Loopback | Checks robots.txt rules, the homepage robots meta tag and canonical link, and noindex settings on published content. |
| Meta descriptions | `seo.meta-descriptions` | Loopback | Checks the homepage meta description and, where an SEO plugin stores them in post meta, per-page descriptions. |
| Page titles | `seo.page-titles` | Loopback | Finds published posts and pages without a title, duplicate titles, and checks the homepage title element. |
| Permalink structure | `seo.permalink-structure` | Server | Checks whether URLs use readable permalinks instead of query strings like ?p=123. |
| Search engine visibility | `seo.search-visibility` | Server | Checks whether WordPress asks search engines not to index the site. |
| Sitemap availability | `seo.sitemap` | Loopback | Checks whether an XML sitemap is reachable and whether robots.txt points to it. |

## Developer

The technical state of the install: PHP extensions, memory limits, cron, REST availability, the debug log and database configuration. These findings are aimed at whoever maintains the site, and the full system report is on the Developer screen.

| Check | ID | How | What it looks at |
|---|---|---|---|
| Active theme and plugins | `developer.active-components` | Server | Lists the active theme, the active plugins, and code loading outside the plugin list (must-use plugins and drop-ins). |
| Cron status | `developer.cron` | Server | Checks whether WP-Cron is enabled, whether scheduled events are overdue and whether core events are registered. |
| Database configuration | `developer.database-configuration` | Server | Checks the database server version, table character sets and server variables such as max_allowed_packet. |
| Debug log | `developer.debug-log` | Server | Checks the size and recency of the PHP/WordPress debug log. Its contents are never read. |
| Environment information | `developer.environment` | Server | Records WordPress, PHP, server and database versions, and checks that the environment type is declared. |
| PHP extensions | `developer.php-extensions` | Server | Checks which PHP extensions are loaded, and which required or recommended ones are missing. |
| PHP memory limit | `developer.memory-limit` | Server | Checks the PHP memory_limit against what WordPress, plugins and the admin typically need. |
| REST API status | `developer.rest-api` | Loopback | Requests the REST root and a core route to confirm the REST API responds with JSON. |
| WordPress memory limit | `developer.wp-memory-limit` | Server | Checks WP_MEMORY_LIMIT and WP_MAX_MEMORY_LIMIT against the PHP memory limit that actually applies. |

## Severity and scoring

| Severity | Meaning | Score weight |
|---|---|---|
| Good | The check passed | 100 |
| Information | Worth knowing, nothing to fix | excluded |
| Warning | Should be addressed | 50 |
| Critical | Needs attention as a priority | 0 |

Skipped checks and checks that could not run are excluded from the score as well. The score summarises only the checks in this report. It is not a security guarantee and not a certification: Site Doctor does not scan for malware, modified files, known vulnerabilities or intrusions, and it cannot measure what visitors experience in a browser. A high score means these checks passed, nothing more.
