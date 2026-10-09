<?php
/**
 * Post revisions check.
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

defined( 'ABSPATH' ) || exit;

/**
 * Measures stored post revisions: count, content size, attached meta, the
 * posts with the most revisions, and the configured revision limit.
 */
final class RevisionsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'database.revisions';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Post revisions', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Counts stored post revisions and their size, and checks the revision limit.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'info_count'     => 500,
				'warning_count'  => 2000,
				'warning_bytes'  => 25 * 1024 * 1024,
				'critical_count' => 50000,
				'critical_bytes' => 250 * 1024 * 1024,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$totals = (array) $wpdb->get_row(
			"SELECT COUNT(*) AS total,
				COALESCE(SUM(LENGTH(post_content) + LENGTH(post_title) + LENGTH(post_excerpt)), 0) AS bytes,
				COALESCE(SUM(post_name LIKE '%-autosave-v1'), 0) AS autosaves
			FROM {$wpdb->posts} WHERE post_type = 'revision'",
			ARRAY_A
		);
		$count     = (int) ( $totals['total'] ?? 0 );
		$bytes     = (int) ( $totals['bytes'] ?? 0 );
		$autosaves = (int) ( $totals['autosaves'] ?? 0 );

		$meta_rows = $count ? (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'revision'"
		) : 0;

		$top = $count ? (array) $wpdb->get_results(
			"SELECT r.post_parent AS parent_id, COUNT(*) AS revisions, p.post_title AS title, p.post_type AS type
			FROM {$wpdb->posts} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
			WHERE r.post_type = 'revision'
			GROUP BY r.post_parent, p.post_title, p.post_type ORDER BY revisions DESC LIMIT 5",
			ARRAY_A
		) : array();
		// phpcs:enable

		$limit       = defined( 'WP_POST_REVISIONS' ) ? WP_POST_REVISIONS : true;
		$limit_label = true === $limit || (int) $limit < 0 ? __( 'unlimited', 'probe-site-doctor' ) : ( 0 === (int) $limit || false === $limit ? __( 'disabled', 'probe-site-doctor' ) : (string) (int) $limit );
		$unlimited   = true === $limit || ( ! is_bool( $limit ) && (int) $limit < 0 );

		$data = array(
			'revisions'          => $count,
			'autosaves'          => $autosaves,
			'content_bytes'      => $bytes,
			'revision_meta_rows' => $meta_rows,
			'revision_limit'     => $limit_label,
			'most_revised'       => array_map(
				static fn( $row ) => array(
					'post'      => '' !== (string) $row['title'] ? wp_strip_all_tags( (string) $row['title'] ) : sprintf( '#%d', (int) $row['parent_id'] ),
					'type'      => (string) $row['type'],
					'revisions' => (int) $row['revisions'],
				),
				$top
			),
		);

		if ( $count >= $t['critical_count'] || $bytes >= $t['critical_bytes'] ) {
			$severity = Severity::CRITICAL;
		} elseif ( $count >= $t['warning_count'] || $bytes >= $t['warning_bytes'] ) {
			$severity = Severity::WARNING;
		} elseif ( $unlimited && $count >= $t['info_count'] ) {
			$severity = Severity::INFO;
		} else {
			$severity = Severity::GOOD;
		}

		$message = sprintf(
			/* translators: 1: Revision count, 2: Size, 3: Meta rows, 4: Revision limit. */
			__( 'The database stores %1$s post revisions holding about %2$s of content, plus %3$s meta rows attached to them. Revision limit: %4$s.', 'probe-site-doctor' ),
			number_format_i18n( $count ),
			size_format( $bytes, 1 ) ?: '0 B',
			number_format_i18n( $meta_rows ),
			$limit_label
		);

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Post revisions are under control', 'probe-site-doctor' ), $message, $data );
		}

		$impact_level = Severity::CRITICAL === $severity ? Impact::MEDIUM : Impact::LOW;

		return $this->result(
			$severity,
			Severity::INFO === $severity ? __( 'Post revisions are unlimited', 'probe-site-doctor' ) : __( 'Many post revisions are stored', 'probe-site-doctor' ),
			$message,
			$unlimited
				? __( 'Limit future revisions by adding define( \'WP_POST_REVISIONS\', 10 ); to wp-config.php, then consider removing old revisions after a backup.', 'probe-site-doctor' )
				: __( 'Consider removing old revisions after a backup. The revision limit only applies to new revisions, so existing ones stay until they are removed.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$impact_level,
			sprintf(
				/* translators: %s: Size. */
				__( 'Revisions are not loaded on normal page views, so the effect on front-end speed is usually small. They enlarge the posts table (about %s), which makes backups and some admin queries slower. Removing them reclaims that space.', 'probe-site-doctor' ),
				size_format( $bytes, 1 ) ?: '0 B'
			)
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Permanently delete stored revisions. Current versions of posts are not affected, but earlier versions can no longer be restored.', 'probe-site-doctor' ),
				array(
					__( 'Make a full database backup and confirm that it can be restored.', 'probe-site-doctor' ),
					__( 'Set a revision limit in wp-config.php so the table does not grow again.', 'probe-site-doctor' ),
					__( 'Delete the revisions with one of the commands below, or with a maintenance plugin that lets you keep the most recent revisions per post.', 'probe-site-doctor' ),
				)
			) )
				->with_command( __( 'Limit future revisions (wp-config.php)', 'probe-site-doctor' ), "define( 'WP_POST_REVISIONS', 10 );", ManualCleanup::TYPE_PHP )
				->with_command( __( 'Delete all revisions with WP-CLI', 'probe-site-doctor' ), 'wp post delete $(wp post list --post_type=revision --format=ids) --force', ManualCleanup::TYPE_WP_CLI )
				->with_command(
					__( 'Delete all revisions and their meta with SQL', 'probe-site-doctor' ),
					"DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'revision';\n"
					. "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_type = 'revision';\n"
					. "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision';"
				)
		);
	}
}
