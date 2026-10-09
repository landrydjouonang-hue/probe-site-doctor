# REST API

Namespace: `probe-site-doctor/v1`. Base: `https://example.com/wp-json/probe-site-doctor/v1`.

Authentication is standard WordPress cookie authentication plus the `wp_rest`
nonce (what `wp.apiFetch` sends), or any authentication method your site
supports (application passwords, JWT, …). Every route except the field-data
collector requires a capability.

| Route | Method | Capability | Purpose |
|---|---|---|---|
| `/checks` | GET | `probesd_view_reports` | List registered checks |
| `/scans` | GET | `probesd_view_reports` | Paginated scan history |
| `/scans` | POST | `probesd_run_scans` | Start a scan; returns the checks to run |
| `/scans/{id}` | GET | `probesd_view_reports` | Full report for a scan |
| `/scans/{id}` | DELETE | `probesd_run_scans` | Delete a scan and its results |
| `/scans/{id}/checks/{check}` | POST | `probesd_run_scans` | Run one check inside a running scan |
| `/scans/{id}/complete` | POST | `probesd_run_scans` | Finalise: tally, score, prune |
| `/vitals` | POST | public, only while enabled | Receive one page view's Core Web Vitals |
| `/vitals` | GET | `probesd_view_reports` | Field-data summary |

## Running a scan

A scan runs one check per request, which is what keeps it inside PHP time
limits on shared hosting.

```js
const { namespace } = { namespace: 'probe-site-doctor/v1' };

// 1. Start. Returns { scan_id, checks: [ { id, label, category }, … ] }
const start = await wp.apiFetch( { path: `/${namespace}/scans`, method: 'POST' } );

// 2. Run each check.
for ( const check of start.checks ) {
	await wp.apiFetch( {
		path: `/${namespace}/scans/${start.scan_id}/checks/${check.id}`,
		method: 'POST',
	} );
}

// 3. Finalise. Returns the completed scan and its score.
const done = await wp.apiFetch( {
	path: `/${namespace}/scans/${start.scan_id}/complete`,
	method: 'POST',
} );
```

Starting a scan while another one is active returns **409 Conflict**. A scan
with no activity for `Scanner::IDLE_TIMEOUT` (120 seconds) is marked
`abandoned` and stops blocking new scans.

## Report shape

`GET /scans/{id}` returns:

```json
{
  "scan": { "id": 70, "status": "completed", "score": 83, "total_checks": 49, "environment": {}, "started_at": "…", "finished_at": "…" },
  "score": 83,
  "band": "good",
  "band_label": "Healthy",
  "previous_score": 83,
  "previous_id": 69,
  "counts": { "critical": 2, "warning": 7, "info": 16, "good": 24, "skipped": 0, "error": 0 },
  "categories": { "performance": { "label": "Performance", "score": 88, "issues": 1, "results": [] } },
  "results": [
    {
      "check_id": "configuration.https",
      "category": "configuration",
      "status": "completed",
      "severity": "critical",
      "title": "The site does not use HTTPS",
      "message": "…",
      "recommendation": "…",
      "data": {},
      "measurement": "loopback",
      "impact": { "level": "high", "summary": "…" },
      "cleanup": { "kind": "harden", "summary": "…", "steps": [], "commands": [] }
    }
  ],
  "updates": [ { "priority": "high", "component": "php", "name": "PHP", "installed": "8.0.30", "available": "8.3" } ]
}
```

`status` is `completed`, `skipped` or `error`; only `completed` results carry a
meaningful `severity`. `measurement` is `server` or `loopback` — never a browser
measurement.

## Field data

`POST /vitals` is the one public route. It exists only while collection is
enabled (otherwise **404**), requires a same-origin `Origin` or `Referer`
(**403**), and is rate limited per address (**429**).

```http
POST /wp-json/probe-site-doctor/v1/vitals
Content-Type: application/json

{ "path": "/pricing", "device": "mobile", "metrics": { "lcp": 2412.5, "cls": 0.06, "inp": 184 } }
```

Unknown metrics and implausible values are discarded rather than stored. The
response is `{ "stored": 3 }`.

`GET /vitals` (capability-checked) returns the summary:

```json
{
  "enabled": true,
  "days": 30,
  "metrics": { "lcp": { "p75": 2460, "samples": 220, "rating": "good" } },
  "paths": [ { "path": "/pricing", "samples": 88, "last": "2026-09-26 08:12:00" } ],
  "total": 1320,
  "note": "These numbers come from real browsers on this site…"
}
```

Accepts `path` and `device` (`mobile`|`desktop`) to narrow the summary.

## Errors

Standard WordPress REST errors: `401` not authenticated, `403` not permitted,
`404` unknown scan or disabled collector, `400` invalid arguments or an invalid
state transition (running a check on a finished scan, completing twice), `409`
another scan is already running.
