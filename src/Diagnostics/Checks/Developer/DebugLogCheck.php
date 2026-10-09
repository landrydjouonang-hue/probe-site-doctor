<?php
/**
 * Debug log check.
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
 * Reports the debug log itself: whether it exists, how big it is and how
 * recently PHP wrote to it.
 *
 * A log that is being written constantly is the fastest way to find a broken
 * plugin, and a log nobody empties eventually fills the disk. The debug
 * *settings* (and whether the log sits in a public location) are covered by
 * configuration.debug-mode.
 *
 * Site Doctor reads file metadata only: the log contents are never read,
 * copied into a report or deleted.
 */
final class DebugLogCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.debug-log';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'Debug log', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks the size and recency of the PHP/WordPress debug log. Its contents are never read.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$thresholds = $this->thresholds(
			array(
				'large_mb'  => 10,
				'huge_mb'   => 100,
				'fresh_min' => 60,
			)
		);

		$debug = SystemInfo::debug();
		$path  = (string) $debug['log_path'];

		if ( ! $debug['exists'] ) {
			return $this->good(
				__( 'No debug log file is present', 'probe-site-doctor' ),
				defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG
					? __( 'Logging is enabled but no log file has been created yet, so PHP has not recorded an error since it was configured.', 'probe-site-doctor' )
					: __( 'Debug logging is off and no log file exists. Enable WP_DEBUG_LOG temporarily when you need to trace an error, and point it outside the web root.', 'probe-site-doctor' ),
				array(
					'log_configured' => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
					'log_exists'     => false,
				)
			);
		}

		$bytes = (int) $debug['log_bytes'];
		$mtime = (int) $debug['log_mtime'];
		$age   = max( 0, time() - $mtime );

		$data = array(
			'log_path'       => $path,
			'log_bytes'      => $bytes,
			'last_write_ago' => human_time_diff( $mtime, time() ),
			'in_web_root'    => (bool) $debug['public'],
			'note'           => __( 'Only the file size and timestamp were read; the log contents are never included in a report.', 'probe-site-doctor' ),
		);

		if ( $bytes > $thresholds['huge_mb'] * MB_IN_BYTES ) {
			return $this->critical(
				sprintf(
					/* translators: %s: File size. */
					__( 'The debug log has grown to %s', 'probe-site-doctor' ),
					size_format( $bytes, 1 )
				),
				__( 'A log this size means something is erroring on nearly every request, and it will keep growing until the disk fills. Writing to it also costs time on every single page load.', 'probe-site-doctor' ),
				__( 'Read the last lines to find the repeating error and fix it, then truncate or remove the file. Do not simply delete it and move on: the underlying error is still happening.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Disk exhaustion takes the whole site down, and every request pays for the log writes.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps( $path ) );
		}

		if ( $bytes > $thresholds['large_mb'] * MB_IN_BYTES ) {
			return $this->warning(
				sprintf(
					/* translators: %s: File size. */
					__( 'The debug log is large (%s)', 'probe-site-doctor' ),
					size_format( $bytes, 1 )
				),
				sprintf(
					/* translators: %s: Human-readable time difference. */
					__( 'PHP last wrote to it %s ago. A log that keeps growing usually points at one repeating notice or deprecation from a plugin or theme rather than a one-off failure.', 'probe-site-doctor' ),
					$data['last_write_ago']
				),
				__( 'Look at the most frequent entries, fix or report the source, then rotate the file. Keep logging off on production once you are done.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Wasted disk and I/O, and real errors get buried in noise.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps( $path ) );
		}

		if ( $age < $thresholds['fresh_min'] * MINUTE_IN_SECONDS ) {
			return $this->info(
				__( 'PHP is writing to the debug log right now', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Human-readable time difference, 2: File size. */
					__( 'The last entry was %1$s ago and the file is %2$s. Something on the site is producing PHP notices, warnings or errors — worth reading while you are troubleshooting.', 'probe-site-doctor' ),
					$data['last_write_ago'],
					size_format( $bytes, 1 )
				),
				__( 'Read the tail of the log to see what is failing. Site Doctor deliberately does not display log contents, because logs can contain paths, queries and occasionally tokens.', 'probe-site-doctor' ),
				$data
			)->with_cleanup( $this->steps( $path ) );
		}

		return $this->good(
			sprintf(
				/* translators: %s: File size. */
				__( 'The debug log is small (%s)', 'probe-site-doctor' ),
				size_format( $bytes, 1 )
			),
			sprintf(
				/* translators: %s: Human-readable time difference. */
				__( 'Nothing has been written for %s, so PHP is not reporting errors at the moment.', 'probe-site-doctor' ),
				$data['last_write_ago']
			),
			$data
		);
	}

	/**
	 * Manual instructions.
	 *
	 * @param string $path Log path.
	 * @return ManualCleanup
	 */
	private function steps( string $path ): ManualCleanup {
		return ( new ManualCleanup(
			__( 'Inspect and rotate the log yourself. Site Doctor never reads, moves or deletes it.', 'probe-site-doctor' ),
			array(
				__( 'Read the last entries to identify the repeating error.', 'probe-site-doctor' ),
				__( 'Fix or report the plugin/theme responsible before clearing the file.', 'probe-site-doctor' ),
				__( 'Truncate the log (keeping the file so PHP can keep writing), or move it outside the web root.', 'probe-site-doctor' ),
			),
			true
		) )->with_command( __( 'Read the last 50 entries', 'probe-site-doctor' ), 'tail -n 50 "' . $path . '"', ManualCleanup::TYPE_WP_CLI )
			->with_command( __( 'Most frequent messages', 'probe-site-doctor' ), 'sort "' . $path . '" | uniq -c | sort -rn | head -n 20', ManualCleanup::TYPE_WP_CLI )
			->with_command( __( 'Truncate after reading it', 'probe-site-doctor' ), ': > "' . $path . '"', ManualCleanup::TYPE_WP_CLI );
	}
}
