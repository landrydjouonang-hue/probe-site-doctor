<?php
/**
 * Internal links check.
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
use ProbeSiteDoctor\Diagnostics\Support\ContentScan;
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Finds internal links in published content that lead nowhere.
 *
 * Two stages, both bounded:
 * 1. Links that resolve to a post or page which is no longer published are
 *    reported from the database alone — no request needed, no false positives.
 * 2. Links that do not resolve to a post (categories, archives, custom
 *    routes, files) are verified with a small number of loopback GET
 *    requests. Anything beyond that cap is reported as "not verified"
 *    rather than guessed, so a large site is never hammered.
 *
 * External links are never requested.
 */
final class InternalLinksCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.internal-links';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Broken internal links', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks internal links in published content: those pointing at unpublished posts, plus a capped sample verified by request.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$t = $this->thresholds(
			array(
				'scan_posts'      => 200,
				'max_links'       => 300,
				'verify_requests' => 20,
				'warning_broken'  => 1,
				'critical_broken' => 10,
			)
		);

		$scan = ContentScan::internal_links( (int) $t['scan_posts'], (int) $t['max_links'] );
		if ( ! $scan['links'] ) {
			return $this->good(
				__( 'No internal links to check', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Number of posts. */
					__( '%s published items were examined and none contained internal links.', 'probe-site-doctor' ),
					number_format_i18n( $scan['posts_scanned'] )
				) . ' ' . $this->seo_scope_note(),
				array( 'posts_scanned' => $scan['posts_scanned'] )
			);
		}

		$broken    = array();
		$unchecked = 0;
		$verified  = 0;

		foreach ( $scan['links'] as $url => $source ) {
			$post_id = url_to_postid( $url );
			if ( 0 === $post_id ) {
				// url_to_postid() only resolves published content, and trashing a post renames its
				// slug to "<slug>__trashed", so look the slug up directly before giving up.
				$post_id = $this->post_by_slug( $url );
			}

			if ( $post_id > 0 ) {
				$status = get_post_status( $post_id );
				if ( 'publish' !== $status ) {
					$broken[] = array(
						'link'      => $url,
						'status'    => sprintf(
							/* translators: %s: Post status such as draft or trash. */
							__( 'target is %s', 'probe-site-doctor' ),
							$status ? $status : __( 'missing', 'probe-site-doctor' )
						),
						'in_post'   => $source['title'],
					);
				}
				continue;
			}

			// Not a post URL: verify a limited number by request.
			if ( $verified >= $t['verify_requests'] ) {
				$unchecked++;
				continue;
			}
			$verified++;
			$probe = Loopback::get( $url, 8 );
			if ( 404 === (int) $probe['status'] || 410 === (int) $probe['status'] ) {
				$broken[] = array(
					'link'    => $url,
					'status'  => sprintf( 'HTTP %d', (int) $probe['status'] ),
					'in_post' => $source['title'],
				);
			}
		}

		$data = array(
			'posts_scanned'      => $scan['posts_scanned'],
			'internal_links'     => count( $scan['links'] ),
			'broken'             => count( $broken ),
			'verified_by_request' => $verified,
			'not_verified'       => $unchecked,
			'examples'           => array_slice( $broken, 0, 15 ),
		);

		$scope = sprintf(
			/* translators: 1: Number of links, 2: Number of posts, 3: Number of requests. */
			__( '%1$s distinct internal links from %2$s published items were examined; %3$s of them were verified with a request.', 'probe-site-doctor' ),
			number_format_i18n( count( $scan['links'] ) ),
			number_format_i18n( $scan['posts_scanned'] ),
			number_format_i18n( $verified )
		);
		if ( $scan['capped'] || $unchecked > 0 ) {
			$scope .= ' ' . sprintf(
				/* translators: %s: Number of links. */
				__( 'Limits keep the scan light, so this is a sample, not a full crawl: %s links were left unverified. A dedicated link checker or crawler covers the whole site.', 'probe-site-doctor' ),
				number_format_i18n( $unchecked )
			);
		}

		if ( ! $broken ) {
			return $this->good( __( 'No broken internal links found', 'probe-site-doctor' ), $scope . ' ' . $this->seo_scope_note(), $data );
		}

		$severity = count( $broken ) >= $t['critical_broken'] ? Severity::CRITICAL : Severity::WARNING;

		return $this->result(
			$severity,
			sprintf(
				/* translators: %s: Number of links. */
				_n( '%s internal link leads nowhere', '%s internal links lead nowhere', count( $broken ), 'probe-site-doctor' ),
				number_format_i18n( count( $broken ) )
			),
			sprintf(
				/* translators: %s: Number of links. */
				_n( '%s internal link points at content that is missing, in the trash or not published.', '%s internal links point at content that is missing, in the trash or not published.', count( $broken ), 'probe-site-doctor' ),
				number_format_i18n( count( $broken ) )
			) . ' ' . $scope . ' ' . $this->seo_scope_note(),
			__( 'Open the posts listed in the details and repoint or remove each link. If a page moved, add a redirect from the old URL so existing links and bookmarks keep working.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			Severity::CRITICAL === $severity ? Impact::MEDIUM : Impact::LOW,
			__( 'Visitors who follow the link hit a "not found" page, and search engines waste crawl budget on dead URLs. Fixing the links also recovers the internal linking between your pages.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Repoint or remove the links yourself. Site Doctor never edits content.', 'probe-site-doctor' ),
				array(
					__( 'Open each post listed in the details and check the link target.', 'probe-site-doctor' ),
					__( 'If the target was renamed or moved, update the link, or add a redirect from the old URL with a redirect plugin.', 'probe-site-doctor' ),
					__( 'If the target is in the trash but still needed, restore and publish it.', 'probe-site-doctor' ),
					__( 'For a complete picture, run a crawler over the whole site; this check samples content.', 'probe-site-doctor' ),
				),
				false
			) )->as_content_fix()
		);
	}

	/**
	 * Resolves a URL to a post of any status by its slug, including posts whose
	 * slug carries the "__trashed" suffix WordPress adds when trashing.
	 *
	 * @param string $url Internal URL.
	 * @return int Post ID, or 0 when nothing matches.
	 */
	private function post_by_slug( string $url ): int {
		global $wpdb;

		$path    = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$parts   = '' === $path ? array() : explode( '/', $path );
		$segment = $parts ? rawurldecode( (string) end( $parts ) ) : '';
		if ( '' === $segment || ! preg_match( '/^[A-Za-z0-9._~%-]+$/', $segment ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_name IN ( %s, %s ) AND post_type IN ( 'post', 'page' )
				ORDER BY FIELD( post_status, 'publish' ) DESC LIMIT 1",
				$segment,
				$segment . '__trashed'
			)
		);
	}
}
