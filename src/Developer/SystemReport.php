<?php
/**
 * Exportable diagnostic report.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Developer;

use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Reporting\ReportSections;
use ProbeSiteDoctor\Reporting\Score;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the system information — and, when one exists, the latest scan's
 * findings — as text a developer can paste into a support ticket, or as JSON
 * for tooling.
 *
 * Read-only. No file is written on the server and no request leaves the site.
 */
final class SystemReport {

	/**
	 * Builds the report data both formats share.
	 *
	 * @param array<string,mixed>|null $report Optional report from ReportBuilder::build().
	 * @return array<string,mixed>
	 */
	public static function build( ?array $report = null ): array {
		$data = array(
			'meta'   => array(
				'title'          => __( 'Probe Site Doctor diagnostic report', 'probe-site-doctor' ),
				'site_name'      => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'site_url'       => home_url( '/' ),
				'generated_at'   => current_time( 'mysql', true ),
				'plugin_version' => PROBESD_VERSION,
				'environment'    => wp_get_environment_type(),
			),
			'system' => SystemInfo::groups(),
		);

		if ( $report ) {
			$data['scan'] = self::scan_summary( $report );
		}

		return $data;
	}

	/**
	 * Markdown/plain text version, for tickets and chat.
	 *
	 * @param array<string,mixed>|null $report Optional report.
	 * @return string
	 */
	public static function markdown( ?array $report = null ): string {
		$data  = self::build( $report );
		$meta  = $data['meta'];
		$lines = array();

		$lines[] = '# ' . $meta['title'];
		$lines[] = '';
		$lines[] = '- Site: ' . $meta['site_name'] . ' (' . $meta['site_url'] . ')';
		$lines[] = '- Environment: ' . $meta['environment'];
		$lines[] = '- Generated: ' . $meta['generated_at'] . ' UTC';
		$lines[] = '- Produced by: Probe Site Doctor ' . $meta['plugin_version'];
		$lines[] = '';

		if ( isset( $data['scan'] ) ) {
			$scan    = $data['scan'];
			$lines[] = '## Health scan summary';
			$lines[] = '';
			$lines[] = '- Score: ' . ( null === $scan['score'] ? 'not scored' : $scan['score'] . '/100 (' . $scan['band_label'] . ')' );
			$lines[] = '- Scan: #' . $scan['id'] . ' on ' . $scan['date'] . ' UTC, ' . $scan['checks'] . ' checks';
			$lines[] = '- Critical: ' . $scan['counts'][ Severity::CRITICAL ] . ' · Warning: ' . $scan['counts'][ Severity::WARNING ]
				. ' · Information: ' . $scan['counts'][ Severity::INFO ] . ' · Good: ' . $scan['counts'][ Severity::GOOD ]
				. ' · Not evaluated: ' . ( $scan['counts']['skipped'] + $scan['counts']['error'] );
			$lines[] = '- Note: the score summarises only these checks. It is not a security guarantee.';
			$lines[] = '';

			if ( $scan['issues'] ) {
				$lines[] = '### Findings needing attention';
				$lines[] = '';
				foreach ( $scan['issues'] as $issue ) {
					$lines[] = '- **' . strtoupper( $issue['severity'] ) . '** · ' . $issue['section'] . ' · ' . $issue['title'];
					if ( '' !== $issue['recommendation'] ) {
						$lines[] = '  - Action: ' . $issue['recommendation'];
					}
				}
				$lines[] = '';
			}
		}

		foreach ( $data['system'] as $group ) {
			$lines[] = '## ' . $group['label'];
			$lines[] = '';
			if ( '' !== (string) $group['note'] ) {
				$lines[] = '_' . $group['note'] . '_';
				$lines[] = '';
			}

			if ( $group['fields'] ) {
				$lines[] = '| Field | Value |';
				$lines[] = '| --- | --- |';
				foreach ( $group['fields'] as $label => $value ) {
					$lines[] = '| ' . self::cell( (string) $label ) . ' | ' . self::cell( self::scalar( $value ) ) . ' |';
				}
				$lines[] = '';
			}

			if ( $group['rows'] ) {
				$columns = array_keys( (array) reset( $group['rows'] ) );
				$lines[] = '| ' . implode( ' | ', array_map( array( __CLASS__, 'heading' ), $columns ) ) . ' |';
				$lines[] = '| ' . implode( ' | ', array_fill( 0, count( $columns ), '---' ) ) . ' |';
				foreach ( $group['rows'] as $row ) {
					$cells = array();
					foreach ( $columns as $column ) {
						$cells[] = self::cell( self::scalar( is_array( $row ) ? ( $row[ $column ] ?? '' ) : '' ) );
					}
					$lines[] = '| ' . implode( ' | ', $cells ) . ' |';
				}
				$lines[] = '';
			}
		}

		$lines[] = '---';
		$lines[] = '';
		$lines[] = 'Collected read-only by Probe Site Doctor. Authentication keys, salts and the database password are never read, and the debug log contents are not included.';
		$lines[] = '';

		$markdown = implode( "\n", $lines );

		/**
		 * Filters the Markdown diagnostic report.
		 *
		 * @since 0.8.0
		 *
		 * @param string              $markdown Report text.
		 * @param array<string,mixed> $data     Report data.
		 */
		return (string) apply_filters( 'probesd_system_report_markdown', $markdown, $data );
	}

