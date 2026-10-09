<?php
/**
 * Transients check.
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
 * Reviews transients stored in the options table: total volume, expired
 * entries that were never removed, transients without an expiry that are
 * autoloaded on every request, and whether the core clean-up event is
 * scheduled.
 */
final class TransientsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'database.transients';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Transients', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Measures transients in the database: expired entries, autoloaded transients and overall volume.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'expired_warning'        => 500,
				'expired_bytes_warning'  => 5 * 1024 * 1024,
				'autoload_bytes_warning' => 512 * 1024,
				'total_rows_info'        => 5000,
				'total_bytes_critical'   => 50 * 1024 * 1024,
			)
		);

		$now      = time();
		$autoload = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		$in       = implode( ',', array_fill( 0, count( $autoload ), '%s' ) );

		$value_like   = $wpdb->esc_like( '_transient_' ) . '%';
		$timeout_like = $wpdb->esc_like( '_transient_timeout_' ) . '%';
		$site_like    = $wpdb->esc_like( '_site_transient_' ) . '%';
		$site_timeout = $wpdb->esc_like( '_site_transient_timeout_' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$totals = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM {$wpdb->options}
				WHERE ( option_name LIKE %s AND option_name NOT LIKE %s ) OR ( option_name LIKE %s AND option_name NOT LIKE %s )",
				$value_like,
				$timeout_like,
				$site_like,
				$site_timeout
			),
			ARRAY_A
		);

		// Expired: a timeout row in the past, joined to its value row. Prefix lengths: 19 and 24 characters.
		$expired = (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(v.option_value)), 0) AS bytes FROM {$wpdb->options} t
				INNER JOIN {$wpdb->options} v ON v.option_name = IF( t.option_name LIKE %s,
					CONCAT( '_site_transient_', SUBSTRING( t.option_name, 25 ) ),
					CONCAT( '_transient_', SUBSTRING( t.option_name, 20 ) ) )
				WHERE ( t.option_name LIKE %s OR t.option_name LIKE %s ) AND CAST( t.option_value AS UNSIGNED ) < %d",
				$site_timeout,
				$timeout_like,
				$site_timeout,
				$now
			),
			ARRAY_A
		);

		// Transients without an expiry are stored with autoload on, so they load on every request.
		// The placeholders for the autoload values are built above, which is why the
		// replacements are passed as one array — prepare() accepts that form.
		$autoloaded = (array) $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$wpdb->prepare(
				"SELECT COUNT(*) AS total, COALESCE(SUM(LENGTH(option_value)), 0) AS bytes FROM {$wpdb->options}
				WHERE option_name LIKE %s AND option_name NOT LIKE %s AND autoload IN ({$in})",
				array_merge( array( $value_like, $timeout_like ), $autoload )
			),
			ARRAY_A
		);

		$largest = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options}
				WHERE ( option_name LIKE %s AND option_name NOT LIKE %s ) OR ( option_name LIKE %s AND option_name NOT LIKE %s )
				ORDER BY bytes DESC LIMIT 5",
				$value_like,
				$timeout_like,
				$site_like,
				$site_timeout
			),
			ARRAY_A
		);
		// phpcs:enable

		$total_rows    = (int) ( $totals['total'] ?? 0 );
		$total_bytes   = (int) ( $totals['bytes'] ?? 0 );
		$expired_rows  = (int) ( $expired['total'] ?? 0 );
		$expired_bytes = (int) ( $expired['bytes'] ?? 0 );
		$auto_rows     = (int) ( $autoloaded['total'] ?? 0 );
		$auto_bytes    = (int) ( $autoloaded['bytes'] ?? 0 );
		$external      = wp_using_ext_object_cache();
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next_cleanup  = wp_next_scheduled( 'delete_expired_transients' );
		$cron_overdue  = $next_cleanup && $next_cleanup < $now - DAY_IN_SECONDS;

		$data = array(
			'transients'              => $total_rows,
			'transient_bytes'         => $total_bytes,
			'expired'                 => $expired_rows,
			'expired_bytes'           => $expired_bytes,
			'autoloaded_transients'   => $auto_rows,
			'autoloaded_bytes'        => $auto_bytes,
			'persistent_object_cache' => $external,
			'cleanup_event_scheduled' => (bool) $next_cleanup,
			'largest'                 => array_map(
				static fn( $row ) => array(
					'transient'  => preg_replace( '/^_(site_)?transient_/', '', (string) $row['option_name'] ),
					'size_bytes' => (int) $row['bytes'],
				),
				$largest
			),
		);

		$severity = Severity::GOOD;
		$issues   = array();

		if ( $total_bytes >= $t['total_bytes_critical'] ) {
			$severity = Severity::CRITICAL;
			/* translators: %s: Size. */
			$issues[] = sprintf( __( 'Transients occupy %s in the options table.', 'probe-site-doctor' ), size_format( $total_bytes, 1 ) );
		}
		if ( $expired_rows >= $t['expired_warning'] || $expired_bytes >= $t['expired_bytes_warning'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			$issues[] = sprintf(
				/* translators: 1: Count, 2: Size. */
				__( '%1$s expired transients (%2$s) were never removed.', 'probe-site-doctor' ),
				number_format_i18n( $expired_rows ),
				size_format( $expired_bytes, 1 ) ?: '0 B'
			);
			if ( $cron_disabled || ! $next_cleanup || $cron_overdue ) {
				$issues[] = __( 'WordPress normally deletes expired transients once a day, but that scheduled event is not running (WP-Cron is disabled, overdue or the event is missing).', 'probe-site-doctor' );
			}
		}
		if ( $auto_bytes >= $t['autoload_bytes_warning'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			$issues[] = sprintf(
				/* translators: 1: Count, 2: Size. */
				__( '%1$s transients without an expiry (%2$s) are autoloaded on every request.', 'probe-site-doctor' ),
				number_format_i18n( $auto_rows ),
				size_format( $auto_bytes, 1 )
			);
		}
		if ( Severity::GOOD === $severity && $total_rows >= $t['total_rows_info'] ) {
			$severity = Severity::INFO;
			/* translators: %s: Count. */
			$issues[] = sprintf( __( '%s transient rows are stored, which usually means a plugin creates many unique transients.', 'probe-site-doctor' ), number_format_i18n( $total_rows ) );
		}
		if ( $external && $total_rows > 0 ) {
			$issues[] = __( 'A persistent object cache is active, so WordPress stores new transients there. The rows in the database are probably leftovers from before the cache was enabled.', 'probe-site-doctor' );
		}

		$summary = sprintf(
			/* translators: 1: Count, 2: Size, 3: Expired count. */
			__( '%1$s transients (%2$s) are stored in the database, %3$s of them expired.', 'probe-site-doctor' ),
			number_format_i18n( $total_rows ),
			size_format( $total_bytes, 1 ) ?: '0 B',
			number_format_i18n( $expired_rows )
		);

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Transients are tidy', 'probe-site-doctor' ), $summary, $data );
		}

		$impact = $auto_bytes >= $t['autoload_bytes_warning'] ? Impact::HIGH : ( Severity::CRITICAL === $severity ? Impact::MEDIUM : Impact::LOW );

		return $this->result(
			$severity,
			__( 'Transients need attention', 'probe-site-doctor' ),
			$summary . ' ' . implode( ' ', $issues ),
			$cron_disabled || ! $next_cleanup || $cron_overdue
				? __( 'Make sure scheduled tasks run (set up a real server cron job if WP-Cron is disabled), then remove expired transients. If a single plugin creates most of them, check its caching settings.', 'probe-site-doctor' )
				: __( 'Remove expired transients. If a single plugin creates most of them or stores large transients without an expiry, check its settings or report it to its developer.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$impact,
			Impact::HIGH === $impact
				? sprintf(
					/* translators: %s: Size. */
					__( 'About %s of autoloaded transients are read on every page request, which costs memory and time on every visit.', 'probe-site-doctor' ),
					size_format( $auto_bytes, 1 )
				)
				: __( 'Expired transients are never read, so they mainly waste space in the options table and slow backups; their effect on page speed is small.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Delete expired transients. Transients are temporary cache data, so WordPress and plugins regenerate them when needed. Deleting all of them can cause brief slowness while caches rebuild.', 'probe-site-doctor' ),
				array(
					__( 'Back up the database.', 'probe-site-doctor' ),
					__( 'Delete expired transients first; this is the lowest-risk option.', 'probe-site-doctor' ),
					__( 'Only if problems remain, delete all transients during a quiet period.', 'probe-site-doctor' ),
				)
			) )
				->with_command( __( 'Delete expired transients with WP-CLI', 'probe-site-doctor' ), 'wp transient delete --expired', ManualCleanup::TYPE_WP_CLI )
				->with_command( __( 'Delete all transients with WP-CLI', 'probe-site-doctor' ), 'wp transient delete --all', ManualCleanup::TYPE_WP_CLI )
				->with_command(
					__( 'Delete expired transients with SQL', 'probe-site-doctor' ),
					"DELETE v, t FROM {$wpdb->options} v INNER JOIN {$wpdb->options} t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(v.option_name, 12))\n"
					. "WHERE v.option_name LIKE '\\_transient\\_%' AND v.option_name NOT LIKE '\\_transient\\_timeout\\_%' AND t.option_value < UNIX_TIMESTAMP();"
				)
		);
	}
}
