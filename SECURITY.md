# Security policy

## Reporting a vulnerability

Please report security issues privately, not in a public issue tracker.

- Email: **landrydjouonang@gmail.com** with `Probe Site Doctor security` in the subject.
- Include the plugin version, WordPress and PHP versions, and steps to
  reproduce. A proof of concept helps.
- You will get an acknowledgement within a few days, and a fix or an
  explanation as quickly as the issue warrants.

Please do not test against sites you do not own.

## Scope

In scope: anything in this plugin — its REST routes, admin screens, exports,
the field-data collector, capability checks, nonces, SQL, output escaping.

Out of scope: vulnerabilities in WordPress core, in other plugins, or in the
hosting environment; findings that require an administrator to already be
compromised; and the deliberate design decisions below.

## Design decisions that are not bugs

- **`POST /wp-json/probe-site-doctor/v1/vitals` is public.** It has to be: it is
  called by anonymous visitors. It only exists while field data collection is
  enabled, requires a same-origin `Origin`/`Referer`, accepts five whitelisted
  metrics within plausible ranges, stores no visitor identifier, and is rate
  limited per address. The worst outcome of abuse is noise in a metrics table
  an administrator can delete with one click.
- **Diagnostic exports describe the server in detail.** That is their purpose.
  They require `probesd_view_reports` (administrator by default) and a
  nonce, and they exclude keys, salts, the database password and log contents.
- **Site Doctor is not a security scanner.** It reads configuration; it does
  not scan for malware, modified files, known vulnerabilities or intrusions. A
  clean report does not mean a site is secure, and the plugin says so on every
  relevant screen.

## What the plugin does to stay safe

- Every REST route has a `permission_callback`; every `admin-post` handler
  checks a capability and `check_admin_referer()`. There are no `nopriv`
  handlers other than the collector described above.
- All SQL is prepared; table names come from `$wpdb->prefix`.
- All output is escaped; notices use whitelisted codes, never raw query text.
- Scans are read-only, which the test suite enforces by intercepting write
  queries during a full scan.
- Uninstall removes every table, option, transient and capability, on every
  site of a multisite network.
