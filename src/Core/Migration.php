<?php
/**
 * Migration from the plugin's former identifiers.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

use ProbeSiteDoctor\Storage\Schema;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Renaming the plugin's own tables.

/**
 * Carries an existing install over from the names the plugin used when it was
 * called "WP Site Doctor" (`sitedoctor_*`, `SITEDOCTOR_*`, `site-doctor/v1`) to
 * the current ones (`probesd_*`).
 *
 * WordPress.org does not allow "wp" in a plugin name or slug, so the rename was
 * not optional — but nobody's stored reports should be lost to it. This runs
 * once, before the schema is installed, and is a no-op on a fresh install and on
 * every load afterwards.
 *
 * Scans, results and field data are kept by renaming the tables rather than
 * copying rows, so it is fast and safe on large tables.
 */
final class Migration {

	/**
	 * Option that records that the migration has already run.
	 */
	public const DONE_OPTION = 'probesd_migrated_from_wp_site_doctor';

	/**
	 * Table suffixes, in both naming schemes.
	 *
	 * @var string[]
	 */
	private const TABLES = array( 'scans', 'results', 'vitals' );

	/**
	 * Legacy option name => current option name.
	 *
	 * @var array<string,string>
	 */
	private const OPTIONS = array(
		'sitedoctor_settings'   => 'probesd_settings',
		'sitedoctor_version'    => 'probesd_version',
		'sitedoctor_db_version' => 'probesd_db_version',
	);

	/**
	 * Legacy capability => current capability.
	 *
	 * @var array<string,string>
	 */
	private const CAPABILITIES = array(
		'sitedoctor_view_reports' => 'probesd_view_reports',
		'sitedoctor_run_scans'    => 'probesd_run_scans',
		'sitedoctor_run_cleanup'  => 'probesd_run_cleanup',
	);

	/**
	 * Runs the migration if this site still carries the old names.
	 *
	 * Must run before Schema::install(), so dbDelta does not create empty
	 * tables that the legacy ones would then have to be merged into.
	 *
	 * @return bool Whether anything was migrated.
	 */
	public static function maybe_run(): bool {
		if ( get_option( self::DONE_OPTION ) ) {
			return false;
		}

		if ( ! self::has_legacy_data() ) {
			// A fresh install: record it so this never looks again.
			update_option( self::DONE_OPTION, PROBESD_VERSION, false );
			return false;
		}

		$tables  = self::migrate_tables();
		$options = self::migrate_options();
		$caps    = self::migrate_capabilities();
		self::delete_legacy_transients();

		update_option( self::DONE_OPTION, PROBESD_VERSION, false );

		/**
		 * Fires after an install has been migrated from the former plugin names.
		 *
		 * @since 0.9.0
		 *
		 * @param int $tables       Tables renamed.
		 * @param int $options      Options renamed.
		 * @param int $capabilities Capabilities renamed.
		 */
		do_action( 'probesd_migrated', $tables, $options, $caps );

		return true;
	}

	/**
	 * Whether anything from the old naming scheme is still present.
	 *
	 * @return bool
	 */
	public static function has_legacy_data(): bool {
		foreach ( array_keys( self::OPTIONS ) as $legacy ) {
			if ( null !== get_option( $legacy, null ) ) {
				return true;
			}
		}

		foreach ( self::TABLES as $suffix ) {
			if ( self::table_exists( self::legacy_table( $suffix ) ) ) {
				return true;
			}
		}

		$role = get_role( 'administrator' );

		return $role && $role->has_cap( 'sitedoctor_view_reports' );
	}

	/**
	 * Renames the scans, results and field-data tables.
	 *
	 * @return int Tables renamed.
	 */
	private static function migrate_tables(): int {
		global $wpdb;
		$renamed = 0;

		foreach ( self::TABLES as $suffix ) {
			$legacy  = self::legacy_table( $suffix );
			$current = $wpdb->prefix . 'probesd_' . $suffix;

			if ( ! self::table_exists( $legacy ) || $legacy === $current ) {
				continue;
			}

			if ( self::table_exists( $current ) ) {
				// A new, empty table was created before this ran: drop it so the
				// one holding the data can take its place. If it already has rows,
				// leave both alone rather than guess which is wanted.
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$current}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $rows > 0 ) {
					continue;
				}
				$wpdb->query( "DROP TABLE IF EXISTS `{$current}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			}

			// Table names come from $wpdb->prefix and this class's own constants.
			if ( false !== $wpdb->query( "RENAME TABLE `{$legacy}` TO `{$current}`" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$renamed++;
			}
		}

		return $renamed;
	}

	/**
	 * Moves the stored options to their new names, keeping their values.
	 *
	 * @return int Options moved.
	 */
	private static function migrate_options(): int {
		$moved = 0;

		foreach ( self::OPTIONS as $legacy => $current ) {
			$value = get_option( $legacy, null );
			if ( null === $value ) {
				continue;
			}

			// The settings option is deliberately not autoloaded.
			if ( null === get_option( $current, null ) ) {
				add_option( $current, $value, '', false );
			}
			delete_option( $legacy );
			$moved++;
		}

		return $moved;
	}

	/**
	 * Grants the new capabilities to every role that had the old ones, and
	 * removes the old ones.
	 *
	 * @return int Capabilities moved.
	 */
	private static function migrate_capabilities(): int {
		$roles = wp_roles();
		$moved = 0;

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPABILITIES as $legacy => $current ) {
				if ( ! $role->has_cap( $legacy ) ) {
					continue;
				}
				if ( ! $role->has_cap( $current ) ) {
					$role->add_cap( $current );
				}
				$role->remove_cap( $legacy );
				$moved++;
			}
		}

		return $moved;
	}

	/**
	 * Deletes the transients the plugin used to store under its old prefix.
	 *
	 * @return void
	 */
	private static function delete_legacy_transients(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_transient_sitedoctor_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_sitedoctor_' ) . '%'
			)
		);
	}

	/**
	 * Former name of one of the plugin's tables.
	 *
	 * @param string $suffix scans|results|vitals.
	 * @return string
	 */
	private static function legacy_table( string $suffix ): string {
		global $wpdb;
		return $wpdb->prefix . 'sitedoctor_' . $suffix;
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Legacy names, for the uninstaller to clean up after a site that was never
	 * migrated (for example a copy restored from an old backup).
	 *
	 * @return array{tables:string[],options:string[],capabilities:string[]}
	 */
	public static function legacy_names(): array {
		return array(
			'tables'       => array_map( array( __CLASS__, 'legacy_table' ), self::TABLES ),
			'options'      => array_keys( self::OPTIONS ),
			'capabilities' => array_keys( self::CAPABILITIES ),
		);
	}
}
