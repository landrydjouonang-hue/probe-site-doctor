<?php
/**
 * Uninstall entry point.
 *
 * @package ProbeSiteDoctor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'PROBESD_UNINSTALLING', true );

if ( ! defined( 'PROBESD_VERSION' ) ) {
	define( 'PROBESD_VERSION', '0.9.0' );
}

require_once __DIR__ . '/src/Autoloader.php';

ProbeSiteDoctor\Autoloader::register( 'ProbeSiteDoctor\\', __DIR__ . '/src/' );

ProbeSiteDoctor\Core\Uninstaller::uninstall();
