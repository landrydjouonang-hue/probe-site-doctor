<?php
/**
 * Inactive plugins check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Extensions;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\UpdateInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Flags installed-but-inactive plugins, which still need maintenance.
 */
final class InactivePluginsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'extensions.inactive-plugins';
	}

	public function get_category(): string {
		return CategoryRegistry::EXTENSIONS;
	}

	public function get_label(): string {
		return __( 'Inactive plugins', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Lists plugins that are installed but not active, and flags those that are also outdated.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t    = $this->thresholds( array( 'warning_count' => 5 ) );
		$info = UpdateInfo::plugins();

		$inactive = array();
		$outdated = 0;
		foreach ( $info['plugins'] as $plugin ) {
			if ( $plugin['active'] ) {
				continue;
			}
			$is_outdated = null !== $plugin['update'];
			$outdated   += $is_outdated ? 1 : 0;
			$inactive[]  = array(
				'plugin'   => $plugin['name'],
				'version'  => $plugin['version'],
				'outdated' => $is_outdated,
				'slug'     => $plugin['slug'],
			);
		}

		$count = count( $inactive );
		$data  = array(
			'count'    => $count,
			'outdated' => $outdated,
			'plugins'  => array_map(
				static function ( $row ) {
					unset( $row['slug'] );
					return $row;
				},
				$inactive
			),
		);
		if ( is_multisite() ) {
			$data['note'] = __( 'On multisite, a plugin inactive here may still be active on other sites of the network.', 'probe-site-doctor' );
		}

		if ( 0 === $count ) {
			return $this->good( __( 'No inactive plugins', 'probe-site-doctor' ), __( 'Every installed plugin is in use.', 'probe-site-doctor' ), $data );
		}

		$message = sprintf(
			/* translators: %s: Number of plugins. */
			_n( '%s plugin is installed but not active.', '%s plugins are installed but not active.', $count, 'probe-site-doctor' ),
			number_format_i18n( $count )
		);
		if ( $outdated ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Number of plugins. */
				_n( '%s of them is also outdated.', '%s of them are also outdated.', $outdated, 'probe-site-doctor' ),
				number_format_i18n( $outdated )
			);
		}

		$severity = ( $count > $t['warning_count'] || $outdated > 0 ) ? Severity::WARNING : Severity::INFO;
		$slugs    = array_slice( array_column( $inactive, 'slug' ), 0, 10 );

		return $this->result(
			$severity,
			$outdated ? __( 'Inactive plugins are installed, some of them outdated', 'probe-site-doctor' ) : __( 'Some plugins are inactive', 'probe-site-doctor' ),
			$message . ' ' . __( 'Inactive plugins do not run, but their files remain on the server and still need updates.', 'probe-site-doctor' ),
			__( 'Delete plugins you no longer need. Keep a plugin only if you plan to use it again soon, and keep it updated.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$outdated ? Impact::MEDIUM : Impact::LOW,
			__( 'Inactive plugins have no effect on speed, but vulnerable code in them can still be reachable on some servers, and they add to update and backup work.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Delete plugins you no longer need. Deleting a plugin removes its files and, for many plugins, its settings and data.', 'probe-site-doctor' ),
				array(
					__( 'Check that the plugin is not needed (for example, it was not only deactivated temporarily for troubleshooting).', 'probe-site-doctor' ),
					__( 'Back up the site.', 'probe-site-doctor' ),
					__( 'Delete it from Plugins → Installed Plugins, or with the command below after reviewing the list.', 'probe-site-doctor' ),
				)
			) )->with_command( __( 'Delete the listed inactive plugins with WP-CLI (review the list first)', 'probe-site-doctor' ), 'wp plugin delete ' . implode( ' ', $slugs ), ManualCleanup::TYPE_WP_CLI )
		);
	}
}
