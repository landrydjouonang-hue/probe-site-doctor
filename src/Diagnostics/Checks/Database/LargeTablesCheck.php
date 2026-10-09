<?php
/**
 * Large tables check.
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
 * Lists tables that are large in absolute terms or dominate the database,
 * and flags those whose names suggest logs, sessions or statistics, which
 * usually grow without limit.
 */
final class LargeTablesCheck extends AbstractCheck {

	/**
	 * Name fragments of tables that typically hold logs, sessions or stats.
	 *
	 * @var string[]
	 */
	private const LOG_HINTS = array( 'log', 'logs', 'session', 'sessions', 'stats', 'statistics', 'visits', 'hits', 'actionscheduler_actions', 'redirection_404', 'wfhits', 'wflogins', 'wfblocks' );

	public function get_id(): string {
		return 'database.large-tables';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Large tables', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Finds the largest tables and flags log, session or statistics tables that keep growing.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t = $this->thresholds(
			array(
				'large_bytes'    => 100 * 1024 * 1024,
				'log_bytes'      => 50 * 1024 * 1024,
				'dominant_pct'   => 50,
				'dominant_min'   => 50 * 1024 * 1024,
				'critical_bytes' => 2 * 1024 * 1024 * 1024,
			)
		);

		$tables = DatabaseInfo::tables();
		if ( ! $tables ) {
			return $this->skipped( __( 'Table information is not available (the database user may lack access to information_schema).', 'probe-site-doctor' ) );
		}

		$total = max( 1, array_sum( array_column( $tables, 'bytes' ) ) );
		$large = array();
		$logs  = array();
		$worst = 0;

		foreach ( $tables as $table ) {
			$share    = round( $table['bytes'] / $total * 100, 1 );
			$is_log   = ! $table['core'] && $this->looks_like_log( $table['name'] );
			$is_large = $table['bytes'] >= $t['large_bytes']
				|| ( $share >= $t['dominant_pct'] && $table['bytes'] >= $t['dominant_min'] )
				|| ( $is_log && $table['bytes'] >= $t['log_bytes'] );

			if ( ! $is_large ) {
				continue;
			}
			$worst   = max( $worst, $table['bytes'] );
			$large[] = array(
				'table'        => $table['name'],
				'owner'        => $table['core'] ? __( 'WordPress core', 'probe-site-doctor' ) : ( $is_log ? __( 'Plugin (log/session/stats)', 'probe-site-doctor' ) : __( 'Plugin or custom', 'probe-site-doctor' ) ),
				'row_estimate' => $table['rows'],
				'size_bytes'   => $table['bytes'],
				'share'        => $share . '%',
			);
			if ( $is_log ) {
				$logs[] = $table['name'];
			}
		}

		$data = array(
			'largest'      => array_map(
				static fn( $table ) => array(
					'table'        => $table['name'],
					'row_estimate' => $table['rows'],
					'size_bytes'   => $table['bytes'],
				),
				array_slice( $tables, 0, 5 )
			),
			'large_tables' => $large,
			'log_tables'   => $logs,
		);

		if ( ! $large ) {
			return $this->good(
				__( 'No unusually large tables', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Table name, 2: Size. */
					__( 'The largest table is %1$s at %2$s.', 'probe-site-doctor' ),
					$tables[0]['name'],
					size_format( $tables[0]['bytes'], 1 ) ?: '0 B'
				),
				$data
			);
		}

		$severity = $worst >= $t['critical_bytes'] ? Severity::CRITICAL : ( $logs ? Severity::WARNING : Severity::INFO );

		$message = sprintf(
			/* translators: %s: Number of tables. */
			_n( '%s table is large compared to the rest of the database.', '%s tables are large compared to the rest of the database.', count( $large ), 'probe-site-doctor' ),
			number_format_i18n( count( $large ) )
		);
		if ( $logs ) {
			$message .= ' ' . __( 'Some of them look like log, session or statistics tables, which grow on every visit or event unless a retention limit is set.', 'probe-site-doctor' );
		}
		$message .= ' ' . __( 'Table ownership is inferred from the table name and may not be exact.', 'probe-site-doctor' );

		return $this->result(
			$severity,
			__( 'Some database tables are very large', 'probe-site-doctor' ),
			$message,
			$logs
				? __( 'Open the settings of the plugin that owns each log, session or statistics table and set a retention period or turn off logging you do not use. For other large tables, check whether the data is still needed.', 'probe-site-doctor' )
				: __( 'Check whether the data in these tables is still needed. Large core tables are normal for big sites; large plugin tables can often be trimmed from the plugin’s own settings.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$logs || Severity::CRITICAL === $severity ? Impact::MEDIUM : Impact::LOW,
			__( 'Large tables slow down backups and any query that scans them. Log tables that are written to on every request can also add write load while visitors browse.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Trim data from large plugin tables. The table structure differs by plugin, so use the owning plugin’s own tools; generic SQL could break the plugin.', 'probe-site-doctor' ),
				array(
					__( 'Identify the plugin that owns each table (the table name usually includes the plugin’s prefix).', 'probe-site-doctor' ),
					__( 'Back up the database.', 'probe-site-doctor' ),
					__( 'Use the plugin’s log or data retention settings, or its “clear logs” tool.', 'probe-site-doctor' ),
					__( 'Only empty a table directly if the plugin is permanently removed. Deactivating a plugin does not remove its tables.', 'probe-site-doctor' ),
				)
			) )->with_command(
				__( 'Template: remove a table left behind by a plugin that has been deleted. Replace the placeholder name and double-check it first; this cannot be undone.', 'probe-site-doctor' ),
				'DROP TABLE `' . $GLOBALS['wpdb']->prefix . 'REPLACE_WITH_LEFTOVER_TABLE_NAME`;'
			)
		);
	}

	/**
	 * Whether a table name suggests log/session/stats data.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	private function looks_like_log( string $name ): bool {
		$name = strtolower( $name );
		foreach ( self::LOG_HINTS as $hint ) {
			if ( preg_match( '/(^|_)' . preg_quote( $hint, '/' ) . '(_|$)/', $name ) || ( strlen( $hint ) > 8 && false !== strpos( $name, $hint ) ) ) {
				return true;
			}
		}
		return false;
	}
}
