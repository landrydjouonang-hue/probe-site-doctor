<?php
/**
 * PSR-4 autoloader (no Composer dependency required at runtime).
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the plugin namespace to the src/ directory.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 *
	 * @param string $prefix   Namespace prefix, with trailing backslash.
	 * @param string $base_dir Base directory, with trailing slash.
	 * @return void
	 */
	public static function register( $prefix, $base_dir ) {
		spl_autoload_register(
			static function ( $class_name ) use ( $prefix, $base_dir ) {
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
