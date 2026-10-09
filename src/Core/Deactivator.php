<?php
/**
 * Deactivation handler.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation. Data is kept; it is only removed on uninstall.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		delete_transient( 'probesd_activated' );

		/**
		 * Fires on deactivation (e.g. for add-ons to clear scheduled events).
		 *
		 * @since 0.1.0
		 */
		do_action( 'probesd_deactivated' );
	}
}
