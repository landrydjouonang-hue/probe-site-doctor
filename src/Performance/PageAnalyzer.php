<?php
/**
 * Per-page speed analysis.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Performance;

use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a page profile into a prioritised list of what is making that page
 * slow, and what to do about each item.
 *
 * Scope, stated on the screen as well: this is a server-side analysis of the
 * delivered HTML and the files it references. It explains *causes* — the work
 * the page gives a browser — and names the Core Web Vital each cause usually
 * affects. It does not measure LCP, INP or CLS: those are measured in real
 * visitors' browsers by the optional field-data collector, or in a lab by
 * PageSpeed Insights and Lighthouse.
 */
final class PageAnalyzer {

	/**
	 * Analyses one URL.
	 *
	 * @param string $url URL on this site.
	 * @return array<string,mixed>
	 */
	public static function analyze( string $url ): array {
		$profile = PageProfile::fetch( $url );

		if ( empty( $profile['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $profile['error'] ?? __( 'The page could not be fetched.', 'probe-site-doctor' ) ),
				'url'   => (string) ( $profile['url'] ?? $url ),
				'status' => (int) ( $profile['status'] ?? 0 ),
			);
		}

		$totals = PageProfile::totals( $profile );
		$issues = self::issues( $profile, $totals );

		usort(
			$issues,
			static function ( $a, $b ) {
				$rank = Severity::rank( $b['severity'] ) <=> Severity::rank( $a['severity'] );
				return 0 !== $rank ? $rank : ( (int) $b['bytes'] <=> (int) $a['bytes'] );
			}
		);

		return array(
			'ok'       => true,
			'url'      => (string) $profile['url'],
			'path'     => PageProfile::path( (string) $profile['url'] ),
			'status'   => (int) $profile['status'],
			'totals'   => $totals,
			'timing'   => array(
				'first_ms'  => (int) $profile['time_ms'],
				'repeat_ms' => (int) ( $profile['repeat_time_ms'] ?? 0 ),
				'cache_hit' => self::cache_signals( $profile ),
			),
			'profile'  => array(
				'elements'       => (int) $profile['elements'],
				'iframes'        => (int) $profile['iframes'],
				'third_party'    => (array) $profile['third_party'],
				'modern_images'  => (bool) $profile['modern_images'],
				'hints'          => (array) $profile['hints'],
				'asset_headers'  => (array) ( $profile['asset_headers'] ?? array() ),
				'largest_images' => self::largest( (array) $profile['images'], 5 ),
				'largest_scripts' => self::largest( (array) $profile['scripts'], 5 ),
				'largest_styles' => self::largest( (array) $profile['styles'], 5 ),
			),
			'issues'   => $issues,
			'counts'   => array(
				Severity::CRITICAL => count( array_filter( $issues, static fn( $i ) => Severity::CRITICAL === $i['severity'] ) ),
				Severity::WARNING  => count( array_filter( $issues, static fn( $i ) => Severity::WARNING === $i['severity'] ) ),
				Severity::INFO     => count( array_filter( $issues, static fn( $i ) => Severity::INFO === $i['severity'] ) ),
			),
			'measured' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Every rule, in one place.
	 *
	 * @param array<string,mixed>  $profile Profile.
	 * @param array<string,mixed>  $totals  Totals.
	 * @return array<int,array<string,mixed>>
	 */
	private static function issues( array $profile, array $totals ): array {
		$issues  = array();
		$first   = (int) $profile['time_ms'];
		$repeat  = (int) ( $profile['repeat_time_ms'] ?? 0 );
		$cached  = self::cache_signals( $profile );
		$headers = (array) $profile['headers'];

		// --- Server response ------------------------------------------------
		if ( $first > 1500 ) {
			$issues[] = self::issue(
				'server-response',
				Severity::CRITICAL,
				__( 'The server takes a long time to produce this page', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: First response time, 2: Repeat response time. */
					__( 'Server response took %1$s ms, and %2$s ms on a second request. Everything a visitor sees waits for this, before a single file is downloaded.', 'probe-site-doctor' ),
					number_format_i18n( $first ),
					number_format_i18n( $repeat )
				),
				__( 'Enable full-page caching so repeat visitors are served from cache, then profile the page with Query Monitor to find slow queries or slow plugin hooks. Object caching (Redis/Memcached) helps when the page cannot be cached.', 'probe-site-doctor' ),
				'TTFB / LCP',
				0
			);
		} elseif ( $first > 600 ) {
			$issues[] = self::issue(
				'server-response',
				Severity::WARNING,
				__( 'Server response is slower than it should be', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: First response time, 2: Repeat response time. */
					__( 'Server response took %1$s ms (%2$s ms on a repeat request). Google treats 800 ms as the upper bound for a good time to first byte, and real visitors also pay network latency on top of this.', 'probe-site-doctor' ),
					number_format_i18n( $first ),
					number_format_i18n( $repeat )
				),
				__( 'Add page caching, check for slow queries with Query Monitor, and make sure PHP has OPcache enabled.', 'probe-site-doctor' ),
				'TTFB / LCP',
				0
			);
		}

		if ( ! $cached['cached'] && $repeat > 400 ) {
			$issues[] = self::issue(
				'no-page-cache',
				Severity::WARNING,
				__( 'No page cache appears to be serving this page', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Repeat response time. */
					__( 'A second identical request still took %s ms and the response carries no cache indicators (no Cache-Control max-age, Age, X-Cache or CDN header). WordPress is rebuilding the page from PHP and the database every time.', 'probe-site-doctor' ),
					number_format_i18n( $repeat )
				),
				__( 'Enable a page cache — a plugin such as WP Super Cache or W3 Total Cache, your host’s built-in cache, or a CDN/reverse proxy. It is the single biggest win for repeat visitors and for TTFB.', 'probe-site-doctor' ),
				'TTFB / LCP',
				0
			);
		}

		if ( '' === (string) ( $headers['content-encoding'] ?? '' ) ) {
			$issues[] = self::issue(
				'no-compression',
				Severity::WARNING,
				__( 'The HTML is served uncompressed', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: HTML size. */
					__( 'The response has no Content-Encoding header, so %s of HTML travels as-is. Gzip or Brotli typically removes 70–80%% of HTML, CSS and JavaScript weight.', 'probe-site-doctor' ),
					size_format( (int) $profile['html_bytes'], 1 )
				),
				__( 'Enable gzip or Brotli on the web server (mod_deflate, nginx gzip, or your CDN). It is a server setting, not a WordPress one.', 'probe-site-doctor' ),
				'LCP',
				(int) $profile['html_bytes']
			);
		}

		// --- Render-blocking ------------------------------------------------
		if ( $totals['blocking_js'] > 0 ) {
			$issues[] = self::issue(
				'render-blocking-js',
				$totals['blocking_js'] >= 4 ? Severity::WARNING : Severity::INFO,
				sprintf(
					/* translators: %s: Number of scripts. */
					_n(
						'%s script in the head blocks rendering',
						'%s scripts in the head block rendering',
						(int) $totals['blocking_js'],
						'probe-site-doctor'
					),
					number_format_i18n( (int) $totals['blocking_js'] )
				),
				sprintf(
					/* translators: %s: JavaScript size. */
					__( 'These scripts have neither async nor defer, so the browser must download and execute them before it paints anything. Total JavaScript on the page is %s.', 'probe-site-doctor' ),
					size_format( (int) $totals['js_bytes'], 1 )
				),
				__( 'Add defer (or async where order does not matter) when enqueuing the scripts, or move them to the footer. In WordPress, wp_enqueue_script() accepts a strategy of "defer" since 6.3.', 'probe-site-doctor' ),
				'LCP / INP',
				(int) $totals['js_bytes']
			);
		}

		if ( $totals['blocking_css'] > 4 || $totals['css_bytes'] > 200 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'render-blocking-css',
				Severity::WARNING,
				__( 'Stylesheets are delaying the first paint', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Number of stylesheets, 2: Total CSS size. */
					__( '%1$s stylesheet(s) load in the head, totalling %2$s. CSS blocks rendering by design: nothing appears until it has arrived and been parsed.', 'probe-site-doctor' ),
					number_format_i18n( (int) $totals['blocking_css'] ),
					size_format( (int) $totals['css_bytes'], 1 )
				),
				__( 'Remove stylesheets the page does not use (many themes and plugins load everything everywhere), combine what is left, and consider inlining the small amount of CSS needed for the top of the page.', 'probe-site-doctor' ),
				'LCP',
				(int) $totals['css_bytes']
			);
		}

		if ( $totals['css_files'] + $totals['js_files'] > 25 ) {
			$issues[] = self::issue(
				'many-requests',
				Severity::INFO,
				sprintf(
					/* translators: %s: Number of files. */
					__( 'The page references %s separate CSS and JavaScript files', 'probe-site-doctor' ),
					number_format_i18n( (int) $totals['css_files'] + (int) $totals['js_files'] )
				),
				__( 'Each file is its own request. Over HTTP/2 this matters much less than it used to, but it is still a reliable sign that several plugins are each loading their own assets on every page.', 'probe-site-doctor' ),
				__( 'Check which plugins load assets on pages that do not use them, and dequeue those. Combining files helps mainly on HTTP/1.1 connections.', 'probe-site-doctor' ),
				'LCP',
				0
			);
		}

		if ( $totals['js_bytes'] > 500 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'heavy-js',
				Severity::WARNING,
				sprintf(
					/* translators: %s: JavaScript size. */
					__( 'JavaScript weighs %s', 'probe-site-doctor' ),
					size_format( (int) $totals['js_bytes'], 1 )
				),
				__( 'That is measured on disk, before compression. JavaScript is the most expensive kind of byte: it has to be downloaded, parsed, compiled and executed, and that work is what makes a page feel unresponsive to taps and clicks on mid-range phones.', 'probe-site-doctor' ),
				__( 'Find the biggest scripts in the table below, then load them only where they are needed. Sliders, page builders and analytics stacks are the usual causes.', 'probe-site-doctor' ),
				'INP / LCP',
				(int) $totals['js_bytes']
			);
		}

		if ( $totals['inline_bytes'] > 100 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'inline-bloat',
				Severity::INFO,
				sprintf(
					/* translators: %s: Inline CSS and JavaScript size. */
					__( '%s of CSS and JavaScript is inlined in the HTML', 'probe-site-doctor' ),
					size_format( (int) $totals['inline_bytes'], 1 )
				),
				__( 'Inline code cannot be cached separately, so every page view downloads it again. A small amount of critical CSS inline is good practice; this is well past that.', 'probe-site-doctor' ),
				__( 'Move large inline blocks into enqueued files so browsers can cache them, keeping only the critical CSS inline.', 'probe-site-doctor' ),
				'LCP',
				(int) $totals['inline_bytes']
			);
		}

		// --- Images ---------------------------------------------------------
		$images  = (array) $profile['images'];
		$biggest = self::largest( $images, 1 );

		if ( $totals['image_bytes'] > 2 * MB_IN_BYTES ) {
			$issues[] = self::issue(
				'heavy-images',
				Severity::CRITICAL,
				sprintf(
					/* translators: 1: Total image size, 2: Number of images. */
					__( 'Images on this page weigh %1$s across %2$s files', 'probe-site-doctor' ),
					size_format( (int) $totals['image_bytes'], 1 ),
					number_format_i18n( (int) $totals['image_files'] )
				),
				__( 'Images are usually the largest element on the screen, so the biggest one normally *is* the Largest Contentful Paint. On a mobile connection this much image data takes seconds.', 'probe-site-doctor' ),
				__( 'Serve WebP or AVIF, resize files to the dimensions actually displayed, and compress them. An image optimisation plugin does all three in bulk.', 'probe-site-doctor' ),
				'LCP',
				(int) $totals['image_bytes']
			);
		} elseif ( $biggest && (int) $biggest[0]['bytes'] > 300 * KB_IN_BYTES ) {
			$issues[] = self::issue(
				'large-single-image',
				Severity::WARNING,
				sprintf(
					/* translators: 1: File name, 2: File size. */
					__( 'One image (%1$s) is %2$s on its own', 'probe-site-doctor' ),
					wp_basename( (string) wp_parse_url( (string) $biggest[0]['url'], PHP_URL_PATH ) ),
					size_format( (int) $biggest[0]['bytes'], 1 )
				),
				__( 'A single heavy image high on the page is the most common cause of a poor Largest Contentful Paint score.', 'probe-site-doctor' ),
				__( 'Re-export it at the displayed size, convert it to WebP/AVIF, and let WordPress serve responsive srcset sizes.', 'probe-site-doctor' ),
				'LCP',
				(int) $biggest[0]['bytes']
			);
		}

		$no_dimensions = array_values(
			array_filter( $images, static fn( $i ) => ( null === $i['width'] || '' === $i['width'] ) || ( null === $i['height'] || '' === $i['height'] ) )
		);
		if ( count( $no_dimensions ) > 0 ) {
			$issues[] = self::issue(
				'images-without-dimensions',
				count( $no_dimensions ) > 3 ? Severity::WARNING : Severity::INFO,
				sprintf(
					/* translators: %s: Number of images. */
					_n(
						'%s image has no width and height attributes',
						'%s images have no width and height attributes',
						count( $no_dimensions ),
						'probe-site-doctor'
					),
					number_format_i18n( count( $no_dimensions ) )
				),
				__( 'Without dimensions the browser cannot reserve space before the image arrives, so the page jumps as it loads. That jumping is exactly what Cumulative Layout Shift measures.', 'probe-site-doctor' ),
				__( 'Add width and height (or a CSS aspect-ratio) to those images. Images inserted through the WordPress editor get them automatically; hand-written theme templates often do not.', 'probe-site-doctor' ),
				'CLS',
				0
			);
		}

		$lazy = array_values( array_filter( $images, static fn( $i ) => 'lazy' === $i['loading'] ) );
		if ( count( $images ) > 5 && count( $lazy ) < 1 ) {
			$issues[] = self::issue(
				'no-lazy-loading',
				Severity::INFO,
				__( 'No images are lazy-loaded', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Number of images. */
					__( 'The page references %s images and none of them defer loading, so images far below the fold compete for bandwidth with what the visitor can actually see.', 'probe-site-doctor' ),
					number_format_i18n( count( $images ) )
				),
				__( 'WordPress adds loading="lazy" automatically to content images; theme templates that build <img> tags by hand need it added. Do not lazy-load the first, largest image.', 'probe-site-doctor' ),
				'LCP',
				0
			);
		}

		$hero = $images[0] ?? null;
		if ( $hero && 'lazy' === $hero['loading'] ) {
			$issues[] = self::issue(
				'lazy-hero',
				Severity::WARNING,
				__( 'The first image on the page is lazy-loaded', 'probe-site-doctor' ),
				__( 'Lazy-loading the image at the top of the page delays the very thing the visitor is waiting for: the browser only starts fetching it after layout, which pushes Largest Contentful Paint out by hundreds of milliseconds.', 'probe-site-doctor' ),
				__( 'Remove loading="lazy" from the hero image and add fetchpriority="high" instead.', 'probe-site-doctor' ),
				'LCP',
				(int) ( $hero['bytes'] ?? 0 )
			);
		}

		if ( count( $images ) > 0 && ! $profile['modern_images'] ) {
			$issues[] = self::issue(
				'legacy-image-formats',
				Severity::INFO,
				__( 'No WebP or AVIF images are used', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Total image size. */
					__( 'All %s of images are in older formats. WebP is typically 25–35%% smaller than JPEG at the same quality, and AVIF more still; both are supported by every current browser.', 'probe-site-doctor' ),
					size_format( (int) $totals['image_bytes'], 1 )
				),
				__( 'Convert the media library with an image optimisation plugin, which will also serve the modern format only to browsers that accept it.', 'probe-site-doctor' ),
				'LCP',
				(int) $totals['image_bytes']
			);
		}

		// --- Third parties and the rest -------------------------------------
		$third = (array) $profile['third_party'];
		if ( count( $third ) > 3 ) {
			$hosts    = array_slice( array_keys( $third ), 0, 6 );
			$issues[] = self::issue(
				'third-party',
				Severity::WARNING,
				sprintf(
					/* translators: %s: Number of hosts. */
					__( 'The page loads assets from %s other domains', 'probe-site-doctor' ),
					number_format_i18n( count( $third ) )
				),
				sprintf(
					/* translators: %s: Comma-separated host names. */
					__( 'Each new domain costs a DNS lookup, a TCP connection and a TLS handshake before anything downloads, and you do not control how fast it responds: %s. Site Doctor deliberately does not request third-party files, so their size is unknown here.', 'probe-site-doctor' ),
					implode( ', ', $hosts )
				),
				__( 'Remove what is not needed, self-host fonts and small scripts, and add <link rel="preconnect"> for the domains you keep. Tag managers are usually the largest single third-party cost.', 'probe-site-doctor' ),
				'LCP / INP',
				0
			);
		} elseif ( $third && ! array_filter( (array) $profile['hints'], static fn( $h ) => false !== strpos( $h['rel'], 'preconnect' ) ) ) {
			$issues[] = self::issue(
				'no-preconnect',
				Severity::INFO,
				__( 'Third-party domains are used without preconnect hints', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Comma-separated host names. */
					__( 'The page loads from %s but gives the browser no advance warning, so the connection setup happens only when the reference is discovered.', 'probe-site-doctor' ),
					implode( ', ', array_keys( $third ) )
				),
				__( 'Add <link rel="preconnect" href="https://example.com" crossorigin> in the head for domains that serve render-critical assets.', 'probe-site-doctor' ),
				'LCP',
				0
			);
		}

		$uncached = array_values(
			array_filter(
				(array) ( $profile['asset_headers'] ?? array() ),
				static function ( $sample ) {
					return 200 === (int) $sample['status']
						&& false === strpos( strtolower( (string) $sample['cache_control'] ), 'max-age' )
						&& '' === (string) $sample['expires'];
				}
			)
		);
		if ( $uncached ) {
			$issues[] = self::issue(
				'no-browser-caching',
				Severity::WARNING,
				__( 'Static assets are served without caching headers', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Number of files. */
					__( '%s of the CSS/JS files sampled came back with no Cache-Control max-age and no Expires header, so returning visitors re-download them on every page view.', 'probe-site-doctor' ),
					number_format_i18n( count( $uncached ) )
				),
				__( 'Set far-future caching for static assets on the web server or CDN (WordPress already adds a ?ver= query string, so cache busting keeps working).', 'probe-site-doctor' ),
				'LCP',
				0
			);
		}

		if ( (int) $profile['elements'] > 1500 ) {
			$issues[] = self::issue(
				'large-dom',
				Severity::INFO,
				sprintf(
					/* translators: %s: Number of elements. */
					__( 'The page contains about %s HTML elements', 'probe-site-doctor' ),
					number_format_i18n( (int) $profile['elements'] )
				),
				__( 'A large DOM makes style calculation, layout and JavaScript queries more expensive on every interaction, which is felt most on low-end phones.', 'probe-site-doctor' ),
				__( 'Reduce nesting from page builders, paginate long archives, and avoid rendering hidden duplicate markup (for example separate desktop and mobile menus).', 'probe-site-doctor' ),
				'INP / CLS',
				0
			);
		}

		if ( (int) $profile['iframes'] > 2 ) {
			$issues[] = self::issue(
				'many-embeds',
				Severity::INFO,
				sprintf(
					/* translators: %s: Number of iframes. */
					__( 'The page embeds %s iframes', 'probe-site-doctor' ),
					number_format_i18n( (int) $profile['iframes'] )
				),
				__( 'Each embed (video player, map, form, social widget) loads its own page with its own scripts. They are often heavier than the page hosting them.', 'probe-site-doctor' ),
				__( 'Add loading="lazy" to embeds, or replace video embeds with a click-to-play thumbnail so the player only loads on demand.', 'probe-site-doctor' ),
				'LCP / INP',
				0
			);
		}

		return $issues;
	}

