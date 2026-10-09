<?php
/**
 * Permalink structure check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Seo;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that "pretty" permalinks are enabled.
 */
final class PermalinkStructureCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.permalink-structure';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Permalink structure', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether URLs use readable permalinks instead of query strings like ?p=123.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$structure = (string) get_option( 'permalink_structure' );
		$data      = array( 'structure' => $structure );

		if ( '' === $structure ) {
			return $this->warning(
				__( 'Plain permalinks are in use', 'probe-site-doctor' ),
				__( 'URLs look like ?p=123. Descriptive URLs are easier for people to read and share and give search engines extra context.', 'probe-site-doctor' ),
				__( 'Choose a structure such as "Post name" under Settings → Permalinks. On an established site, add redirects from the old URLs.', 'probe-site-doctor' ),
				$data
			);
		}

		if ( 0 === strpos( $structure, '/index.php' ) ) {
			return $this->info(
				__( 'Permalinks include index.php', 'probe-site-doctor' ),
				__( 'Readable permalinks are enabled but every URL contains /index.php/, usually because URL rewriting is unavailable on the server.', 'probe-site-doctor' ),
				__( 'Ask your host to enable URL rewriting (mod_rewrite or equivalent) so /index.php/ can be removed.', 'probe-site-doctor' ),
				$data
			);
		}

		return $this->good(
			__( 'Readable permalinks are enabled', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: Permalink structure tag string. */
				__( 'The permalink structure is %s.', 'probe-site-doctor' ),
				$structure
			),
			$data
		);
	}
}
