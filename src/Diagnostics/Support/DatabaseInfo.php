<?php
/**
 * Read-only database metadata helpers.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Metadata reads for diagnostics.

/**
 * Table metadata from information_schema, scoped to the current site.
 *
 * Scope: on a single site, every table with the site prefix. On a
 * multisite main site, tables of other sites (prefix + digits + "_") are
 * excluded. On a sub-site, only that site's tables are included; network
 * tables such as users are reported for the main site.
 */
final class DatabaseInfo {

	/**
	 * Per-request cache.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private static ?array $tables = null;

	/**
	 * Clears the per-request cache (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$tables = null;
	}

	/**
	 * Tables of the current site, largest first.
	 *
	 * @return array<int,array{name:string,engine:string,rows:int,data_bytes:int,index_bytes:int,free_bytes:int,bytes:int,core:bool}>
	 */
	public static function tables(): array {
		if ( null !== self::$tables ) {
			return self::$tables;
		}

		global $wpdb;

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS row_estimate,
					DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND TABLE_TYPE = %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%',
				'BASE TABLE'
			),
			ARRAY_A
		);

		$core    = array_flip( array_values( $wpdb->tables( 'all', true ) ) );
		$exclude = is_multisite() && $wpdb->prefix === $wpdb->base_prefix
			? '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/'
			: null;

		$tables = array();
		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			if ( null !== $exclude && preg_match( $exclude, $name ) ) {
				continue;
			}
			$data     = (int) $row['data_bytes'];
			$index    = (int) $row['index_bytes'];
			$tables[] = array(
				'name'        => $name,
				'engine'      => (string) $row['engine'],
				'rows'        => (int) $row['row_estimate'],
				'data_bytes'  => $data,
				'index_bytes' => $index,
				'free_bytes'  => (int) $row['free_bytes'],
				'bytes'       => $data + $index,
				'core'        => isset( $core[ $name ] ),
			);
		}

		usort( $tables, static fn( $a, $b ) => $b['bytes'] <=> $a['bytes'] );

		self::$tables = $tables;
		return $tables;
	}

	/**
	 * Metadata for one table, or null.
	 *
	 * @param string $name Full table name.
	 * @return array<string,mixed>|null
	 */
	public static function table( string $name ): ?array {
		foreach ( self::tables() as $table ) {
			if ( $table['name'] === $name ) {
				return $table;
			}
		}
		return null;
	}

	/**
	 * Counts the rows of a sub-query, stopping at $cap to keep the query
	 * bounded on very large tables.
	 *
	 * @param string $prepared_select A complete, already prepared/safe SELECT (without LIMIT).
	 * @param int    $cap             Maximum to count.
	 * @return array{count:int,capped:bool}
	 */
	public static function capped_count( string $prepared_select, int $cap ): array {
		global $wpdb;
		// The sub-query is already prepared; preparing it again could misread literal "%" signs, so the cap is cast instead.
		$limit = max( 1, $cap ) + 1;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ({$prepared_select} LIMIT {$limit}) capped" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'count'  => min( $count, $cap ),
			'capped' => $count > $cap,
		);
	}
}
