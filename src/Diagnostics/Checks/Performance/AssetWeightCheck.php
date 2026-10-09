<?php
/**
 * Excessive asset loading check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Performance;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\FrontendSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Totals the JavaScript and CSS the homepage references: file count,
 * on-disk weight of local files, inline code, HTML size and third-party
 * hosts.
 */
final class AssetWeightCheck extends AbstractCheck {

	public function get_id(): string {
		return 'performance.asset-weight';
	}

	public function get_category(): string {
		return CategoryRegistry::PERFORMANCE;
	}

	public function get_label(): string {
		return __( 'Excessive asset loading', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts the JavaScript and CSS files the homepage loads, their size on disk, and the number of third-party hosts.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$snapshot = FrontendSnapshot::get();
		if ( empty( $snapshot['ok'] ) ) {
			return $this->skipped(
				sprintf(
					/* translators: %s: Error message. */
					__( 'The homepage HTML could not be retrieved through a loopback request (%s).', 'probe-site-doctor' ),
					(string) $snapshot['error']
				)
			);
		}

		$t = $this->thresholds(
			array(
				'files_warning'  => 30,
				'files_critical' => 60,
				'bytes_warning'  => 1024 * 1024,
				'bytes_critical' => 3 * 1024 * 1024,
				'hosts_warning'  => 6,
				'html_warning'   => 512 * 1024,
				'inline_warning' => 150 * 1024,
			)
		);

		$assets    = array_merge( $snapshot['scripts'], $snapshot['styles'] );
		$files     = count( $assets );
		$js_bytes  = 0;
		$css_bytes = 0;
		$unsized   = 0;
		$hosts     = array();

		foreach ( $snapshot['scripts'] as $s ) {
			if ( null === $s['bytes'] ) {
				$unsized++;
			} else {
				$js_bytes += (int) $s['bytes'];
			}
		}
		foreach ( $snapshot['styles'] as $s ) {
			if ( null === $s['bytes'] ) {
				$unsized++;
			} else {
				$css_bytes += (int) $s['bytes'];
			}
		}
		foreach ( $assets as $a ) {
			if ( ! $a['local'] && '' !== $a['host'] ) {
				$hosts[ $a['host'] ] = true;
			}
		}

		$local_bytes  = $js_bytes + $css_bytes;
		$inline_bytes = (int) $snapshot['inline_script_bytes'] + (int) $snapshot['inline_style_bytes'];
		$html_bytes   = (int) $snapshot['html_bytes'];

		$data = array(
			'script_files'       => count( $snapshot['scripts'] ),
			'stylesheet_files'   => count( $snapshot['styles'] ),
			'local_js_bytes'     => $js_bytes,
			'local_css_bytes'    => $css_bytes,
			'files_without_size' => $unsized,
			'inline_code_bytes'  => $inline_bytes,
			'html_bytes'         => $html_bytes,
			'third_party_hosts'  => array_keys( $hosts ),
			'page'               => $snapshot['url'],
		);

		$severity = Severity::GOOD;
		$issues   = array();

		if ( $files >= $t['files_critical'] || $local_bytes >= $t['bytes_critical'] ) {
			$severity = Severity::CRITICAL;
		} elseif ( $files >= $t['files_warning'] || $local_bytes >= $t['bytes_warning'] ) {
			$severity = Severity::WARNING;
		}
		if ( $files >= $t['files_warning'] ) {
			/* translators: %s: Number of files. */
			$issues[] = sprintf( __( '%s JavaScript and CSS files are referenced.', 'probe-site-doctor' ), number_format_i18n( $files ) );
		}
		if ( $local_bytes >= $t['bytes_warning'] ) {
			/* translators: %s: Size. */
			$issues[] = sprintf( __( 'Local JavaScript and CSS total %s on disk.', 'probe-site-doctor' ), size_format( $local_bytes, 1 ) );
		}
		if ( count( $hosts ) >= $t['hosts_warning'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			/* translators: %s: Number of hosts. */
			$issues[] = sprintf( __( 'Assets come from %s different third-party hosts, and each one needs its own connection.', 'probe-site-doctor' ), number_format_i18n( count( $hosts ) ) );
		}
		if ( $html_bytes >= $t['html_warning'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			/* translators: %s: Size. */
			$issues[] = sprintf( __( 'The HTML document itself is %s.', 'probe-site-doctor' ), size_format( $html_bytes, 1 ) );
		}
		if ( $inline_bytes >= $t['inline_warning'] ) {
			$severity = $this->worst( $severity, Severity::INFO );
			/* translators: %s: Size. */
			$issues[] = sprintf( __( 'Inline scripts and styles add %s to every page, and they cannot be cached separately.', 'probe-site-doctor' ), size_format( $inline_bytes, 1 ) );
		}

		$summary = sprintf(
			/* translators: 1: Script count, 2: Stylesheet count, 3: Size, 4: Host count. */
			__( 'The homepage references %1$s scripts and %2$s stylesheets (%3$s of local files on disk) from %4$s third-party hosts.', 'probe-site-doctor' ),
			number_format_i18n( $data['script_files'] ),
			number_format_i18n( $data['stylesheet_files'] ),
			size_format( $local_bytes, 1 ),
			number_format_i18n( count( $hosts ) )
		);
		$scope   = __( 'Only the homepage is analysed. Sizes are uncompressed file sizes; third-party files, and assets that JavaScript loads later, are not measured. Actual transfer size and load time can only be measured in a browser.', 'probe-site-doctor' );

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Asset loading looks reasonable', 'probe-site-doctor' ), $summary . ' ' . $scope, $data );
		}

		return $this->result(
			$severity,
			Severity::INFO === $severity ? __( 'Asset loading could be leaner', 'probe-site-doctor' ) : __( 'The homepage loads a lot of assets', 'probe-site-doctor' ),
			$summary . ' ' . implode( ' ', $issues ) . ' ' . $scope,
			__( 'Find the plugins that load assets on pages where they are not needed, and restrict or remove them. Replace heavy page-builder or slider features where you can, and self-host critical third-party files such as fonts. Minification and HTTP/2 help, but loading less helps most.', 'probe-site-doctor' ),
			$data
		);
	}
}
