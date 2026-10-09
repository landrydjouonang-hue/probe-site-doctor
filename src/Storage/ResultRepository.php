<?php
/**
 * Result persistence.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Storage;

use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables.

/**
 * CRUD for the results table.
 */
final class ResultRepository {

	/**
	 * Inserts or replaces the result of a check within a scan.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param Result $result  Result.
	 * @return bool
	 */
	public function save( int $scan_id, Result $result ): bool {
		global $wpdb;
		$table = Schema::results_table();

		$data = wp_json_encode( $result->get_data() );

		// Re-running a check within the same scan overwrites the earlier result.
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (scan_id, check_id, category, status, severity, title, message, recommendation, data, duration_ms, measurement, impact, cleanup, created_at)
			VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s, %s)
			ON DUPLICATE KEY UPDATE category = VALUES(category), status = VALUES(status), severity = VALUES(severity),
				title = VALUES(title), message = VALUES(message), recommendation = VALUES(recommendation),
				data = VALUES(data), duration_ms = VALUES(duration_ms), measurement = VALUES(measurement),
				impact = VALUES(impact), cleanup = VALUES(cleanup), created_at = VALUES(created_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$scan_id,
			substr( $result->get_check_id(), 0, 100 ),
			substr( $result->get_category(), 0, 50 ),
			$result->get_status(),
			$result->get_severity(),
			mb_substr( $result->get_title(), 0, 255 ),
			$result->get_message(),
			$result->get_recommendation(),
			false === $data ? '{}' : $data,
			$result->get_duration_ms(),
			$result->get_measurement(),
			$result->get_impact() ? (string) wp_json_encode( $result->get_impact() ) : '',
			$result->get_cleanup() ? (string) wp_json_encode( $result->get_cleanup() ) : '',
			current_time( 'mysql', true )
		);

		return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * All results of a scan, most severe first.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_scan( int $scan_id ): array {
		global $wpdb;
		$table = Schema::results_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE scan_id = %d
				ORDER BY FIELD(severity, %s, %s, %s, %s), category, check_id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$scan_id,
				Severity::CRITICAL,
				Severity::WARNING,
				Severity::INFO,
				Severity::GOOD
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Data and timestamp of the most recent completed result of a check
	 * from a completed scan. Used for trend comparisons, e.g. database growth.
	 *
	 * @param string $check_id Check ID.
	 * @return array{data:array<string,mixed>,created_at:string}|null
	 */
	public function latest_completed_data( string $check_id ): ?array {
		global $wpdb;
		$results = Schema::results_table();
		$scans   = Schema::scans_table();
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT r.data, r.created_at FROM {$results} r INNER JOIN {$scans} s ON s.id = r.scan_id
				WHERE r.check_id = %s AND r.status = %s AND s.status = %s
				ORDER BY s.id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$check_id,
				Result::STATUS_COMPLETED,
				ScanRepository::STATUS_COMPLETED
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return array(
			'data'       => is_array( $data ) ? $data : array(),
			'created_at' => (string) $row['created_at'],
		);
	}

	/**
	 * Tallies a scan's results: counts per severity for completed results,
	 * plus "error" (errored) and "skipped".
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,int>
	 */
	public function tally( int $scan_id ): array {
		global $wpdb;
		$table = Schema::results_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, severity, COUNT(*) AS total FROM {$table} WHERE scan_id = %d GROUP BY status, severity", $scan_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$counts = array_fill_keys( Severity::all(), 0 ) + array(
			'error'   => 0,
			'skipped' => 0,
		);

		foreach ( (array) $rows as $row ) {
			if ( Result::STATUS_ERROR === $row['status'] ) {
				$counts['error'] += (int) $row['total'];
			} elseif ( Result::STATUS_SKIPPED === $row['status'] ) {
				$counts['skipped'] += (int) $row['total'];
			} elseif ( isset( $counts[ $row['severity'] ] ) ) {
				$counts[ $row['severity'] ] += (int) $row['total'];
			}
		}

		return $counts;
	}

	/**
	 * Deletes all results of the given scans.
	 *
	 * @param int[] $scan_ids Scan IDs.
	 * @return int Rows deleted.
	 */
	public function delete_for_scans( array $scan_ids ): int {
		global $wpdb;
		$scan_ids = array_values( array_filter( array_map( 'absint', $scan_ids ) ) );
		if ( ! $scan_ids ) {
			return 0;
		}
		$table        = Schema::results_table();
		$placeholders = implode( ',', array_fill( 0, count( $scan_ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE scan_id IN ({$placeholders})", $scan_ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Casts a raw row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$data   = json_decode( (string) ( $row['data'] ?? '' ), true );
		$impact = json_decode( (string) ( $row['impact'] ?? '' ), true );

		return array(
			'id'             => (int) $row['id'],
			'scan_id'        => (int) $row['scan_id'],
			'check_id'       => (string) $row['check_id'],
			'category'       => (string) $row['category'],
			'status'         => (string) $row['status'],
			'severity'       => (string) $row['severity'],
			'title'          => (string) $row['title'],
			'message'        => (string) $row['message'],
			'recommendation' => (string) $row['recommendation'],
			'data'           => is_array( $data ) ? $data : array(),
			'duration_ms'    => (int) $row['duration_ms'],
			'measurement'    => Measurement::normalize( (string) ( $row['measurement'] ?? Measurement::SERVER ) ),
			'impact'         => is_array( $impact ) && Impact::is_valid( $impact['level'] ?? null ) ? array(
				'level'   => (string) $impact['level'],
				'summary' => (string) ( $impact['summary'] ?? '' ),
			) : array(),
			'cleanup'        => ManualCleanup::normalize( json_decode( (string) ( $row['cleanup'] ?? '' ), true ) ),
			'created_at'     => (string) $row['created_at'],
		);
	}
}
