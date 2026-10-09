<?php
/**
 * Persistent object cache check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Performance;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Detects whether a persistent object cache (Redis, Memcached, …) is in use.
 */
final class ObjectCacheCheck extends AbstractCheck {

	public function get_id(): string {
		return 'performance.object-cache';
	}

	public function get_category(): string {
		return CategoryRegistry::PERFORMANCE;
	}

	public function get_label(): string {
		return __( 'Persistent object cache', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether WordPress stores cached data in a persistent backend such as Redis or Memcached.', 'probe-site-doctor' );
	}

	public function run(): Result {
		if ( wp_using_ext_object_cache() ) {
			return $this->good(
				__( 'A persistent object cache is active', 'probe-site-doctor' ),
				__( 'WordPress keeps cached query results between requests, reducing database load.', 'probe-site-doctor' ),
				array( 'dropin' => file_exists( WP_CONTENT_DIR . '/object-cache.php' ) )
			);
		}

		$available = array();
		foreach ( array( 'redis', 'memcached', 'memcache', 'apcu' ) as $extension ) {
			if ( extension_loaded( $extension ) ) {
				$available[] = $extension;
			}
		}

		$message = __( 'WordPress uses its default non-persistent cache, so cached data is rebuilt on every request.', 'probe-site-doctor' );
		if ( $available ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Comma-separated PHP extension names. */
				__( 'Your server has these cache-capable PHP extensions loaded: %s.', 'probe-site-doctor' ),
				implode( ', ', $available )
			);
		}

		// Small sites benefit little, so this is informational rather than a warning.
		return $this->info(
			__( 'No persistent object cache detected', 'probe-site-doctor' ),
			$message,
			__( 'For busy or dynamic sites (shops, membership sites), ask your host about Redis or Memcached and install a matching object cache plugin.', 'probe-site-doctor' ),
			array( 'available_extensions' => $available )
		);
	}
}
