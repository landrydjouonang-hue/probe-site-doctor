<?php
/**
 * WordPress memory limit check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Developer;

use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Compares WP_MEMORY_LIMIT and WP_MAX_MEMORY_LIMIT with the limit PHP is
 * actually running under.
 *
 * WordPress only ever raises the limit, never lowers it, and it does so with
 * ini_set() — which many hosts forbid. So the useful questions are whether the
 * raise can work at all, and whether the two constants are the right way
 * round. To answer the first, this check attempts a raise inside its own PHP
 * process and puts the original value back; nothing outside this request is
 * affected.
 */
final class WpMemoryLimitCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.wp-memory-limit';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'WordPress memory limit', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks WP_MEMORY_LIMIT and WP_MAX_MEMORY_LIMIT against the PHP memory limit that actually applies.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$memory    = SystemInfo::wp_memory();
		$php       = (int) $memory['php_bytes'];
		$wp        = (int) $memory['wp_bytes'];
		$wp_max    = (int) $memory['max_bytes'];
		$can_raise = $this->can_raise_limit();

		$data = array(
			'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : null,
			'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : null,
			'php_memory_limit'    => (string) ini_get( 'memory_limit' ),
			'runtime_can_raise'   => $can_raise,
			'wp_bytes'            => $wp,
			'php_bytes'           => $php,
		);

		if ( -1 === $php ) {
			return $this->info(
				__( 'PHP sets no memory limit, so the WordPress limits are the effective ones', 'probe-site-doctor' ),
				__( 'memory_limit is -1. WP_MEMORY_LIMIT (front end) and WP_MAX_MEMORY_LIMIT (admin and image processing) are what WordPress will apply.', 'probe-site-doctor' ),
				'',
				$data
			);
		}

		$wanted = max( $wp, $wp_max );
		if ( $wanted > $php && ! $can_raise ) {
			return $this->warning(
				__( 'WordPress cannot apply its memory limit on this host', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Requested limit, 2: Actual PHP limit. */
					__( 'wp-config.php asks for up to %1$s, but PHP is running with %2$s and this host does not allow the limit to be changed at runtime. WordPress raises memory with ini_set(), so the constants have no effect here and the real ceiling stays %2$s.', 'probe-site-doctor' ),
					size_format( $wanted, 0 ),
					size_format( $php, 0 )
				),
				__( 'Raise memory_limit in php.ini or your host’s PHP settings instead. Leaving the constants as they are does no harm, but they are not what applies.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Memory errors continue although the constant looks configured, which sends debugging in the wrong direction.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( $wp_max > 0 && $wp > $wp_max ) {
			return $this->warning(
				__( 'WP_MAX_MEMORY_LIMIT is lower than WP_MEMORY_LIMIT', 'probe-site-doctor' ),
				__( 'The admin limit is meant to be the higher of the two: WP_MEMORY_LIMIT applies to normal requests, WP_MAX_MEMORY_LIMIT to the admin and image processing. As configured, heavy admin work gets less memory than the front end.', 'probe-site-doctor' ),
				__( 'Set WP_MAX_MEMORY_LIMIT to at least WP_MEMORY_LIMIT, typically double it.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::LOW, __( 'Image resizing and bulk admin actions are the first to fail.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		return $this->good(
			sprintf(
				/* translators: %s: Effective memory limit. */
				__( 'Effective memory limit is %s', 'probe-site-doctor' ),
				size_format( max( $php, $wp ), 0 )
			),
			sprintf(
				/* translators: 1: WP_MEMORY_LIMIT, 2: WP_MAX_MEMORY_LIMIT, 3: PHP limit. */
				__( 'WP_MEMORY_LIMIT is %1$s and WP_MAX_MEMORY_LIMIT is %2$s, with PHP running at %3$s. WordPress only ever raises the limit, and this host allows it to do so, so admin work and image processing get the higher of these values.', 'probe-site-doctor' ),
				size_format( $wp, 0 ),
				$wp_max > 0 ? size_format( $wp_max, 0 ) : __( 'not set', 'probe-site-doctor' ),
				size_format( $php, 0 )
			),
			$data
		);
	}

	/**
	 * Whether PHP lets the memory limit be raised at runtime, which is how
	 * WordPress applies its constants.
	 *
	 * The probe raises the limit inside this process and immediately restores
	 * the original value, so nothing outside this request changes.
	 *
	 * @return bool
	 */
	private function can_raise_limit(): bool {
		$original = (string) ini_get( 'memory_limit' );
		$current  = SystemInfo::bytes_from_ini( $original );

		if ( -1 === $current ) {
			return true;
		}

		$target = (int) round( $current / MB_IN_BYTES ) + 16;
		// phpcs:ignore WordPress.PHP.IniSet.Risky, WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Probe only: this is how WordPress itself raises the limit, and the original value is restored immediately.
		$raised = @ini_set( 'memory_limit', $target . 'M' );
		$worked = false !== $raised && SystemInfo::bytes_from_ini( (string) ini_get( 'memory_limit' ) ) > $current;

		if ( false !== $raised ) {
			// phpcs:ignore WordPress.PHP.IniSet.Risky, WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restoring the value the request started with.
			@ini_set( 'memory_limit', $original );
		}

		return $worked;
	}

	/**
	 * Manual instructions.
	 *
	 * @return ManualCleanup
	 */
	private function steps(): ManualCleanup {
		return ( new ManualCleanup(
			__( 'Both limits are constants in wp-config.php, above the "stop editing" comment. Site Doctor never edits configuration files.', 'probe-site-doctor' ),
			array(
				__( 'Make sure the PHP memory_limit is at least as high as the value you set.', 'probe-site-doctor' ),
				__( 'Add or adjust the constants in wp-config.php.', 'probe-site-doctor' ),
				__( 'Re-run the scan: the values are read from the running process.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command(
				__( 'wp-config.php', 'probe-site-doctor' ),
				"define( 'WP_MEMORY_LIMIT', '256M' );\ndefine( 'WP_MAX_MEMORY_LIMIT', '512M' );",
				ManualCleanup::TYPE_PHP
			);
	}
}
