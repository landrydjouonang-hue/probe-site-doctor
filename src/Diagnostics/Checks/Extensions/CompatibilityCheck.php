<?php
/**
 * Plugin compatibility indicators check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Extensions;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\UpdateInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for signs that plugins (and the active theme) may not be compatible
 * with this site, using only local headers, readme files and cached update data:
 *
 * - "Requires PHP" / "Requires at least" higher than the running versions;
 * - "Tested up to" several WordPress releases behind (possibly unmaintained);
 * - required plugins ("Requires Plugins") missing or inactive;
 * - available updates that cannot be installed on this PHP/WordPress version.
 *
 * These are indicators. Real compatibility can only be confirmed by testing.
 */
final class CompatibilityCheck extends AbstractCheck {

	public function get_id(): string {
		return 'extensions.compatibility';
	}

	public function get_category(): string {
		return CategoryRegistry::EXTENSIONS;
	}

	public function get_label(): string {
		return __( 'Plugin compatibility indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks plugin and theme requirements, “Tested up to” versions and plugin dependencies against this site.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t = $this->thresholds( array( 'untested_releases' => 3 ) );

		$wp_version = (string) get_bloginfo( 'version' );
		$info       = UpdateInfo::plugins();
		$by_slug    = array();
		foreach ( $info['plugins'] as $plugin ) {
			$by_slug[ $plugin['slug'] ] = $plugin;
		}

		$rows     = array();
		$severity = Severity::GOOD;
		$flag     = function ( string $level, string $name, bool $active, string $issue ) use ( &$rows, &$severity ) {
			// Problems in inactive plugins are reported, but only as information.
			$level    = $active ? $level : Severity::INFO;
			$severity = $this->worst( $severity, $level );
			$rows[]   = array(
				'plugin' => $name,
				'active' => $active,
				'issue'  => $issue,
				'level'  => $level,
			);
		};

		foreach ( $info['plugins'] as $plugin ) {
			$name   = $plugin['name'];
			$active = $plugin['active'];

			if ( '' !== $plugin['requires_php'] && version_compare( PHP_VERSION, $plugin['requires_php'], '<' ) ) {
				/* translators: 1: Required PHP version, 2: Running PHP version. */
				$flag( Severity::CRITICAL, $name, $active, sprintf( __( 'Requires PHP %1$s; the server runs %2$s', 'probe-site-doctor' ), $plugin['requires_php'], PHP_VERSION ) );
			}
			if ( '' !== $plugin['requires_wp'] && version_compare( $wp_version, $plugin['requires_wp'], '<' ) ) {
				/* translators: 1: Required WordPress version, 2: Installed version. */
				$flag( Severity::CRITICAL, $name, $active, sprintf( __( 'Requires WordPress %1$s; %2$s is installed', 'probe-site-doctor' ), $plugin['requires_wp'], $wp_version ) );
			}

			$tested = $plugin['update']['tested'] ?? '';
			$tested = '' !== $tested ? $tested : $plugin['tested'];
			if ( '' !== $tested && UpdateInfo::releases_between( $tested, $wp_version ) >= $t['untested_releases'] ) {
				/* translators: 1: WordPress version, 2: Number of releases. */
				$flag( Severity::WARNING, $name, $active, sprintf( __( 'Tested only up to WordPress %1$s (%2$d releases behind); it may be unmaintained', 'probe-site-doctor' ), $tested, UpdateInfo::releases_between( $tested, $wp_version ) ) );
			}

			foreach ( $plugin['requires'] as $dependency ) {
				$dep = $by_slug[ $dependency ] ?? null;
				if ( ! $dep ) {
					/* translators: %s: Plugin slug. */
					$flag( Severity::WARNING, $name, $active, sprintf( __( 'Requires the plugin “%s”, which is not installed', 'probe-site-doctor' ), $dependency ) );
				} elseif ( ! $dep['active'] && $active ) {
					/* translators: %s: Plugin name. */
					$flag( Severity::WARNING, $name, $active, sprintf( __( 'Requires “%s”, which is not active', 'probe-site-doctor' ), $dep['name'] ) );
				}
			}

			$update = $plugin['update'];
			if ( $update && '' !== $update['requires_php'] && version_compare( PHP_VERSION, $update['requires_php'], '<' ) ) {
				/* translators: 1: Version, 2: PHP version. */
				$flag( Severity::WARNING, $name, $active, sprintf( __( 'Update %1$s needs PHP %2$s, so it cannot be installed', 'probe-site-doctor' ), $update['version'], $update['requires_php'] ) );
			}
			if ( $update && '' !== $update['requires_wp'] && version_compare( $wp_version, $update['requires_wp'], '<' ) ) {
				/* translators: 1: Version, 2: WordPress version. */
				$flag( Severity::WARNING, $name, $active, sprintf( __( 'Update %1$s needs WordPress %2$s, so it cannot be installed', 'probe-site-doctor' ), $update['version'], $update['requires_wp'] ) );
			}
		}

		// The active theme (and parent) against the running versions.
		foreach ( UpdateInfo::themes()['themes'] as $theme ) {
			if ( ! $theme['active'] && ! $theme['parent_of_active'] ) {
				continue;
			}
			/* translators: %s: Theme name. */
			$label = sprintf( __( '%s (theme)', 'probe-site-doctor' ), $theme['name'] );
			if ( '' !== $theme['requires_php'] && version_compare( PHP_VERSION, $theme['requires_php'], '<' ) ) {
				/* translators: 1: Required PHP version, 2: Running PHP version. */
				$flag( Severity::CRITICAL, $label, true, sprintf( __( 'Requires PHP %1$s; the server runs %2$s', 'probe-site-doctor' ), $theme['requires_php'], PHP_VERSION ) );
			}
			if ( '' !== $theme['requires_wp'] && version_compare( $wp_version, $theme['requires_wp'], '<' ) ) {
				/* translators: 1: Required WordPress version, 2: Installed version. */
				$flag( Severity::CRITICAL, $label, true, sprintf( __( 'Requires WordPress %1$s; %2$s is installed', 'probe-site-doctor' ), $theme['requires_wp'], $wp_version ) );
			}
		}

		$without_info = count(
			array_filter( $info['plugins'], static fn( $p ) => $p['active'] && '' === $p['tested'] && '' === ( $p['update']['tested'] ?? '' ) )
		);

		$data = array(
			'plugins_checked'           => count( $info['plugins'] ),
			'issues'                    => $rows,
			'active_without_tested_tag' => $without_info,
			'wordpress'                 => $wp_version,
			'php'                       => PHP_VERSION,
		);

		$scope = __( 'These indicators come from plugin headers, readme files and WordPress’s cached update data. Only testing, ideally on a staging copy, confirms real compatibility.', 'probe-site-doctor' );
		if ( $without_info ) {
			$scope .= ' ' . sprintf(
				/* translators: %s: Number of plugins. */
				_n( '%s active plugin declares no “Tested up to” version, so it could not be assessed.', '%s active plugins declare no “Tested up to” version, so they could not be assessed.', $without_info, 'probe-site-doctor' ),
				number_format_i18n( $without_info )
			);
		}

		if ( ! $rows ) {
			return $this->good( __( 'No compatibility warnings', 'probe-site-doctor' ), __( 'All plugin and theme requirements are met, and none is far behind the current WordPress release.', 'probe-site-doctor' ) . ' ' . $scope, $data );
		}

		$recommendation = Severity::CRITICAL === $severity
			? __( 'A component in use declares requirements this site does not meet and may fail. Upgrade PHP or WordPress as required, or replace the component, after testing on staging.', 'probe-site-doctor' )
			: __( 'Review the listed plugins: install or activate missing dependencies, upgrade PHP/WordPress where updates are blocked, and look for maintained alternatives to plugins that have not been tested with recent WordPress versions.', 'probe-site-doctor' );

		return $this->result(
			$severity,
			Severity::CRITICAL === $severity ? __( 'Some components do not meet their requirements', 'probe-site-doctor' ) : __( 'Compatibility concerns were found', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: Number of issues. */
				_n( '%s compatibility concern was found.', '%s compatibility concerns were found.', count( $rows ), 'probe-site-doctor' ),
				number_format_i18n( count( $rows ) )
			) . ' ' . $scope,
			$recommendation,
			$data
		)->with_impact(
			Severity::CRITICAL === $severity ? Impact::HIGH : Impact::MEDIUM,
			__( 'Incompatible or unmaintained plugins are a common cause of errors after WordPress or PHP updates, and they can stop receiving security fixes.', 'probe-site-doctor' )
		);
	}
}
