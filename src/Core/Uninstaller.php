<?php
/**
 * Uninstall routine.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

use ProbeSiteDoctor\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Removes every trace of the plugin: tables, options, transients, capabilities.
 */
final class Uninstaller {

	/**
	 * Uninstalls from every site (multisite) or the single site.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::uninstall_site();
				restore_current_blog();
			}
			return;
		}

		self::uninstall_site();
	}

	/**
	 * Cleans up the current site.
	 *
	 * @return void
	 */
	private static function uninstall_site(): void {
		Schema::drop();

		delete_option( Settings::OPTION );
		delete_option( Installer::VERSION_OPTION );
		delete_option( Installer::DB_OPTION );
		delete_transient( 'probesd_activated' );
		delete_transient( 'probesd_frontend_snapshot' );

		delete_option( Migration::DONE_OPTION );

		// Pending cleanup challenges/tokens (probesd_cln_*), if any were ever created.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_probesd_cln_' ) . '%', $wpdb->esc_like( '_transient_timeout_probesd_cln_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		Capabilities::revoke();

		self::remove_legacy_leftovers();
	}

	/**
	 * Removes anything still carrying the names the plugin used before it was
	 * renamed — for a site that was restored from an old backup and never ran
	 * the migration.
	 *
	 * @return void
	 */
	private static function remove_legacy_leftovers(): void {
		global $wpdb;

		$legacy = Migration::legacy_names();

		foreach ( $legacy['tables'] as $table ) {
			// Table names are built from $wpdb->prefix, never from input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		}

		foreach ( $legacy['options'] as $option ) {
			delete_option( $option );
		}

		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $legacy['capabilities'] as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_sitedoctor_' ) . '%', $wpdb->esc_like( '_transient_timeout_sitedoctor_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
