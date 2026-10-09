<?php
/**
 * XML-RPC status check.
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
 * Reports whether xmlrpc.php is reachable and whether pingbacks are enabled.
 *
 * Only a plain GET is made, which XML-RPC answers with a notice that it
 * accepts POST requests only. No XML-RPC method is ever called.
 */
final class XmlRpcCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.xmlrpc';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'XML-RPC status', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether xmlrpc.php is reachable, whether it is disabled by a filter, and whether pingbacks are on.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a WordPress core filter, not declaring one.
		$enabled_by_filter = (bool) apply_filters( 'xmlrpc_enabled', true );
		$file_exists       = file_exists( ABSPATH . 'xmlrpc.php' );
		$pingbacks         = 'open' === get_option( 'default_ping_status' );

		$probe = $file_exists ? Loopback::get( site_url( '/xmlrpc.php' ), 8 ) : array( 'ok' => false, 'status' => 0, 'body' => '', 'error' => 'missing' );

		// WordPress answers a GET with HTTP 405 and this notice, which means XML-RPC is alive and
		// would accept POST requests. The marker decides, not the status code.
		$reachable = false !== stripos( (string) $probe['body'], 'XML-RPC server accepts POST requests only' );
		$blocked   = $file_exists && ! $reachable && in_array( (int) $probe['status'], array( 401, 403, 404, 410, 451, 500, 503 ), true );

		$data = array(
			'file_present'      => $file_exists,
			'reachable'         => $reachable,
			'http_status'        => (int) $probe['status'],
			'enabled_by_filter' => $enabled_by_filter,
			'pingbacks_enabled' => $pingbacks,
		);

		$uses = __( 'XML-RPC is a legitimate interface: the WordPress mobile apps, Jetpack and some plugins and desktop editors use it. Disabling it can break those.', 'probe-site-doctor' );

		$steps = ( new ManualCleanup(
			__( 'Block XML-RPC yourself, if nothing you use needs it. Site Doctor never changes this setting.', 'probe-site-doctor' ),
			array(
				__( 'Check first whether the mobile app, Jetpack or any plugin relies on XML-RPC.', 'probe-site-doctor' ),
				__( 'Preferably block /xmlrpc.php at the server or firewall level, which also saves the PHP request.', 'probe-site-doctor' ),
				__( 'Otherwise disable it in a small plugin or your theme functions, and turn off pingbacks under Settings → Discussion.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'Disable XML-RPC in PHP', 'probe-site-doctor' ), "add_filter( 'xmlrpc_enabled', '__return_false' );", ManualCleanup::TYPE_PHP )
			->with_command( __( 'Turn off pingbacks for new posts with WP-CLI', 'probe-site-doctor' ), 'wp option update default_ping_status closed', ManualCleanup::TYPE_WP_CLI );

		if ( ! $file_exists || $blocked || ! $enabled_by_filter ) {
			$reason = ! $file_exists
				? __( 'xmlrpc.php is not present.', 'probe-site-doctor' )
				: ( $blocked
					/* translators: %d: HTTP status code. */
					? sprintf( __( 'Requests to xmlrpc.php are refused (HTTP %d).', 'probe-site-doctor' ), (int) $probe['status'] )
					: __( 'XML-RPC is switched off by a filter, so requests are rejected.', 'probe-site-doctor' ) );

			return $this->good(
				__( 'XML-RPC is not available', 'probe-site-doctor' ),
				$reason . ' ' . $uses . ' ' . $this->indicator_note(),
				$data
			);
		}

		if ( ! $reachable ) {
			return $this->skipped(
				sprintf(
					/* translators: %s: Error or status. */
					__( 'xmlrpc.php could not be reached through a loopback request (%s), so its status is unknown.', 'probe-site-doctor' ),
					'' !== (string) ( $probe['error'] ?? '' ) ? (string) $probe['error'] : 'HTTP ' . (int) $probe['status']
				)
			);
		}

		if ( $pingbacks ) {
			return $this->warning(
				__( 'XML-RPC is open and pingbacks are enabled', 'probe-site-doctor' ),
				__( 'xmlrpc.php answers requests and new posts accept pingbacks. This combination is used to relay reflected pingback requests at other sites and for brute-force login attempts that try many passwords per request.', 'probe-site-doctor' ) . ' ' . $uses . ' ' . $this->indicator_note(),
				__( 'Turn off pingbacks under Settings → Discussion, and block xmlrpc.php at the server level unless an app or plugin needs it.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Being reachable is not a vulnerability by itself, but it adds a login surface that is not rate-limited by WordPress and can be abused to send traffic to third parties.', 'probe-site-doctor' ) )
				->with_cleanup( $steps );
		}

		return $this->info(
			__( 'XML-RPC is reachable', 'probe-site-doctor' ),
			__( 'xmlrpc.php answers requests. Pingbacks are off, which removes the most-abused part.', 'probe-site-doctor' ) . ' ' . $uses . ' ' . $this->indicator_note(),
			__( 'If nothing you use needs XML-RPC, block /xmlrpc.php at the server level.', 'probe-site-doctor' ),
			$data
		)->with_impact( Impact::LOW, __( 'An extra login endpoint remains available; with pingbacks off the common abuse cases are limited.', 'probe-site-doctor' ) )
			->with_cleanup( $steps );
	}
}
