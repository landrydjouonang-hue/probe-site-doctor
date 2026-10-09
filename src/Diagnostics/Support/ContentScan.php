<?php
/**
 * Read-only scans of published content for technical SEO checks.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Support;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Diagnostic reads; results are stored per scan.

/**
 * Looks at published posts and pages: titles, description metadata, images
 * without alt text and internal links.
 *
 * Every method is read-only and capped, so the work stays bounded on large
 * sites. The caps are reported so findings can say how much was examined.
 */
final class ContentScan {

	/** Post types that matter for these checks. */
	private const TYPES = array( 'post', 'page' );

	/**
	 * Description meta keys used by the common SEO plugins.
	 *
	 * @var array<string,array{name:string,key:string}>
	 */
	private const SEO_PLUGINS = array(
		'wordpress-seo'         => array(
			'name' => 'Yoast SEO',
			'key'  => '_yoast_wpseo_metadesc',
		),
		'seo-by-rank-math'      => array(
			'name' => 'Rank Math',
			'key'  => 'rank_math_description',
		),
		'wp-seopress'           => array(
			'name' => 'SEOPress',
			'key'  => '_seopress_titles_desc',
		),
		'autodescription'       => array(
			'name' => 'The SEO Framework',
			'key'  => '_genesis_description',
		),
		'slim-seo'              => array(
			'name' => 'Slim SEO',
			'key'  => '',
		),
		'all-in-one-seo-pack'   => array(
			'name' => 'All in One SEO',
			'key'  => '',
		),
		'squirrly-seo'          => array(
			'name' => 'Squirrly SEO',
			'key'  => '',
		),
	);

