<?php
/**
 * Check result value object.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable outcome of a single check.
 *
 * Status describes whether the check itself ran ("completed", "error",
 * "skipped"); severity describes what it found. Only completed results
 * count towards the health score.
 */
final class Result {

	public const STATUS_COMPLETED = 'completed';
	public const STATUS_ERROR     = 'error';
	public const STATUS_SKIPPED   = 'skipped';

	private string $check_id;
	private string $category = '';
	private string $severity;
	private string $status;
	private string $title;
	private string $message;
	private string $recommendation;
	/** @var array<string,mixed> */
	private array $data;
	private int $duration_ms = 0;
	private string $measurement = Measurement::SERVER;
	/** @var array{level?:string,summary?:string} */
	private array $impact = array();
	/** @var array<string,mixed> */
	private array $cleanup = array();

	/**
	 * Constructor.
	 *
	 * @param string              $check_id       Check identifier.
	 * @param string              $severity       One of the Severity constants.
	 * @param string              $title          Short headline of the finding.
	 * @param string              $message        Explanation of what was found.
	 * @param string              $recommendation What to do about it (empty when nothing).
	 * @param array<string,mixed> $data           Structured, JSON-serialisable details.
	 * @param string              $status         One of the STATUS_* constants.
	 *
	 * @throws \InvalidArgumentException On an unknown severity or status.
	 */
	public function __construct(
		string $check_id,
		string $severity,
		string $title,
		string $message = '',
		string $recommendation = '',
		array $data = array(),
		string $status = self::STATUS_COMPLETED
	) {
		// The values are interpolated into the message, so they are escaped: an
		// uncaught exception can end up in output.
		if ( ! Severity::is_valid( $severity ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown severity "%s".', esc_html( $severity ) ) );
		}
		if ( ! in_array( $status, array( self::STATUS_COMPLETED, self::STATUS_ERROR, self::STATUS_SKIPPED ), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown result status "%s".', esc_html( $status ) ) );
		}

		$this->check_id       = $check_id;
		$this->severity       = $severity;
		$this->title          = $title;
		$this->message        = $message;
		$this->recommendation = $recommendation;
		$this->data           = $data;
		$this->status         = $status;
	}

	/**
	 * Builds a result describing a check that failed to execute.
	 *
	 * @param string $check_id Check identifier.
	 * @param string $message  Error description.
	 * @return self
	 */
	public static function error( string $check_id, string $message ): self {
		return new self(
			$check_id,
			Severity::INFO,
			__( 'The check could not be completed', 'probe-site-doctor' ),
			$message,
			__( 'Run the scan again. If the problem persists, check the PHP error log.', 'probe-site-doctor' ),
			array(),
			self::STATUS_ERROR
		);
	}

	/**
	 * Returns a copy carrying engine-supplied metadata.
	 *
	 * @param string $category    Category ID.
	 * @param int    $duration_ms Execution time in milliseconds.
	 * @param string $measurement Measurement method (see Measurement).
	 * @return self
	 */
	public function with_meta( string $category, int $duration_ms, string $measurement = Measurement::SERVER ): self {
		$clone              = clone $this;
		$clone->category    = $category;
		$clone->duration_ms = max( 0, $duration_ms );
		$clone->measurement = Measurement::normalize( $measurement );
		return $clone;
	}

	/**
	 * Returns a copy carrying an estimated impact.
	 *
	 * @param string $level   One of the Impact constants.
	 * @param string $summary Why the impact is estimated at this level.
	 * @return self
	 */
	public function with_impact( string $level, string $summary ): self {
		$clone         = clone $this;
		$clone->impact = array(
			'level'   => Impact::is_valid( $level ) ? $level : Impact::LOW,
			'summary' => $summary,
		);
		return $clone;
	}

	/**
	 * Returns a copy carrying optional manual cleanup instructions.
	 *
	 * @param ManualCleanup $cleanup Instructions (never executed by the plugin).
	 * @return self
	 */
	public function with_cleanup( ManualCleanup $cleanup ): self {
		$clone          = clone $this;
		$clone->cleanup = $cleanup->to_array();
		return $clone;
	}

	/**
	 * Estimated impact (empty when not assessed).
	 *
	 * @return array{level?:string,summary?:string}
	 */
	public function get_impact(): array {
		return $this->impact;
	}

	/**
	 * Manual cleanup instructions (empty when none).
	 *
	 * @return array<string,mixed>
	 */
	public function get_cleanup(): array {
		return $this->cleanup;
	}

	public function get_check_id(): string {
		return $this->check_id;
	}

	public function get_category(): string {
		return $this->category;
	}

	public function get_severity(): string {
		return $this->severity;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_title(): string {
		return $this->title;
	}

	public function get_message(): string {
		return $this->message;
	}

	public function get_recommendation(): string {
		return $this->recommendation;
	}

	/**
	 * Structured details.
	 *
	 * @return array<string,mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}

	public function get_duration_ms(): int {
		return $this->duration_ms;
	}

	public function get_measurement(): string {
		return $this->measurement;
	}

	/**
	 * Array representation (storage and REST).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'check_id'       => $this->check_id,
			'category'       => $this->category,
			'status'         => $this->status,
			'severity'       => $this->severity,
			'title'          => $this->title,
			'message'        => $this->message,
			'recommendation' => $this->recommendation,
			'data'           => $this->data,
			'duration_ms'    => $this->duration_ms,
			'measurement'    => $this->measurement,
			'impact'         => $this->impact,
			'cleanup'        => $this->cleanup,
		);
	}
}
