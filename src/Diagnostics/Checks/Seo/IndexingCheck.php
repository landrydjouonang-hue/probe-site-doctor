<?php
/**
 * Indexing-related configuration indicators check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Seo;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\FrontendSnapshot;
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews the settings and markup that decide whether search engines may
 * index the site: robots.txt rules, the homepage robots meta tag, the
 * canonical link, and how many published items are marked noindex by a
 * supported SEO plugin.
 *
 * Whether a page is actually indexed can only be seen in Google Search
 * Console; this check reports the configuration.
 */
final class IndexingCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.indexing';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Indexing configuration indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks robots.txt rules, the homepage robots meta tag and canonical link, and noindex settings on published content.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		global $wpdb;

		$snapshot = FrontendSnapshot::get();
		$robots   = Loopback::get( home_url( '/robots.txt' ), 8 );
		$body     = ! empty( $robots['ok'] ) ? (string) $robots['body'] : '';

		// A blanket "Disallow: /" for all user agents blocks crawling of the whole site.
		// Note: \R must not appear inside a character class, so line content uses [^\r\n].
		$blocks_all = (bool) preg_match( '/User-agent:\s*\*\s*(?:[\r\n]+(?:#[^\r\n]*[\r\n]+)*)?Disallow:\s*\/\s*(?:[\r\n]|$)/i', $body );
		$meta       = ! empty( $snapshot['ok'] ) ? strtolower( (string) ( $snapshot['meta_robots'] ?? '' ) ) : '';
		$noindex    = false !== strpos( $meta, 'noindex' );
		$canonical  = ! empty( $snapshot['ok'] ) ? (string) ( $snapshot['canonical'] ?? '' ) : '';
		$public     = '0' !== (string) get_option( 'blog_public' );

		// Noindex flags stored by Yoast and Rank Math, where present.
		$flagged = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_status = 'publish' AND p.post_type IN ( 'post', 'page' )
					AND ( ( m.meta_key = %s AND m.meta_value = '1' ) OR ( m.meta_key = %s AND m.meta_value LIKE %s ) )",
				'_yoast_wpseo_meta-robots-noindex',
				'rank_math_robots',
				'%noindex%'
			)
		);

		$data = array(
			'search_engines_allowed' => $public,
			'robots_txt_reachable'   => ! empty( $robots['ok'] ),
			'robots_txt_blocks_site' => $blocks_all,
			'homepage_meta_robots'   => '' !== $meta ? $meta : null,
			'canonical_url'          => '' !== $canonical ? $canonical : null,
			'published_noindex'      => $flagged,
		);

		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		if ( $blocks_all && ! $this->is_non_production() ) {
			$severity  = Severity::CRITICAL;
			$issues[]  = __( 'robots.txt tells every crawler to stay off the whole site (User-agent: * with Disallow: /).', 'probe-site-doctor' );
			$actions[] = __( 'Remove that Disallow rule. If the file is a real file in the web root, edit it there; if WordPress generates it, check the "Discourage search engines" setting and any SEO plugin.', 'probe-site-doctor' );
		} elseif ( $blocks_all ) {
			$severity = Severity::INFO;
			$issues[] = __( 'robots.txt blocks all crawlers, which is expected on a development or staging site.', 'probe-site-doctor' );
		}

		if ( $noindex && ! $this->is_non_production() ) {
			$severity  = $this->worst( $severity, Severity::CRITICAL );
			$issues[]  = sprintf(
				/* translators: %s: Robots meta content. */
				__( 'The homepage sends a robots meta tag of "%s", which asks search engines to drop it from their index.', 'probe-site-doctor' ),
				$meta
			);
			$actions[] = __( 'Find what sets noindex: Settings → Reading, your SEO plugin’s settings for the front page, or theme code.', 'probe-site-doctor' );
		} elseif ( $noindex ) {
			$severity = $this->worst( $severity, Severity::INFO );
			$issues[] = __( 'The homepage is marked noindex, which is normal for a development or staging site.', 'probe-site-doctor' );
		}

		if ( '' === $canonical && ! empty( $snapshot['ok'] ) ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = __( 'The homepage has no canonical link. WordPress normally outputs one, so this usually means the theme omits wp_head() output or a plugin removed it.', 'probe-site-doctor' );
			$actions[] = __( 'Make sure the theme calls wp_head(), or let your SEO plugin manage canonical links.', 'probe-site-doctor' );
		}

		if ( $flagged > 0 ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = sprintf(
				/* translators: %s: Number of items. */
				_n( '%s published item is marked noindex in its SEO settings.', '%s published items are marked noindex in their SEO settings.', $flagged, 'probe-site-doctor' ),
				number_format_i18n( $flagged )
			);
			$actions[] = __( 'Confirm that each of those pages should really stay out of search results.', 'probe-site-doctor' );
		}

		if ( ! empty( $robots['ok'] ) && '' === trim( $body ) ) {
			$severity = $this->worst( $severity, Severity::INFO );
			$issues[] = __( 'robots.txt is empty. That is harmless, but a sitemap reference there helps crawlers.', 'probe-site-doctor' );
		}

		$scope = __( 'These are configuration indicators. Whether pages are actually indexed, and why, can only be seen in Google Search Console or Bing Webmaster Tools.', 'probe-site-doctor' );

		if ( Severity::GOOD === $severity ) {
			return $this->good(
				__( 'Indexing configuration looks correct', 'probe-site-doctor' ),
				__( 'Search engines are allowed, robots.txt does not block the site, the homepage is indexable and has a canonical link.', 'probe-site-doctor' ) . ' ' . $scope . ' ' . $this->seo_scope_note(),
				$data
			);
		}

		return $this->result(
			$severity,
			Severity::CRITICAL === $severity ? __( 'The site is configured to stay out of search results', 'probe-site-doctor' ) : __( 'Indexing configuration has details worth reviewing', 'probe-site-doctor' ),
			implode( ' ', $issues ) . ' ' . $scope . ' ' . $this->seo_scope_note(),
			implode( ' ', $actions ),
			$data
		)->with_impact(
			Severity::CRITICAL === $severity ? Impact::HIGH : Impact::LOW,
			Severity::CRITICAL === $severity
				? __( 'While this is in place the site will not appear in search results at all, whatever else is optimised.', 'probe-site-doctor' )
				: __( 'These are small refinements; none of them keeps the site out of search results.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Change the indexing settings yourself. Site Doctor never edits settings, robots.txt or theme files.', 'probe-site-doctor' ),
				array(
					__( 'Check Settings → Reading for "Discourage search engines from indexing this site".', 'probe-site-doctor' ),
					__( 'Check your SEO plugin’s indexing settings for the front page and for individual pages.', 'probe-site-doctor' ),
					__( 'If a physical robots.txt exists in the web root, edit or remove the blocking rule there; WordPress only generates the file when no real file exists.', 'probe-site-doctor' ),
					__( 'After fixing it, request indexing in Google Search Console so the change is picked up sooner.', 'probe-site-doctor' ),
				),
				false
			) )->as_hardening()
				->with_command( __( 'Show the current setting with WP-CLI (changes nothing)', 'probe-site-doctor' ), 'wp option get blog_public', ManualCleanup::TYPE_WP_CLI )
		);
	}
}
