<?php
/**
 * Scan persistence.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Storage;

use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; caching is handled by callers where useful.

/**
 * CRUD for the scans table.
 */
final class ScanRepository {

	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_ABANDONED = 'abandoned';

	/**
	 * Creates a running scan.
	 *
	 * @param string              $source       Origin: manual, fallback, cli, scheduled.
	 * @param int                 $user_id      Initiating user.
	 * @param int                 $total_checks Planned number of checks.
	 * @param array<string,mixed> $environment  Environment snapshot.
	 * @return int Scan ID (0 on failure).
	 */
	public function create( string $source, int $user_id, int $total_checks, array $environment ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			Schema::scans_table(),
			array(
				'status'         => self::STATUS_RUNNING,
				'source'         => sanitize_key( $source ),
				'user_id'        => $user_id,
				'total_checks'   => $total_checks,
				'plugin_version' => PROBESD_VERSION,
				'environment'    => wp_json_encode( $environment ),
				'started_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetches one scan.
	 *
	 * @param int $id Scan ID.
	 * @return array<string,mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;
		$table = Schema::scans_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Latest completed scan.
	 *
	 * @return array<string,mixed>|null
	 */
	public function latest_completed(): ?array {
		global $wpdb;
		$table = Schema::scans_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY started_at DESC, id DESC LIMIT 1", self::STATUS_COMPLETED ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Completed scan immediately before the given one (for trend comparison).
	 *
	 * @param int $id Scan ID.
	 * @return array<string,mixed>|null
	 */
	public function previous_completed( int $id ): ?array {
		global $wpdb;
		$table = Schema::scans_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND id < %d ORDER BY id DESC LIMIT 1", self::STATUS_COMPLETED, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Most recently active running scan, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public function running(): ?array {
		global $wpdb;
		$table = Schema::scans_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY updated_at DESC LIMIT 1", self::STATUS_RUNNING ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Paginated list.
	 *
	 * @param int         $per_page Items per page.
	 * @param int         $page     1-based page.
	 * @param string|null $status   Optional status filter.
	 * @return array<int,array<string,mixed>>
	 */
	public function paginate( int $per_page, int $page, ?string $status = null ): array {
		global $wpdb;
		$table    = Schema::scans_table();
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		if ( null !== $status ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d", $status, $per_page, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d", $per_page, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array_map( array( $this, 'hydrate' ), (array) $wpdb->get_results( $sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Row count.
	 *
	 * @param string|null $status Optional status filter.
	 * @return int
	 */
	public function count( ?string $status = null ): int {
		global $wpdb;
		$table = Schema::scans_table();
		if ( null !== $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Marks the scan as recently active.
	 *
	 * @param int $id Scan ID.
	 * @return void
	 */
	public function touch( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::scans_table(), array( 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Stores final counts and score and marks the scan completed.
	 *
	 * @param int             $id     Scan ID.
	 * @param array<string,int> $counts Counts keyed by severity plus "error".
	 * @param int|null        $score  Health score.
	 * @return bool
	 */
	public function complete( int $id, array $counts, ?int $score ): bool {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = array(
			'status'         => self::STATUS_COMPLETED,
			'count_good'     => (int) ( $counts[ Severity::GOOD ] ?? 0 ),
			'count_info'     => (int) ( $counts[ Severity::INFO ] ?? 0 ),
			'count_warning'  => (int) ( $counts[ Severity::WARNING ] ?? 0 ),
			'count_critical' => (int) ( $counts[ Severity::CRITICAL ] ?? 0 ),
			'count_error'    => (int) ( $counts['error'] ?? 0 ),
			'updated_at'     => $now,
			'finished_at'    => $now,
		);
		$formats = array( '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s' );

		$ok = false !== $wpdb->update( Schema::scans_table(), $data, array( 'id' => $id ), $formats, array( '%d' ) );

		// $wpdb->update() cannot write NULL with a format, so the score is set separately.
		if ( $ok ) {
			$table = Schema::scans_table();
			if ( null === $score ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET score = NULL WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET score = %d WHERE id = %d", $score, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		return $ok;
	}

	/**
	 * Marks running scans with no activity since the cutoff as abandoned.
	 *
	 * @param int $idle_seconds Inactivity threshold.
	 * @return int Rows affected.
	 */
	public function abandon_stale( int $idle_seconds ): int {
		global $wpdb;
		$table  = Schema::scans_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 60, $idle_seconds ) );
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, finished_at = updated_at WHERE status = %s AND updated_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_ABANDONED,
				self::STATUS_RUNNING,
				$cutoff
			)
		);
	}

	/**
	 * IDs of scans to prune: completed scans beyond the newest $keep, plus
	 * every abandoned scan (they hold no usable report).
	 *
	 * @param int $keep Number of newest completed scans to keep.
	 * @return int[]
	 */
	public function ids_to_prune( int $keep ): array {
		global $wpdb;
		$table = Schema::scans_table();

		$completed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = %s ORDER BY started_at DESC, id DESC LIMIT 18446744073709551615 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::STATUS_COMPLETED,
				max( 1, $keep )
			)
		);
		$abandoned = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE status = %s", self::STATUS_ABANDONED ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', array_merge( (array) $completed, (array) $abandoned ) );
	}

	/**
	 * Deletes a scan row (results are removed by ResultRepository first).
	 *
	 * @param int $id Scan ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::scans_table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Casts a raw row.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$environment = json_decode( (string) ( $row['environment'] ?? '' ), true );

		return array(
			'id'             => (int) $row['id'],
			'status'         => (string) $row['status'],
			'source'         => (string) $row['source'],
			'user_id'        => (int) $row['user_id'],
			'total_checks'   => (int) $row['total_checks'],
			'counts'         => array(
				Severity::GOOD     => (int) $row['count_good'],
				Severity::INFO     => (int) $row['count_info'],
				Severity::WARNING  => (int) $row['count_warning'],
				Severity::CRITICAL => (int) $row['count_critical'],
				'error'            => (int) $row['count_error'],
			),
			'score'          => null === $row['score'] ? null : (int) $row['score'],
			'plugin_version' => (string) $row['plugin_version'],
			'environment'    => is_array( $environment ) ? $environment : array(),
			'started_at'     => (string) $row['started_at'],
			'updated_at'     => (string) $row['updated_at'],
			'finished_at'    => null === $row['finished_at'] ? null : (string) $row['finished_at'],
		);
	}
}
