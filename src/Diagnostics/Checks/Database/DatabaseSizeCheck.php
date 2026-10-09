<?php
/**
 * Database size check.
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
use ProbeSiteDoctor\Diagnostics\Support\DatabaseInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the site's database size (data and indexes), reclaimable free
 * space, and growth since the previous completed scan.
 */
final class DatabaseSizeCheck extends AbstractCheck {

	public function get_id(): string {
		return 'database.size';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Database size', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Measures the total size of the site’s tables, reclaimable space, and growth since the previous scan.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t = $this->thresholds(
			array(
				'info_bytes'         => 1024 * 1024 * 1024,
				'warning_bytes'      => 5 * 1024 * 1024 * 1024,
				'free_info_bytes'    => 100 * 1024 * 1024,
				'growth_warning_pct' => 50,
				'growth_min_bytes'   => 100 * 1024 * 1024,
			)
		);

		$tables = DatabaseInfo::tables();
		if ( ! $tables ) {
			return $this->skipped( __( 'Table information is not available (the database user may lack access to information_schema).', 'probe-site-doctor' ) );
		}

		$data_bytes  = array_sum( array_column( $tables, 'data_bytes' ) );
		$index_bytes = array_sum( array_column( $tables, 'index_bytes' ) );
		$free_bytes  = array_sum( array_column( $tables, 'free_bytes' ) );
		$total       = $data_bytes + $index_bytes;

		$data = array(
			'tables'            => count( $tables ),
			'total_bytes'       => $total,
			'data_bytes'        => $data_bytes,
			'index_bytes'       => $index_bytes,
			'reclaimable_bytes' => $free_bytes,
		);

		// Growth since the previous completed scan (read from stored results).
		$growth_pct = null;
		$previous   = function_exists( 'probe_site_doctor' ) ? \probe_site_doctor()->results()->latest_completed_data( $this->get_id() ) : null;
		if ( $previous && isset( $previous['data']['total_bytes'] ) && (int) $previous['data']['total_bytes'] > 0 ) {
			$before                   = (int) $previous['data']['total_bytes'];
			$growth_pct               = round( ( $total - $before ) / $before * 100, 1 );
			$data['previous_bytes']   = $before;
			$data['growth']           = ( $growth_pct >= 0 ? '+' : '' ) . $growth_pct . '%';
			$data['previous_scan_at'] = $previous['created_at'];
		}

		$summary = sprintf(
			/* translators: 1: Size, 2: Table count, 3: Data size, 4: Index size. */
			__( 'The site’s %2$s tables use %1$s (%3$s data, %4$s indexes).', 'probe-site-doctor' ),
			size_format( $total, 1 ),
			number_format_i18n( count( $tables ) ),
			size_format( $data_bytes, 1 ),
			size_format( $index_bytes, 1 ) ?: '0 B'
		);
		if ( null !== $growth_pct ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: Percentage change such as +12.5%. */
				__( 'Change since the previous scan: %s.', 'probe-site-doctor' ),
				$data['growth']
			);
		}

		$severity = Severity::GOOD;
		$notes    = array();
		if ( $total >= $t['warning_bytes'] ) {
			$severity = Severity::WARNING;
		} elseif ( $total >= $t['info_bytes'] ) {
			$severity = Severity::INFO;
		}
		if ( null !== $growth_pct && $growth_pct >= $t['growth_warning_pct'] && ( $total - (int) $data['previous_bytes'] ) >= $t['growth_min_bytes'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			$notes[]  = __( 'The database has grown quickly since the previous scan, which often points to a plugin logging or caching data in the database.', 'probe-site-doctor' );
		}
		if ( $free_bytes >= $t['free_info_bytes'] ) {
			$severity = $this->worst( $severity, Severity::INFO );
			$notes[]  = sprintf(
				/* translators: %s: Size. */
				__( 'About %s is allocated to the tables but unused (for example after large deletions). This is an estimate reported by the database server; with InnoDB it may be shared space that cannot be returned to the disk.', 'probe-site-doctor' ),
				size_format( $free_bytes, 1 )
			);
		}

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'The database size is modest', 'probe-site-doctor' ), $summary, $data );
		}

		return $this->result(
			$severity,
			__( 'The database is large or growing', 'probe-site-doctor' ),
			trim( $summary . ' ' . implode( ' ', $notes ) ),
			__( 'Review the “Large tables”, “Post revisions”, “Transients” and “Orphaned metadata” findings to see where the space goes. Size alone is not a problem, but unexplained growth usually has a cause worth fixing.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$total >= $t['warning_bytes'] ? Impact::MEDIUM : Impact::LOW,
			__( 'A large database makes backups, restores and migrations slower and costs more storage. Page speed is affected only when frequently queried tables are large or poorly indexed.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Reclaim unused space by rebuilding tables. OPTIMIZE TABLE does not delete rows, but it rebuilds the table, which can lock it and take a long time on large tables.', 'probe-site-doctor' ),
				array(
					__( 'Deal with the underlying causes first (revisions, transients, logs, orphaned metadata).', 'probe-site-doctor' ),
					__( 'Back up the database.', 'probe-site-doctor' ),
					__( 'During a quiet period, optimize the tables that report the most unused space, one table at a time.', 'probe-site-doctor' ),
				),
				false
			) )
				->with_command( __( 'Optimize all tables with WP-CLI', 'probe-site-doctor' ), 'wp db optimize', ManualCleanup::TYPE_WP_CLI )
				->with_command( __( 'Optimize one table with SQL', 'probe-site-doctor' ), 'OPTIMIZE TABLE ' . $tables[0]['name'] . ';' )
		);
	}
}
