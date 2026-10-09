<?php
/**
 * Activation handler.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Activation callback.
	 *
	 * On network activation the current site is installed immediately and
	 * every other site installs itself on its next admin request (Upgrader),
	 * which avoids a long blocking loop on large networks.
	 *
	 * @param bool $network_wide Whether activated network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		Installer::install();
		set_transient( 'probesd_activated', 1, MINUTE_IN_SECONDS );
	}
}
