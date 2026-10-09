<?php
/**
 * Diagnostic categories.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the categories checks are grouped under.
 */
final class CategoryRegistry {

	public const PERFORMANCE   = 'performance';
	public const DATABASE      = 'database';
	public const CONFIGURATION = 'configuration';
	public const EXTENSIONS    = 'extensions';
	public const SEO           = 'seo';
	public const DEVELOPER     = 'developer';

	/**
	 * Categories keyed by ID, in display order.
	 *
	 * @var array<string,array{id:string,label:string,description:string,icon:string}>|null
	 */
	private ?array $categories = null;

	/**
	 * All categories.
	 *
	 * @return array<string,array{id:string,label:string,description:string,icon:string}>
	 */
	public function all(): array {
		if ( null === $this->categories ) {
			$this->categories = $this->load();
		}
		return $this->categories;
	}

	/**
	 * Whether a category exists.
	 *
	 * @param string $id Category ID.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->all()[ $id ] );
	}

	/**
	 * Single category, or a generic fallback for unknown IDs (e.g. results
	 * stored by a check whose add-on has since been removed).
	 *
	 * @param string $id Category ID.
	 * @return array{id:string,label:string,description:string,icon:string}
	 */
	public function get( string $id ): array {
		$all = $this->all();
		if ( isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		return array(
			'id'          => $id,
			'label'       => ucwords( str_replace( array( '-', '_' ), ' ', $id ) ),
			'description' => '',
			'icon'        => 'dashicons-admin-generic',
		);
	}

	/**
	 * Builds the category list.
	 *
	 * @return array<string,array{id:string,label:string,description:string,icon:string}>
	 */
	private function load(): array {
		$defaults = array(
			self::PERFORMANCE   => array(
				'label'       => __( 'Performance', 'probe-site-doctor' ),
				'description' => __( 'Server-side indicators of speed: caching, server response time, images and the scripts and styles the homepage loads. These are not browser page-load measurements.', 'probe-site-doctor' ),
				'icon'        => 'dashicons-performance',
			),
			self::DATABASE      => array(
				'label'       => __( 'Database', 'probe-site-doctor' ),
				'description' => __( 'Database size, large tables, revisions, transients, metadata and autoloaded options. Findings may include optional manual cleanup steps; Site Doctor never deletes anything itself.', 'probe-site-doctor' ),
				'icon'        => 'dashicons-database',
			),
			self::CONFIGURATION => array(
				'label'       => __( 'Configuration', 'probe-site-doctor' ),
				'description' => __( 'PHP, server, wp-config.php and WordPress settings, including security-related indicators such as debug output, file editing, HTTPS, XML-RPC and REST exposure. These are configuration indicators, not a security audit: nothing here scans for malware, vulnerable code or intrusions, and Site Doctor never changes a setting.', 'probe-site-doctor' ),
				'icon'        => 'dashicons-admin-settings',
			),
			self::EXTENSIONS    => array(
				'label'       => __( 'Plugins & Themes', 'probe-site-doctor' ),
				'description' => __( 'Installed plugins and themes: available updates, unused extensions and compatibility indicators. Site Doctor recommends updates but never installs them.', 'probe-site-doctor' ),
				'icon'        => 'dashicons-admin-plugins',
			),
			self::SEO           => array(
				'label'       => __( 'SEO (Technical)', 'probe-site-doctor' ),
				'description' => __( 'Technical settings and markup that affect how search engines can crawl and index the site: indexing configuration, sitemap, titles, descriptions, image alt text and internal links. Content quality, keywords, rankings and backlinks are not assessed, and this does not replace an SEO platform.', 'probe-site-doctor' ),
				'icon'        => 'dashicons-search',
			),
			self::DEVELOPER     => array(
				'label'       => __( 'Developer', 'probe-site-doctor' ),
				'description' => __( 'The technical state of the install: PHP extensions, memory limits, cron, REST availability, the debug log and database configuration. These findings are aimed at whoever maintains the site, and the full system report is on the Developer screen.', 'probe-site-doctor' ),
				'icon'        => 'dashicons-editor-code',
			),
		);

		/**
		 * Filters the diagnostic categories.
		 *
		 * Each entry: [ 'label' => string, 'description' => string, 'icon' => dashicon class ].
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,array<string,string>> $defaults Categories keyed by ID.
		 */
		$filtered = apply_filters( 'probesd_categories', $defaults );

		$categories = array();
		foreach ( (array) $filtered as $id => $category ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_array( $category ) || empty( $category['label'] ) ) {
				continue;
			}
			$categories[ $id ] = array(
				'id'          => $id,
				'label'       => (string) $category['label'],
				'description' => isset( $category['description'] ) ? (string) $category['description'] : '',
				'icon'        => isset( $category['icon'] ) ? sanitize_html_class( (string) $category['icon'] ) : 'dashicons-admin-generic',
			);
		}

		return $categories;
	}
}
