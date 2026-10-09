<?php
/**
 * Outdated themes check.
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
 * Lists themes with an available update, highlighting the active theme and
 * its parent. Never updates anything.
 */
final class OutdatedThemesCheck extends AbstractCheck {

	public function get_id(): string {
		return 'extensions.outdated-themes';
	}

	public function get_category(): string {
		return CategoryRegistry::EXTENSIONS;
	}

	public function get_label(): string {
		return __( 'Outdated themes', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Lists themes with an available update, with extra attention to the active theme and its parent theme.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$info = UpdateInfo::themes();

		if ( ! $info['available'] ) {
			return $this->skipped( __( 'WordPress has not checked for theme updates yet. Visit Dashboard → Updates and run the scan again.', 'probe-site-doctor' ) );
		}

		$wp_version = (string) get_bloginfo( 'version' );
		$recs       = array();
		$in_use     = array();
		$unused     = array();
		$blocked    = 0;
		$commands   = array();

		foreach ( $info['themes'] as $theme ) {
			if ( ! $theme['update'] || '' === $theme['update']['version'] ) {
				continue;
			}
			$update = $theme['update'];
			$used   = $theme['active'] || $theme['parent_of_active'];
			$change = UpdateInfo::change( $theme['version'], $update['version'] );
			$notes  = array();
			$block  = false;

			if ( $theme['active'] ) {
				$notes[] = __( 'Active theme', 'probe-site-doctor' );
			} elseif ( $theme['parent_of_active'] ) {
				$notes[] = __( 'Parent of the active child theme', 'probe-site-doctor' );
			} else {
				$notes[] = __( 'Inactive: consider deleting instead', 'probe-site-doctor' );
			}
			if ( '' !== $update['requires_php'] && version_compare( PHP_VERSION, $update['requires_php'], '<' ) ) {
				$block   = true;
				/* translators: %s: PHP version. */
				$notes[] = sprintf( __( 'Blocked: needs PHP %s', 'probe-site-doctor' ), $update['requires_php'] );
			}
			if ( '' !== $update['requires_wp'] && version_compare( $wp_version, $update['requires_wp'], '<' ) ) {
				$block   = true;
				/* translators: %s: WordPress version. */
				$notes[] = sprintf( __( 'Blocked: needs WordPress %s', 'probe-site-doctor' ), $update['requires_wp'] );
			}
			if ( 'major' === $change ) {
				$notes[] = __( 'Major version: test on staging first', 'probe-site-doctor' );
			}

			$blocked += $block ? 1 : 0;
			$recs[]   = array(
				'priority'  => $block ? 'blocked' : ( $used ? ( 'major' === $change ? 'medium' : 'high' ) : 'low' ),
				'component' => 'theme',
				'name'      => $theme['name'],
				'installed' => $theme['version'],
				'available' => $update['version'],
				'change'    => $change,
				'note'      => implode( '; ', $notes ),
			);
			if ( $used ) {
				$in_use[] = $theme['name'];
			} else {
				$unused[] = $theme['name'];
			}
			if ( ! $block ) {
				$commands[] = $theme['slug'];
			}
		}

		$recs = UpdateInfo::sort_recommendations( $recs );
		$data = array(
			'outdated_in_use'        => count( $in_use ),
			'outdated_inactive'      => count( $unused ),
			'blocked_updates'        => $blocked,
			'update_recommendations' => $recs,
			'last_checked'           => $info['last_checked'] ? gmdate( 'c', $info['last_checked'] ) : null,
		);

		if ( ! $recs ) {
			return $this->good( __( 'All themes are up to date', 'probe-site-doctor' ), __( 'WordPress reports no theme updates.', 'probe-site-doctor' ), $data );
		}

		$message = sprintf(
			/* translators: 1: Count of themes in use, 2: Count of inactive themes. */
			__( '%1$s theme(s) in use and %2$s inactive theme(s) have updates available.', 'probe-site-doctor' ),
			number_format_i18n( count( $in_use ) ),
			number_format_i18n( count( $unused ) )
		);
		if ( $in_use ) {
			$message .= ' ' . __( 'If the theme’s files were edited directly, updating will overwrite those edits; customizations belong in a child theme.', 'probe-site-doctor' );
		}

		$cleanup = ( new ManualCleanup(
			__( 'Install the theme updates yourself. Site Doctor never updates themes automatically.', 'probe-site-doctor' ),
			array(
				__( 'Make a full backup (files and database).', 'probe-site-doctor' ),
				__( 'Check whether the active theme’s files were edited directly; if so, move the changes to a child theme first.', 'probe-site-doctor' ),
				__( 'Update from Dashboard → Updates or Appearance → Themes, then check the front end.', 'probe-site-doctor' ),
			),
			false
		) )->as_update();
		if ( $commands ) {
			$cleanup = $cleanup->with_command( __( 'Update the listed themes with WP-CLI', 'probe-site-doctor' ), 'wp theme update ' . implode( ' ', $commands ), ManualCleanup::TYPE_WP_CLI );
		}

		return $this->result(
			$in_use ? Severity::WARNING : Severity::INFO,
			$in_use ? __( 'The active theme has an update available', 'probe-site-doctor' ) : __( 'Inactive themes have updates available', 'probe-site-doctor' ),
			$message,
			$in_use
				? __( 'Back up the site and update the active theme (and its parent). Test major versions on staging.', 'probe-site-doctor' )
				: __( 'Delete inactive themes you do not need; update the one you keep as a fallback.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$in_use ? Impact::MEDIUM : Impact::LOW,
			$in_use
				? __( 'The active theme runs on every page; updates fix bugs, security issues and compatibility with new WordPress versions.', 'probe-site-doctor' )
				: __( 'Inactive themes do not run, but their files stay on the server and can still contain vulnerable code.', 'probe-site-doctor' )
		)->with_cleanup( $cleanup );
	}
}
