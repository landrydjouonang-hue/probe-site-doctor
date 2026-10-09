<?php
/**
 * Scan orchestration.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

use ProbeSiteDoctor\Core\Settings;
use ProbeSiteDoctor\Reporting\Score;
use ProbeSiteDoctor\Storage\ResultRepository;
use ProbeSiteDoctor\Storage\ScanRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the scan lifecycle: start → run each check → complete.
 *
 * The lifecycle is split into steps so the dashboard can run one check per
 * request (progress feedback, no PHP timeouts on slow hosts). run_all() does
 * the whole lifecycle in one request for the no-JavaScript fallback and CLI.
 */
final class Scanner {

	/**
	 * A running scan without activity for this long is considered abandoned.
	 */
	public const IDLE_TIMEOUT = 120;

	private CheckRegistry $registry;
	private Engine $engine;
	private ScanRepository $scans;
	private ResultRepository $results;
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param CheckRegistry    $registry Checks.
	 * @param Engine           $engine   Engine.
	 * @param ScanRepository   $scans    Scans.
	 * @param ResultRepository $results  Results.
	 * @param Settings         $settings Settings.
	 */
	public function __construct( CheckRegistry $registry, Engine $engine, ScanRepository $scans, ResultRepository $results, Settings $settings ) {
		$this->registry = $registry;
		$this->engine   = $engine;
		$this->scans    = $scans;
		$this->results  = $results;
		$this->settings = $settings;
	}

	/**
	 * Starts a scan.
	 *
	 * @param string $source  Origin (manual, fallback, cli).
	 * @param int    $user_id Initiating user.
	 * @return array{scan_id:int,checks:string[]}
	 *
	 * @throws ScanInProgressException When another scan is active.
	 * @throws \RuntimeException        When the scan cannot be stored.
	 */
	public function start( string $source, int $user_id ): array {
		$this->scans->abandon_stale( self::IDLE_TIMEOUT );

		$running = $this->scans->running();
		if ( $running ) {
			throw new ScanInProgressException( (int) $running['id'] );
		}

		$checks  = array_keys( $this->registry->applicable() );
		$scan_id = $this->scans->create( $source, $user_id, count( $checks ), $this->environment() );

		if ( ! $scan_id ) {
			throw new \RuntimeException( esc_html__( 'The scan could not be saved to the database.', 'probe-site-doctor' ) );
		}

		/**
		 * Fires when a scan starts.
		 *
		 * @since 0.1.0
		 *
		 * @param int      $scan_id Scan ID.
		 * @param string[] $checks  Planned check IDs.
		 */
		do_action( 'probesd_scan_started', $scan_id, $checks );

		return array(
			'scan_id' => $scan_id,
			'checks'  => $checks,
		);
	}

	/**
	 * Runs one check within a running scan and stores the result.
	 *
	 * @param int    $scan_id  Scan ID.
	 * @param string $check_id Check ID.
	 * @return Result
	 *
	 * @throws \DomainException When the scan or check is not valid for this call.
	 */
	public function run_check( int $scan_id, string $check_id ): Result {
		$scan = $this->scans->find( $scan_id );
		if ( ! $scan || ScanRepository::STATUS_RUNNING !== $scan['status'] ) {
			throw new \DomainException( esc_html__( 'This scan is not running.', 'probe-site-doctor' ) );
		}

		$check = $this->registry->get( $check_id );
		if ( ! $check ) {
			throw new \DomainException( esc_html__( 'Unknown check.', 'probe-site-doctor' ) );
		}

		$result = $this->engine->execute( $check );

		$this->results->save( $scan_id, $result );
		$this->scans->touch( $scan_id );

		return $result;
	}

	/**
	 * Finalises a scan: tallies results, stores the score, applies retention.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array<string,mixed> Updated scan.
	 *
	 * @throws \DomainException When the scan is not running.
	 */
	public function complete( int $scan_id ): array {
		$scan = $this->scans->find( $scan_id );
		if ( ! $scan || ScanRepository::STATUS_RUNNING !== $scan['status'] ) {
			throw new \DomainException( esc_html__( 'This scan is not running.', 'probe-site-doctor' ) );
		}

		$counts = $this->results->tally( $scan_id );
		$score  = Score::calculate( $counts );

		$this->scans->complete( $scan_id, $counts, $score );
		$this->prune();

		$scan = (array) $this->scans->find( $scan_id );

		/**
		 * Fires after a scan completes.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $scan Completed scan.
		 */
		do_action( 'probesd_scan_completed', $scan );

		return $scan;
	}

	/**
	 * Runs a complete scan in a single request.
	 *
	 * @param string $source  Origin.
	 * @param int    $user_id Initiating user.
	 * @return array<string,mixed> Completed scan.
	 */
	public function run_all( string $source, int $user_id ): array {
		$started = $this->start( $source, $user_id );

		foreach ( $started['checks'] as $check_id ) {
			$this->run_check( $started['scan_id'], $check_id );
		}

		return $this->complete( $started['scan_id'] );
	}

	/**
	 * Deletes a scan and its results.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public function delete( int $scan_id ): bool {
		$this->results->delete_for_scans( array( $scan_id ) );
		return $this->scans->delete( $scan_id );
	}

	/**
	 * Removes completed scans beyond the retention limit and interrupted scans.
	 *
	 * @return int Number of scans removed.
	 */
	public function prune(): int {
		$ids = $this->scans->ids_to_prune( $this->settings->retention() );
		if ( ! $ids ) {
			return 0;
		}
		$this->results->delete_for_scans( $ids );
		foreach ( $ids as $id ) {
			$this->scans->delete( $id );
		}
		return count( $ids );
	}

	/**
	 * Non-sensitive snapshot of the environment the scan ran in.
	 *
	 * @return array<string,mixed>
	 */
	private function environment(): array {
		global $wpdb;

		$theme = wp_get_theme();

		return array(
			'wp_version'       => get_bloginfo( 'version' ),
			'php_version'      => PHP_VERSION,
			'db_server'        => $wpdb->db_server_info(),
			'environment_type' => wp_get_environment_type(),
			'multisite'        => is_multisite(),
			'theme'            => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'active_plugins'   => count( (array) get_option( 'active_plugins', array() ) ),
			'locale'           => get_locale(),
		);
	}
}
