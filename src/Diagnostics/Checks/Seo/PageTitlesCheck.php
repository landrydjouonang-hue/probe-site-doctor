<?php
/**
 * Page titles check.
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
use ProbeSiteDoctor\Diagnostics\Support\FrontendSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for published content without a title, titles used more than once,
 * and whether the homepage delivers a <title> element and a real tagline.
 */
final class PageTitlesCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.page-titles';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Page titles', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Finds published posts and pages without a title, duplicate titles, and checks the homepage title element.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$t = $this->thresholds( array( 'duplicate_warning' => 5 ) );

		$published  = ContentScan::published_count();
		$untitled   = ContentScan::missing_titles( 20 );
		$duplicates = ContentScan::duplicate_titles( 10 );
		$snapshot   = FrontendSnapshot::get();

		$home_title   = ! empty( $snapshot['ok'] ) ? (string) ( $snapshot['title'] ?? '' ) : null;
		$tagline      = (string) get_option( 'blogdescription' );
		$stock_line   = 'Just another WordPress site' === $tagline;
		$duplicated   = array_sum( array_column( $duplicates, 'count' ) );

		$data = array(
			'published_posts_pages' => $published,
			'without_title'         => count( $untitled ),
			'duplicate_title_groups' => count( $duplicates ),
			'homepage_title'        => $home_title,
			'tagline'               => '' === $tagline ? __( '(empty)', 'probe-site-doctor' ) : $tagline,
			'untitled_examples'      => $untitled,
			'duplicates'            => $duplicates,
		);

		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		if ( $untitled ) {
			$severity  = Severity::WARNING;
			$issues[]  = sprintf(
				/* translators: %s: Number of items. */
				_n( '%s published item has no title, so search results and browser tabs show a URL or a placeholder.', '%s published items have no title, so search results and browser tabs show a URL or a placeholder.', count( $untitled ), 'probe-site-doctor' ),
				number_format_i18n( count( $untitled ) )
			);
			$actions[] = __( 'Open the items listed in the details and give each a descriptive title.', 'probe-site-doctor' );
		}

		if ( $duplicated >= $t['duplicate_warning'] ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = sprintf(
				/* translators: 1: Number of items, 2: Number of groups. */
				__( '%1$s published items share only %2$s distinct titles. Identical titles make search results ambiguous and can look like duplicate pages.', 'probe-site-doctor' ),
				number_format_i18n( $duplicated ),
				number_format_i18n( count( $duplicates ) )
			);
			$actions[] = __( 'Give repeated titles distinguishing wording, or merge pages that really are duplicates.', 'probe-site-doctor' );
		}

		if ( null !== $home_title && '' === $home_title ) {
			$severity  = $this->worst( $severity, Severity::WARNING );
			$issues[]  = __( 'The homepage HTML contains no title element, which usually means the theme does not call wp_head() properly.', 'probe-site-doctor' );
			$actions[] = __( 'Make sure the theme calls wp_head() in header.php and declares support for the title tag.', 'probe-site-doctor' );
		}

		if ( $stock_line ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = __( 'The site tagline is still the WordPress default ("Just another WordPress site"), and many themes put it in the homepage title.', 'probe-site-doctor' );
			$actions[] = __( 'Set a real tagline under Settings → General, or clear it.', 'probe-site-doctor' );
		}

		$summary = sprintf(
			/* translators: 1: Number of items, 2: Homepage title. */
			__( '%1$s published posts and pages were checked. Homepage title: %2$s.', 'probe-site-doctor' ),
			number_format_i18n( $published ),
			null === $home_title ? __( 'not retrieved', 'probe-site-doctor' ) : ( '' === $home_title ? __( 'missing', 'probe-site-doctor' ) : '"' . $home_title . '"' )
		);

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Page titles look fine', 'probe-site-doctor' ), $summary . ' ' . $this->seo_scope_note(), $data );
		}

		return $this->result(
			$severity,
			$untitled ? __( 'Some published content has no title', 'probe-site-doctor' ) : __( 'Page titles could be improved', 'probe-site-doctor' ),
			$summary . ' ' . implode( ' ', $issues ) . ' ' . $this->seo_scope_note(),
			implode( ' ', $actions ),
			$data
		)->with_impact(
			$untitled ? Impact::MEDIUM : Impact::LOW,
			$untitled
				? __( 'A title is the main thing people see in search results and the strongest on-page signal about a page’s subject; pages without one rarely rank for anything useful.', 'probe-site-doctor' )
				: __( 'Duplicate or generic titles mostly cost click-throughs rather than indexing.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Edit the titles yourself. Site Doctor never changes content.', 'probe-site-doctor' ),
				array(
					__( 'Open each item listed in the details (Posts or Pages in the admin).', 'probe-site-doctor' ),
					__( 'Write a specific title that describes the page in its own words.', 'probe-site-doctor' ),
					__( 'For duplicates, add what makes each page different, or combine them and redirect one URL to the other.', 'probe-site-doctor' ),
				),
				false
			) )->as_content_fix()
				->with_command( __( 'List published items without a title (WP-CLI, changes nothing)', 'probe-site-doctor' ), 'wp post list --post_type=post,page --post_status=publish --fields=ID,post_title,post_name', ManualCleanup::TYPE_WP_CLI )
		);
	}
}