	/**
	 * Cache indicators in the response headers.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @return array{cached:bool,signals:string[]}
	 */
	private static function cache_signals( array $profile ): array {
		$headers = array_merge( (array) $profile['headers'], (array) ( $profile['repeat_headers'] ?? array() ) );
		$signals = array();

		foreach ( array( 'x-cache', 'x-cache-status', 'cf-cache-status', 'x-litespeed-cache', 'x-proxy-cache', 'age', 'x-fastcgi-cache', 'x-nananana' ) as $header ) {
			if ( isset( $headers[ $header ] ) && '' !== (string) $headers[ $header ] ) {
				$signals[] = $header . ': ' . $headers[ $header ];
			}
		}
		$cache_control = strtolower( (string) ( $headers['cache-control'] ?? '' ) );
		if ( false !== strpos( $cache_control, 'max-age' ) && false === strpos( $cache_control, 'max-age=0' ) ) {
			$signals[] = 'cache-control: ' . $headers['cache-control'];
		}

		return array(
			'cached'  => (bool) $signals,
			'signals' => $signals,
		);
	}

	/**
	 * The largest assets of a list, with sizes known.
	 *
	 * @param array<int,array<string,mixed>> $assets Assets.
	 * @param int                            $limit  How many.
	 * @return array<int,array<string,mixed>>
	 */
	private static function largest( array $assets, int $limit ): array {
		$sized = array_values( array_filter( $assets, static fn( $a ) => null !== ( $a['bytes'] ?? null ) ) );
		usort( $sized, static fn( $a, $b ) => (int) $b['bytes'] <=> (int) $a['bytes'] );
		return array_slice( $sized, 0, $limit );
	}

	/**
	 * Builds one issue.
	 *
	 * @param string $id       Rule ID.
	 * @param string $severity Severity.
	 * @param string $title    What is wrong.
	 * @param string $evidence What was measured.
	 * @param string $fix      How to improve it.
	 * @param string $affects  Which metric it typically affects.
	 * @param int    $bytes    Weight, for ordering.
	 * @return array<string,mixed>
	 */
	private static function issue( string $id, string $severity, string $title, string $evidence, string $fix, string $affects, int $bytes ): array {
		return array(
			'id'       => $id,
			'severity' => $severity,
			'title'    => $title,
			'evidence' => $evidence,
			'fix'      => $fix,
			'affects'  => $affects,
			'bytes'    => $bytes,
		);
	}
}
