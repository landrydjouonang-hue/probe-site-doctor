<?php
/**
 * Script/style loading check.
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
 * Counts render-blocking scripts and stylesheets in the homepage <head>.
 *
 * A script in <head> without async, defer or type="module" stops HTML
 * parsing until it is downloaded and executed. A stylesheet in <head>
 * (other than media="print") must load before the first paint. This check
 * reads that from the HTML markup. How much it slows a real visit depends on
 * file sizes, caching and the visitor's connection, which only a browser test
 * can measure.
 */
final class RenderBlockingAssetsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'performance.render-blocking-assets';
	}

	public function get_category(): string {
		return CategoryRegistry::PERFORMANCE;
	}

	public function get_label(): string {
		return __( 'Script and style loading', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts scripts and stylesheets in the homepage <head> that block rendering, based on the HTML the server delivers.', 'probe-site-doctor' );
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
				'scripts_warning'  => 3,
				'scripts_critical' => 10,
				'styles_warning'   => 10,
				'styles_critical'  => 25,
			)
		);

		$blocking_scripts = array_values(
			array_filter(
				$snapshot['scripts'],
				static fn( $s ) => $s['head'] && ! $s['async'] && ! $s['defer'] && ! $s['module']
			)
		);
		$blocking_styles = array_values(
			array_filter(
				$snapshot['styles'],
				static fn( $s ) => $s['head'] && 'print' !== $s['media']
			)
		);
		$deferred        = count(
			array_filter(
				$snapshot['scripts'],
				static fn( $s ) => $s['async'] || $s['defer'] || $s['module']
			)
		);

		$bs = count( $blocking_scripts );
		$bc = count( $blocking_styles );

		$severity = Severity::GOOD;
		if ( $bs >= $t['scripts_critical'] ) {
			$severity = Severity::CRITICAL;
		} elseif ( $bs >= $t['scripts_warning'] ) {
			$severity = Severity::WARNING;
		}
		if ( $bc >= $t['styles_critical'] ) {
			$severity = $this->worst( $severity, Severity::CRITICAL );
		} elseif ( $bc >= $t['styles_warning'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
		}

		$data = array(
			'scripts_total'             => count( $snapshot['scripts'] ),
			'render_blocking_scripts'   => $bs,
			'async_or_deferred_scripts' => $deferred,
			'stylesheets_total'         => count( $snapshot['styles'] ),
			'render_blocking_styles'    => $bc,
			'blocking_script_files'     => $this->short_list( $blocking_scripts ),
			'blocking_style_files'      => $this->short_list( $blocking_styles ),
			'page'                      => $snapshot['url'],
		);

		$message = sprintf(
			/* translators: 1: Number of scripts, 2: Number of stylesheets, 3: Number of deferred scripts. */
			__( 'The homepage <head> loads %1$s render-blocking scripts and %2$s render-blocking stylesheets; %3$s scripts use async, defer or type="module".', 'probe-site-doctor' ),
			number_format_i18n( $bs ),
			number_format_i18n( $bc ),
			number_format_i18n( $deferred )
		);
		$scope   = __( 'Based on the HTML markup only. How much this delays a real visit depends on file sizes, caching and the visitor’s connection, which can only be measured in a browser (for example with Lighthouse or PageSpeed Insights).', 'probe-site-doctor' );

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Few render-blocking assets', 'probe-site-doctor' ), $message . ' ' . $scope, $data );
		}

		return $this->result(
			$severity,
			__( 'Many scripts or styles block page rendering', 'probe-site-doctor' ),
			$message . ' ' . __( 'The browser must download these files before it can display the page.', 'probe-site-doctor' ) . ' ' . $scope,
			__( 'Load non-critical scripts with defer or in the footer (WordPress 6.3+ supports a loading strategy in wp_enqueue_script), and remove scripts and styles that the homepage does not use. An optimization plugin can defer or combine assets, but test the site afterwards.', 'probe-site-doctor' ),
			$data
		);
	}

	/**
	 * Compact "host/path/file" list (max 15) for display.
	 *
	 * @param array<int,array<string,mixed>> $assets Assets.
	 * @return string[]
	 */
	private function short_list( array $assets ): array {
		$list = array();
		foreach ( array_slice( $assets, 0, 15 ) as $asset ) {
			$path   = (string) wp_parse_url( (string) $asset['url'], PHP_URL_PATH );
			$list[] = ( $asset['local'] ? '' : $asset['host'] . ' ' ) . wp_basename( $path );
		}
		if ( count( $assets ) > 15 ) {
			/* translators: %d: Number of further items. */
			$list[] = sprintf( __( '… and %d more', 'probe-site-doctor' ), count( $assets ) - 15 );
		}
		return $list;
	}
}
