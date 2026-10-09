<?php
/**
 * REST API availability check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Developer;

use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Tests whether the REST API answers at all.
 *
 * This is the opposite question to configuration.rest-api, which reports how
 * much the REST API exposes: here the concern is a broken REST API, because
 * the block editor, site health and most modern plugins stop working.
 */
final class RestApiStatusCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.rest-api';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'REST API status', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Requests the REST root and a core route to confirm the REST API responds with JSON.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$rest = SystemInfo::rest();

		$data = array(
			'rest_url'     => rest_url(),
			'status'       => $rest['status'],
			'returns_json' => $rest['ok'],
			'namespaces'   => $rest['namespaces'],
			'types_status' => $rest['types_status'],
			'time_ms'      => $rest['time_ms'],
			'permalinks'   => (string) get_option( 'permalink_structure' ) !== '' ? 'pretty' : 'plain',
		);

		if ( 0 === $rest['status'] ) {
			return $this->critical(
				__( 'The REST API could not be reached', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Error message. */
					__( 'The server could not request its own REST root: %s. Either loopback requests are blocked or the site is not reachable under its configured URL. The block editor and anything using REST will fail for everyone.', 'probe-site-doctor' ),
					$rest['error'] ?: __( 'no response', 'probe-site-doctor' )
				),
				__( 'Check that the server can resolve and reach its own domain (hosts file, firewall, DNS), and that the Site Address in Settings → General matches how the site is actually served.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'The editor cannot save, and REST-based plugins and mobile apps stop working.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( in_array( (int) $rest['status'], array( 401, 403 ), true ) ) {
			return $this->warning(
				sprintf(
					/* translators: %s: HTTP status code. */
					__( 'The REST API rejects anonymous requests (HTTP %s)', 'probe-site-doctor' ),
					number_format_i18n( (int) $rest['status'] )
				),
				__( 'Something is requiring authentication for the REST root — usually a security plugin, an "disable REST API" setting or server-level rules. That also blocks legitimate anonymous use: block-editor previews, oEmbed, some themes and any headless front end.', 'probe-site-doctor' ),
				__( 'If this is deliberate, confirm the editor still works while logged in. If not, find the plugin or rule filtering rest_authentication_errors and narrow it to the routes you actually want closed.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Anonymous REST consumers break; logged-in editing may still work.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( ! $rest['ok'] ) {
			return $this->critical(
				sprintf(
					/* translators: %s: HTTP status code. */
					__( 'The REST API does not return JSON (HTTP %s)', 'probe-site-doctor' ),
					number_format_i18n( (int) $rest['status'] )
				),
				__( 'The REST root answered, but not with a JSON document. That is what happens when a plugin or theme prints output before the response, when a fatal error occurs during rest_api_init, or when the server returns an error page for the route.', 'probe-site-doctor' ),
				__( 'Open the REST URL in a browser to see the raw output — stray whitespace, a PHP notice or an HTML error page will be visible. Deactivate plugins one by one, or check the error log for a fatal in a rest_api_init callback.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Everything built on REST fails, usually with "Updating failed" in the editor.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( $rest['types_status'] >= 400 ) {
			return $this->warning(
				__( 'The REST root works but a core route does not', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: HTTP status code. */
					__( 'The REST root returned JSON, but /wp/v2/types answered HTTP %s. Individual routes are being blocked rather than the API as a whole — typically by a security plugin rule or a server WAF.', 'probe-site-doctor' ),
					number_format_i18n( (int) $rest['types_status'] )
				),
				__( 'Review REST-related rules in your security plugin or WAF and allow the wp/v2 namespace the editor needs.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Parts of the editor and some plugins fail while the API looks healthy.', 'probe-site-doctor' ) );
		}

		return $this->good(
			__( 'The REST API responds normally', 'probe-site-doctor' ),
			sprintf(
				/* translators: 1: Number of namespaces, 2: Server response time. */
				__( 'The REST root returned JSON with %1$s namespaces and a core route answered as expected. Server response time was %2$s ms — measured on the server, not in a browser.', 'probe-site-doctor' ),
				number_format_i18n( count( $rest['namespaces'] ) ),
				number_format_i18n( (int) $rest['time_ms'] )
			),
			$data
		);
	}

	/**
	 * Manual instructions.
	 *
	 * @return ManualCleanup
	 */
	private function steps(): ManualCleanup {
		return ( new ManualCleanup(
			__( 'Diagnose from the command line and the error log. Site Doctor changes no setting and disables no plugin.', 'probe-site-doctor' ),
			array(
				__( 'Request the REST root yourself and look at the raw body, not just the status code.', 'probe-site-doctor' ),
				__( 'Check the PHP error log for a fatal error raised during rest_api_init.', 'probe-site-doctor' ),
				__( 'If a security plugin is involved, allow the wp/v2 namespace rather than the whole API.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'Request the REST root', 'probe-site-doctor' ), 'curl -sS -i ' . rest_url(), ManualCleanup::TYPE_WP_CLI )
			->with_command( __( 'List routes WordPress knows about', 'probe-site-doctor' ), 'wp rest route list 2>/dev/null || wp eval "print_r( array_keys( rest_get_server()->get_routes() ) );"', ManualCleanup::TYPE_WP_CLI );
	}
}
