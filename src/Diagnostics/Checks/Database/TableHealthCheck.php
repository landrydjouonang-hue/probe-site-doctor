<?php
/**
 * Table storage engine check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Database;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Support\DatabaseInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Checks the storage engine of the site's tables. Size is covered by the
 * "Database size" and "Large tables" checks.
 */
final class TableHealthCheck extends AbstractCheck {

	public function get_id(): string {
		return 'database.tables';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Table storage engines', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks that the site’s tables use InnoDB rather than MyISAM.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$tables = DatabaseInfo::tables();
		if ( ! $tables ) {
			return $this->skipped( __( 'Table information is not available (the database user may lack access to information_schema).', 'probe-site-doctor' ) );
		}

		$engines = array();
		$myisam  = array();
		foreach ( $tables as $table ) {
			$engine             = '' !== $table['engine'] ? $table['engine'] : __( 'unknown', 'probe-site-doctor' );
			$engines[ $engine ] = ( $engines[ $engine ] ?? 0 ) + 1;
			if ( 'myisam' === strtolower( $table['engine'] ) ) {
				$myisam[] = $table['name'];
			}
		}

		$data = array(
			'tables'        => count( $tables ),
			'engines'       => array_map(
				static fn( $engine, $count ) => $engine . ': ' . $count,
				array_keys( $engines ),
				$engines
			),
			'myisam_tables' => $myisam,
		);

		if ( ! $myisam ) {
			return $this->good(
				__( 'Tables use a modern storage engine', 'probe-site-doctor' ),
				__( 'No MyISAM tables were found.', 'probe-site-doctor' ),
				$data
			);
		}

		return $this->warning(
			sprintf(
				/* translators: %s: Number of tables. */
				_n( '%s table uses the MyISAM engine', '%s tables use the MyISAM engine', count( $myisam ), 'probe-site-doctor' ),
				number_format_i18n( count( $myisam ) )
			),
			__( 'MyISAM locks the whole table on every write, so concurrent visitors wait on each other, and it has no crash recovery. InnoDB is the recommended engine for WordPress.', 'probe-site-doctor' ),
			__( 'After a full backup, convert the tables to InnoDB, or ask your host to do it.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			Impact::MEDIUM,
			__( 'Busy sites and sites with frequent writes (comments, orders, logs) suffer most from table locking. Converting also improves resilience after a crash.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Convert each table to InnoDB. No data is deleted, but the table is rebuilt and locked while it converts, which can take a long time for large tables.', 'probe-site-doctor' ),
				array(
					__( 'Make a full database backup.', 'probe-site-doctor' ),
					__( 'Convert one table at a time during a quiet period.', 'probe-site-doctor' ),
				),
				false
			) )->with_command(
				__( 'Convert a table to InnoDB', 'probe-site-doctor' ),
				'ALTER TABLE `' . $myisam[0] . '` ENGINE=InnoDB;'
			)
		);
	}
}
