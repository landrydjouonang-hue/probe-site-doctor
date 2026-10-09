<?php
/**
 * Database configuration check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Developer;

use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews the database server version, character set and a few server
 * variables that cause hard-to-diagnose failures when set too low.
 *
 * Reads metadata and SHOW VARIABLES only; it never alters a table.
 */
final class DatabaseConfigurationCheck extends AbstractCheck {

	/** Versions below these are no longer supported by WordPress. */
	private const MIN_MYSQL   = '5.7';
	private const MIN_MARIADB = '10.4';

	public function get_id(): string {
		return 'developer.database-configuration';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'Database configuration', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks the database server version, table character sets and server variables such as max_allowed_packet.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$thresholds = $this->thresholds( array( 'packet_mb' => 8 ) );

		$db      = SystemInfo::database();
		$version = (string) $db['version'];
		$maria   = 'mariadb' === $db['server'];
		$minimum = $maria ? self::MIN_MARIADB : self::MIN_MYSQL;
		$packet  = isset( $db['variables']['max_allowed_packet'] ) ? (int) $db['variables']['max_allowed_packet'] : 0;

		$data = array(
			'server'             => $maria ? 'MariaDB' : 'MySQL',
			'version'            => $version,
			'supported_minimum'  => $minimum,
			'charset'            => (string) ( $db['fields'][ __( 'Charset', 'probe-site-doctor' ) ] ?? '' ),
			'tables'             => (int) $db['tables'],
			'tables_not_utf8mb4' => $db['non_utf8'],
			'max_allowed_packet' => $packet,
			'total_bytes'        => (int) $db['bytes'],
		);

		$severity = Severity::GOOD;
		$problems = array();
		$actions  = array();

		if ( '' !== $version && version_compare( $version, $minimum, '<' ) ) {
			$severity   = $this->worst( $severity, Severity::WARNING );
			$problems[] = sprintf(
				/* translators: 1: Server name and version, 2: Minimum supported version. */
				__( '%1$s is older than the %2$s WordPress requires, so it no longer receives WordPress compatibility testing and may be missing security fixes from the vendor.', 'probe-site-doctor' ),
				( $maria ? 'MariaDB ' : 'MySQL ' ) . $version,
				$minimum
			);
			$actions[] = __( 'Ask the host to move the database to a supported version — this is usually a panel setting or a migration they run for you.', 'probe-site-doctor' );
		}

		if ( $db['non_utf8'] ) {
			$severity   = $this->worst( $severity, Severity::WARNING );
			$problems[] = sprintf(
				/* translators: 1: Number of tables, 2: Comma-separated table names. */
				_n(
					'%1$s table is not using utf8mb4 (%2$s), so emoji and some non-Latin characters are stored incorrectly or truncated.',
					'%1$s tables are not using utf8mb4 (%2$s), so emoji and some non-Latin characters are stored incorrectly or truncated.',
					count( $db['non_utf8'] ),
					'probe-site-doctor'
				),
				number_format_i18n( count( $db['non_utf8'] ) ),
				implode( ', ', array_slice( $db['non_utf8'], 0, 5 ) )
			);
			$actions[] = __( 'Convert those tables to utf8mb4 after taking a backup; mixing collations also causes join errors between tables.', 'probe-site-doctor' );
		}

		if ( $packet > 0 && $packet < $thresholds['packet_mb'] * MB_IN_BYTES ) {
			$severity   = $this->worst( $severity, Severity::INFO );
			$problems[] = sprintf(
				/* translators: %s: Size, e.g. "1 MB". */
				__( 'max_allowed_packet is only %s. Large option values, imports and backups fail against a small packet size, usually with "MySQL server has gone away".', 'probe-site-doctor' ),
				size_format( $packet, 1 )
			);
			$actions[] = __( 'Raise max_allowed_packet to 64M on the database server if you import or export data.', 'probe-site-doctor' );
		}

		if ( ! $problems ) {
			return $this->good(
				sprintf(
					/* translators: 1: Server name and version, 2: Number of tables, 3: Total size. */
					__( '%1$s, %2$s tables, %3$s', 'probe-site-doctor' ),
					( $maria ? 'MariaDB ' : 'MySQL ' ) . $version,
					number_format_i18n( (int) $db['tables'] ),
					size_format( (int) $db['bytes'], 1 )
				),
				__( 'The database server is a supported version, every table of this site uses utf8mb4, and the server variables Site Doctor checks are within normal ranges.', 'probe-site-doctor' ),
				$data
			);
		}

		return $this->result(
			$severity,
			Severity::WARNING === $severity
				? __( 'The database configuration needs attention', 'probe-site-doctor' )
				: __( 'Notes on the database configuration', 'probe-site-doctor' ),
			implode( ' ', $problems ),
			implode( ' ', $actions ),
			$data
		)->with_impact(
			Severity::WARNING === $severity ? Impact::MEDIUM : Impact::LOW,
			__( 'Character corruption and failed imports are the usual symptoms; both are hard to trace back to the database configuration.', 'probe-site-doctor' )
		)->with_cleanup( $this->steps( $db['non_utf8'] ) );
	}

	/**
	 * Manual instructions.
	 *
	 * @param string[] $tables Tables that are not utf8mb4.
	 * @return ManualCleanup
	 */
	private function steps( array $tables ): ManualCleanup {
		$table = $tables ? $tables[0] : 'REPLACE_WITH_TABLE_NAME';

		return ( new ManualCleanup(
			__( 'These changes alter the database or the server configuration. Site Doctor never runs them.', 'probe-site-doctor' ),
			array(
				__( 'Take and test a full database backup first — a character-set conversion rewrites every row.', 'probe-site-doctor' ),
				__( 'Convert one table, check the data, then do the rest.', 'probe-site-doctor' ),
				__( 'Set DB_CHARSET to utf8mb4 in wp-config.php so new tables are created correctly.', 'probe-site-doctor' ),
			),
			true
		) )->with_command(
			__( 'Convert one table to utf8mb4', 'probe-site-doctor' ),
			"ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;",
			ManualCleanup::TYPE_SQL
		)->with_command( __( 'wp-config.php', 'probe-site-doctor' ), "define( 'DB_CHARSET', 'utf8mb4' );\ndefine( 'DB_COLLATE', '' );", ManualCleanup::TYPE_PHP )
			->with_command( __( 'Check the server variables', 'probe-site-doctor' ), "SHOW VARIABLES WHERE Variable_name IN ('version','max_allowed_packet','innodb_buffer_pool_size');", ManualCleanup::TYPE_SQL );
	}
}
