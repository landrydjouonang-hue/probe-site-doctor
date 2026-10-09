<?php
/**
 * Trash, spam and auto-draft check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Database;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Counts auto-drafts, trashed posts and spam/trashed comments. Revisions,
 * transients and orphaned metadata have their own checks.
 */
final class DatabaseBloatCheck extends AbstractCheck {

	public function get_id(): string {
		return 'database.bloat';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Trash, spam and auto-drafts', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts auto-drafts, trashed posts and spam or trashed comments that are waiting to be emptied.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'auto_drafts'   => 200,
				'trashed_posts' => 500,
				'spam_comments' => 1000,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$counts = array(
			'auto_drafts'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trashed_posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam_comments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam', 'trash')" ),
		);
		// phpcs:enable

		$labels = array(
			'auto_drafts'   => __( 'auto-drafts', 'probe-site-doctor' ),
			'trashed_posts' => __( 'items in the trash', 'probe-site-doctor' ),
			'spam_comments' => __( 'spam or trashed comments', 'probe-site-doctor' ),
		);

		$over = array();
		foreach ( $counts as $key => $count ) {
			if ( $count >= $t[ $key ] ) {
				$over[] = number_format_i18n( $count ) . ' ' . $labels[ $key ];
			}
		}

		$data = $counts + array(
			'trash_retention_days' => defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30,
		);

		if ( ! $over ) {
			return $this->good(
				__( 'Trash, spam and drafts are within normal levels', 'probe-site-doctor' ),
				sprintf(
					/* translators: 1: Auto-drafts, 2: Trashed posts, 3: Spam comments. */
					__( '%1$s auto-drafts, %2$s trashed posts and %3$s spam or trashed comments.', 'probe-site-doctor' ),
					number_format_i18n( $counts['auto_drafts'] ),
					number_format_i18n( $counts['trashed_posts'] ),
					number_format_i18n( $counts['spam_comments'] )
				),
				$data
			);
		}

		return $this->warning(
			__( 'Trash, spam or auto-drafts have piled up', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: List such as "800 items in the trash and 3,000 spam comments". */
				__( 'Found %s. WordPress normally empties these automatically; large numbers suggest scheduled tasks are not running or the trash is not being emptied.', 'probe-site-doctor' ),
				wp_sprintf( '%l', $over )
			),
			__( 'Empty the trash and spam folders from the admin screens (Posts → Trash, Comments → Spam). Check that WP-Cron runs so auto-drafts and old trash are removed automatically.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			Impact::LOW,
			__( 'These rows are excluded from normal page queries, so page speed is barely affected. They enlarge the posts and comments tables and slow down backups.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Permanently delete trashed posts and spam comments. Items in the trash can no longer be restored after this.', 'probe-site-doctor' ),
				array(
					__( 'Review the trash and spam folders for anything worth keeping.', 'probe-site-doctor' ),
					__( 'Use “Empty Trash” and “Empty Spam” in the admin, or the commands below after a backup.', 'probe-site-doctor' ),
				)
			) )
				->with_command( __( 'Delete trashed posts with WP-CLI', 'probe-site-doctor' ), 'wp post delete $(wp post list --post_status=trash --post_type=any --format=ids) --force', ManualCleanup::TYPE_WP_CLI )
				->with_command( __( 'Delete spam comments with WP-CLI', 'probe-site-doctor' ), 'wp comment delete $(wp comment list --status=spam --format=ids) --force', ManualCleanup::TYPE_WP_CLI )
		);
	}
}