	/**
	 * JSON version, for tooling.
	 *
	 * @param array<string,mixed>|null $report Optional report.
	 * @return string
	 */
	public static function json( ?array $report = null ): string {
		$data = self::build( $report );

		/**
		 * Filters the diagnostic report data before it is encoded as JSON.
		 *
		 * @since 0.8.0
		 *
		 * @param array<string,mixed> $data Report data.
		 */
		$data = (array) apply_filters( 'probesd_system_report_data', $data );

		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Download filename for a format.
	 *
	 * @param string $format md|json.
	 * @return string
	 */
	public static function filename( string $format ): string {
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return sanitize_file_name(
			sprintf(
				'probe-site-doctor-diagnostics-%s-%s.%s',
				$host ? $host : 'site',
				gmdate( 'Ymd-Hi' ),
				'json' === $format ? 'json' : 'md'
			)
		);
	}

	/**
	 * Compact summary of a scan report.
	 *
	 * @param array<string,mixed> $report Report.
	 * @return array<string,mixed>
	 */
	private static function scan_summary( array $report ): array {
		$scan     = (array) $report['scan'];
		$sections = ReportSections::all();
		$issues   = array();

		foreach ( (array) $report['results'] as $result ) {
			if ( 'completed' !== $result['status'] || ! in_array( $result['severity'], array( Severity::CRITICAL, Severity::WARNING ), true ) ) {
				continue;
			}
			$section  = ReportSections::for_check( (string) $result['check_id'], (string) $result['category'] );
			$issues[] = array(
				'severity'       => (string) $result['severity'],
				'section'        => (string) ( $sections[ $section ]['label'] ?? $section ),
				'check_id'       => (string) $result['check_id'],
				'title'          => (string) $result['title'],
				'recommendation' => (string) $result['recommendation'],
			);
		}

		usort( $issues, static fn( $a, $b ) => Severity::rank( $b['severity'] ) <=> Severity::rank( $a['severity'] ) );

		return array(
			'id'         => (int) $scan['id'],
			'date'       => (string) ( $scan['finished_at'] ?? $scan['started_at'] ),
			'checks'     => (int) $scan['total_checks'],
			'score'      => $scan['score'],
			'band_label' => (string) $report['band_label'],
			'disclaimer' => Score::disclaimer(),
			'counts'     => (array) $report['counts'],
			'issues'     => $issues,
		);
	}

	/**
	 * Formats a value for text output.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( null === $value || '' === $value ) {
			return '—';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( __CLASS__, 'scalar' ), $value ) );
		}
		return (string) $value;
	}

	/**
	 * Escapes a Markdown table cell.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function cell( string $value ): string {
		return trim( str_replace( array( "\r", "\n", '|' ), array( ' ', ' ', '\\|' ), $value ) );
	}

	/**
	 * Column heading from a row key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function heading( string $key ): string {
		return ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
	}
}
