<?php
/**
 * Inactive themes check.
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
 * Counts installed themes that are neither active nor the parent of the
 * active theme. One default theme is worth keeping as a fallback for
 * troubleshooting, so it is not counted as surplus.
 */
final class InactiveThemesCheck extends AbstractCheck {

	public function get_id(): string {
		return 'extensions.inactive-themes';
	}

	public function get_category(): string {
		return CategoryRegistry::EXTENSIONS;
	}

	public function get_label(): string {
		return __( 'Inactive themes', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts unused themes, keeping one default theme as a recommended fallback.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t    = $this->thresholds( array( 'warning_surplus' => 3 ) );
		$info = UpdateInfo::themes();

		$unused   = array();
		$defaults = array();
		foreach ( $info['themes'] as $theme ) {
			if ( $theme['active'] || $theme['parent_of_active'] ) {
				continue;
			}
			$unused[ $theme['slug'] ] = $theme;
			if ( $theme['is_default'] ) {
				$defaults[] = $theme['slug'];
			}
		}

		// Keep the newest default ("twenty…") theme as the fallback, unless the active theme is one already.
		$active_is_default = (bool) array_filter( $info['themes'], static fn( $th ) => $th['active'] && $th['is_default'] );
		// Newest default first. Slugs spell years ("twentytwentyfive"), so sort by the required WordPress version instead of the name.
		usort(
			$defaults,
			static fn( $a, $b ) => version_compare( $unused[ $b ]['requires_wp'] ?: '0', $unused[ $a ]['requires_wp'] ?: '0' )
		);
		$fallback = ! $active_is_default && $defaults ? $defaults[0] : null;

		$surplus  = array_diff_key( $unused, null !== $fallback ? array( $fallback => true ) : array() );
		$outdated = array_filter( $surplus, static fn( $th ) => null !== $th['update'] );

		$data = array(
			'inactive_themes'  => count( $unused ),
			'kept_as_fallback' => $fallback ? $unused[ $fallback ]['name'] : ( $active_is_default ? __( 'not needed (the active theme is a default theme)', 'probe-site-doctor' ) : __( 'none installed', 'probe-site-doctor' ) ),
			'removable'        => array_values(
				array_map(
					static fn( $th ) => array(
						'theme'    => $th['name'],
						'version'  => $th['version'],
						'outdated' => null !== $th['update'],
					),
					$surplus
				)
			),
		);

		if ( ! $surplus ) {
			return $this->good(
				__( 'No surplus themes installed', 'probe-site-doctor' ),
				$fallback
					/* translators: %s: Theme name. */
					? sprintf( __( 'Only the active theme and one fallback theme (%s) are installed.', 'probe-site-doctor' ), $unused[ $fallback ]['name'] )
					: __( 'Only the theme(s) in use are installed.', 'probe-site-doctor' ),
				$data
			);
		}

		$message = sprintf(
			/* translators: %s: Number of themes. */
			_n( '%s unused theme could be removed.', '%s unused themes could be removed.', count( $surplus ), 'probe-site-doctor' ),
			number_format_i18n( count( $surplus ) )
		);
		if ( $fallback ) {
			/* translators: %s: Theme name. */
			$message .= ' ' . sprintf( __( '%s is kept as a fallback theme for troubleshooting and is not counted.', 'probe-site-doctor' ), $unused[ $fallback ]['name'] );
		}
		if ( $outdated ) {
			$message .= ' ' . __( 'Some of them are also outdated.', 'probe-site-doctor' );
		}

		return $this->result(
			( count( $surplus ) >= $t['warning_surplus'] || $outdated ) ? Severity::WARNING : Severity::INFO,
			__( 'Unused themes are installed', 'probe-site-doctor' ),
			$message,
			__( 'Delete themes you do not use. Keep one up-to-date default theme so you can switch to it if the active theme breaks.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$outdated ? Impact::MEDIUM : Impact::LOW,
			__( 'Unused themes do not affect speed, but their files stay on the server, need updates, and may contain vulnerable code.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Delete unused themes. This removes their files; the active theme and your content are not affected.', 'probe-site-doctor' ),
				array(
					__( 'Make sure the theme is not used by another site (multisite) and was not kept on purpose.', 'probe-site-doctor' ),
					__( 'Back up the site.', 'probe-site-doctor' ),
					__( 'Delete it under Appearance → Themes → Theme Details → Delete, or with the command below.', 'probe-site-doctor' ),
				)
			) )->with_command( __( 'Delete the listed unused themes with WP-CLI (review the list first)', 'probe-site-doctor' ), 'wp theme delete ' . implode( ' ', array_keys( $surplus ) ), ManualCleanup::TYPE_WP_CLI )
		);
	}
}
