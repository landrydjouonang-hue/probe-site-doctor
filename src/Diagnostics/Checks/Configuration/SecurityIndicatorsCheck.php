<?php
/**
 * Security-related configuration indicators check.
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
 * A group of configuration indicators that often appear on hardening
 * checklists: security keys and salts, wp-config.php permissions, directory
 * listing of the uploads folder, the database table prefix and application
 * passwords over plain HTTP.
 *
 * Deliberately not tested, because it would require changing the site:
 * whether PHP files in the uploads folder can be executed (that needs
 * uploading a test file). Malware, file integrity and intrusion detection
 * are out of scope entirely.
 */
final class SecurityIndicatorsCheck extends AbstractCheck {

	/**
	 * Constants WordPress ships as placeholders in wp-config-sample.php.
	 *
	 * @var string[]
	 */
	private const KEYS = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	public function get_id(): string {
		return 'configuration.security-indicators';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'Security-related configuration indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Reviews security keys and salts, wp-config.php permissions, uploads directory listing, the table prefix and application passwords.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		global $wpdb;

		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		// 1. Security keys and salts.
		$weak_keys = array();
		foreach ( self::KEYS as $key ) {
			if ( ! defined( $key ) ) {
				$weak_keys[] = $key;
				continue;
			}
			$value = (string) constant( $key );
			if ( strlen( $value ) < 32 || false !== stripos( $value, 'put your unique phrase here' ) ) {
				$weak_keys[] = $key;
			}
		}
		if ( $weak_keys ) {
			$severity  = Severity::CRITICAL;
			$issues[]  = sprintf(
				/* translators: %s: Number of constants. */
				_n( '%s security key or salt is missing, too short or still the sample placeholder.', '%s security keys or salts are missing, too short or still the sample placeholders.', count( $weak_keys ), 'probe-site-doctor' ),
				number_format_i18n( count( $weak_keys ) )
			);
			$actions[] = __( 'Generate fresh keys and salts and paste them into wp-config.php (this logs everyone out, including you).', 'probe-site-doctor' );
		}

		// 2. wp-config.php permissions (POSIX only; Windows reports a permissive mask).
		$config_path  = file_exists( ABSPATH . 'wp-config.php' ) ? ABSPATH . 'wp-config.php' : dirname( ABSPATH ) . '/wp-config.php';
		$windows      = 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) );
		$perms        = null;
		$world_write  = false;
		if ( file_exists( $config_path ) && ! $windows ) {
			$perms       = fileperms( $config_path ) & 0777;
			$world_write = (bool) ( $perms & 0002 );
			if ( $world_write ) {
				$severity  = Severity::CRITICAL;
				/* translators: %s: File permissions in octal. */
				$issues[]  = sprintf( __( 'wp-config.php is writable by any user on the server (permissions %s). It holds the database credentials.', 'probe-site-doctor' ), decoct( $perms ) );
				$actions[] = __( 'Set wp-config.php to 640 (or 600) and make sure it is owned by the site user.', 'probe-site-doctor' );
			}
		}

		// 3. Directory listing of the uploads folder.
		$uploads  = wp_get_upload_dir();
		$listing  = Loopback::get( trailingslashit( (string) $uploads['baseurl'] ), 8 );
		$is_index = ! empty( $listing['ok'] ) && 200 === (int) $listing['status'] && preg_match( '/<title>\s*Index of|Directory listing for/i', (string) $listing['body'] );
		if ( $is_index ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = __( 'The uploads folder returns a browsable file listing, so anyone can page through uploaded files, including ones not linked publicly.', 'probe-site-doctor' );
			$actions[] = __( 'Disable directory listing for the uploads folder in the server configuration (Options -Indexes on Apache, autoindex off on nginx).', 'probe-site-doctor' );
		}

		// 4. Default table prefix (obscurity only).
		$default_prefix = 'wp_' === $wpdb->prefix;
		if ( $default_prefix ) {
			$severity = $this->worst( $severity, Severity::INFO );
			$issues[] = __( 'The database uses the default table prefix "wp_". This is worth knowing, but changing it is risky and protects against very little: it only matters for a narrow class of blind SQL injection. Do not change it on a live site for security reasons alone.', 'probe-site-doctor' );
		}

		// 5. Application passwords over plain HTTP.
		// Core already returns false unless the connection is HTTPS or the environment is local.
		$app_passwords = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
		$plain_http    = 0 !== stripos( (string) get_option( 'home' ), 'https://' );
		if ( $app_passwords && $plain_http && ! $this->is_non_production() ) {
			$severity  = $this->worst( $severity, Severity::WARNING );
			$issues[]  = __( 'Application passwords are available while the site runs over plain HTTP, so those credentials would be sent unencrypted.', 'probe-site-doctor' );
			$actions[] = __( 'Move the site to HTTPS (see the HTTPS finding), or disable application passwords until you have.', 'probe-site-doctor' );
		}

		$data = array(
			'keys_and_salts_ok'          => ! $weak_keys,
			'wp_config_permissions'      => null !== $perms ? decoct( $perms ) : ( $windows ? __( 'not applicable on Windows', 'probe-site-doctor' ) : __( 'unknown', 'probe-site-doctor' ) ),
			'wp_config_world_writable'   => $world_write,
			'uploads_directory_listing'  => (bool) $is_index,
			'default_table_prefix'       => $default_prefix,
			'application_passwords'      => (bool) $app_passwords,
			'not_checked'                => __( 'PHP execution in the uploads folder (would require uploading a test file), malware, file integrity and intrusion detection.', 'probe-site-doctor' ),
		);

		if ( Severity::GOOD === $severity ) {
			return $this->good(
				__( 'No configuration concerns found in these indicators', 'probe-site-doctor' ),
				__( 'Security keys and salts are set, wp-config.php is not world-writable, the uploads folder does not list files and the table prefix has been changed.', 'probe-site-doctor' ) . ' ' . $this->indicator_note(),
				$data
			);
		}

		$title = Severity::CRITICAL === $severity
			? __( 'A configuration setting needs attention', 'probe-site-doctor' )
			: ( Severity::WARNING === $severity ? __( 'Some security-related settings could be tightened', 'probe-site-doctor' ) : __( 'Minor configuration indicators worth knowing', 'probe-site-doctor' ) );

		return $this->result(
			$severity,
			$title,
			implode( ' ', $issues ) . ' ' . $this->indicator_note(),
			implode( ' ', $actions ) ?: __( 'No action is strictly needed; treat these as context.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			Severity::CRITICAL === $severity ? Impact::HIGH : ( Severity::WARNING === $severity ? Impact::MEDIUM : Impact::LOW ),
			$weak_keys
				? __( 'Weak or placeholder keys make stolen session cookies easier to forge, which can let someone stay logged in as an administrator.', 'probe-site-doctor' )
				: ( $world_write
					? __( 'A world-writable wp-config.php lets any account on the server read or change the database credentials.', 'probe-site-doctor' )
					: __( 'These are small, mostly obscurity-level items. Updates, strong passwords, two-factor authentication and backups matter far more.', 'probe-site-doctor' ) )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Make these changes yourself. Site Doctor never edits wp-config.php, file permissions or server configuration.', 'probe-site-doctor' ),
				array(
					__( 'Back up wp-config.php before editing it.', 'probe-site-doctor' ),
					__( 'Replace the keys and salts with freshly generated values from the WordPress.org secret-key service (this ends all sessions).', 'probe-site-doctor' ),
					__( 'Ask your host to correct file permissions and to disable directory listing if you cannot.', 'probe-site-doctor' ),
				),
				false
			) )->as_hardening()
				->with_command( __( 'Regenerate the keys and salts in wp-config.php with WP-CLI (ends all sessions)', 'probe-site-doctor' ), 'wp config shuffle-salts', ManualCleanup::TYPE_WP_CLI )
				->with_command( __( 'Restrict wp-config.php permissions (Linux/macOS shell)', 'probe-site-doctor' ), 'chmod 640 wp-config.php', ManualCleanup::TYPE_WP_CLI )
		);
	}
}
