<?php
/**
 * OPcache check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Performance;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies that PHP OPcache is enabled for the web SAPI.
 */
final class OpcacheCheck extends AbstractCheck {

	public function get_id(): string {
		return 'performance.opcache';
	}

	public function get_category(): string {
		return CategoryRegistry::PERFORMANCE;
	}

	public function get_label(): string {
		return __( 'PHP OPcache', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether PHP caches compiled scripts in memory.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$recommendation = __( 'Ask your host to enable the Zend OPcache extension (opcache.enable=1). It is standard on most production PHP installs and speeds up every request.', 'probe-site-doctor' );

		if ( ! extension_loaded( 'Zend OPcache' ) ) {
			return $this->warning(
				__( 'OPcache is not installed', 'probe-site-doctor' ),
				__( 'PHP recompiles every WordPress file on every request.', 'probe-site-doctor' ),
				$recommendation
			);
		}

		if ( ! filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
			return $this->warning(
				__( 'OPcache is installed but disabled', 'probe-site-doctor' ),
				__( 'The extension is loaded but opcache.enable is off, so compiled scripts are not cached.', 'probe-site-doctor' ),
				$recommendation
			);
		}

		$data = array(
			'memory_mb' => (int) ini_get( 'opcache.memory_consumption' ),
		);

		// opcache_get_status() may be restricted via opcache.restrict_api; it is optional detail.
		if ( function_exists( 'opcache_get_status' ) ) {
			$status = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $status ) && isset( $status['memory_usage'] ) ) {
				$used  = (float) $status['memory_usage']['used_memory'];
				$free  = (float) $status['memory_usage']['free_memory'];
				$total = $used + $free;

				$data['memory_used_pct'] = $total > 0 ? round( $used / $total * 100, 1 ) : null;
				$data['cache_full']      = ! empty( $status['cache_full'] );

				if ( ! empty( $status['cache_full'] ) ) {
					return $this->warning(
						__( 'OPcache memory is full', 'probe-site-doctor' ),
						__( 'OPcache is enabled but has run out of memory, so some scripts are no longer cached.', 'probe-site-doctor' ),
						__( 'Increase opcache.memory_consumption (and opcache.max_accelerated_files if needed).', 'probe-site-doctor' ),
						$data
					);
				}
			}
		}

		return $this->good(
			__( 'OPcache is enabled', 'probe-site-doctor' ),
			__( 'Compiled PHP scripts are cached in memory.', 'probe-site-doctor' ),
			$data
		);
	}
}
