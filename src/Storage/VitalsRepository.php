<?php
/**
 * Field-data persistence.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Storage;

use ProbeSiteDoctor\Performance\Vitals;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * Stores and summarises the metrics reported by visitors' browsers.
 *
 * Rows hold a path, a device class, a metric and a value. There is no visitor
 * identifier, no IP address and no user agent string, so the table cannot be
 * used to follow an individual.
 */
final class VitalsRepository {

	/** Samples kept per path and metric; older ones are pruned. */
	private const MAX_PER_SERIES = 500;

	/**
	 * Stores one page view's metrics.
	 *
	 * @param string             $path    Path such as /about.
	 * @param string             $device  mobile|desktop.
	 * @param array<string,float> $metrics Metric => value.
	 * @return int Rows written.
	 */
	public function record( string $path, string $device, array $metrics ): int {
		global $wpdb;

		$path   = $this->normalize_path( $path );
		$device = 'mobile' === $device ? 'mobile' : 'desktop';
		$now    = current_time( 'mysql', true );
		$table  = Schema::vitals_table();
		$rows   = 0;

		foreach ( $metrics as $metric => $value ) {
			$metric = strtolower( (string) $metric );
			if ( ! in_array( $metric, Vitals::METRICS, true ) || ! is_numeric( $value ) ) {
				continue;
			}
			$value = (float) $value;
			// Reject impossible values rather than storing them.
			$max = 'cls' === $metric ? 10 : 600000;
			if ( $value < 0 || $value > $max ) {
				continue;
			}

			$rows += (int) $wpdb->insert(
				$table,
				array(
					'path'       => $path,
					'device'     => $device,
					'metric'     => $metric,
					'value'      => $value,
					'created_at' => $now,
				),
				array( '%s', '%s', '%s', '%f', '%s' )
			);
		}

		return $rows;
	}

	/**
	 * 75th-percentile summary for one path, or for the whole site.
	 *
	 * The 75th percentile is what Google reports for Core Web Vitals: three
	 * quarters of page views were at least this good.
	 *
	 * @param string|null $path   Path, or null for every page.
	 * @param string|null $device mobile|desktop, or null for both.
	 * @param int         $days   Look-back window in days.
	 * @return array<string,array{p75:float|null,samples:int,rating:string}>
	 */
	public function summary( ?string $path = null, ?string $device = null, int $days = 30 ): array {
		global $wpdb;

		$table = Schema::vitals_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$out = array();
		foreach ( Vitals::METRICS as $metric ) {
			$sql    = "SELECT value FROM {$table} WHERE metric = %s AND created_at >= %s";
			$params = array( $metric, $since );

			if ( null !== $path ) {
				$sql     .= ' AND path = %s';
				$params[] = $this->normalize_path( $path );
			}
			if ( null !== $device ) {
				$sql     .= ' AND device = %s';
				$params[] = 'mobile' === $device ? 'mobile' : 'desktop';
			}
			$sql .= ' ORDER BY created_at DESC LIMIT ' . self::MAX_PER_SERIES;

			$values = array_map(
				'floatval',
				(array) $wpdb->get_col( $wpdb->prepare( $sql, $params ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);

			$p75 = $this->percentile( $values, 75 );

			$out[ $metric ] = array(
				'p75'     => $p75,
				'samples' => count( $values ),
				'rating'  => null === $p75 ? '' : Vitals::rating( $metric, $p75 ),
			);
		}

		return $out;
	}

	/**
	 * Paths with field data, most sampled first.
	 *
	 * @param int $limit Maximum paths.
	 * @param int $days  Look-back window in days.
	 * @return array<int,array{path:string,samples:int,last:string}>
	 */
	public function paths( int $limit = 20, int $days = 30 ): array {
		global $wpdb;

		$table = Schema::vitals_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT path, COUNT(*) AS samples, MAX(created_at) AS last FROM {$table}
				WHERE created_at >= %s GROUP BY path ORDER BY samples DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$since,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return array_map(
			static fn( $row ) => array(
				'path'    => (string) $row['path'],
				'samples' => (int) $row['samples'],
				'last'    => (string) $row['last'],
			),
			$rows
		);
	}

	/**
	 * Total samples stored.
	 *
	 * @return int
	 */
	public function total(): int {
		global $wpdb;
		$table = Schema::vitals_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Deletes samples older than the retention window.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public function prune( int $days ): int {
		global $wpdb;
		$table = Schema::vitals_table();
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Deletes every sample. Used when an administrator turns collection off
	 * and asks for the data to go with it.
	 *
	 * @return int Rows deleted.
	 */
	public function delete_all(): int {
		global $wpdb;
		$table = Schema::vitals_table();
		return (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Percentile of a value set.
	 *
	 * @param float[] $values     Values.
	 * @param int     $percentile Percentile (0–100).
	 * @return float|null
	 */
	private function percentile( array $values, int $percentile ): ?float {
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		$index = (int) ceil( $percentile / 100 * count( $values ) ) - 1;
		return (float) $values[ max( 0, min( count( $values ) - 1, $index ) ) ];
	}

	/**
	 * Normalises a reported path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_path( string $path ): string {
		$path = strtok( trim( $path ), '?' );
		$path = '' === $path || false === $path ? '/' : $path;
		$path = '/' . ltrim( str_replace( array( "\r", "\n", '..' ), '', $path ), '/' );
		$path = '/' === $path ? $path : untrailingslashit( $path );

		return substr( $path, 0, 190 );
	}
}
