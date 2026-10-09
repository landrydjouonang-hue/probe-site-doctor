# Database

Schema version: **4** (`Storage\Schema::VERSION`, stored in option `probesd_db_version`). Tables are created with `dbDelta()` on activation and whenever the stored version differs (`Core\Upgrader`). All datetimes are UTC.

## `{prefix}probesd_scans`

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK AI | |
| status | varchar(20) | `running`, `completed`, `abandoned` |
| source | varchar(20) | `manual` (REST/dashboard), `fallback` (no-JS), `cli` |
| user_id | bigint unsigned | Initiating user (0 = system) |
| total_checks | smallint unsigned | Checks planned at start |
| count_good / count_info / count_warning / count_critical | smallint unsigned | Completed results per severity |
| count_error | smallint unsigned | Checks that failed to run |
| score | tinyint unsigned NULL | 0–100; NULL when nothing scorable |
| plugin_version | varchar(20) | Version that produced the scan |
| environment | longtext | JSON snapshot: WP/PHP/DB versions, environment type, theme, plugin count, locale |
| started_at / updated_at / finished_at | datetime | `updated_at` is touched per check (used for idle detection) |

Indexes: `status_started (status, started_at)`, `started_at`.

## `{prefix}probesd_results`

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK AI | |
| scan_id | bigint unsigned | Owning scan |
| check_id | varchar(100) | e.g. `database.autoloaded-options` |
| category | varchar(50) | Category ID |
| status | varchar(20) | `completed`, `skipped`, `error` |
| severity | varchar(20) | `good`, `info`, `warning`, `critical` |
| title | varchar(255) | |
| message / recommendation | text | |
| data | longtext | JSON details |
| duration_ms | int unsigned | |
| measurement | varchar(20) | `server` or `loopback` (added in schema 2, default `server`) |
| impact | text NULL | JSON `{level: low|medium|high, summary}` (schema 3) |
| cleanup | longtext NULL | JSON `{summary, steps[], commands[{label, command, type}], destructive}`: manual instructions only, never executed (schema 3) |
| created_at | datetime | |

Indexes: `UNIQUE scan_check (scan_id, check_id)`, which makes re-running a check within a scan an upsert. Also `scan_severity (scan_id, severity)`.

`dbDelta()` cannot manage foreign keys, so `Scanner::delete()` and `prune()` delete results before their scan.

## Options

| Option | Autoload | Content |
|---|---|---|
| `probesd_settings` | no | `{ retention: 20 }` |
| `probesd_version` | no | Installed plugin version |
| `probesd_db_version` | no | Installed schema version |

Transients: `probesd_activated` (1 minute) shows the post-activation notice. `probesd_frontend_snapshot` (10 minutes, cleared at each scan start) caches the parsed homepage for the loopback checks. `probesd_cln_c_*` / `probesd_cln_t_*` hold cleanup confirmation challenges (10 min) and tokens (5 min). They are only created by a future cleanup UI and are removed on uninstall.

## `{prefix}probesd_vitals`

Core Web Vitals reported by visitors' browsers. Written only while field data collection is enabled.

| Column | Type | Notes |
|---|---|---|
| id | bigint unsigned PK AI | |
| path | varchar(190) | Request path, query string stripped, no trailing slash |
| device | varchar(10) | `mobile` or `desktop`, derived from viewport width |
| metric | varchar(10) | `lcp`, `inp`, `cls`, `fcp`, `ttfb` |
| value | double | Milliseconds, or unitless for CLS. Out-of-range values are rejected, not stored |
| created_at | datetime | |

Indexes: `path_metric (path, metric, created_at)`, `created_at`.

**Deliberately absent:** IP address, user agent, user ID, session or cookie identifier, referrer, query string. A row cannot be tied to a visitor. Summaries read at most the newest 500 samples per path and metric and report the 75th percentile.

Retention: `web_vitals_days` (default 30, 1–365). Pruning happens opportunistically after a write (roughly one write in twenty), so no cron event is needed, and an administrator can delete everything from the Page Speed screen.

## Schema history

| Version | Plugin | Change |
|---|---|---|
| 1 | 0.1.0 | Initial tables |
| 2 | 0.2.0 | `results.measurement` column |
| 3 | 0.3.0 | `results.impact` and `results.cleanup` columns |
| 4 | 0.8.0 | `{prefix}sitedoctor_vitals` table for browser field data |
| 4 | 0.9.0 | Same schema, new names: the three tables were renamed `sitedoctor_*` → `probesd_*` by `Core\Migration` when the plugin was renamed |

## Retention

When a scan completes, completed scans beyond the newest `retention` (default 20, filter `probesd_scan_retention`, range 1–200) are deleted, along with every `abandoned` scan.

## Migration from the former names

Up to 0.8.0 the plugin was called WP Site Doctor and its tables were
`{prefix}sitedoctor_scans`, `_results` and `_vitals`, with options
`sitedoctor_settings`, `sitedoctor_version` and `sitedoctor_db_version` and
capabilities `sitedoctor_*`.

`Core\Migration` runs once, before the schema install, and moves an existing
site over: the three tables are **renamed** (`RENAME TABLE`, so no rows are
copied and large tables are unaffected), option values are kept under the new
names, each role's capabilities are swapped, and the old transients are
deleted. It records `probesd_migrated_from_wp_site_doctor` and never runs
again. If a new empty table already exists it is dropped first so the one
holding the data takes its place; if that table already has rows, both are
left alone rather than guessing.

## Uninstall

`uninstall.php` drops both tables, deletes the options and the transient, and removes the capabilities from all roles, on every site of a multisite network.
