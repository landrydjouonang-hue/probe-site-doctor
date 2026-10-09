<?php
/**
 * Probe Site Doctor
 *
 * @package           ProbeSiteDoctor
 * @author            Djouonang Landry
 * @copyright         2026 Djouonang Landry
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Probe Site Doctor
 * Description:       WordPress website health, performance &amp; diagnostics. Analyzes performance, database, configuration, plugin/theme and SEO-related technical checks and produces actionable health reports. Not a malware or vulnerability scanner.
 * Version:           0.9.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Djouonang Landry
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       probe-site-doctor
 * Domain Path:       /languages
 */

/*
 * This file must stay parseable by old PHP versions so the requirements
 * notice can be shown instead of a fatal error. Keep modern syntax in src/.
 */

defined( 'ABSPATH' ) || exit;

define( 'PROBESD_VERSION', '0.9.0' );
define( 'PROBESD_FILE', __FILE__ );
define( 'PROBESD_PATH', plugin_dir_path( __FILE__ ) );
define( 'PROBESD_URL', plugin_dir_url( __FILE__ ) );
define( 'PROBESD_BASENAME', plugin_basename( __FILE__ ) );
define( 'PROBESD_MIN_PHP', '8.0' );
define( 'PROBESD_MIN_WP', '6.4' );

require_once PROBESD_PATH . 'src/Core/Requirements.php';

$probesd_requirements = new ProbeSiteDoctor\Core\Requirements( PROBESD_MIN_PHP, PROBESD_MIN_WP );

if ( ! $probesd_requirements->met() ) {
	$probesd_requirements->register_notice();
	unset( $probesd_requirements );
	return;
}

unset( $probesd_requirements );

require_once PROBESD_PATH . 'src/Autoloader.php';

ProbeSiteDoctor\Autoloader::register( 'ProbeSiteDoctor\\', PROBESD_PATH . 'src/' );

register_activation_hook( __FILE__, array( 'ProbeSiteDoctor\\Core\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ProbeSiteDoctor\\Core\\Deactivator', 'deactivate' ) );

/**
 * Returns the main plugin instance.
 *
 * @return ProbeSiteDoctor\Plugin
 */
function probe_site_doctor() {
	return ProbeSiteDoctor\Plugin::instance();
}

add_action( 'plugins_loaded', array( probe_site_doctor(), 'boot' ) );
