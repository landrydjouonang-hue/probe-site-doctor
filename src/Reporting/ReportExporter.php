<?php
/**
 * Report export.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Reporting;

use ProbeSiteDoctor\Admin\View;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a report as one self-contained HTML file.
 *
 * The file carries its own stylesheet inline and references nothing external,
 * so it can be archived, emailed or opened offline, and printed to PDF from any
 * browser. No PDF engine is bundled: shipping one would add a large dependency
 * for something every browser already does well.
 */
final class ReportExporter {

	/**
	 * Builds the complete HTML document.
	 *
	 * @param array<string,mixed>  $document     Document from ReportDocument::build().
	 * @param array<string,string> $check_labels Check ID => label.
	 * @return string
	 */
	public static function render( array $document, array $check_labels ): string {
		$body = View::capture(
			'report/document',
			array(
				'document'     => $document,
				'check_labels' => $check_labels,
				'context'      => 'file',
			)
		);

		$title = sprintf(
			/* translators: %s: Site name. */
			__( 'Website Health Report — %s', 'probe-site-doctor' ),
			$document['meta']['site_name']
		);

		$html  = '<!DOCTYPE html>' . "\n";
		$html .= '<html lang="' . esc_attr( str_replace( '_', '-', get_bloginfo( 'language' ) ) ) . '"' . ( is_rtl() ? ' dir="rtl"' : '' ) . '>' . "\n";
		$html .= '<head>' . "\n";
		$html .= '<meta charset="utf-8">' . "\n";
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		$html .= '<meta name="robots" content="noindex, nofollow">' . "\n";
		$html .= '<meta name="generator" content="Probe Site Doctor ' . esc_attr( $document['meta']['plugin_version'] ) . '">' . "\n";
		$html .= '<title>' . esc_html( $title ) . '</title>' . "\n";
		$html .= '<style>' . "\n" . self::styles() . "\n" . '</style>' . "\n";
		$html .= '</head>' . "\n";
		$html .= '<body class="probesd-report-file">' . "\n";
		$html .= $body;
		$html .= "\n" . '</body>' . "\n" . '</html>' . "\n";

		/**
		 * Filters the exported report HTML.
		 *
		 * @since 0.7.0
		 *
		 * @param string              $html     Complete HTML document.
		 * @param array<string,mixed> $document Report document.
		 */
		return (string) apply_filters( 'probesd_report_export_html', $html, $document );
	}

	/**
	 * Filename for the download.
	 *
	 * @param array<string,mixed> $document Document.
	 * @return string
	 */
	public static function filename( array $document ): string {
		$host = wp_parse_url( (string) $document['meta']['site_url'], PHP_URL_HOST );
		$date = substr( str_replace( array( '-', ':', ' ' ), '', (string) $document['meta']['scanned_at'] ), 0, 8 );

		return sanitize_file_name(
			sprintf(
				'site-health-report-%s-%s-%d.html',
				$host ? $host : 'site',
				$date ? $date : gmdate( 'Ymd' ),
				(int) $document['meta']['scan_id']
			)
		);
	}

	/**
	 * The report stylesheet, inlined. Falls back to no styling rather than
	 * failing the export if the file cannot be read.
	 *
	 * @return string
	 */
	private static function styles(): string {
		$file = PROBESD_PATH . 'assets/css/report.css';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$css = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin asset.

		// Nothing in the stylesheet may close the style element.
		return str_replace( '</', '<\/', (string) $css );
	}
}
