# Privacy

What Probe Site Doctor reads, what it stores, and what it never touches.

## Summary

- Site Doctor makes **no outbound requests to third parties**. Every request it
  makes is to your own site (loopback), and update data is read from the cache
  WordPress already keeps.
- No telemetry, no "phone home", no licensing call, no analytics.
- Everything it stores lives in your own database, in the plugin's own tables
  and options, and is removed on uninstall.

## What a scan stores

Two tables (see [DATABASE.md](DATABASE.md)):

- `{prefix}probesd_scans` — one row per scan: status, source, the user ID that
  started it, counts, score, plugin version, an environment snapshot (versions,
  environment type, theme, plugin count, locale) and timestamps.
- `{prefix}probesd_results` — one row per check: severity, title, message,
  recommendation, structured measurements, impact and manual instructions.

Findings contain measurements — file sizes, counts, table names, version numbers,
setting values. They do **not** contain post content, user data, e-mail
addresses, passwords or log contents.

## What is never read

- Authentication keys and salts (`AUTH_KEY`, `NONCE_SALT`, …).
- The database password.
- The contents of the debug log. Site Doctor reports its path, size and last
  write time only.
- Post, page, comment or user content. Content checks count things (missing
  titles, images without alt text) and store counts and IDs, not the text.
- Anything whose constant name ends in `KEY`, `SALT`, `PASSWORD`, `SECRET` or
  `TOKEN`, even if it is on the reported-constants whitelist.

## Core Web Vitals field data (optional, off by default)

The only feature that involves site visitors. It is **disabled until an
administrator with `manage_options` turns it on**, because it adds a script to
public pages.

### When enabled

A ~3 KB first-party script runs on public pages, reads the browser's own
performance entries and sends one report per page view to your site's REST
route `POST /wp-json/probe-site-doctor/v1/vitals`.

**Stored per report**, in `{prefix}probesd_vitals`:

| Field | Example | Why |
|---|---|---|
| `path` | `/pricing` | Which page the measurement belongs to. Query strings are stripped |
| `device` | `mobile` | Viewport width below 768px counts as mobile |
| `metric` | `lcp` | One of lcp, inp, cls, fcp, ttfb |
| `value` | `2412` | Milliseconds, or unitless for CLS. Implausible values are rejected |
| `created_at` | `2026-09-26 08:14:22` | For the retention window |

**Not stored, not collected, not derived:** IP address, user agent, screen
details beyond the mobile/desktop split, referrer, query strings, user ID,
session or visitor identifier, cookie, geolocation, or anything written to the
visitor's browser. The script sets no cookie and uses no local storage, so it
does not require a cookie banner on its own account — but check your own
jurisdiction and privacy policy obligations.

**Logged-in users are excluded** from measurement.

### Controls

- **Sampling**: measure a percentage of page views (default 100%).
- **Retention**: delete samples older than N days (default 30). Pruning happens
  automatically as new samples arrive.
- **Delete all collected field data**: one checkbox on the Page Speed screen.
- **Disable**: the public route immediately returns 404 and the script stops
  being enqueued.
- Filter `probesd_web_vitals_enabled` can force it off in code, for example
  on a staging copy.

### Abuse protection

The public route only exists while collection is enabled, accepts same-origin
requests only, validates every metric against a whitelist and plausible range,
and is rate limited per address. The address is hashed into a transient key for
rate limiting and is never stored.

## Exports

The diagnostic report (Markdown/JSON) and the Website Health Report (HTML)
describe your server in detail: versions, paths, plugin inventory, database
configuration. They contain no secrets, but they are still sensitive —
treat an exported report the way you would treat a server inventory, and share
it only with people you would give that information to anyway.

Exports are generated on demand, streamed to the browser, and never written to
disk on the server.

## Uninstall

Deleting the plugin removes both scan tables, the field-data table, the
plugin's options and transients, and its capabilities — on every site of a
multisite network. Nothing is left behind.
