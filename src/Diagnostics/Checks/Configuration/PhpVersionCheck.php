<?php
/**
 * PHP version support check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Configuration;

use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Diagnostics\Support\UpdateInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the running PHP branch against its upstream end-of-life date, and
 * reports updates that the current PHP version blocks. Never changes PHP.
 */
final class PhpVersionCheck extends AbstractCheck {

	/** Warn this many days before the branch stops receiving security fixes. */
	private const WARN_DAYS = 180;

	public function get_id(): string {
		return 'configuration.php-version';
	}

	public function get_category(): string {
		return CategoryRegistry::CONFIGURATION;
	}

	public function get_label(): string {
		return __( 'PHP version support', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether the PHP version still receives security updates, and whether a newer, faster PHP branch is available.', 'probe-site-doctor' );
	}

	/**
	 * Security-support end dates per PHP branch (php.net/supported-versions).
	 *
	 * @return array<string,string>
	 */
	private function eol_dates(): array {
		$dates = array(
			'7.4' => '2022-11-28',
			'8.0' => '2023-11-26',
			'8.1' => '2025-12-31',
			'8.2' => '2026-12-31',
			'8.3' => '2027-12-31',
			'8.4' => '2028-12-31',
			'8.5' => '2029-12-31',
		);

		/**
		 * Filters the PHP branch end-of-life dates (Y-m-d) used by the PHP version check.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,string> $dates Dates keyed by "major.minor".
		 */
		return (array) apply_filters( 'probesd_php_eol_dates', $dates );
	}

	public function run(): Result {
		$branch   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$dates    = $this->eol_dates();
		$branches = array_map( 'strval', array_keys( $dates ) );
		usort( $branches, 'version_compare' );
		$newest = (string) end( $branches );

		$context = $this->component_context();
		$data    = array(
			'version'                        => PHP_VERSION,
			'branch'                         => $branch,
			'eol'                            => $dates[ $branch ] ?? null,
			'newest_known_branch'            => $newest,
			'updates_blocked_by_php'         => $context['blocked'],
			'highest_php_required_installed' => $context['highest'],
		);

		$eol_label = '';
		if ( ! isset( $dates[ $branch ] ) ) {
			$status = version_compare( $branch, (string) reset( $branches ), '<' ) ? 'unsupported' : 'current';
		} else {
			$eol       = (int) strtotime( $dates[ $branch ] . ' 23:59:59 UTC' );
			$days_left = (int) floor( ( $eol - time() ) / DAY_IN_SECONDS );
			$eol_label = date_i18n( get_option( 'date_format' ), $eol );
			$status    = $days_left < 0 ? 'eol' : ( $days_left <= self::WARN_DAYS ? 'soon' : 'ok' );
		}

		$blocked_note = $context['blocked']
			? ' ' . sprintf(
				/* translators: %s: Number of updates. */
				_n( '%s plugin or theme update cannot be installed until PHP is upgraded.', '%s plugin or theme updates cannot be installed until PHP is upgraded.', count( $context['blocked'] ), 'probe-site-doctor' ),
				number_format_i18n( count( $context['blocked'] ) )
			)
			: '';

		switch ( $status ) {
			case 'unsupported':
				$severity = Severity::CRITICAL;
				$title    = __( 'PHP version is no longer supported', 'probe-site-doctor' );
				/* translators: %s: PHP version. */
				$message = sprintf( __( 'PHP %s stopped receiving security updates years ago.', 'probe-site-doctor' ), PHP_VERSION );
				break;
			case 'eol':
				$severity = Severity::CRITICAL;
				$title    = __( 'PHP version no longer receives security updates', 'probe-site-doctor' );
				/* translators: 1: PHP version, 2: End-of-life date. */
				$message = sprintf( __( 'The site runs PHP %1$s. Security support for this branch ended on %2$s, so newly discovered PHP vulnerabilities are not fixed.', 'probe-site-doctor' ), PHP_VERSION, $eol_label );
				break;
			case 'soon':
				$severity = Severity::WARNING;
				$title    = __( 'PHP version reaches end of life soon', 'probe-site-doctor' );
				/* translators: 1: PHP version, 2: End-of-life date. */
				$message = sprintf( __( 'The site runs PHP %1$s, which stops receiving security updates on %2$s.', 'probe-site-doctor' ), PHP_VERSION, $eol_label );
				break;
			default:
				$severity = $context['blocked'] ? Severity::INFO : Severity::GOOD;
				$title    = $context['blocked'] ? __( 'PHP is supported, but some updates need a newer version', 'probe-site-doctor' ) : ( 'current' === $status ? __( 'PHP version is current', 'probe-site-doctor' ) : __( 'PHP version is supported', 'probe-site-doctor' ) );
				$message  = '' !== $eol_label
					/* translators: 1: PHP version, 2: End-of-life date. */
					? sprintf( __( 'The site runs PHP %1$s, supported with security updates until %2$s.', 'probe-site-doctor' ), PHP_VERSION, $eol_label )
					/* translators: %s: PHP version. */
					: sprintf( __( 'The site runs PHP %s.', 'probe-site-doctor' ), PHP_VERSION );
				if ( version_compare( $newest, $branch, '>' ) ) {
					/* translators: %s: PHP branch such as 8.4. */
					$message .= ' ' . sprintf( __( 'PHP %s is available; newer PHP branches usually run WordPress faster.', 'probe-site-doctor' ), $newest );
				}
		}

		if ( Severity::GOOD === $severity ) {
			return $this->good( $title, $message, $data );
		}

		$target = $this->recommended_branch( $dates, $context['highest'] );

		$data['update_recommendations'] = array(
			array(
				'priority'  => Severity::CRITICAL === $severity ? 'high' : ( Severity::WARNING === $severity ? 'medium' : 'low' ),
				'component' => 'php',
				'name'      => 'PHP',
				'installed' => PHP_VERSION,
				'available' => $target,
				// No "minor/major" tag: PHP branch numbering does not map to that scale, and any branch change is significant.
				'change'    => '',
				'note'      => __( 'Changed by your host or server administrator, not from WordPress', 'probe-site-doctor' ),
			),
		);

		$steps = ( new ManualCleanup(
			__( 'Upgrade PHP through your hosting control panel or server administrator. Site Doctor cannot and does not change PHP.', 'probe-site-doctor' ),
			array(
				__( 'Update WordPress, plugins and themes first; current versions support newer PHP best.', 'probe-site-doctor' ),
				/* translators: %s: PHP branch. */
				sprintf( __( 'On a staging copy, switch to PHP %s and test the front end, admin, forms and checkout.', 'probe-site-doctor' ), $target ),
				__( 'Check the PHP error log on staging for deprecation warnings and fatal errors.', 'probe-site-doctor' ),
				__( 'Switch the live site, then keep an eye on the error log for a few days.', 'probe-site-doctor' ),
			),
			false
		) )->as_update()->with_command( __( 'Show the PHP version and extensions WordPress runs with (WP-CLI, changes nothing)', 'probe-site-doctor' ), 'wp --info', ManualCleanup::TYPE_WP_CLI );

		return $this->result(
			$severity,
			$title,
			$message . $blocked_note,
			$this->recommendation( $target ),
			$data
		)->with_impact(
			Severity::CRITICAL === $severity ? Impact::HIGH : ( Severity::WARNING === $severity ? Impact::MEDIUM : Impact::LOW ),
			__( 'PHP runs every request: an unsupported version gets no security fixes, blocks plugin and theme updates, and is usually slower than current releases.', 'probe-site-doctor' )
		)->with_cleanup( $steps );
	}

	/**
	 * Updates blocked by PHP, and the highest PHP version installed components require.
	 *
	 * @return array{blocked:string[],highest:string}
	 */
	private function component_context(): array {
		$blocked = array();
		$highest = '';

		foreach ( UpdateInfo::plugins()['plugins'] as $plugin ) {
			$highest = $this->max_version( $highest, $plugin['requires_php'] );
			if ( $plugin['update'] && '' !== $plugin['update']['requires_php'] ) {
				$highest = $this->max_version( $highest, $plugin['update']['requires_php'] );
				if ( version_compare( PHP_VERSION, $plugin['update']['requires_php'], '<' ) ) {
					$blocked[] = $plugin['name'];
				}
			}
		}
		foreach ( UpdateInfo::themes()['themes'] as $theme ) {
			$highest = $this->max_version( $highest, $theme['requires_php'] );
			if ( $theme['update'] && '' !== $theme['update']['requires_php'] && version_compare( PHP_VERSION, $theme['update']['requires_php'], '<' ) ) {
				$blocked[] = $theme['name'];
			}
		}

		return array(
			'blocked' => $blocked,
			'highest' => $highest,
		);
	}

	/**
	 * Oldest branch that is supported for at least a year and meets every
	 * component requirement; falls back to the newest known branch.
	 *
	 * @param array<string,string> $dates   EOL dates.
	 * @param string               $minimum Highest version required by components.
	 * @return string
	 */
	private function recommended_branch( array $dates, string $minimum ): string {
		uksort( $dates, 'version_compare' );
		foreach ( $dates as $branch => $date ) {
			if ( strtotime( $date ) > time() + YEAR_IN_SECONDS && ( '' === $minimum || version_compare( $branch . '.99', $minimum, '>=' ) ) ) {
				return (string) $branch;
			}
		}
		$keys = array_keys( $dates );
		return (string) end( $keys );
	}

	/**
	 * Larger of two version strings (empty strings ignored).
	 *
	 * @param string $a Version.
	 * @param string $b Version.
	 * @return string
	 */
	private function max_version( string $a, string $b ): string {
		if ( '' === $b ) {
			return $a;
		}
		return '' === $a || version_compare( $b, $a, '>' ) ? $b : $a;
	}

	/**
	 * Shared recommendation.
	 *
	 * @param string $target Recommended branch.
	 * @return string
	 */
	private function recommendation( string $target ): string {
		return sprintf(
			/* translators: %s: PHP branch. */
			__( 'Plan an upgrade to PHP %s (or newer) with your host; newer PHP branches are also usually faster. Test on a staging copy first to confirm your theme and plugins are compatible.', 'probe-site-doctor' ),
			$target
		);
	}
}