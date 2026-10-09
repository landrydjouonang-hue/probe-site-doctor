<?php
/**
 * Large images check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Performance;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Finds media-library images whose served file is heavy or oversized.
 *
 * When WordPress has scaled a big upload (big_image_size_threshold), the
 * scaled copy is what gets served, so only that copy is evaluated.
 */
final class LargeImagesCheck extends AbstractCheck {

	/** Rows fetched per query. */
	private const BATCH = 500;

	public function get_id(): string {
		return 'performance.large-images';
	}

	public function get_category(): string {
		return CategoryRegistry::PERFORMANCE;
	}

	public function get_label(): string {
		return __( 'Large images', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Looks for media-library images with a large file size or very large dimensions.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'max_bytes'     => 1024 * 1024,
				'max_dimension' => 2560,
				'scan_limit'    => 5000,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a WordPress core filter, not declaring one.
		$scaling = apply_filters( 'big_image_size_threshold', 2560, array( 0, 0 ), '', 0 );
		$base    = array(
			'images_in_library'   => $total,
			'big_image_scaling'   => false !== $scaling,
			'threshold_bytes'     => (int) $t['max_bytes'],
			'threshold_px'        => (int) $t['max_dimension'],
		);

		if ( 0 === $total ) {
			return $this->good( __( 'No images in the media library', 'probe-site-doctor' ), __( 'There are no uploaded images to evaluate.', 'probe-site-doctor' ), $base );
		}

		$uploads  = wp_get_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$limit    = min( $total, max( 1, (int) $t['scan_limit'] ) );
		$heavy    = array();
		$oversize = 0;
		$checked  = 0;

		for ( $offset = 0; $offset < $limit; $offset += self::BATCH ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_mime_type, m.meta_value FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_metadata'
					WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s
					ORDER BY p.ID DESC LIMIT %d OFFSET %d",
					$wpdb->esc_like( 'image/' ) . '%',
					min( self::BATCH, $limit - $offset ),
					$offset
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$meta = maybe_unserialize( (string) $row['meta_value'] );
				if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
					continue;
				}
				$checked++;

				$bytes = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
				if ( ! $bytes ) {
					$file = wp_normalize_path( $basedir . $meta['file'] );
					if ( false === strpos( $meta['file'], '..' ) && is_file( $file ) ) {
						$bytes = (int) filesize( $file );
					}
				}

				$width  = (int) ( $meta['width'] ?? 0 );
				$height = (int) ( $meta['height'] ?? 0 );

				if ( $bytes > $t['max_bytes'] ) {
					$heavy[] = array(
						'file'       => wp_basename( (string) $meta['file'] ),
						'size_bytes' => $bytes,
						'dimensions' => $width && $height ? $width . '×' . $height : '—',
						'edit'       => (int) $row['ID'],
					);
				} elseif ( max( $width, $height ) > $t['max_dimension'] ) {
					$oversize++;
				}
			}
		}
		// phpcs:enable

		usort( $heavy, static fn( $a, $b ) => $b['size_bytes'] <=> $a['size_bytes'] );

		$data = $base + array(
			'images_checked'       => $checked,
			'heavy_images'         => count( $heavy ),
			'oversized_dimensions' => $oversize,
			'largest'              => array_map(
				static function ( $image ) {
					unset( $image['edit'] );
					return $image;
				},
				array_slice( $heavy, 0, 10 )
			),
		);

		$sampled = $checked < $total
			? ' ' . sprintf(
				/* translators: 1: Number of images checked, 2: Total images. */
				__( 'The %1$s most recent of %2$s images were checked.', 'probe-site-doctor' ),
				number_format_i18n( $checked ),
				number_format_i18n( $total )
			)
			: '';

		$scope = __( 'These are files in the media library. Whether they are shown at full size on a page depends on the theme and how each image is inserted; a browser-based test is needed to confirm what visitors actually download.', 'probe-site-doctor' );

		if ( $heavy ) {
			return $this->warning(
				sprintf(
					/* translators: %s: Number of images. */
					_n( '%s image is larger than recommended', '%s images are larger than recommended', count( $heavy ), 'probe-site-doctor' ),
					number_format_i18n( count( $heavy ) )
				),
				sprintf(
					/* translators: 1: File size such as "1 MB". */
					__( 'These images exceed %1$s. Heavy images are a frequent cause of slow pages.', 'probe-site-doctor' ),
					size_format( (int) $t['max_bytes'] )
				) . $sampled . ' ' . $scope,
				__( 'Compress or resize the largest images (most photos need no more than 2560 px wide and a few hundred KB). An image optimization plugin can do this in bulk and for future uploads. Keep a backup of the originals.', 'probe-site-doctor' ),
				$data
			);
		}

		if ( $oversize > 0 || ! $data['big_image_scaling'] ) {
			$message = $oversize > 0
				? sprintf(
					/* translators: 1: Number of images, 2: Pixel dimension. */
					_n( '%1$s image is wider or taller than %2$s px, although its file size is acceptable.', '%1$s images are wider or taller than %2$s px, although their file sizes are acceptable.', $oversize, 'probe-site-doctor' ),
					number_format_i18n( $oversize ),
					number_format_i18n( (int) $t['max_dimension'] )
				)
				: __( 'No heavy images were found.', 'probe-site-doctor' );
			if ( ! $data['big_image_scaling'] ) {
				$message .= ' ' . __( 'Automatic scaling of big uploads has been disabled (big_image_size_threshold), so future large uploads will be served at full size.', 'probe-site-doctor' );
			}
			return $this->info(
				$oversize > 0 ? __( 'Some images have very large dimensions', 'probe-site-doctor' ) : __( 'Automatic image scaling is disabled', 'probe-site-doctor' ),
				$message . $sampled,
				__( 'Keep automatic scaling enabled, and resize very large originals before uploading.', 'probe-site-doctor' ),
				$data
			);
		}

		return $this->good(
			__( 'No oversized images found', 'probe-site-doctor' ),
			sprintf(
				/* translators: 1: File size, 2: Pixel dimension. */
				__( 'All checked images are under %1$s and %2$s px.', 'probe-site-doctor' ),
				size_format( (int) $t['max_bytes'] ),
				number_format_i18n( (int) $t['max_dimension'] )
			) . $sampled,
			$data
		);
	}
}
