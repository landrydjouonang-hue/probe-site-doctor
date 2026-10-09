<?php
/**
 * Image alt text check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Seo;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\ContentScan;
use ProbeSiteDoctor\Diagnostics\Support\FrontendSnapshot;

defined( 'ABSPATH' ) || exit;

/**
 * Looks for images without alternative text: in the media library, inside
 * published content, and on the homepage as delivered.
 *
 * Only a completely missing alt attribute is counted. An empty alt (alt="")
 * is the correct markup for a decorative image, so it is not reported.
 * Alt text matters for screen-reader users first and image search second.
 */
final class ImageAltTextCheck extends AbstractCheck {

	public function get_id(): string {
		return 'seo.image-alt-text';
	}

	public function get_category(): string {
		return CategoryRegistry::SEO;
	}

	public function get_label(): string {
		return __( 'Image alt text', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts media-library images without alt text and content images with no alt attribute at all.', 'probe-site-doctor' );
	}

	public function get_measurement(): string {
		return Measurement::LOOPBACK;
	}

	public function run(): Result {
		$t = $this->thresholds(
			array(
				'scan_posts'      => 300,
				'library_share'   => 50,
				'content_warning' => 5,
			)
		);

		$library  = ContentScan::attachments_missing_alt( 10 );
		$content  = ContentScan::content_images_missing_alt( (int) $t['scan_posts'] );
		$snapshot = FrontendSnapshot::get();

		$home_images = ! empty( $snapshot['ok'] ) ? (int) ( $snapshot['images'] ?? 0 ) : 0;
		$home_no_alt = ! empty( $snapshot['ok'] ) ? (array) ( $snapshot['images_without_alt'] ?? array() ) : array();

		$data = array(
			'library_images'          => $library['total'],
			'library_without_alt'     => $library['missing'],
			'content_images_scanned'  => $content['images'],
			'content_without_alt'     => $content['without_alt'],
			'posts_scanned'           => $content['posts_scanned'],
			'homepage_images'         => $home_images,
			'homepage_without_alt'    => count( $home_no_alt ),
			'library_examples'        => $library['examples'],
			'content_examples'        => $content['examples'],
			'homepage_examples'       => array_slice( $home_no_alt, 0, 10 ),
		);

		$share    = $library['total'] > 0 ? (int) round( $library['missing'] / $library['total'] * 100 ) : 0;
		$severity = Severity::GOOD;
		$issues   = array();
		$actions  = array();

		if ( $content['without_alt'] >= $t['content_warning'] ) {
			$severity  = Severity::WARNING;
			$issues[]  = sprintf(
				/* translators: 1: Number of images, 2: Number of posts. */
				__( '%1$s images inside published content have no alt attribute at all (found in %2$s items).', 'probe-site-doctor' ),
				number_format_i18n( $content['without_alt'] ),
				number_format_i18n( count( $content['examples'] ) )
			);
			$actions[] = __( 'Edit the items listed in the details and describe each image in a few words, or mark purely decorative images as decorative so they get an empty alt.', 'probe-site-doctor' );
		} elseif ( $content['without_alt'] > 0 ) {
			$severity = Severity::INFO;
			$issues[] = sprintf(
				/* translators: %s: Number of images. */
				_n( '%s image inside published content has no alt attribute.', '%s images inside published content have no alt attribute.', $content['without_alt'], 'probe-site-doctor' ),
				number_format_i18n( $content['without_alt'] )
			);
		}

		if ( $library['total'] > 0 && $share >= $t['library_share'] && $library['missing'] > 0 ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = sprintf(
				/* translators: 1: Number of images, 2: Total, 3: Percentage. */
				__( '%1$s of %2$s media-library images (%3$s%%) have no alt text stored. WordPress uses that value when an image is inserted, so filling it in helps future posts.', 'probe-site-doctor' ),
				number_format_i18n( $library['missing'] ),
				number_format_i18n( $library['total'] ),
				number_format_i18n( $share )
			);
			$actions[] = __( 'Add alt text to the images you reuse most in Media → Library.', 'probe-site-doctor' );
		}

		if ( $home_no_alt ) {
			$severity  = $this->worst( $severity, Severity::INFO );
			$issues[]  = sprintf(
				/* translators: 1: Number of images, 2: Total images. */
				__( 'The homepage delivers %1$s of %2$s images without an alt attribute, including theme and widget images.', 'probe-site-doctor' ),
				number_format_i18n( count( $home_no_alt ) ),
				number_format_i18n( $home_images )
			);
			$actions[] = __( 'Check the theme templates and widgets that output those images.', 'probe-site-doctor' );
		}

		$scope = __( 'Only images with no alt attribute at all are counted; alt="" is correct for decorative images and is not reported. Alt text matters first for people using screen readers, and secondarily for image search.', 'probe-site-doctor' );
		if ( $content['capped'] ) {
			$scope .= ' ' . sprintf(
				/* translators: %s: Number of posts. */
				__( 'Content scanning stopped after %s published items to keep the scan light.', 'probe-site-doctor' ),
				number_format_i18n( $content['posts_scanned'] )
			);
		}

		if ( Severity::GOOD === $severity ) {
			return $this->good(
				__( 'Images have alt text', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Content images, 2: Library images. */
					__( '%1$s content images and %2$s media-library images were checked and none is missing alt text.', 'probe-site-doctor' ),
					number_format_i18n( $content['images'] ),
					number_format_i18n( $library['total'] )
				) . ' ' . $scope . ' ' . $this->seo_scope_note(),
				$data
			);
		}

		return $this->result(
			$severity,
			Severity::WARNING === $severity ? __( 'Many images have no alt text', 'probe-site-doctor' ) : __( 'Some images have no alt text', 'probe-site-doctor' ),
			implode( ' ', $issues ) . ' ' . $scope . ' ' . $this->seo_scope_note(),
			implode( ' ', $actions ),
			$data
		)->with_impact(
			Severity::WARNING === $severity ? Impact::MEDIUM : Impact::LOW,
			__( 'Missing alt text leaves screen-reader users without the information the image carries, which is an accessibility problem before an SEO one. It also keeps images out of image search.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Add the alt text yourself. Site Doctor never edits content or media metadata.', 'probe-site-doctor' ),
				array(
					__( 'In Media → Library, open an image and fill in "Alternative Text" with what the image shows.', 'probe-site-doctor' ),
					__( 'In the block editor, select an image block and set the alt text in the block settings.', 'probe-site-doctor' ),
					__( 'Leave the alt empty for images that carry no information (decoration, spacers) instead of repeating the file name.', 'probe-site-doctor' ),
					__( 'Do not stuff keywords: describe the image as you would to someone who cannot see it.', 'probe-site-doctor' ),
				),
				false
			) )->as_content_fix()
		);
	}
}
