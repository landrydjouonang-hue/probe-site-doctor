<?php
/**
 * Postmeta volume check.
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
 * Measures the post meta table: row count, size, meta per post, the keys
 * that use the most rows and space, and whether the meta_key index exists.
 */
final class PostmetaVolumeCheck extends AbstractCheck {

	public function get_id(): string {
		return 'database.postmeta';
	}

	public function get_category(): string {
		return CategoryRegistry::DATABASE;
	}

	public function get_label(): string {
		return __( 'Post meta volume', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Measures the size of the post meta table, the keys that dominate it, and its indexes.', 'probe-site-doctor' );
	}

	public function run(): Result {
		global $wpdb;

		$t = $this->thresholds(
			array(
				'rows_warning'       => 1000000,
				'rows_critical'      => 10000000,
				'per_post_warning'   => 60,
				'min_rows_ratio'     => 50000,
				'breakdown_max_rows' => 2000000,
			)
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		$posts = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type NOT IN ('revision')" );

		$indexes     = array_unique( wp_list_pluck( (array) $wpdb->get_results( "SHOW INDEX FROM {$wpdb->postmeta}" ), 'Column_name' ) );
		$has_key_idx = in_array( 'meta_key', $indexes, true );
		$has_post_ix = in_array( 'post_id', $indexes, true );

		// The per-key breakdown needs a full scan, so it is skipped on very large tables.
		$breakdown = array();
		if ( $rows > 0 && $rows <= $t['breakdown_max_rows'] ) {
			$breakdown = (array) $wpdb->get_results(
				"SELECT meta_key, COUNT(*) AS total, COALESCE(SUM(LENGTH(meta_value)), 0) AS bytes
				FROM {$wpdb->postmeta} GROUP BY meta_key ORDER BY total DESC LIMIT 10",
				ARRAY_A
			);
		}
		// phpcs:enable

		$table    = DatabaseInfo::table( $wpdb->postmeta );
		$per_post = $posts > 0 ? round( $rows / $posts, 1 ) : (float) $rows;

		$data = array(
			'rows'           => $rows,
			'table_bytes'    => $table ? (int) $table['bytes'] : null,
			'posts'          => $posts,
			'meta_per_post'  => $per_post,
			'meta_key_index' => $has_key_idx,
			'post_id_index'  => $has_post_ix,
			'top_keys'       => array_map(
				static fn( $row ) => array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A reported value, not a query argument.
				'meta_key'   => (string) $row['meta_key'],
					'rows'       => (int) $row['total'],
					'size_bytes' => (int) $row['bytes'],
				),
				$breakdown
			),
		);
		if ( $rows > $t['breakdown_max_rows'] ) {
			$data['top_keys_note'] = __( 'Key breakdown skipped because the table is very large.', 'probe-site-doctor' );
		}

		$severity = Severity::GOOD;
		$issues   = array();

		if ( $rows >= $t['rows_critical'] ) {
			$severity = Severity::CRITICAL;
		} elseif ( $rows >= $t['rows_warning'] ) {
			$severity = Severity::WARNING;
		}
		if ( $per_post >= $t['per_post_warning'] && $rows >= $t['min_rows_ratio'] ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			$issues[] = sprintf(
				/* translators: %s: Number. */
				__( 'Posts carry %s meta rows each on average, which usually means a plugin stores data per post that it no longer needs.', 'probe-site-doctor' ),
				number_format_i18n( $per_post, 1 )
			);
		}
		if ( ! $has_key_idx || ! $has_post_ix ) {
			$severity = $this->worst( $severity, Severity::WARNING );
			$issues[] = __( 'A standard index on the post meta table (meta_key or post_id) is missing, so meta queries have to scan the whole table.', 'probe-site-doctor' );
		}

		$summary = sprintf(
			/* translators: 1: Row count, 2: Size, 3: Posts, 4: Average. */
			__( 'The post meta table holds %1$s rows (%2$s) for %3$s posts, about %4$s per post.', 'probe-site-doctor' ),
			number_format_i18n( $rows ),
			$table ? size_format( (int) $table['bytes'], 1 ) : __( 'size unknown', 'probe-site-doctor' ),
			number_format_i18n( $posts ),
			number_format_i18n( $per_post, 1 )
		);

		if ( Severity::GOOD === $severity ) {
			return $this->good( __( 'Post meta volume is normal', 'probe-site-doctor' ), $summary, $data );
		}

		$missing_index = ! $has_key_idx || ! $has_post_ix;
		$top_key       = $breakdown ? (string) $breakdown[0]['meta_key'] : 'REPLACE_WITH_META_KEY';

		return $this->result(
			$severity,
			$missing_index ? __( 'The post meta table is missing an index', 'probe-site-doctor' ) : __( 'The post meta table is very large', 'probe-site-doctor' ),
			trim( $summary . ' ' . implode( ' ', $issues ) ),
			$missing_index
				? __( 'Ask your host or developer to restore the standard WordPress indexes on the post meta table (running the database repair/upgrade routine after a backup usually restores them).', 'probe-site-doctor' )
				: __( 'Review the keys in the details. Keys belonging to plugins you have removed can usually be deleted. Keys of active plugins should be reduced through the plugin’s own settings.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			$missing_index || Severity::CRITICAL === $severity ? Impact::HIGH : Impact::MEDIUM,
			__( 'WordPress reads post meta on almost every page, and many plugins filter posts by meta values. A very large or poorly indexed meta table slows these queries directly.', 'probe-site-doctor' )
		)->with_cleanup(
			( new ManualCleanup(
				__( 'Delete meta keys that belong to plugins or features you no longer use. Deleting keys that an active plugin still uses will break that plugin’s data.', 'probe-site-doctor' ),
				array(
					__( 'Identify which plugin owns each large key (keys starting with an underscore are usually internal to a plugin).', 'probe-site-doctor' ),
					__( 'Confirm that the plugin is permanently removed or the data is not needed.', 'probe-site-doctor' ),
					__( 'Back up the database, then delete that key only.', 'probe-site-doctor' ),
				)
			) )
				->with_command(
					__( 'Template: count rows for one key before deleting it', 'probe-site-doctor' ),
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql( $top_key ) . "';"
				)
				->with_command(
					__( 'Template: delete one unused key (replace the key and double-check it)', 'probe-site-doctor' ),
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'REPLACE_WITH_UNUSED_META_KEY';"
				)
				->with_command(
					__( 'Template: the same deletion through WP-CLI (replace the key)', 'probe-site-doctor' ),
					"wp db query \"DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'REPLACE_WITH_UNUSED_META_KEY';\"",
					ManualCleanup::TYPE_WP_CLI
				)
		);
	}
}
