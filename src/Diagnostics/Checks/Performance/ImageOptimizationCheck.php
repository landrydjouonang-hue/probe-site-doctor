<?php
/**
 * Image optimization indicators check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Performance;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews signals that images are being optimised: modern formats, an
 * optimization plugin, JPEG quality, and native lazy loading.
 *
 * These are indicators only. The compression level of existing files is not
 * measured.
 */
final class ImageOptimizationCheck extends AbstractCheck {

	/**
	 * Well-known image optimization plugins (directory slug => name).
	 *
	 * @var array<string,string>
	 */
	private const OPTIMIZERS = array(
		'ewww-image-optimizer'       => 'EWWW Image Optimizer',
		'wp-smushit'                 => 'Smush',
		'wp-smush-pro'               => 'Smush Pro',
		'imagify'                    => 'Imagify',
		'shortpixel-image-optimiser' => 'ShortPixel',
		'optimole-wp'                => 'Optimole',
		'tiny-compress-images'       => 'TinyPNG',
		'webp-express'               => 'WebP Express',
		'webp-converter-for-media'   => 'Converter for Media',
		'webp-uploads'               => 'Modern Image Formats',
		'robin-image-optimizer'      => 'Robin Image Optimizer',
		'litespeed-cache'            => 'LiteSpeed Cache',
		'wp-optimize'                => 'WP-Optimize',
	);

	public function get_id(): string {
		return 'performance.image-optimization';
	}

	public function get_category(): string {
		return CategoryRegistry::PERFORMANCE;
	}

	public function get_label(): string {
		return __( 'Image optimization indicators', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Looks for signs of image optimization: modern formats (WebP/AVIF), an optimization plugin, JPEG quality and lazy loading.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'max_jpeg_quality' => 90,
				'min_images'       => 20,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		$modern = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/webp', 'image/avif')" );
		// phpcs:enable

		$supports_webp = wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$supports_avif = wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading a WordPress core filter, not declaring one.
		$output_map    = (array) apply_filters( 'image_editor_output_format', array(), '', 'image/jpeg' );
		$converts_jpeg = isset( $output_map['image/jpeg'] ) && in_array( $output_map['image/jpeg'], array( 'image/webp', 'image/avif' ), true );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading WordPress core filters, not declaring them.
		$quality = (int) apply_filters( 'wp_editor_set_quality', 82, 'image/jpeg' );
		$quality = (int) apply_filters( 'jpeg_quality', $quality, 'image_resize' );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$lazy = wp_lazy_loading_enabled( 'img', 'the_content' );

		$optimizers = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
			$slug = dirname( (string) $basename );
			if ( isset( self::OPTIMIZERS[ $slug ] ) ) {
				$optimizers[] = self::OPTIMIZERS[ $slug ];
			}
		}

		$modern_pct = $total > 0 ? round( $modern / $total * 100, 1 ) : 0.0;

		$data = array(
			'optimization_plugins'     => $optimizers,
			'modern_format_uploads'    => $modern,
			'modern_format_share'      => $modern_pct . '%',
			'server_supports_webp'     => $supports_webp,
			'server_supports_avif'     => $supports_avif,
			'jpeg_converted_on_upload' => $converts_jpeg,
			'jpeg_quality'             => $quality,
			'lazy_loading'             => $lazy,
		);

		$severity = Severity::GOOD;
		$findings = array();
		$actions  = array();

		if ( ! $lazy ) {
			$severity   = $this->worst( $severity, Severity::WARNING );
			$findings[] = __( 'Native lazy loading of content images has been disabled, so every image on a page is requested immediately.', 'probe-site-doctor' );
			$actions[]  = __( 'Remove the code or plugin setting that disables lazy loading (wp_lazy_loading_enabled filter).', 'probe-site-doctor' );
		}

		$has_pipeline = $optimizers || $converts_jpeg || ( $total > 0 && $modern_pct >= 50 );
		if ( ! $has_pipeline && $total >= $t['min_images'] ) {
			$severity   = $this->worst( $severity, Severity::WARNING );
			$findings[] = __( 'No image optimization plugin was detected, uploads are not converted to a modern format, and most images are JPEG/PNG/GIF.', 'probe-site-doctor' );
			$actions[]  = $supports_webp
				? __( 'Your server can create WebP images. Enable WebP output (for example with the Modern Image Formats plugin) or use an image optimization plugin to compress existing images.', 'probe-site-doctor' )
				: __( 'Use an image optimization plugin or service. Your server’s image library cannot create WebP, so ask your host about enabling WebP support in Imagick or GD.', 'probe-site-doctor' );
		} elseif ( ! $has_pipeline ) {
			$severity   = $this->worst( $severity, Severity::INFO );
			$findings[] = __( 'No image optimization plugin or modern-format conversion was detected. With few images this matters little today.', 'probe-site-doctor' );
			$actions[]  = __( 'Set up image optimization before the media library grows.', 'probe-site-doctor' );
		}

		if ( $quality > $t['max_jpeg_quality'] ) {
			$severity   = $this->worst( $severity, Severity::INFO );
			$findings[] = sprintf(
				/* translators: %d: JPEG quality 1–100. */
				__( 'Generated JPEG sizes use quality %d, which produces noticeably bigger files with little visible gain (WordPress uses 82 by default).', 'probe-site-doctor' ),
				$quality
			);
			$actions[] = __( 'Lower the JPEG quality back towards the WordPress default of 82.', 'probe-site-doctor' );
		}

		$note = __( 'These are configuration indicators; the actual compression of existing image files is not measured.', 'probe-site-doctor' );

		if ( Severity::GOOD === $severity ) {
			return $this->good(
				__( 'Image optimization is in place', 'probe-site-doctor' ),
				( $optimizers
					? sprintf(
						/* translators: %s: Plugin names. */
						__( 'Detected: %s. Lazy loading is enabled.', 'probe-site-doctor' ),
						implode( ', ', $optimizers )
					)
					: __( 'Modern image formats are in use and lazy loading is enabled.', 'probe-site-doctor' ) ) . ' ' . $note,
				$data
			);
		}

		return $this->result(
			$severity,
			Severity::WARNING === $severity ? __( 'Images may not be optimized', 'probe-site-doctor' ) : __( 'Image optimization could be improved', 'probe-site-doctor' ),
			implode( ' ', $findings ) . ' ' . $note,
			implode( ' ', $actions ),
			$data
		);
	}
}