	/**
	 * Which SEO plugin is active, if any.
	 *
	 * @return array{active:bool,name:string,meta_key:string}
	 */
	public static function seo_plugin(): array {
		foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
			$slug = dirname( (string) $basename );
			if ( isset( self::SEO_PLUGINS[ $slug ] ) ) {
				return array(
					'active'   => true,
					'name'     => self::SEO_PLUGINS[ $slug ]['name'],
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Describes which meta key the SEO plugin uses; not a query argument.
					'meta_key' => self::SEO_PLUGINS[ $slug ]['key'],
				);
			}
		}
		return array(
			'active'   => false,
			'name'     => '',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Empty placeholder in the returned shape; not a query argument.
			'meta_key' => '',
		);
	}

	/**
	 * Published posts and pages in total.
	 *
	 * @return int
	 */
	public static function published_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'post', 'page' )"
		);
	}

	/**
	 * Published content whose title is empty or a placeholder.
	 *
	 * @param int $limit Maximum rows returned.
	 * @return array<int,array{id:int,type:string,url:string}>
	 */
	public static function missing_titles( int $limit = 20 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_type IN ( 'post', 'page' )
					AND ( TRIM( post_title ) = '' OR post_title IN ( 'Auto Draft', '(no title)' ) )
				ORDER BY post_date DESC LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static fn( $row ) => array(
				'id'   => (int) $row['ID'],
				'type' => (string) $row['post_type'],
				'url'  => (string) get_permalink( (int) $row['ID'] ),
			),
			$rows
		);
	}

	/**
	 * Titles used by more than one published post or page.
	 *
	 * @param int $limit Maximum groups returned.
	 * @return array<int,array{title:string,count:int}>
	 */
	public static function duplicate_titles( int $limit = 10 ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_title, COUNT(*) AS total FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_type IN ( 'post', 'page' ) AND TRIM( post_title ) <> ''
				GROUP BY post_title HAVING total > 1 ORDER BY total DESC LIMIT %d",
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map(
			static fn( $row ) => array(
				'title' => wp_strip_all_tags( (string) $row['post_title'] ),
				'count' => (int) $row['total'],
			),
			$rows
		);
	}

	/**
	 * Published content without a description in the active SEO plugin's field.
	 *
	 * @param string $meta_key Meta key holding the description.
	 * @param int    $limit    Maximum examples returned.
	 * @return array{checked:int,missing:int,examples:array<int,array{id:int,title:string}>}
	 */
	public static function missing_descriptions( string $meta_key, int $limit = 10 ): array {
		global $wpdb;

		$checked = self::published_count();
		$rows    = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status = 'publish' AND p.post_type IN ( 'post', 'page' )
					AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )
				ORDER BY p.post_date DESC LIMIT %d",
				$meta_key,
				max( 1, $limit )
			),
			ARRAY_A
		);
		$missing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_status = 'publish' AND p.post_type IN ( 'post', 'page' )
					AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )",
				$meta_key
			)
		);

		return array(
			'checked'  => $checked,
			'missing'  => $missing,
			'examples' => array_map(
				static fn( $row ) => array(
					'id'    => (int) $row['ID'],
					'title' => wp_strip_all_tags( (string) $row['post_title'] ) ?: sprintf( '#%d', (int) $row['ID'] ),
				),
				$rows
			),
		);
	}

	/**
	 * Media-library images without alt text.
	 *
	 * @param int $limit Maximum examples returned.
	 * @return array{total:int,missing:int,examples:string[]}
	 */
	public static function attachments_missing_alt( int $limit = 10 ): array {
		global $wpdb;

		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		$missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
				AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )"
		);
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.post_title FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
					AND ( m.meta_id IS NULL OR TRIM( m.meta_value ) = '' )
				ORDER BY p.post_date DESC LIMIT %d",
				$wpdb->esc_like( 'image/' ) . '%',
				max( 1, $limit )
			)
		);

		return array(
			'total'    => $total,
			'missing'  => $missing,
			'examples' => array_map( 'wp_strip_all_tags', $rows ),
		);
	}

	/**
	 * Images inside published content that carry no alt attribute at all.
	 *
	 * @param int $scan_posts Maximum posts examined.
	 * @return array{posts_scanned:int,images:int,without_alt:int,capped:bool,examples:array<int,array{id:int,title:string,images:int}>}
	 */
	public static function content_images_missing_alt( int $scan_posts = 300 ): array {
		global $wpdb;

		$total = self::published_count();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_type IN ( 'post', 'page' ) AND post_content LIKE %s
				ORDER BY post_date DESC LIMIT %d",
				'%<img%',
				max( 1, $scan_posts )
			),
			ARRAY_A
		);

		$images   = 0;
		$no_alt   = 0;
		$examples = array();
		foreach ( $rows as $row ) {
			$tags  = new \WP_HTML_Tag_Processor( (string) $row['post_content'] );
			$count = 0;
			while ( $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
				$images++;
				$role = strtolower( trim( (string) $tags->get_attribute( 'role' ) ) );
				if ( null === $tags->get_attribute( 'alt' ) && 'presentation' !== $role ) {
					$no_alt++;
					$count++;
				}
			}
			if ( $count > 0 && count( $examples ) < 10 ) {
				$examples[] = array(
					'id'     => (int) $row['ID'],
					'title'  => wp_strip_all_tags( (string) $row['post_title'] ) ?: sprintf( '#%d', (int) $row['ID'] ),
					'images' => $count,
				);
			}
		}

		return array(
			'posts_scanned' => count( $rows ),
			'images'        => $images,
			'without_alt'   => $no_alt,
			'capped'        => $total > $scan_posts,
			'examples'      => $examples,
		);
	}

	/**
	 * Internal links found in published content, with the post they appear in.
	 *
	 * @param int $scan_posts Maximum posts examined.
	 * @param int $max_links  Maximum distinct links returned.
	 * @return array{posts_scanned:int,capped:bool,links:array<string,array{id:int,title:string}>}
	 */
	public static function internal_links( int $scan_posts = 200, int $max_links = 300 ): array {
		global $wpdb;

		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$total = self::published_count();
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_type IN ( 'post', 'page' ) AND post_content LIKE %s
				ORDER BY post_date DESC LIMIT %d",
				'%<a %',
				max( 1, $scan_posts )
			),
			ARRAY_A
		);

		$links = array();
		foreach ( $rows as $row ) {
			$tags = new \WP_HTML_Tag_Processor( (string) $row['post_content'] );
			while ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
				$href = trim( (string) $tags->get_attribute( 'href' ) );
				if ( '' === $href || preg_match( '#^(mailto:|tel:|javascript:|\#|data:)#i', $href ) ) {
					continue;
				}
				$url = 0 === strpos( $href, '/' ) ? home_url( $href ) : $href;
				if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== $host ) {
					continue;
				}
				$url = strtok( $url, '#' );
				if ( ! isset( $links[ $url ] ) && count( $links ) < $max_links ) {
					$links[ $url ] = array(
						'id'    => (int) $row['ID'],
						'title' => wp_strip_all_tags( (string) $row['post_title'] ) ?: sprintf( '#%d', (int) $row['ID'] ),
					);
				}
			}
		}

		return array(
			'posts_scanned' => count( $rows ),
			'capped'        => $total > $scan_posts || count( $links ) >= $max_links,
			'links'         => $links,
		);
	}
}
