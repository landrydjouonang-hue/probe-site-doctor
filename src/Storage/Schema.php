<?php
/**
 * Database schema.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's table definitions.
 *
 * All datetimes are stored in UTC. Deleting a scan deletes its results in
 * the repository (dbDelta cannot manage foreign keys).
 */
final class Schema {

	/**
	 * Schema version; bump when a definition changes so the upgrader re-runs dbDelta.
	 */
	public const VERSION = '4';

	/**
	 * Scans table name.
	 *
	 * @return string
	 */
	public static function scans_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'probesd_scans';
	}

	/**
	 * Results table name.
	 *
	 * @return string
	 */
	public static function results_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'probesd_results';
	}

	/**
	 * Field-data samples table name.
	 *
	 * @return string
	 */
	public static function vitals_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'probesd_vitals';
	}

	/**
	 * All table names.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array( self::results_table(), self::scans_table(), self::vitals_table() );
	}

	/**
	 * Creates or updates the tables.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$scans   = self::scans_table();
		$results = self::results_table();

		// dbDelta formatting rules: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$scans} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  status varchar(20) NOT NULL DEFAULT 'running',
  source varchar(20) NOT NULL DEFAULT 'manual',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  total_checks smallint(5) unsigned NOT NULL DEFAULT 0,
  count_good smallint(5) unsigned NOT NULL DEFAULT 0,
  count_info smallint(5) unsigned NOT NULL DEFAULT 0,
  count_warning smallint(5) unsigned NOT NULL DEFAULT 0,
  count_critical smallint(5) unsigned NOT NULL DEFAULT 0,
  count_error smallint(5) unsigned NOT NULL DEFAULT 0,
  score tinyint(3) unsigned DEFAULT NULL,
  plugin_version varchar(20) NOT NULL DEFAULT '',
  environment longtext NULL,
  started_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  finished_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status_started (status,started_at),
  KEY started_at (started_at)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$results} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  scan_id bigint(20) unsigned NOT NULL,
  check_id varchar(100) NOT NULL,
  category varchar(50) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'completed',
  severity varchar(20) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  message text NULL,
  recommendation text NULL,
  data longtext NULL,
  duration_ms int(10) unsigned NOT NULL DEFAULT 0,
  measurement varchar(20) NOT NULL DEFAULT 'server',
  impact text NULL,
  cleanup longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY scan_check (scan_id,check_id),
  KEY scan_severity (scan_id,severity)
) {$charset};"
		);

		$vitals = self::vitals_table();

		// One row per reported metric. No visitor identifiers are stored: the
		// path, the device class, the metric, its value and the time, nothing else.
		dbDelta(
			"CREATE TABLE {$vitals} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  path varchar(190) NOT NULL DEFAULT '/',
  device varchar(10) NOT NULL DEFAULT 'desktop',
  metric varchar(10) NOT NULL,
  value double NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY path_metric (path,metric,created_at),
  KEY created_at (created_at)
) {$charset};"
		);
	}

	/**
	 * Drops the tables.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			// Table names come from $wpdb->prefix and constants, never from input.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}
}
