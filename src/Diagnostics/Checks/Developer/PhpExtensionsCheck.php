<?php
/**
 * PHP extensions check.
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
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Reports PHP extensions WordPress requires or benefits from.
 *
 * Reads the loaded extension list; it installs nothing.
 */
final class PhpExtensionsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.php-extensions';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'PHP extensions', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks which PHP extensions are loaded, and which required or recommended ones are missing.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$info    = SystemInfo::extensions();
		$missing = $info['missing'];

		$data = array(
			'loaded_count'        => count( $info['loaded'] ),
			'missing_required'    => $missing['required'],
			'missing_recommended' => $missing['recommended'],
			'image_library'       => extension_loaded( 'imagick' ) ? 'imagick' : ( function_exists( 'gd_info' ) ? 'gd' : 'none' ),
		);

		if ( $missing['required'] ) {
			return $this->critical(
				__( 'Required PHP extensions are missing', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Comma-separated extension names. */
					__( 'WordPress needs these PHP extensions and they are not loaded: %s. Parts of WordPress will fail outright, usually with a fatal error rather than a clear message.', 'probe-site-doctor' ),
					implode( ', ', $missing['required'] )
				),
				__( 'Ask the host (or your container/image build) to enable the missing extensions. On most systems they are separate packages such as php-mbstring or php-xml.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Editor saves, media handling, HTTP requests or translations can break entirely, depending on which extension is missing.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps( $missing['required'] ) );
		}

		// No image library at all is a real problem: uploads will not get resized versions.
		$no_images = ! extension_loaded( 'imagick' ) && ! function_exists( 'gd_info' );
		if ( $no_images ) {
			return $this->warning(
				__( 'No image library is available', 'probe-site-doctor' ),
				__( 'Neither Imagick nor GD is loaded, so WordPress cannot create the resized image sizes it registers. Uploads will be served at full size, which is slow for visitors.', 'probe-site-doctor' ),
				__( 'Ask the host to enable the imagick or gd extension, then regenerate thumbnails for existing uploads.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Every image is delivered at its original dimensions and weight.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps( array( 'imagick' ) ) );
		}

		if ( $missing['recommended'] ) {
			$notable = array_values( array_intersect( array( 'curl', 'zip', 'intl', 'sodium', 'fileinfo', 'exif' ), $missing['recommended'] ) );
			return $this->result(
				$notable ? Severity::WARNING : Severity::INFO,
				__( 'Recommended PHP extensions are missing', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Comma-separated extension names. */
					__( 'These recommended extensions are not loaded: %s. WordPress works without them, but individual features fall back to slower paths or stop working: cURL for HTTP requests, zip for plugin installs, intl for locale handling, sodium for signature checks, fileinfo for upload type detection, exif for image orientation.', 'probe-site-doctor' ),
					implode( ', ', $missing['recommended'] )
				),
				__( 'Enable the extensions you actually need, starting with cURL and zip. On shared hosting this is a support request; on your own server it is a package install and a PHP restart.', 'probe-site-doctor' ),
				$data
			)->with_impact(
				$notable ? Impact::MEDIUM : Impact::LOW,
				__( 'Specific features degrade rather than the whole site.', 'probe-site-doctor' )
			)->with_cleanup( $this->steps( $missing['recommended'] ) );
		}

		return $this->good(
			__( 'All required and recommended PHP extensions are loaded', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: Number of extensions. */
				__( '%s extensions are loaded, including everything WordPress requires and the optional ones it uses when available.', 'probe-site-doctor' ),
				number_format_i18n( count( $info['loaded'] ) )
			),
			$data
		);
	}

	/**
	 * Manual instructions.
	 *
	 * @param string[] $extensions Extensions to enable.
	 * @return ManualCleanup
	 */
	private function steps( array $extensions ): ManualCleanup {
		$list = implode( ' ', array_map( static fn( $e ) => 'php-' . $e, array_slice( $extensions, 0, 6 ) ) );

		return ( new ManualCleanup(
			__( 'Extensions are enabled in the server’s PHP configuration. Site Doctor never installs or enables them.', 'probe-site-doctor' ),
			array(
				__( 'On managed or shared hosting: ask support to enable the extensions for your PHP version.', 'probe-site-doctor' ),
				__( 'On your own server: install the packages, then restart PHP-FPM or Apache.', 'probe-site-doctor' ),
				__( 'Confirm with the Developer screen in Site Doctor, or with php -m on the command line.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command(
				__( 'Debian/Ubuntu example (adjust the PHP version)', 'probe-site-doctor' ),
				'sudo apt install ' . $list . "\nsudo systemctl restart php8.2-fpm",
				ManualCleanup::TYPE_PHP
			);
	}
}
