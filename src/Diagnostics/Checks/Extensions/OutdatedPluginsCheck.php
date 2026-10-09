<?php
/**
 * Outdated plugins check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Extensions;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\UpdateInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Lists plugins with an available update and recommends how to update them.
 * Never updates anything. Reads WordPress's cached update data only.
 */
final class OutdatedPluginsCheck extends AbstractCheck {

	public function get_id(): string {
		return 'extensions.outdated-plugins';
	}

	public function get_category(): string {
		return CategoryRegistry::EXTENSIONS;
	}

	public function get_label(): string {
		return __( 'Outdated plugins', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Lists plugins with an available update, the size of each update, and anything that blocks it.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$t    = $this->thresholds( array( 'critical_active' => 5 ) );
		$info = UpdateInfo::plugins();

		if ( ! $info['available'] ) {
			return $this->skipped( __( 'WordPress has not checked for plugin updates yet. Visit Dashboard → Updates and run the scan again.', 'probe-site-doctor' ) );
		}

		$wp_version = (string) get_bloginfo( 'version' );
		$recs       = array();
		$active_out = array();
		$inactive   = array();
		$blocked    = 0;
		$unknown    = array();
		$commands   = array();

		foreach ( $info['plugins'] as $plugin ) {
			if ( ! $plugin['wporg'] && ! $plugin['update'] ) {
				$unknown[] = $plugin['name'];
			}
			if ( ! $plugin['update'] || '' === $plugin['update']['version'] ) {
				continue;
			}

			$update = $plugin['update'];
			$change = UpdateInfo::change( $plugin['version'], $update['version'] );
			$notes  = array();
			$block  = false;

			if ( '' !== $update['requires_php'] && version_compare( PHP_VERSION, $update['requires_php'], '<' ) ) {
				$block   = true;
				/* translators: %s: PHP version. */
				$notes[] = sprintf( __( 'Blocked: needs PHP %s', 'probe-site-doctor' ), $update['requires_php'] );
			}
			if ( '' !== $update['requires_wp'] && version_compare( $wp_version, $update['requires_wp'], '<' ) ) {
				$block   = true;
				/* translators: %s: WordPress version. */
				$notes[] = sprintf( __( 'Blocked: needs WordPress %s', 'probe-site-doctor' ), $update['requires_wp'] );
			}
			if ( 'major' === $change ) {
				$notes[] = __( 'Major version: read the changelog and test on staging first', 'probe-site-doctor' );
			}
			if ( '' !== $update['tested'] ) {
				/* translators: %s: WordPress version. */
				$notes[] = sprintf( __( 'Tested up to WordPress %s', 'probe-site-doctor' ), $update['tested'] );
			}
			if ( $plugin['auto_update'] ) {
				$notes[] = __( 'Auto-updates are on; WordPress should install it soon', 'probe-site-doctor' );
			}
			if ( ! $plugin['active'] ) {
				$notes[] = __( 'Inactive: consider deleting instead', 'probe-site-doctor' );
			}

			$blocked += $block ? 1 : 0;
			$recs[]   = array(
				'priority'  => $block ? 'blocked' : ( $plugin['active'] ? ( 'major' === $change ? 'medium' : 'high' ) : 'low' ),
				'component' => 'plugin',
				'name'      => $plugin['name'],
				'installed' => $plugin['version'],
				'available' => $update['version'],
				'change'    => $change,
				'note'      => implode( '; ', $notes ),
			);

			if ( $plugin['active'] ) {
				$active_out[] = $plugin['name'];
			} else {
				$inactive[] = $plugin['name'];
			}
			if ( ! $block && count( $commands ) < 10 ) {
				$commands[] = $plugin['slug'];
			}
		}

		$recs = UpdateInfo::sort_recommendations( $recs );

		$data = array(
			'outdated_active'        => count( $active_out ),
			'outdated_inactive'      => count( $inactive ),
			'blocked_updates'        => $blocked,
			'update_recommendations' => $recs,
			'update_status_unknown'  => $unknown,
			'last_checked'           => $info['last_checked'] ? gmdate( 'c', $info['last_checked'] ) : null,
		);

		$stale = UpdateInfo::is_stale( $info['last_checked'] )
			? ' ' . __( 'WordPress last checked for updates more than three days ago, so newer updates may exist. Visit Dashboard → Updates to refresh.', 'probe-site-doctor' )
			: '';
		$unknown_note = $unknown
			? ' ' . sprintf(
				/* translators: %s: Number of plugins. */
				_n( '%s plugin is not listed on WordPress.org (premium or custom), so its update status is unknown here; check with its vendor.', '%s plugins are not listed on WordPress.org (premium or custom), so their update status is unknown here; check with their vendors.', count( $unknown ), 'probe-site-doctor' ),
				number_format_i18n( count( $unknown ) )
			)
			: '';

		if ( ! $recs ) {
			if ( $stale ) {
				return $this->info( __( 'No plugin updates found, but the update data is old', 'probe-site-doctor' ), __( 'WordPress reports no plugin updates.', 'probe-site-doctor' ) . $stale . $unknown_note, __( 'Refresh the update data under Dashboard → Updates and run the scan again.', 'probe-site-doctor' ), $data );
			}
			return $this->good( __( 'All plugins are up to date', 'probe-site-doctor' ), __( 'WordPress reports no plugin updates.', 'probe-site-doctor' ) . $unknown_note, $data );
		}

		if ( count( $active_out ) >= $t['critical_active'] ) {
			$severity = Severity::CRITICAL;
		} elseif ( $active_out ) {
			$severity = Severity::WARNING;
		} else {
			$severity = Severity::INFO;
		}

		$message = sprintf(
			/* translators: 1: Active count, 2: Inactive count. */
			__( '%1$s active and %2$s inactive plugins have updates available. Plugin updates regularly fix security vulnerabilities and compatibility problems.', 'probe-site-doctor' ),
			number_format_i18n( count( $active_out ) ),
			number_format_i18n( count( $inactive ) )
		);
		if ( $blocked ) {
			$message .= ' ' . sprintf(
				/* translators: %s: Number of updates. */
				_n( '%s update cannot be installed until PHP or WordPress is upgraded.', '%s updates cannot be installed until PHP or WordPress is upgraded.', $blocked, 'probe-site-doctor' ),
				number_format_i18n( $blocked )
			);
		}

		$cleanup = ( new ManualCleanup(
			__( 'Install the updates yourself. Site Doctor never updates plugins automatically.', 'probe-site-doctor' ),
			array(
				__( 'Make a full backup (files and database).', 'probe-site-doctor' ),
				__( 'For major versions, read the changelog and test the update on a staging copy first.', 'probe-site-doctor' ),
				__( 'Update from Dashboard → Updates or Plugins, one plugin at a time for important sites, and check the site after each update.', 'probe-site-doctor' ),
				__( 'Delete inactive plugins you no longer need instead of updating them.', 'probe-site-doctor' ),
			),
			false
		) )->as_update();
		if ( $commands ) {
			$cleanup = $cleanup->with_command( __( 'Update the listed plugins with WP-CLI', 'probe-site-doctor' ), 'wp plugin update ' . implode( ' ', $commands ), ManualCleanup::TYPE_WP_CLI );
		}
		$cleanup = $cleanup->with_command( __( 'Preview available plugin updates with WP-CLI (changes nothing)', 'probe-site-doctor' ), 'wp plugin update --all --dry-run', ManualCleanup::TYPE_WP_CLI );

		return $this->result(
			$severity,
			$active_out ? __( 'Active plugins have updates available', 'probe-site-doctor' ) : __( 'Inactive plugins have updates available', 'probe-site-doctor' ),
			$message . $stale . $unknown_note,
			__( 'Back up the site, then update the plugins in the order shown in the update recommendations: high priority first. Test major versions on staging.', 'probe-site-doctor' ),
			$data
		)->with_impact(
			Severity::CRITICAL === $severity ? Impact::HIGH : ( $active_out ? Impact::MEDIUM : Impact::LOW ),
			$active_out
				? __( 'Plugin updates often fix publicly disclosed vulnerabilities, and outdated plugins are a frequent cause of compromised WordPress sites. They can also break when WordPress or PHP is updated.', 'probe-site-doctor' )
				: __( 'Inactive plugins do not run, but their files remain on the server and can still contain exploitable code.', 'probe-site-doctor' )
		)->with_cleanup( $cleanup );
	}
}
