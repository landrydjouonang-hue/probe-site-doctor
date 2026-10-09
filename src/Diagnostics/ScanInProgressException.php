<?php
/**
 * Raised when a scan is requested while another is active.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Concurrent scan guard.
 */
final class ScanInProgressException extends \RuntimeException {

	/**
	 * The active scan ID.
	 */
	private int $scan_id;

	/**
	 * Constructor.
	 *
	 * @param int $scan_id Active scan ID.
	 */
	public function __construct( int $scan_id ) {
		parent::__construct( __( 'Another scan is currently running. Wait for it to finish and try again.', 'probe-site-doctor' ) );
		$this->scan_id = $scan_id;
	}

	/**
	 * Active scan ID.
	 *
	 * @return int
	 */
	public function get_scan_id(): int {
		return $this->scan_id;
	}
}
