<?php
/**
 * PHP memory limit check.
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
 * Reports the PHP memory limit and how much of it this request used.
 *
 * Reads ini values; it never changes them.
 */
final class MemoryLimitCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.memory-limit';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'PHP memory limit', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks the PHP memory_limit against what WordPress, plugins and the admin typically need.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$thresholds = $this->thresholds(
			array(
				'critical_mb' => 64,
				'warning_mb'  => 128,
				'comfort_mb'  => 256,
			)
		);

		$limit = SystemInfo::bytes_from_ini( (string) ini_get( 'memory_limit' ) );
		$peak  = memory_get_peak_usage( true );

		$data = array(
			'memory_limit'     => (string) ini_get( 'memory_limit' ),
			'limit_bytes'      => $limit,
			'peak_bytes'       => $peak,
			'peak_pct_of_limit' => $limit > 0 ? (int) round( $peak / $limit * 100 ) : null,
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
		);

		if ( -1 === $limit ) {
			return $this->info(
				__( 'PHP memory is unlimited', 'probe-site-doctor' ),
				__( 'memory_limit is -1, so a single PHP request may consume as much memory as the server has. Nothing will fail for lack of memory, but a runaway plugin can exhaust the machine instead of erroring out.', 'probe-site-doctor' ),
				__( 'On a shared or small server, set an explicit limit such as 512M so one bad request cannot take the whole server down.', 'probe-site-doctor' ),
				$data
			);
		}

		if ( $limit <= 0 ) {
			return $this->skipped( __( 'The PHP memory limit could not be read from this environment.', 'probe-site-doctor' ) );
		}

		$mb = (int) round( $limit / MB_IN_BYTES );

		if ( $mb < $thresholds['critical_mb'] ) {
			return $this->critical(
				sprintf(
					/* translators: %s: Memory limit, e.g. "48 MB". */
					__( 'PHP memory limit is very low (%s)', 'probe-site-doctor' ),
					size_format( $limit, 0 )
				),
				__( 'WordPress asks for 64 MB by default and the block editor, image resizing and updates often need more. At this limit requests will fail with a fatal "allowed memory size exhausted" error, usually on media uploads or in the admin.', 'probe-site-doctor' ),
				__( 'Raise memory_limit to at least 256M in php.ini (or ask the host to). WP_MEMORY_LIMIT in wp-config.php cannot exceed the server limit.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Uploads, updates and editor saves can fail with a blank page or a 500 error.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( $mb < $thresholds['warning_mb'] ) {
			return $this->warning(
				sprintf(
					/* translators: %s: Memory limit. */
					__( 'PHP memory limit is tight (%s)', 'probe-site-doctor' ),
					size_format( $limit, 0 )
				),
				__( 'This is enough for a simple site but leaves little headroom for the block editor, WooCommerce, page builders, backups or image processing. Memory errors under this kind of limit usually appear as intermittent white screens.', 'probe-site-doctor' ),
				__( 'Raise memory_limit to 256M, which is the practical baseline for a plugin-heavy site.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Occasional fatal errors in the admin under load or on large operations.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( null !== $data['peak_pct_of_limit'] && $data['peak_pct_of_limit'] >= 80 ) {
			return $this->warning(
				__( 'This request came close to the memory limit', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Peak memory, 2: Limit, 3: Percentage. */
					__( 'Peak memory for this request was %1$s of a %2$s limit (%3$s%%). Site Doctor is a light workload, so heavier admin screens are likely to hit the ceiling.', 'probe-site-doctor' ),
					size_format( $peak, 1 ),
					size_format( $limit, 0 ),
					number_format_i18n( $data['peak_pct_of_limit'] )
				),
				__( 'Raise the limit, or find the plugin responsible with Query Monitor or a profiler before the site starts failing.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Little headroom left for heavier requests.', 'probe-site-doctor' ) );
		}

		return $this->good(
			sprintf(
				/* translators: %s: Memory limit. */
				__( 'PHP memory limit is %s', 'probe-site-doctor' ),
				size_format( $limit, 0 )
			),
			sprintf(
				/* translators: 1: Peak memory, 2: Comfortable limit. */
				__( 'That is at or above the %2$s baseline a plugin-heavy site needs. Peak memory for this request was %1$s.', 'probe-site-doctor' ),
				size_format( $peak, 1 ),
				size_format( $thresholds['comfort_mb'] * MB_IN_BYTES, 0 )
			),
			$data
		);
	}

	/**
	 * Manual instructions.
	 *
	 * @return ManualCleanup
	 */
	private function steps(): ManualCleanup {
		return ( new ManualCleanup(
			__( 'The limit lives in the server’s PHP configuration. Site Doctor never changes it.', 'probe-site-doctor' ),
			array(
				__( 'Preferred: set memory_limit in php.ini (or your host’s PHP settings panel) and restart PHP.', 'probe-site-doctor' ),
				__( 'If you only have WordPress access, WP_MEMORY_LIMIT can raise it up to the server ceiling, never above it.', 'probe-site-doctor' ),
				__( 'Re-run the scan afterwards: the reported value comes from the running PHP process.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'php.ini', 'probe-site-doctor' ), "memory_limit = 256M", ManualCleanup::TYPE_PHP )
			->with_command(
				__( 'wp-config.php (only within the server limit)', 'probe-site-doctor' ),
				"define( 'WP_MEMORY_LIMIT', '256M' );\ndefine( 'WP_MAX_MEMORY_LIMIT', '512M' );",
				ManualCleanup::TYPE_PHP
			);
	}
}
