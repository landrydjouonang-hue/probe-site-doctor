<?php
/**
 * Core Web Vitals field data.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Performance;

use ProbeSiteDoctor\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The only part of Site Doctor that reports what visitors actually
 * experienced: Largest Contentful Paint, Interaction to Next Paint,
 * Cumulative Layout Shift, First Contentful Paint and Time to First Byte,
 * measured by the browser itself.
 *
 * How it works: when an administrator enables it, a small first-party script
 * runs on public pages, reads the browser's own performance entries and sends
 * them back to this site. Nothing is sent anywhere else, no third-party
 * service is involved, and no visitor identifier is stored — only the path,
 * a device class, the metric and its value.
 *
 * It is off by default because it adds a script to the public site.
 */
final class Vitals {

	/** Metrics collected, in reporting order. */
	public const METRICS = array( 'lcp', 'inp', 'cls', 'fcp', 'ttfb' );

	/** Google's "good" and "poor" boundaries. Values between them need improvement. */
	private const THRESHOLDS = array(
		'lcp'  => array( 2500, 4000 ),
		'inp'  => array( 200, 500 ),
		'cls'  => array( 0.1, 0.25 ),
		'fcp'  => array( 1800, 3000 ),
		'ttfb' => array( 800, 1800 ),
	);

	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks the public collector.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the collector on public pages.
	 *
	 * Skipped for logged-in users: their pages carry the admin bar and are
	 * usually uncached, which would skew the numbers away from what visitors see.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->settings->web_vitals_enabled() || is_user_logged_in() || is_preview() || is_customize_preview() ) {
			return;
		}
		if ( is_404() || is_search() || ( function_exists( 'is_robots' ) && is_robots() ) ) {
			return;
		}

		wp_enqueue_script(
			'probesd-vitals',
			PROBESD_URL . 'assets/js/vitals.js',
			array(),
			PROBESD_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'probesd-vitals',
			'probeSiteDoctorVitals',
			array(
				'endpoint' => rest_url( 'probe-site-doctor/v1/vitals' ),
				'path'     => PageProfile::path( self::current_url() ),
				'sampling' => $this->settings->web_vitals_sampling(),
			)
		);
	}

	/**
	 * Whether a value is good, needs improvement, or poor.
	 *
	 * @param string $metric Metric.
	 * @param float  $value  Value (ms, or unitless for CLS).
	 * @return string good|needs-improvement|poor
	 */
	public static function rating( string $metric, float $value ): string {
		if ( ! isset( self::THRESHOLDS[ $metric ] ) ) {
			return 'needs-improvement';
		}
		list( $good, $poor ) = self::THRESHOLDS[ $metric ];

		if ( $value <= $good ) {
			return 'good';
		}
		return $value <= $poor ? 'needs-improvement' : 'poor';
	}

	/**
	 * Thresholds for a metric.
	 *
	 * @param string $metric Metric.
	 * @return array{0:float,1:float}
	 */
	public static function thresholds( string $metric ): array {
		return self::THRESHOLDS[ $metric ] ?? array( 0, 0 );
	}

	/**
	 * Human label.
	 *
	 * @param string $metric Metric.
	 * @return string
	 */
	public static function label( string $metric ): string {
		switch ( $metric ) {
			case 'lcp':
				return __( 'Largest Contentful Paint', 'probe-site-doctor' );
			case 'inp':
				return __( 'Interaction to Next Paint', 'probe-site-doctor' );
			case 'cls':
				return __( 'Cumulative Layout Shift', 'probe-site-doctor' );
			case 'fcp':
				return __( 'First Contentful Paint', 'probe-site-doctor' );
			case 'ttfb':
				return __( 'Time to First Byte', 'probe-site-doctor' );
			default:
				return strtoupper( $metric );
		}
	}

	/**
	 * What the metric means, in one sentence.
	 *
	 * @param string $metric Metric.
	 * @return string
	 */
	public static function description( string $metric ): string {
		switch ( $metric ) {
			case 'lcp':
				return __( 'How long until the largest thing on the screen — usually the hero image or heading — has rendered. Good: 2.5 s or less.', 'probe-site-doctor' );
			case 'inp':
				return __( 'How long the page takes to respond visibly after a tap, click or key press. Good: 200 ms or less.', 'probe-site-doctor' );
			case 'cls':
				return __( 'How much the layout jumps around while loading. Good: 0.1 or less.', 'probe-site-doctor' );
			case 'fcp':
				return __( 'How long until anything at all is painted. Good: 1.8 s or less.', 'probe-site-doctor' );
			case 'ttfb':
				return __( 'How long the browser waited for the first byte of the document, including network latency. Good: 800 ms or less.', 'probe-site-doctor' );
			default:
				return '';
		}
	}

	/**
	 * Formats a value with its unit.
	 *
	 * @param string     $metric Metric.
	 * @param float|null $value  Value.
	 * @return string
	 */
	public static function format( string $metric, ?float $value ): string {
		if ( null === $value ) {
			return '—';
		}
		if ( 'cls' === $metric ) {
			return number_format_i18n( $value, 3 );
		}
		if ( $value >= 1000 ) {
			/* translators: %s: Number of seconds. */
			return sprintf( __( '%s s', 'probe-site-doctor' ), number_format_i18n( $value / 1000, 2 ) );
		}
		/* translators: %s: Number of milliseconds. */
		return sprintf( __( '%s ms', 'probe-site-doctor' ), number_format_i18n( round( $value ) ) );
	}

	/**
	 * Label for a rating.
	 *
	 * @param string $rating Rating.
	 * @return string
	 */
	public static function rating_label( string $rating ): string {
		switch ( $rating ) {
			case 'good':
				return __( 'Good', 'probe-site-doctor' );
			case 'poor':
				return __( 'Poor', 'probe-site-doctor' );
			default:
				return __( 'Needs improvement', 'probe-site-doctor' );
		}
	}

	/**
	 * Why field data and the server-side analysis are different things.
	 *
	 * @return string
	 */
	public static function scope_note(): string {
		return __( 'These numbers come from real browsers on this site, reported by visitors’ own devices — they are the only figures in Site Doctor that describe what visitors experience. Everything else Site Doctor measures happens on the server. Field data needs traffic before it means anything: treat fewer than about 30 samples as an indication, not a measurement.', 'probe-site-doctor' );
	}

	/**
	 * Current front-end URL, for keying the report.
	 *
	 * @return string
	 */
	private static function current_url(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $path );
	}
}
