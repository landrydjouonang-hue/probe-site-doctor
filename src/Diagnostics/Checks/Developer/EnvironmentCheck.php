<?php
/**
 * Environment information check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Developer;

use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Records the environment a report was produced in, and flags the case where
 * the site does not declare which environment it is.
 *
 * WP_ENVIRONMENT_TYPE is what lets WordPress, plugins and deploy tooling
 * behave differently on staging than on production; when it is missing,
 * staging copies index in search engines, send real e-mail and take payments.
 */
final class EnvironmentCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.environment';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'Environment information', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Records WordPress, PHP, server and database versions, and checks that the environment type is declared.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wp_version;

		$declared = defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' );
		$type     = wp_get_environment_type();
		$db       = SystemInfo::database();
		$uploads  = wp_get_upload_dir();

		$data = array(
			'wordpress'        => (string) $wp_version,
			'php'              => PHP_VERSION,
			'php_sapi'         => PHP_SAPI,
			'database'         => ( 'mariadb' === $db['server'] ? 'MariaDB ' : 'MySQL ' ) . $db['version'],
			'server_software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'os'               => PHP_OS_FAMILY,
			'environment_type' => $type,
			'type_declared'    => $declared,
			'multisite'        => is_multisite(),
			'locale'           => get_locale(),
			'timezone'         => wp_timezone_string(),
			'https'            => 0 === strpos( home_url(), 'https://' ),
			'permalinks'       => (string) get_option( 'permalink_structure' ),
			'uploads_writable' => wp_is_writable( (string) ( $uploads['basedir'] ?? '' ) ),
			'object_cache'     => wp_using_ext_object_cache(),
			'page_cache_dropin' => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
		);

		$summary = sprintf(
			/* translators: 1: WordPress version, 2: PHP version, 3: Database server and version, 4: Web server. */
			__( 'WordPress %1$s on PHP %2$s with %3$s, served by %4$s.', 'probe-site-doctor' ),
			$data['wordpress'],
			$data['php'],
			$data['database'],
			'' !== $data['server_software'] ? $data['server_software'] : __( 'an unreported web server', 'probe-site-doctor' )
		);

		if ( ! $data['uploads_writable'] ) {
			return $this->critical(
				__( 'The uploads directory is not writable', 'probe-site-doctor' ),
				$summary . ' ' . __( 'PHP cannot write to the uploads directory, so media uploads, image sizes, plugin installs and any generated cache files inside wp-content will fail.', 'probe-site-doctor' ),
				__( 'Fix the ownership and permissions of wp-content/uploads so the PHP user can write to it (typically the web server user, directories 755 and files 644).', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Uploads and updates fail outright.', 'probe-site-doctor' ) );
		}

		if ( ! $declared ) {
			return $this->info(
				__( 'The environment type is not declared', 'probe-site-doctor' ),
				$summary . ' ' . __( 'WP_ENVIRONMENT_TYPE is not set, so WordPress assumes "production". That is right for a live site, but a staging or development copy that does not say so will behave like production: plugins send real e-mail, indexing is allowed and payment gateways stay in live mode.', 'probe-site-doctor' ),
				__( 'Declare the environment on every copy of the site — production, staging, development, local. Deploy tooling and many plugins key their behaviour off it.', 'probe-site-doctor' ),
				$data
			)->with_cleanup( $this->steps() );
		}

		return $this->good(
			sprintf(
				/* translators: %s: Environment type such as "production". */
				__( 'Environment declared as %s', 'probe-site-doctor' ),
				$type
			),
			$summary . ' ' . __( 'The environment type is declared, so WordPress and plugins can adapt their behaviour to it. Full details are on the Developer screen and in the exportable system report.', 'probe-site-doctor' ),
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
			__( 'The environment type is a constant in wp-config.php or an environment variable. Site Doctor never sets it.', 'probe-site-doctor' ),
			array(
				__( 'Add the constant to wp-config.php on each copy of the site, with the value that copy really is.', 'probe-site-doctor' ),
				__( 'Valid values: production, staging, development, local.', 'probe-site-doctor' ),
				__( 'On containerised hosting, set the WP_ENVIRONMENT_TYPE environment variable instead.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'wp-config.php (staging copy)', 'probe-site-doctor' ), "define( 'WP_ENVIRONMENT_TYPE', 'staging' );", ManualCleanup::TYPE_PHP );
	}
}
