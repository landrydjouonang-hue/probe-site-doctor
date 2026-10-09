<?php
/**
 * Per-site installation.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

use ProbeSiteDoctor\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, options and capabilities for the current site. Idempotent.
 */
final class Installer {

	public const VERSION_OPTION = 'probesd_version';
	public const DB_OPTION      = 'probesd_db_version';

	/**
	 * Installs or upgrades the current site.
	 *
	 * @return void
	 */
	public static function install(): void {
		// Carry over an install that still uses the names from before the plugin
		// was renamed. Runs before the schema, so no empty table is created first.
		Migration::maybe_run();

		Schema::install();
		Capabilities::grant();

		// Settings are not autoloaded: they are only needed on plugin screens and during scans.
		add_option( Settings::OPTION, Settings::defaults(), '', false );

		update_option( self::DB_OPTION, Schema::VERSION, false );
		update_option( self::VERSION_OPTION, PROBESD_VERSION, false );
	}

	/**
	 * Whether the current site needs install/upgrade.
	 *
	 * @return bool
	 */
	public static function needs_install(): bool {
		return get_option( self::DB_OPTION ) !== Schema::VERSION
			|| get_option( self::VERSION_OPTION ) !== PROBESD_VERSION;
	}
}
