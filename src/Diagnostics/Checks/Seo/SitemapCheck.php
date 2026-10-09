<?php
/**
 * Sitemap availability check.
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
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether an XML sitemap is reachable and referenced in robots.txt.
 *
 * WordPress ships its own sitemap at /wp-sitemap.xml; SEO plugins usually
 * replace it with /sitemap_index.xml or /sitemap.xml. Each candidate is
 * requested once and accepted only when it really returns XML.
 */
final class SitemapCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.sitemap';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Sitemap availability', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether an XML sitemap is reachable and whether robots.txt points to it.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		// Core sitemaps are switched on unless something filters them off (there is no function for this, only the filter).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a WordPress core filter, not declaring one.
		$core_on    = function_exists( 'wp_sitemaps_get_server' ) && (bool) apply_filters( 'wp_sitemaps_enabled', true );
		$candidates = array();
		if ( $core_on ) {
			$candidates[] = home_url( '/wp-sitemap.xml' );
		}
		foreach ( array( '/sitemap_index.xml', '/sitemap.xml' ) as $path ) {
			$candidates[] = home_url( $path );
		}

		$found   = null;
		$tried   = array();
		$entries = null;
		foreach ( $candidates as $url ) {
			$probe   = Loopback::get( $url, 8 );
			$body    = (string) $probe['body'];
			$is_xml  = 200 === (int) $probe['status'] && ( false !== stripos( $body, '<urlset' ) || false !== stripos( $body, '<sitemapindex' ) );
			$tried[] = array(
				'url'    => $url,
				'status' => 0 === (int) $probe['status'] ? __( 'no response', 'probe-site-doctor' ) : (string) (int) $probe['status'],
				'xml'    => $is_xml,
			);
			if ( $is_xml && null === $found ) {
				$found   = $url;
				$entries = preg_match_all( '/<(?:url|sitemap)>/i', $body );
			}
		}

		$robots    = Loopback::get( home_url( '/robots.txt' ), 8 );
		$robots_ok = ! empty( $robots['ok'] );
		$declared  = $robots_ok && preg_match( '/^\s*Sitemap:\s*(\S+)/im', (string) $robots['body'], $m );

		$data = array(
			'sitemap_url'              => $found,
			'entries_in_first_file'    => $found ? (int) $entries : null,
			'core_sitemaps_enabled'    => $core_on,
			'declared_in_robots_txt'   => (bool) $declared,
			'robots_sitemap_line'      => $declared ? trim( (string) $m[1] ) : null,
			'urls_tried'               => $tried,
		);

		$scope = __( 'Only availability and the robots.txt reference are checked here: the sitemap contents, which URLs it lists and whether search engines have fetched it are not. Submit the sitemap in Google Search Console to see that.', 'probe-site-doctor' );

		if ( null === $found ) {
			return $this->warning(
				__( 'No XML sitemap was found', 'probe-site-doctor' ),
				__( 'None of the usual sitemap addresses returned XML (wp-sitemap.xml, sitemap_index.xml, sitemap.xml). A sitemap helps search engines discover pages that are not linked prominently, especially on larger or newer sites.', 'probe-site-doctor' )
					. ( false === $core_on ? ' ' . __( 'The built-in WordPress sitemap is switched off, and no plugin sitemap answered either.', 'probe-site-doctor' ) : '' )
					. ' ' . $scope . ' ' . $this->seo_scope_note(),
				__( 'Re-enable the built-in WordPress sitemap, or let your SEO plugin generate one, then add a Sitemap: line to robots.txt and submit the URL in Google Search Console.', 'probe-site-doctor' ),
				$data
			)->with_impact(
				Impact::MEDIUM,
				__( 'Without a sitemap, discovery relies entirely on internal links. Established, well-linked sites cope; new pages and large archives are found more slowly.', 'probe-site-doctor' )
			)->with_cleanup( $this->steps() );
		}

		if ( ! $declared ) {
			return $this->info(
				__( 'A sitemap is available but not referenced in robots.txt', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Sitemap URL, 2: Number of entries. */
					__( 'The sitemap at %1$s responds with XML and lists %2$s entries in its first file, but robots.txt contains no Sitemap: line.', 'probe-site-doctor' ),
					$found,
					number_format_i18n( (int) $entries )
				) . ' ' . $scope . ' ' . $this->seo_scope_note(),
				__( 'Add a Sitemap: line to robots.txt (most SEO plugins do this for you) and submit the sitemap in Google Search Console.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::LOW, __( 'Crawlers find the common sitemap addresses anyway; the reference just makes it explicit.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		return $this->good(
			__( 'An XML sitemap is available', 'probe-site-doctor' ),
			sprintf(
				/* translators: 1: Sitemap URL, 2: Number of entries. */
				__( '%1$s responds with XML and lists %2$s entries in its first file, and robots.txt points to it.', 'probe-site-doctor' ),
				$found,
				number_format_i18n( (int) $entries )
			) . ' ' . $scope . ' ' . $this->seo_scope_note(),
			$data
		);
	}

	/**
	 * Manual steps for providing a sitemap.
	 *
	 * @return ManualCleanup
	 */
	private function steps(): ManualCleanup {
		return ( new ManualCleanup(
			__( 'Set the sitemap up yourself. Site Doctor never changes settings or files.', 'probe-site-doctor' ),
			array(
				__( 'If you use an SEO plugin, enable its XML sitemap; otherwise make sure the built-in WordPress sitemap is not disabled.', 'probe-site-doctor' ),
				__( 'Open the sitemap URL in a browser and confirm it lists your pages.', 'probe-site-doctor' ),
				__( 'Add a Sitemap: line to robots.txt pointing at that URL.', 'probe-site-doctor' ),
				__( 'Submit the sitemap in Google Search Console (and Bing Webmaster Tools) so crawling picks it up.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command(
				__( 'Re-enable the built-in WordPress sitemap (PHP, only needed if something disabled it)', 'probe-site-doctor' ),
				"remove_all_filters( 'wp_sitemaps_enabled' );\nadd_filter( 'wp_sitemaps_enabled', '__return_true' );",
				ManualCleanup::TYPE_PHP
			)
			->with_command(
				__( 'Example robots.txt line', 'probe-site-doctor' ),
				'Sitemap: ' . home_url( '/wp-sitemap.xml' ),
				ManualCleanup::TYPE_PHP
			);
	}
}
