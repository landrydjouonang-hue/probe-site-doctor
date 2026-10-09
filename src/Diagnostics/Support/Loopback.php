<?php
/**
 * Loopback HTTP helper.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Requests the site's own public homepage the way an anonymous visitor
 * would: no cookies and no authentication.
 *
 * The timing is measured on the server for a request to itself. It excludes
 * visitor DNS, network latency, and browser download, parsing and rendering,
 * so it must never be presented as a page-load time.
 */
final class Loopback {

	/**
	 * Performs one GET request.
	 *
	 * @param string $url     URL (defaults to the homepage).
	 * @param int    $timeout Timeout in seconds.
	 * @return array{ok:bool,error:string,status:int,time_ms:int,headers:array<string,string>,body:string,url:string}
	 */
	public static function get( string $url = '', int $timeout = 10 ): array {
		$url = '' === $url ? home_url( '/' ) : $url;

		$args = array(
			'timeout'     => $timeout,
			'redirection' => 3,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a WordPress core filter; same default as core's own loopback tests.
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			'headers'     => array(
				'Accept'     => 'text/html,application/xhtml+xml',
				'User-Agent' => 'Probe-Site-Doctor/' . PROBESD_VERSION . '; ' . home_url( '/' ),
			),
			'cookies'     => array(),
		);

		/**
		 * Filters the loopback request arguments (e.g. to add Basic Auth on a protected staging site).
		 *
		 * @since 0.2.0
		 *
		 * @param array<string,mixed> $args wp_remote_get() arguments.
		 * @param string              $url  Requested URL.
		 */
		$args = (array) apply_filters( 'probesd_loopback_args', $args, $url );

		$start    = microtime( true );
		$response = wp_remote_get( $url, $args );
		$time_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'error'   => $response->get_error_message(),
				'status'  => 0,
				'time_ms' => $time_ms,
				'headers' => array(),
				'body'    => '',
				'url'     => $url,
			);
		}

		$headers = array();
		foreach ( (array) wp_remote_retrieve_headers( $response )->getAll() as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'ok'      => $status >= 200 && $status < 400,
			'error'   => $status >= 400 ? sprintf( 'HTTP %d', $status ) : '',
			'status'  => $status,
			'time_ms' => $time_ms,
			'headers' => $headers,
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'url'     => $url,
		);
	}
}
