<?php
/**
 * WordPress version check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Configuration;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\UpdateInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the installed WordPress version with the newest version WordPress
 * last found, and reviews the core automatic-update settings. Reads cached
 * data only, never contacts WordPress.org and never installs anything.
 */
final class WordPressVersionCheck extends AbstractCheck {

	public function get_id(): string {
		return 'configuration.wordpress-version';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'WordPress core version', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether WordPress core is up to date and whether automatic security releases are enabled.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$core = UpdateInfo::core();
		$auto = UpdateInfo::core_auto_updates();

		if ( false !== strpos( $core['installed'], '-' ) ) {
			return $this->info(
				__( 'A development version of WordPress is installed', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Version. */
					__( 'WordPress %s is a pre-release (beta, RC or nightly) build.', 'probe-site-doctor' ),
					$core['installed']
				),
				__( 'Run pre-release versions on test or staging sites only.', 'probe-site-doctor' ),
				array( 'installed' => $core['installed'] )
			);
		}

		if ( ! $core['available'] ) {
			return $this->skipped( __( 'WordPress has not checked for core updates yet. Visit Dashboard → Updates and run the scan again.', 'probe-site-doctor' ) );
		}

		$recs = array();
		if ( $core['branch_update'] ) {
			$recs[] = array(
				'priority'  => 'high',
				'component' => 'core',
				'name'      => 'WordPress',
				'installed' => $core['installed'],
				'available' => $core['branch_update'],
				'change'    => 'patch',
				'note'      => __( 'Maintenance/security release in your current branch', 'probe-site-doctor' ),
			);
		}
		if ( $core['major_update'] ) {
			$recs[] = array(
				'priority'  => 'medium',
				'component' => 'core',
				'name'      => 'WordPress',
				'installed' => $core['installed'],
				'available' => $core['major_update'],
				'change'    => 'major',
				'note'      => __( 'New feature release: check plugin/theme compatibility and test on staging first', 'probe-site-doctor' ),
			);
		}

		$data = array(
			'installed'                   => $core['installed'],
			'latest'                      => $core['latest'],
			'automatic_security_releases' => $auto['minor'],
			'automatic_major_releases'    => $auto['major'],
			'file_modifications_allowed'  => $auto['file_mods'],
			'update_recommendations'      => $recs,
			'last_checked'                => $core['last_checked'] ? gmdate( 'c', $core['last_checked'] ) : null,
		);

		$as_of     = __( 'Based on WordPress’s most recent update check.', 'probe-site-doctor' );
		$auto_note = $auto['minor']
			? __( 'Automatic installation of maintenance and security releases is enabled.', 'probe-site-doctor' )
			: __( 'Automatic installation of maintenance and security releases is disabled, so security fixes wait until someone installs them.', 'probe-site-doctor' );

		$steps = ( new ManualCleanup(
			__( 'Update WordPress core yourself. Site Doctor never installs updates.', 'probe-site-doctor' ),
			array(
				__( 'Make a full backup (files and database).', 'probe-site-doctor' ),
				__( 'For a new feature release, check that your theme and key plugins support it, ideally on a staging copy.', 'probe-site-doctor' ),
				__( 'Update from Dashboard → Updates, then check the front end and admin.', 'probe-site-doctor' ),
			),
			false
		) )->as_update()
			->with_command( __( 'Update to the latest version with WP-CLI', 'probe-site-doctor' ), 'wp core update', ManualCleanup::TYPE_WP_CLI )
			->with_command( __( 'Install only the maintenance release of your current branch with WP-CLI', 'probe-site-doctor' ), 'wp core update --minor', ManualCleanup::TYPE_WP_CLI );

		if ( ! $auto['minor'] ) {
			$steps = $steps->with_command( __( 'Re-enable automatic security releases (wp-config.php)', 'probe-site-doctor' ), "define( 'WP_AUTO_UPDATE_CORE', 'minor' );", ManualCleanup::TYPE_PHP );
		}

		if ( $core['branch_update'] ) {
			return $this->critical(
				__( 'A WordPress maintenance or security release is pending', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Installed version, 2: Available version. */
					__( 'WordPress %1$s is installed, and %2$s is available in the same branch. Minor releases contain security and bug fixes and rarely change features.', 'probe-site-doctor' ),
					$core['installed'],
					$core['branch_update']
				) . ' ' . $auto_note . ' ' . $as_of,
				__( 'Back up the site and install the maintenance release now. Keep automatic security releases enabled so future fixes arrive without delay.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::HIGH, __( 'Maintenance releases often close publicly known security issues; until installed, the site stays exposed to them.', 'probe-site-doctor' ) )
				->with_cleanup( $steps );
		}

		if ( $core['major_update'] ) {
			return $this->warning(
				__( 'A newer major version of WordPress is available', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Installed version, 2: Latest version. */
					__( 'WordPress %1$s is installed; the latest version is %2$s. Only the latest branch is officially supported with fixes, and major releases regularly include performance improvements.', 'probe-site-doctor' ),
					$core['installed'],
					$core['major_update']
				) . ' ' . $auto_note . ' ' . $as_of,
				__( 'Back up the site, confirm that your theme and key plugins support the new version (see the compatibility indicators), and update, ideally after testing on staging.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Older branches stop receiving fixes, and plugins increasingly require recent WordPress versions.', 'probe-site-doctor' ) )
				->with_cleanup( $steps );
		}

		if ( ! $auto['minor'] ) {
			return $this->warning(
				__( 'WordPress is up to date, but automatic security releases are off', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Version. */
					__( 'WordPress %s is the latest version.', 'probe-site-doctor' ),
					$core['installed']
				) . ' ' . $auto_note . ' ' . $as_of,
				__( 'Unless updates are handled by your host or a deployment process, re-enable automatic maintenance and security releases.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Security releases are published without warning; with automatic updates off, the site stays exposed until someone installs them.', 'probe-site-doctor' ) )
				->with_cleanup( $steps );
		}

		return $this->good(
			__( 'WordPress is up to date', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: Version. */
				__( 'WordPress %s is the latest available version.', 'probe-site-doctor' ),
				$core['installed']
			) . ' ' . $auto_note . ' ' . $as_of,
			$data
		);
	}
}
