<?php
/**
 * Custom capabilities.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the plugin's capabilities and grants them to administrators.
 *
 * - probesd_view_reports: open the dashboard and read reports.
 * - probesd_run_scans:    start scans and delete stored reports.
 * - probesd_run_cleanup:  confirm and run cleanup operations. No cleanup
 *   operation exists yet; any future one also requires explicit, typed
 *   confirmation through ProbeSiteDoctor\Cleanup\ConfirmationGuard.
 *
 * Reports reveal server details (PHP/DB versions, plugin lists), so none of
 * these capabilities is granted to non-administrator roles by default.
 */
final class Capabilities {

	public const VIEW_REPORTS = 'probesd_view_reports';
	public const RUN_SCANS    = 'probesd_run_scans';
	public const RUN_CLEANUP  = 'probesd_run_cleanup';

	/**
	 * All capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::VIEW_REPORTS, self::RUN_SCANS, self::RUN_CLEANUP );
	}

	/**
	 * Grants all capabilities to the administrator role.
	 *
	 * @return void
	 */
	public static function grant(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}
		foreach ( self::all() as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes the capabilities from every role.
	 *
	 * @return void
	 */
	public static function revoke(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
