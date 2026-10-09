<?php
/**
 * Search engine visibility check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Seo;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the "Discourage search engines from indexing this site" setting.
 */
final class SearchVisibilityCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.search-visibility';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Search engine visibility', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether WordPress asks search engines not to index the site.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$public = '0' !== (string) get_option( 'blog_public' );
		$data   = array(
			'blog_public'      => $public,
			'environment_type' => wp_get_environment_type(),
		);

		if ( $public ) {
			return $this->good(
				__( 'Search engines may index the site', 'probe-site-doctor' ),
				__( 'The "Discourage search engines" setting is off.', 'probe-site-doctor' ),
				$data
			);
		}

		if ( $this->is_non_production() ) {
			return $this->info(
				__( 'Search engines are discouraged (non-production environment)', 'probe-site-doctor' ),
				__( 'Indexing is discouraged, which is appropriate for a development or staging site.', 'probe-site-doctor' ),
				__( 'Remember to turn this off when the site goes live.', 'probe-site-doctor' ),
				$data
			);
		}

		return $this->critical(
			__( 'Search engines are asked not to index the site', 'probe-site-doctor' ),
			__( 'Settings → Reading → "Discourage search engines from indexing this site" is enabled, so the site is likely to disappear from search results.', 'probe-site-doctor' ),
			__( 'If this is the live site, untick the option under Settings → Reading. If it is a staging site, set WP_ENVIRONMENT_TYPE to "staging" in wp-config.php.', 'probe-site-doctor' ),
			$data
		);
	}
}
