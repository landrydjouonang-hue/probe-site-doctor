<?php
/**
 * REST API exposure indicators check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Configuration;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what the REST API returns to an anonymous visitor: whether the API
 * responds at all, and whether the users endpoint lists accounts.
 *
 * Only anonymous GET requests are made, to endpoints WordPress exposes by
 * design. Nothing is written and no credentials are used.
 */
final class RestApiCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.rest-api';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'REST API exposure indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether the REST API answers anonymous requests and whether the users endpoint lists accounts.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$root  = Loopback::get( rest_url(), 8 );
		$users = Loopback::get( rest_url( 'wp/v2/users' ), 8 );

		if ( ! $root['ok'] && 0 === (int) $root['status'] ) {
			return $this->skipped(
				sprintf(
					/* translators: %s: Error message. */
					__( 'The REST API could not be reached through a loopback request (%s).', 'probe-site-doctor' ),
					(string) $root['error']
				)
			);
		}

		$root_open   = 200 === (int) $root['status'];
		$users_code  = (int) $users['status'];
		$decoded     = json_decode( (string) $users['body'], true );
		$listed      = 200 === $users_code && is_array( $decoded ) ? count( $decoded ) : 0;
		$logins      = array();
		if ( $listed && isset( $decoded[0] ) && is_array( $decoded[0] ) ) {
			foreach ( array_slice( $decoded, 0, 5 ) as $user ) {
				if ( isset( $user['slug'] ) ) {
					$logins[] = (string) $user['slug'];
				}
			}
		}

		$data = array(
			'api_responds'          => $root_open,
			'api_status'            => (int) $root['status'],
			'users_endpoint_status' => $users_code,
			'users_listed'          => $listed,
			'example_user_slugs'    => $logins,
		);

		$design = __( 'The REST API is part of WordPress: the block editor and many plugins need it, and the users endpoint intentionally lists authors who have published content. This is default behaviour, not a vulnerability.', 'probe-site-doctor' );

		if ( ! $root_open ) {
			return $this->info(
				__( 'The REST API does not answer anonymous requests', 'probe-site-doctor' ),
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'A request to the REST root returned HTTP %d. Something (a plugin, the server or a firewall) is restricting the API.', 'probe-site-doctor' ),
					(int) $root['status']
				) . ' ' . __( 'Blocking the API wholesale often breaks the block editor, site health and plugins that rely on it.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				__( 'Confirm this restriction is intended, and check that editing posts and the admin screens still work.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::LOW, __( 'Restricting the API reduces the surface reachable without logging in, at the cost of breaking features that depend on it.', 'probe-site-doctor' ) );
		}

		if ( $listed > 0 ) {
			return $this->info(
				__( 'The users endpoint lists accounts to anonymous visitors', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Number of users, 2: Example slugs. */
					__( 'A request to the users endpoint returned %1$s accounts, including the login-like slugs %2$s. Usernames are also visible through author archives and page source on most sites.', 'probe-site-doctor' ),
					number_format_i18n( $listed ),
					implode( ', ', array_slice( $logins, 0, 3 ) )
				) . ' ' . $design . ' ' . $this->indicator_note(),
				__( 'Treat usernames as public and rely on strong unique passwords, two-factor authentication and login rate limiting. Restrict the endpoint only if nothing on the site needs it.', 'probe-site-doctor' ),
				$data
			)->with_impact(
				Impact::LOW,
				__( 'Knowing a username removes one unknown for a guessing attack, but it is not secret in WordPress. Password strength and login rate limiting decide the outcome, so hiding this has limited value.', 'probe-site-doctor' )
			)->with_cleanup(
				( new ManualCleanup(
					__( 'Limit anonymous access to the users endpoint, if you are sure nothing needs it. Site Doctor never changes API behaviour.', 'probe-site-doctor' ),
					array(
						__( 'Check that no plugin, theme, mobile app or headless front end reads the users endpoint.', 'probe-site-doctor' ),
						__( 'Add a small filter that requires a logged-in user for that route.', 'probe-site-doctor' ),
						__( 'Verify the block editor, author archives and any integrations still work.', 'probe-site-doctor' ),
					),
					false
				) )->as_hardening()
					->with_command(
						__( 'Require authentication for the users endpoint (PHP)', 'probe-site-doctor' ),
						"add_filter(\n    'rest_authentication_errors',\n    function ( \$result ) {\n        if ( ! is_user_logged_in() && false !== strpos( (string) \$_SERVER['REQUEST_URI'], '/wp/v2/users' ) ) {\n            return new WP_Error( 'rest_forbidden', 'Authentication required.', array( 'status' => 401 ) );\n        }\n        return \$result;\n    }\n);",
						ManualCleanup::TYPE_PHP
					)
			);
		}

		return $this->good(
			__( 'REST API exposure looks contained', 'probe-site-doctor' ),
			sprintf(
				/* translators: %d: HTTP status code. */
				__( 'The REST API answers requests, and the users endpoint does not list accounts anonymously (HTTP %d).', 'probe-site-doctor' ),
				$users_code
			) . ' ' . $design,
			$data
		);
	}
}
