<?php
/**
 * Active theme and plugins inventory.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Developer;

use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Inventories what is actually running: the active theme and its parent, the
 * active plugins, and the code that loads outside the plugin list.
 *
 * Must-use plugins and drop-ins are the things a developer inheriting a site
 * misses first, because they never appear on the Plugins screen as
 * deactivatable items.
 */
final class ActiveComponentsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.active-components';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'Active theme and plugins', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Lists the active theme, the active plugins, and code loading outside the plugin list (must-use plugins and drop-ins).', 'probe-site-doctor' );
	}

	public function run(): Result {
		$thresholds = $this->thresholds( array( 'many_plugins' => 40 ) );

		$theme   = wp_get_theme();
		$parent  = $theme->parent();
		$plugins = SystemInfo::plugins();
		$rows    = (array) $plugins['rows'];

		$mu      = (string) ( $plugins['fields'][ __( 'Must-use plugins', 'probe-site-doctor' ) ] ?? '' );
		$dropins = (string) ( $plugins['fields'][ __( 'Drop-ins', 'probe-site-doctor' ) ] ?? '' );
		$none    = __( 'none', 'probe-site-doctor' );

		$data = array(
			'theme'             => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'theme_slug'        => $theme->get_stylesheet(),
			'child_theme'       => (bool) $parent,
			'parent_theme'      => $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : null,
			'block_theme'       => method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme(),
			'active_plugins'    => count( $rows ),
			'must_use_plugins'  => $mu,
			'dropins'           => $dropins,
			'plugin_list'       => array_map(
				static fn( $row ) => $row['plugin'] . ' ' . $row['version'],
				array_slice( $rows, 0, 60 )
			),
		);

		$summary = sprintf(
			/* translators: 1: Theme name and version, 2: Number of active plugins. */
			_n(
				'Active theme: %1$s, with %2$s active plugin.',
				'Active theme: %1$s, with %2$s active plugins.',
				count( $rows ),
				'probe-site-doctor'
			),
			$data['theme'],
			number_format_i18n( count( $rows ) )
		);

		if ( $parent ) {
			$summary .= ' ' . sprintf(
				/* translators: %s: Parent theme name and version. */
				__( 'It is a child of %s, so template changes belong in the child theme.', 'probe-site-doctor' ),
				(string) $data['parent_theme']
			);
		}

		$hidden = array();
		if ( '' !== $mu && $none !== $mu ) {
			$hidden[] = sprintf(
				/* translators: %s: Comma-separated plugin names. */
				__( 'must-use plugins that always load and cannot be deactivated from the admin (%s)', 'probe-site-doctor' ),
				$mu
			);
		}
		if ( '' !== $dropins && $none !== $dropins ) {
			$hidden[] = sprintf(
				/* translators: %s: Comma-separated file names. */
				__( 'drop-ins replacing core behaviour (%s)', 'probe-site-doctor' ),
				$dropins
			);
		}

		if ( $hidden ) {
			return $this->info(
				__( 'Code is loading outside the plugin list', 'probe-site-doctor' ),
				$summary . ' ' . sprintf(
					/* translators: %s: List of items such as "must-use plugins (…)". */
					__( 'This install also has %s. These load before or instead of normal plugins, so they survive "deactivate everything" troubleshooting and are the first thing to check when behaviour cannot be traced to a plugin.', 'probe-site-doctor' ),
					implode( __( ' and ', 'probe-site-doctor' ), $hidden )
				),
				__( 'Read what those files do before changing anything else: hosts often install must-use plugins for caching or security, and object-cache.php or advanced-cache.php come from a caching stack.', 'probe-site-doctor' ),
				$data
			);
		}

		if ( count( $rows ) >= $thresholds['many_plugins'] ) {
			return $this->info(
				sprintf(
					/* translators: %s: Number of plugins. */
					__( '%s active plugins', 'probe-site-doctor' ),
					number_format_i18n( count( $rows ) )
				),
				$summary . ' ' . __( 'That is a large surface to maintain and to debug: every one of them can add queries, scripts and update obligations. The number alone is not a problem, but it is worth knowing which ones are still earning their place.', 'probe-site-doctor' ),
				__( 'Review the list for plugins that are no longer used, duplicated in function, or abandoned upstream. The Plugins & Themes findings cover updates and inactive copies.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::LOW, __( 'Maintenance and debugging effort rather than a direct failure.', 'probe-site-doctor' ) );
		}

		return $this->good(
			sprintf(
				/* translators: %s: Theme name and version. */
				__( 'Active theme: %s', 'probe-site-doctor' ),
				(string) $data['theme']
			),
			$summary . ' ' . __( 'Nothing is loading outside the normal plugin list. The full inventory, with versions and available updates, is on the Developer screen and in the exportable system report.', 'probe-site-doctor' ),
			$data
		);
	}
}
