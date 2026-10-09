<?php
/**
 * Database query performance indicators.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Database;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Measures what can safely be measured from inside a scan request:
 * - the database round-trip time (a trivial SELECT 1, repeated);
 * - the time of a typical read-only query (the latest published posts);
 * - whether SAVEQUERIES is enabled, which slows every request.
 *
 * It does not profile the queries that real page views run. That needs a
 * profiler such as Query Monitor on the page in question.
 */
final class QueryPerformanceCheck extends AbstractCheck {

	private const PING_SAMPLES  = 10;
	private const QUERY_SAMPLES = 3;

	public function get_id(): string {
		return 'database.query-performance';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Database query indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Times the database round trip and a typical read-only query, and checks for query logging overhead.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'latency_warning_ms'  => 5,
				'latency_critical_ms' => 20,
				'query_warning_ms'    => 50,
				'query_critical_ms'   => 250,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ping      = array();
		$responded = true;
		for ( $i = 0; $i < self::PING_SAMPLES; $i++ ) {
			$start     = microtime( true );
			$responded = '1' === (string) $wpdb->get_var( 'SELECT 1' ) && $responded;
			$ping[]    = ( microtime( true ) - $start ) * 1000;
		}

		$query = array();
		for ( $i = 0; $i < self::QUERY_SAMPLES; $i++ ) {
			$start   = microtime( true );
			$wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY post_date DESC LIMIT 10" );
			$query[] = ( microtime( true ) - $start ) * 1000;
		}
		// phpcs:enable

		if ( ! $responded ) {
			return $this->skipped( __( 'The timing queries returned a database error.', 'probe-site-doctor' ) );
		}

		$latency     = $this->median( $ping );
		$query_time  = $this->median( $query );
		$savequeries = defined( 'SAVEQUERIES' ) && SAVEQUERIES;

		$data = array(
			'round_trip_median_ms'    => round( $latency, 2 ),
			'typical_query_median_ms' => round( $query_time, 2 ),
			'savequeries'             => $savequeries,
			'server'                  => $wpdb->db_server_info(),
		);

		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		if ( $latency >= $t['latency_critical_ms'] || $query_time >= $t['query_critical_ms'] ) {
			$severity = Severity::CRITICAL;
		} elseif ( $latency >= $t['latency_warning_ms'] || $query_time >= $t['query_warning_ms'] ) {
			$severity = Severity::WARNING;
		}
		if ( $latency >= $t['latency_warning_ms'] ) {
			$issues[]  = __( 'Each database round trip is slow. A WordPress page often runs dozens of queries, so this delay adds up.', 'probe-site-doctor' );
			$actions[] = __( 'Ask your host whether the database runs on a separate or overloaded server. Use a persistent object cache to reduce the number of queries.', 'probe-site-doctor' );
		}
		if ( $query_time >= $t['query_warning_ms'] ) {
			$issues[]  = __( 'A simple indexed query on the posts table is slow, which suggests the database server is under load or short of memory, or that the posts table is very large or damaged.', 'probe-site-doctor' );
			$actions[] = __( 'Ask your host to check database server load and memory (for example innodb_buffer_pool_size), and check the tables for errors.', 'probe-site-doctor' );
		}
		if ( $savequeries && ! $this->is_non_production() ) {
			$severity  = $this->worst( $severity, Severity::WARNING );
			$issues[]  = __( 'SAVEQUERIES is enabled, so every query of every request is recorded with a backtrace, which costs memory and time.', 'probe-site-doctor' );
			$actions[] = __( 'Remove SAVEQUERIES from wp-config.php once debugging is finished.', 'probe-site-doctor' );
		}

		$summary = sprintf(
			/* translators: 1: Milliseconds, 2: Milliseconds. */
			__( 'Median database round trip: %1$s ms. Median time for a typical query (latest posts): %2$s ms.', 'probe-site-doctor' ),
			number_format_i18n( $latency, 2 ),
			number_format_i18n( $query_time, 2 )
		);
		$scope   = __( 'Measured on the server during this scan; the load at that moment affects the result. The queries run by real page views are not profiled. Use a query profiler such as Query Monitor on a specific page for that.', 'probe-site-doctor' );

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Database responds quickly', 'probe-site-doctor' ), $summary . ' ' . $scope, $data );
		}

		return $this->result(
			$severity,
			__( 'Database queries are slower than expected', 'probe-site-doctor' ),
			$summary . ' ' . implode( ' ', $issues ) . ' ' . $scope,
			implode( ' ', $actions ),
			$data
		);
	}

	/**
	 * Median of samples.
	 *
	 * @param float[] $samples Samples.
	 * @return float
	 */
	private function median( array $samples ): float {
		sort( $samples );
		$n = count( $samples );
		if ( 0 === $n ) {
			return 0.0;
		}
		$mid = intdiv( $n, 2 );
		return $n % 2 ? $samples[ $mid ] : ( $samples[ $mid - 1 ] + $samples[ $mid ] ) / 2;
	}
}
