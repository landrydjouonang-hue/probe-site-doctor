<?php
/**
 * Debug configuration check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Configuration;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for debug settings that should not be active on a live site.
 */
final class DebugModeCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.debug-mode';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'Debug settings', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks WP_DEBUG, on-screen error display and whether the debug log is written to a publicly reachable location.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$debug   = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display = $debug && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY ) && filter_var( ini_get( 'display_errors' ), FILTER_VALIDATE_BOOLEAN );
		$log     = $debug && defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;

		// WP_DEBUG_LOG === true (or "1") writes to wp-content/debug.log, which is web-accessible by default.
		$public_log = $debug && ( true === $log || '1' === $log || ( is_string( $log ) && $this->is_inside_webroot( $log ) ) );

		$data = array(
			'wp_debug'         => $debug,
			'script_debug'     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'display_errors'   => $display,
			'debug_log'        => (bool) $log,
			'log_in_webroot'   => $public_log,
			'environment_type' => wp_get_environment_type(),
		);

		if ( ! $debug ) {
			return $this->good(
				__( 'Debug mode is off', 'probe-site-doctor' ),
				__( 'WP_DEBUG is disabled, as recommended for live sites.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				$data
			);
		}

		if ( $this->is_non_production() ) {
			return $this->info(
				__( 'Debug mode is on (non-production environment)', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Environment type such as "local" or "staging". */
					__( 'WP_DEBUG is enabled and the environment type is "%s", which is expected during development.', 'probe-site-doctor' ),
					wp_get_environment_type()
				),
				__( 'Make sure debugging is switched off before this configuration is used on the live site.', 'probe-site-doctor' ),
				$data
			);
		}

		if ( $display ) {
			return $this->critical(
				__( 'PHP errors are displayed to visitors', 'probe-site-doctor' ),
				__( 'WP_DEBUG is on and errors are printed on screen. Error messages can reveal file paths and other internals to anyone viewing the site.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				__( 'In wp-config.php set WP_DEBUG to false, or at least set WP_DEBUG_DISPLAY to false and log errors instead.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Error output can reveal file paths, database details and plugin internals to any visitor, and it breaks pages visually.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( $public_log ) {
			return $this->warning(
				__( 'Debug log is stored in a public location', 'probe-site-doctor' ),
				__( 'WP_DEBUG_LOG writes to a file inside the web root (by default wp-content/debug.log), which can often be downloaded by anyone who knows the URL.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				__( 'Set WP_DEBUG_LOG to an absolute path outside the web root, block access to the log file in the server configuration, or disable debugging on the live site.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'A downloadable debug log can disclose paths, queries and occasionally tokens; it also grows without limit.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		return $this->info(
			__( 'Debug mode is on', 'probe-site-doctor' ),
			__( 'WP_DEBUG is enabled on a production environment. Errors are not displayed and are not logged to a public location.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
			__( 'Disable WP_DEBUG once troubleshooting is finished; it adds overhead and can surface notices from plugins.', 'probe-site-doctor' ),
			$data
		)->with_impact( Impact::LOW, __( 'Nothing is exposed to visitors right now; debugging only adds overhead and noise.', 'probe-site-doctor' ) )
			->with_cleanup( $this->steps() );
	}

	/**
	 * Manual steps for turning debugging off.
	 *
	 * @return ManualCleanup
	 */
	private function steps(): ManualCleanup {
		return ( new ManualCleanup(
			__( 'Change the debug settings in wp-config.php yourself. Site Doctor never edits configuration files.', 'probe-site-doctor' ),
			array(
				__( 'Edit wp-config.php and set the constants as shown below.', 'probe-site-doctor' ),
				__( 'If you need logging, point WP_DEBUG_LOG at a file outside the web root.', 'probe-site-doctor' ),
				__( 'Delete any existing wp-content/debug.log after checking what it contains.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command(
				__( 'Production debug settings (wp-config.php)', 'probe-site-doctor' ),
				"define( 'WP_DEBUG', false );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'WP_DEBUG_LOG', false );",
				ManualCleanup::TYPE_PHP
			);
	}

	/**
	 * Whether a path lies inside the WordPress root directory.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_inside_webroot( string $path ): bool {
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		$path = wp_normalize_path( $path );
		return '' !== $root && 0 === stripos( $path, $root . '/' );
	}
}
