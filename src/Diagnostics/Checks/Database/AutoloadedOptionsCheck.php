<?php
/**
 * Autoloaded options size check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Database;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Measures the total size of options loaded on every request.
 */
final class AutoloadedOptionsCheck extends AbstractCheck {

	/** Size under which autoloaded data is considered healthy (matches WordPress core Site Health). */
	private const WARNING_BYTES = 800 * 1024;

	/** Size from which autoloaded data is considered critical. */
	private const CRITICAL_BYTES = 3 * 1024 * 1024;

	public function get_id(): string {
		return 'database.autoloaded-options';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Autoloaded options size', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Measures how much data from the options table is loaded into memory on every page request.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		// Values used by WordPress 6.6+ for autoloaded rows, plus the legacy "yes".
		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		$in       = implode( ',', array_fill( 0, count( $autoload ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$totals = $wpdb->get_row(
			$wpdb->prepare( "SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM {$wpdb->options} WHERE autoload IN ({$in})", $autoload ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$largest = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ({$in}) ORDER BY bytes DESC LIMIT 10", $autoload ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $totals ) ) {
			return $this->skipped( __( 'The options table could not be queried.', 'probe-site-doctor' ) );
		}

		$t = $this->thresholds(
			array(
				'warning_bytes'  => self::WARNING_BYTES,
				'critical_bytes' => self::CRITICAL_BYTES,
				'large_option'   => 100 * 1024,
			)
		);

		$bytes    = (int) $totals['bytes'];
		$leftover = $this->inactive_plugin_prefixes();
		$rows     = array();
		$suspects = array();
		foreach ( (array) $largest as $row ) {
			$name   = (string) $row['option_name'];
			$owner  = $this->match_prefix( $name, $leftover );
			$rows[] = array(
				'name'       => $name,
				'size_bytes' => (int) $row['bytes'],
				'note'       => null !== $owner
					/* translators: %s: Plugin name. */
					? sprintf( __( 'May belong to inactive plugin %s', 'probe-site-doctor' ), $owner )
					: ( (int) $row['bytes'] >= $t['large_option'] ? __( 'Large', 'probe-site-doctor' ) : '' ),
			);
			if ( null !== $owner ) {
				$suspects[] = $name;
			}
		}

		$data = array(
			'count'   => (int) $totals['total'],
			'bytes'   => $bytes,
			'largest' => $rows,
		);

		$size    = size_format( $bytes, 1 );
		$message = sprintf(
			/* translators: 1: Number of options, 2: Human-readable size. */
			__( '%1$s autoloaded options totalling %2$s are loaded on every request.', 'probe-site-doctor' ),
			number_format_i18n( $data['count'] ),
			$size
		);
		if ( $suspects ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Number of options. */
				_n( '%s of the largest options may belong to an inactive plugin (matched by name, so this is only a hint).', '%s of the largest options may belong to inactive plugins (matched by name, so this is only a hint).', count( $suspects ), 'probe-site-doctor' ),
				number_format_i18n( count( $suspects ) )
			);
		}

		if ( $bytes < $t['warning_bytes'] ) {
			return $this->good( __( 'Autoloaded options are a healthy size', 'probe-site-doctor' ), $message, $data );
		}

		$critical = $bytes >= $t['critical_bytes'];
		// Never suggest a real option unless it is attributed to an inactive plugin: core options such as
		// rewrite_rules, and transients, must stay as they are, and names alone cannot tell them apart safely.
		$example = $suspects && ! preg_match( '/^_(site_)?transient_/', $suspects[0] ) ? $suspects[0] : 'REPLACE_WITH_OPTION_NAME';

		return $this->result(
			$critical ? Severity::CRITICAL : Severity::WARNING,
			$critical ? __( 'Autoloaded options are very large', 'probe-site-doctor' ) : __( 'Autoloaded options are larger than recommended', 'probe-site-doctor' ),
			$message,
			__( 'Review the largest autoloaded options in the details. Options left by removed plugins can usually be deleted; large options of active plugins can often be set to not autoload. Back up the database first.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$critical ? Impact::HIGH : Impact::MEDIUM,
			sprintf(
				/* translators: %s: Size. */
				__( 'All %s are read from the database, or from the object cache, and unserialized on every request, including admin and REST requests. With Memcached, very large autoloaded data can exceed the 1 MB item limit and stop being cached.', 'probe-site-doctor' ),
				$size
			)
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Stop autoloading large options that are not needed on every page, and delete options left behind by removed plugins. Turning off autoload keeps the data and is reversible. Deleting an option cannot be undone.', 'probe-site-doctor' ),
				array(
					__( 'Back up the database.', 'probe-site-doctor' ),
					__( 'For each large option, find the plugin that owns it (usually recognizable from the name).', 'probe-site-doctor' ),
					__( 'If the plugin is active, switch the option to not autoload and check the site. If the plugin has been removed, delete the option.', 'probe-site-doctor' ),
				)
			) )
				->with_command( __( 'Stop autoloading one option (reversible)', 'probe-site-doctor' ), "wp option set-autoload '" . str_replace( "'", '', $example ) . "' no", ManualCleanup::TYPE_WP_CLI )
				->with_command( __( 'Stop autoloading one option with PHP (WordPress 6.4+)', 'probe-site-doctor' ), "wp_set_option_autoload( '" . esc_sql( $example ) . "', false );", ManualCleanup::TYPE_PHP )
				->with_command( __( 'Template: delete an option left by a removed plugin (replace the name)', 'probe-site-doctor' ), 'wp option delete REPLACE_WITH_LEFTOVER_OPTION_NAME', ManualCleanup::TYPE_WP_CLI )
		);
	}

	/**
	 * Option-name prefixes derived from installed but inactive plugins.
	 *
	 * @return array<string,string> Prefix => plugin name.
	 */
	private function inactive_plugin_prefixes(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$prefixes = array();
		foreach ( get_plugins() as $file => $plugin ) {
			if ( is_plugin_active( $file ) ) {
				continue;
			}
			$slug = strtolower( dirname( $file ) );
			if ( '.' === $slug || strlen( $slug ) < 4 ) {
				continue;
			}
			$name              = wp_strip_all_tags( $plugin['Name'] );
			$prefixes[ $slug ] = $name;
			$prefixes[ str_replace( '-', '_', $slug ) ] = $name;
		}
		return $prefixes;
	}

	/**
	 * Plugin name whose prefix starts the option name, if any.
	 *
	 * @param string               $option   Option name.
	 * @param array<string,string> $prefixes Prefixes.
	 * @return string|null
	 */
	private function match_prefix( string $option, array $prefixes ): ?string {
		$option = strtolower( ltrim( $option, '_' ) );
		foreach ( $prefixes as $prefix => $name ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return $name;
			}
		}
		return null;
	}
}
