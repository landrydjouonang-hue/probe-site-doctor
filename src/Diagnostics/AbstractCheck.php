<?php
/**
 * Base class for checks.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

use ProbeSiteDoctor\Diagnostics\Contracts\Check;
use ProbeSiteDoctor\Diagnostics\Contracts\ReportsMeasurement;

defined( 'ABSPATH' ) || exit;

/**
 * Convenience base: applicable by default, server-side by default, and
 * provides result factories so concrete checks only describe themselves
 * and implement run().
 */
abstract class AbstractCheck implements Check, ReportsMeasurement {

	/**
	 * {@inheritDoc}
	 */
	public function is_applicable(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_measurement(): string {
		return Measurement::SERVER;
	}

	/**
	 * Thresholds for this check, filterable per check.
	 *
	 * @param array<string,int|float> $defaults Default thresholds.
	 * @return array<string,int|float>
	 */
	protected function thresholds( array $defaults ): array {
		/**
		 * Filters a check's thresholds.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string,int|float> $defaults Thresholds keyed by name.
		 * @param string                  $check_id Check ID.
		 */
		$filtered = apply_filters( 'probesd_check_thresholds', $defaults, $this->get_id() );

		$thresholds = $defaults;
		foreach ( (array) $filtered as $key => $value ) {
			if ( array_key_exists( $key, $defaults ) && is_numeric( $value ) ) {
				$thresholds[ $key ] = $value + 0;
			}
		}
		return $thresholds;
	}

	/**
	 * Builds a "good" result.
	 *
	 * @param string              $title   Headline.
	 * @param string              $message Details.
	 * @param array<string,mixed> $data    Structured data.
	 * @return Result
	 */
	protected function good( string $title, string $message = '', array $data = array() ): Result {
		return new Result( $this->get_id(), Severity::GOOD, $title, $message, '', $data );
	}

	/**
	 * Builds an "information" result.
	 *
	 * @param string              $title          Headline.
	 * @param string              $message        Details.
	 * @param string              $recommendation Optional suggestion.
	 * @param array<string,mixed> $data           Structured data.
	 * @return Result
	 */
	protected function info( string $title, string $message = '', string $recommendation = '', array $data = array() ): Result {
		return new Result( $this->get_id(), Severity::INFO, $title, $message, $recommendation, $data );
	}

	/**
	 * Builds a "warning" result.
	 *
	 * @param string              $title          Headline.
	 * @param string              $message        Details.
	 * @param string              $recommendation What to do.
	 * @param array<string,mixed> $data           Structured data.
	 * @return Result
	 */
	protected function warning( string $title, string $message, string $recommendation, array $data = array() ): Result {
		return new Result( $this->get_id(), Severity::WARNING, $title, $message, $recommendation, $data );
	}

	/**
	 * Builds a "critical" result.
	 *
	 * @param string              $title          Headline.
	 * @param string              $message        Details.
	 * @param string              $recommendation What to do.
	 * @param array<string,mixed> $data           Structured data.
	 * @return Result
	 */
	protected function critical( string $title, string $message, string $recommendation, array $data = array() ): Result {
		return new Result( $this->get_id(), Severity::CRITICAL, $title, $message, $recommendation, $data );
	}

	/**
	 * Builds a "skipped" result (the check ran but could not evaluate).
	 *
	 * @param string $reason Why it was skipped.
	 * @return Result
	 */
	protected function skipped( string $reason ): Result {
		return new Result(
			$this->get_id(),
			Severity::INFO,
			__( 'Not evaluated', 'probe-site-doctor' ),
			$reason,
			'',
			array(),
			Result::STATUS_SKIPPED
		);
	}

	/**
	 * Returns the more severe of two severities.
	 *
	 * @param string $current   Current severity.
	 * @param string $candidate Candidate severity.
	 * @return string
	 */
	protected function worst( string $current, string $candidate ): string {
		return Severity::rank( $candidate ) > Severity::rank( $current ) ? $candidate : $current;
	}

	/**
	 * Builds a result of the given severity.
	 *
	 * @param string              $severity       Severity.
	 * @param string              $title          Headline.
	 * @param string              $message        Explanation.
	 * @param string              $recommendation Recommended action (ignored for Good).
	 * @param array<string,mixed> $data           Structured data.
	 * @return Result
	 */
	protected function result( string $severity, string $title, string $message, string $recommendation, array $data = array() ): Result {
		return new Result( $this->get_id(), $severity, $title, $message, Severity::GOOD === $severity ? '' : $recommendation, $data );
	}

	/**
	 * Standard wording for configuration and security-related findings.
	 *
	 * Site Doctor reads settings; it does not scan for malware, vulnerable
	 * code or intrusions. Every such finding must say so.
	 *
	 * @return string
	 */
	protected function indicator_note(): string {
		return __( 'This is a configuration indicator, not a security audit: Site Doctor reads settings only and does not scan for malware, vulnerable code or intrusions. A clean result does not mean the site is secure.', 'probe-site-doctor' );
	}

	/**
	 * Standard wording for technical SEO findings.
	 *
	 * Site Doctor checks technical settings and markup. It does not assess
	 * content quality or rankings and is not an SEO platform.
	 *
	 * @return string
	 */
	protected function seo_scope_note(): string {
		return __( 'Technical check only: Site Doctor does not assess content quality, keywords, rankings, backlinks or competitors, and does not replace an SEO platform such as Yoast, Rank Math, Ahrefs or Semrush.', 'probe-site-doctor' );
	}

	/**
	 * Whether the site declares itself as a non-production environment.
	 *
	 * @return bool
	 */
	protected function is_non_production(): bool {
		return in_array( wp_get_environment_type(), array( 'local', 'development', 'staging' ), true );
	}
}
