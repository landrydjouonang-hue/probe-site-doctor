<?php
/**
 * Orphaned metadata check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Database;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\DatabaseInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Counts metadata and relationship rows that point to objects that no
 * longer exist. These are indicators only: counts are capped, and rows
 * with object ID 0 are reported separately because some plugins store
 * global data that way on purpose.
 */
final class OrphanedMetaCheck extends AbstractCheck {

	/** Counting stops here per type to keep queries bounded. */
	private const CAP = 100000;

	public function get_id(): string {
		return 'database.orphaned-meta';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Orphaned metadata', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts post, comment, term and user metadata, term relationships and revisions whose parent object no longer exists.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'info_rows'    => 1,
				'warning_rows' => 5000,
			)
		);

		$types = array(
			'postmeta'           => array(
				'label' => __( 'post meta without a post', 'probe-site-doctor' ),
				'sql'   => "SELECT m.meta_id FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL AND m.post_id <> 0",
				'fix'   => "DELETE m FROM {$wpdb->postmeta} m LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL AND m.post_id <> 0;",
			),
			'commentmeta'        => array(
				'label' => __( 'comment meta without a comment', 'probe-site-doctor' ),
				'sql'   => "SELECT m.meta_id FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_ID IS NULL AND m.comment_id <> 0",
				'fix'   => "DELETE m FROM {$wpdb->commentmeta} m LEFT JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_ID IS NULL AND m.comment_id <> 0;",
			),
			'termmeta'           => array(
				'label' => __( 'term meta without a term', 'probe-site-doctor' ),
				'sql'   => "SELECT m.meta_id FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id WHERE t.term_id IS NULL AND m.term_id <> 0",
				'fix'   => "DELETE m FROM {$wpdb->termmeta} m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.term_id WHERE t.term_id IS NULL AND m.term_id <> 0;",
			),
			'usermeta'           => array(
				'label' => __( 'user meta without a user', 'probe-site-doctor' ),
				'sql'   => "SELECT m.umeta_id FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL AND m.user_id <> 0",
				'fix'   => "DELETE m FROM {$wpdb->usermeta} m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id WHERE u.ID IS NULL AND m.user_id <> 0;",
			),
			// Link categories relate to links, not posts, so they are excluded.
			'term_relationships' => array(
				'label' => __( 'term relationships without a post', 'probe-site-doctor' ),
				'sql'   => "SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL AND tt.taxonomy <> 'link_category'",
				'fix'   => "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL AND tt.taxonomy <> 'link_category';",
			),
			'revisions'          => array(
				'label' => __( 'revisions whose post no longer exists', 'probe-site-doctor' ),
				'sql'   => "SELECT r.ID FROM {$wpdb->posts} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.ID IS NULL",
				'fix'   => "DELETE r FROM {$wpdb->posts} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND p.ID IS NULL;",
			),
		);

		// On multisite, users and user meta are network-wide; only report them on the main site.
		if ( is_multisite() && ! is_main_site() ) {
			unset( $types['usermeta'] );
		}

		$counts = array();
		$capped = false;
		$total  = 0;
		$found  = array();

		foreach ( $types as $key => $type ) {
			$result         = DatabaseInfo::capped_count( $type['sql'], self::CAP );
			$counts[ $key ] = $result['count'];
			$capped         = $capped || $result['capped'];
			$total         += $result['count'];
			if ( $result['count'] > 0 ) {
				$found[] = number_format_i18n( $result['count'] ) . ( $result['capped'] ? '+' : '' ) . ' ' . $type['label'];
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$zero_postmeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = 0" );

		$data = $counts + array(
			'counts_capped'      => $capped,
			'postmeta_with_id_0' => $zero_postmeta,
		);

		$note = $zero_postmeta > 0
			? ' ' . sprintf(
				/* translators: %s: Number of rows. */
				__( '%s post meta rows use post ID 0. They are not counted as orphans, because some plugins store global data that way on purpose.', 'probe-site-doctor' ),
				number_format_i18n( $zero_postmeta )
			)
			: '';

		if ( $total < $t['info_rows'] ) {
			return $this->good(
				__( 'No orphaned metadata found', 'probe-site-doctor' ),
				__( 'All metadata, term relationships and revisions point to existing objects.', 'probe-site-doctor' ) . $note,
				$data
			);
		}

		$severity = $total >= $t['warning_rows'] ? Severity::WARNING : Severity::INFO;

		$cleanup = new ManualCleanup(
			__( 'Delete rows that point to objects that no longer exist. Orphaned rows are not shown anywhere in WordPress, but a plugin with an unusual data model might still read them, so review before deleting.', 'probe-site-doctor' ),
			array(
				__( 'Back up the database.', 'probe-site-doctor' ),
				__( 'Preview the affected rows first: in a command, change the leading “DELETE m FROM” (or “DELETE r”, “DELETE tr”) to “SELECT m.* FROM” (or “SELECT r.*”, “SELECT tr.*”) and run that instead.', 'probe-site-doctor' ),
				__( 'Delete one type at a time with the matching command, then check the site.', 'probe-site-doctor' ),
			)
		);
		foreach ( $types as $key => $type ) {
			if ( $counts[ $key ] > 0 ) {
				$cleanup = $cleanup->with_command(
					/* translators: %s: Type of orphaned rows. */
					sprintf( __( 'Delete %s', 'probe-site-doctor' ), $type['label'] ),
					$type['fix']
				);
			}
		}

		return $this->result(
			$severity,
			__( 'Orphaned metadata was found', 'probe-site-doctor' ),
			sprintf(
				/* translators: %s: List of findings. */
				__( 'Found %s. These rows are usually left behind when content, comments, terms or users are deleted directly in the database or by a plugin that does not clean up after itself.', 'probe-site-doctor' ),
				wp_sprintf( '%l', $found )
			) . ( $capped ? ' ' . __( 'Some counts stopped at the counting limit, so the real number is higher.', 'probe-site-doctor' ) : '' ) . $note,
			__( 'Remove the orphaned rows after a backup. If the number keeps growing, find the plugin or process that deletes objects without their metadata.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$total >= $t['warning_rows'] ? Impact::MEDIUM : Impact::LOW,
			__( 'Orphaned rows are never displayed, but they enlarge the meta tables and indexes that WordPress queries on every page. The benefit of removing them grows with their number.', 'probe-site-doctor' )
		)->with_cleanup( $cleanup );
	}
}
