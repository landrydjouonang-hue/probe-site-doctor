<?php
/**
 * Meta descriptions check.
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
 * Reports meta descriptions where they can be detected.
 *
 * WordPress core does not output meta descriptions, so what can be seen
 * depends on the site: the homepage HTML always, and per-page values only
 * when a supported SEO plugin stores them in post meta. When a plugin keeps
 * them elsewhere (its own tables), the per-page part is reported as not
 * detectable rather than guessed.
 */
final class MetaDescriptionsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.meta-descriptions';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Meta descriptions', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks the homepage meta description and, where an SEO plugin stores them in post meta, per-page descriptions.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$t = $this->thresholds( array( 'missing_share_warning' => 50 ) );

		$plugin   = ContentScan::seo_plugin();
		$snapshot = FrontendSnapshot::get();
		$home     = ! empty( $snapshot['ok'] ) ? $snapshot['meta_description'] : null;
		$home_has = is_string( $home ) && '' !== trim( $home );

		$per_page = null;
		if ( $plugin['active'] && '' !== $plugin['meta_key'] ) {
			$per_page = ContentScan::missing_descriptions( $plugin['meta_key'], 10 );
		}

		$data = array(
			'seo_plugin'               => $plugin['active'] ? $plugin['name'] : __( 'none detected', 'probe-site-doctor' ),
			'homepage_meta_description' => $home_has ? $home : null,
			'per_page_detectable'      => null !== $per_page,
		);
		if ( null !== $per_page ) {
			$data['published_checked']     = $per_page['checked'];
			$data['without_description']   = $per_page['missing'];
			$data['examples']              = $per_page['examples'];
		}

		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		if ( ! $home_has && ! empty( $snapshot['ok'] ) ) {
			$severity  = Severity::INFO;
			$issues[]  = $plugin['active']
				? __( 'The homepage sends no meta description, even though an SEO plugin is active.', 'probe-site-doctor' )
				: __( 'The homepage sends no meta description. WordPress does not create one on its own, so search engines write their own snippet from the page text.', 'probe-site-doctor' );
			$actions[] = $plugin['active']
				/* translators: %s: Plugin name. */
				? sprintf( __( 'Set the homepage description in %s.', 'probe-site-doctor' ), $plugin['name'] )
				: __( 'Install an SEO plugin (Yoast SEO, Rank Math, SEOPress and others are free) and write descriptions for your most important pages.', 'probe-site-doctor' );
		}

		if ( null !== $per_page && $per_page['checked'] > 0 ) {
			$share = (int) round( $per_page['missing'] / max( 1, $per_page['checked'] ) * 100 );
			$data['missing_share'] = $share . '%';
			if ( $share >= $t['missing_share_warning'] && $per_page['missing'] > 0 ) {
				$severity  = $this->worst( $severity, Severity::WARNING );
				$issues[]  = sprintf(
					/* translators: 1: Number of items, 2: Total, 3: Percentage. */
					__( '%1$s of %2$s published posts and pages (%3$s%%) have no description in %4$s, so the snippet is left to the search engine.', 'probe-site-doctor' ),
					number_format_i18n( $per_page['missing'] ),
					number_format_i18n( $per_page['checked'] ),
					number_format_i18n( $share ),
					$plugin['name']
				);
				$actions[] = __( 'Write descriptions for the pages that matter most (home, services, key posts) rather than all of them at once.', 'probe-site-doctor' );
			} elseif ( $per_page['missing'] > 0 ) {
				$severity = $this->worst( $severity, Severity::INFO );
				$issues[] = sprintf(
					/* translators: 1: Number of items, 2: Total. */
					__( '%1$s of %2$s published posts and pages have no description set.', 'probe-site-doctor' ),
					number_format_i18n( $per_page['missing'] ),
					number_format_i18n( $per_page['checked'] )
				);
			}
		}

		$detect = null === $per_page
			? ( $plugin['active']
				/* translators: %s: Plugin name. */
				? sprintf( __( '%s is active but stores descriptions outside post meta, so per-page descriptions could not be read here; check them in the plugin.', 'probe-site-doctor' ), $plugin['name'] )
				: __( 'No supported SEO plugin was detected, so per-page descriptions cannot be read; only the homepage was checked.', 'probe-site-doctor' ) )
			: '';

		$note = __( 'A missing description is not an error: Google often ignores the tag and writes its own snippet. It matters most on pages where you want to control the wording.', 'probe-site-doctor' );

		if ( Severity::GOOD === $severity ) {
			return $this->good(
				__( 'Meta descriptions are in place where they can be checked', 'probe-site-doctor' ),
				trim( ( $home_has ? __( 'The homepage sends a meta description.', 'probe-site-doctor' ) : '' ) . ' ' . $detect ) . ' ' . $this->seo_scope_note(),
				$data
			);
		}

		return $this->result(
			$severity,
			Severity::WARNING === $severity ? __( 'Most pages have no meta description', 'probe-site-doctor' ) : __( 'Meta descriptions are incomplete', 'probe-site-doctor' ),
			trim( implode( ' ', $issues ) . ' ' . $detect ) . ' ' . $note . ' ' . $this->seo_scope_note(),
			implode( ' ', $actions ),
			$data
		)->with_impact(
			Impact::LOW,
			__( 'Descriptions do not affect rankings directly; they influence how often people click your result. Writing them for a handful of important pages is usually enough.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Write the descriptions yourself. Site Doctor never edits content or plugin settings.', 'probe-site-doctor' ),
				array(
					__( 'Pick the pages that bring in visitors or sales first.', 'probe-site-doctor' ),
					__( 'In the editor, open the SEO plugin panel and write a description of roughly 120–160 characters that reads like an invitation, not a keyword list.', 'probe-site-doctor' ),
					__( 'Leave the rest to the search engine rather than writing filler for every page.', 'probe-site-doctor' ),
				),
				false
			) )->as_content_fix()
		);
	}
}
