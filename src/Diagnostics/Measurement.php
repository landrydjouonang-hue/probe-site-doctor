<?php
/**
 * Measurement methods.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * How a check obtained its evidence.
 *
 * Every method runs on the web server. None of them is a browser
 * measurement: Site Doctor never reports page-load metrics such as LCP,
 * INP or CLS, which can only be measured in a real browser.
 */
final class Measurement {

	/** Reads settings, files, PHP/WordPress state or the database. */
	public const SERVER = 'server';

	/** The server requests its own public homepage (no cookies) and inspects the response. */
	public const LOOPBACK = 'loopback';

	/**
	 * All methods.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::SERVER, self::LOOPBACK );
	}

	/**
	 * Normalises unknown values to SERVER.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	public static function normalize( string $method ): string {
		return in_array( $method, self::all(), true ) ? $method : self::SERVER;
	}

	/**
	 * Short label.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	public static function label( string $method ): string {
		return self::LOOPBACK === $method
			? __( 'Server loopback', 'probe-site-doctor' )
			: __( 'Server-side', 'probe-site-doctor' );
	}

	/**
	 * What the method can and cannot tell.
	 *
	 * @param string $method Method.
	 * @return string
	 */
	public static function description( string $method ): string {
		if ( self::LOOPBACK === $method ) {
			return __( 'The server requested its own homepage as an anonymous visitor and analysed the response and HTML. Timings are server-to-itself and exclude visitor network latency, image downloads, JavaScript execution and rendering.', 'probe-site-doctor' );
		}
		return __( 'Read from the server configuration, files, WordPress settings or the database. Describes how the site is set up, not how fast pages load in a browser.', 'probe-site-doctor' );
	}
}
