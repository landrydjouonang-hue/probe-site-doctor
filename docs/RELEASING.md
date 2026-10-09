# Releasing

How to publish a version to GitHub, to the WordPress.org plugin directory, and
to your own site. Nothing here runs automatically — every step is yours to run.

## 0. Pre-flight

```bash
# Syntax check everything (no build step, no dependencies)
find wp-content/plugins/probe-site-doctor -name '*.php' -print0 | xargs -0 -n1 php -l

# Run the suites against a local install
php sd-test.php && php sd-test-p2.php && php sd-test-p3.php && php sd-test-p4.php \
  && php sd-test-p5.php && php sd-test-p6.php && php sd-test-p7.php && php sd-test-p8.php
```

Checklist:

- [ ] Version bumped in **three** places: `probe-site-doctor.php` header, `PROBESD_VERSION`, and the fallback in `uninstall.php`.
- [ ] `readme.txt`: `Stable tag` matches, `Tested up to` matches the current WordPress, changelog entry added.
- [ ] `CHANGELOG.md` entry added.
- [ ] `docs/CHECKS.md` regenerated if checks changed.
- [ ] Screenshots regenerated if the UI changed, and `readme.txt` captions still match the numbering.
- [ ] No BOM in any PHP file (a BOM sends output before headers).

```bash
# Quick BOM check
grep -rlIP '\xEF\xBB\xBF' wp-content/plugins/probe-site-doctor || echo "no BOM"
```

## 1. Build the distributable zip

The zip must contain a single top-level folder named `probe-site-doctor`, and must
not contain the repository furniture listed in `.distignore`.

```bash
# With WP-CLI (reads .distignore)
wp dist-archive wp-content/plugins/probe-site-doctor ./probe-site-doctor-0.9.0.zip

# Or by hand
rsync -a --exclude-from=wp-content/plugins/probe-site-doctor/.distignore \
  wp-content/plugins/probe-site-doctor/ /tmp/probe-site-doctor/
( cd /tmp && zip -r ~/probe-site-doctor-0.9.0.zip probe-site-doctor )
```

Install that zip on a clean site and run a scan before shipping it.

## 2. GitHub

```bash
cd wp-content/plugins/probe-site-doctor
git init
git add .
git commit -m "Probe Site Doctor 0.9.0"
git branch -M main
git remote add origin git@github.com:<you>/probe-site-doctor.git
git push -u origin main

git tag -a v0.9.0 -m "Probe Site Doctor 0.9.0"
git push origin v0.9.0
```

Then create the release from the tag and attach `probe-site-doctor-0.9.0.zip`.

Repository settings worth setting once: description, topics
(`wordpress-plugin`, `site-health`, `performance`, `diagnostics`,
`core-web-vitals`), and the social preview image
(`.wordpress-org/banner-1544x500.png`).

## 3. WordPress.org

### First submission

1. Sign in at <https://wordpress.org/plugins/developers/add/> and upload the zip.
2. The review is manual and usually takes a few days to a few weeks. Expect
   questions about: the public REST route (answer: it is the Core Web Vitals
   collector, off by default, same-origin only, no identifiers stored — see
   `docs/PRIVACY.md`), and about data collection in general.
3. When approved you get SVN access at `https://plugins.svn.wordpress.org/probe-site-doctor/`.

### Publishing a version over SVN

```bash
svn co https://plugins.svn.wordpress.org/probe-site-doctor/ svn-probe-site-doctor
cd svn-probe-site-doctor

# Plugin files (everything the zip contains)
rsync -a --delete --exclude-from=../probe-site-doctor/.distignore ../probe-site-doctor/ trunk/

# Store listing assets: banners, icon and numbered screenshots
cp ../probe-site-doctor/.wordpress-org/* assets/

svn add --force trunk assets
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

svn ci -m "Release 0.9.0"
svn cp trunk tags/0.9.0
svn ci -m "Tag 0.9.0"
```

The directory serves whatever `Stable tag` in `trunk/readme.txt` points at, so
the tag must exist before the stable tag is bumped.

### Asset naming (already correct in `.wordpress-org/`)

| File | Used for |
|---|---|
| `banner-772x250.png` / `banner-1544x500.png` | Header on the plugin page |
| `icon-128x128.png` / `icon-256x256.png` | Search results and the updates screen |
| `screenshot-1.png` … `screenshot-10.png` | The Screenshots tab, captioned by `readme.txt` in the same order |

## 4. Your own website

- `docs/demo/index.html` is a self-contained walkthrough player: copy the folder
  (with `docs/screenshots/`) to your site and embed it in an iframe, or record it
  once to produce an MP4.
- `.wordpress-org/banner-1544x500.png` works as a hero image.
- `docs/screenshots/*.png` are 1440px-wide product shots.

## 5. After release

- Watch the WordPress.org support forum and GitHub issues.
- For a fix release, repeat with the patch version bumped everywhere.
- Keep `Tested up to` current — it is the first thing users look at.
