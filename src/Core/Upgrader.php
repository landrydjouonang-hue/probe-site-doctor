<?php
/**
 * Version upgrades.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Re-runs the installer when the stored version differs from the code, which
 * covers plugin updates (activation hooks do not fire on update) and sites of
 * a network activation that have not been installed yet.
 */
final class Upgrader {

	/**
	 * Hooks the upgrade check.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'rest_api_init', array( $this, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Installs/upgrades when needed.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( Installer::needs_install() ) {
			Installer::install();
		}
	}
}
