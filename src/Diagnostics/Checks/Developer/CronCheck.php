<?php
/**
 * Cron status check.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Checks\Developer;

use ProbeSiteDoctor\Developer\SystemInfo;
use ProbeSiteDoctor\Diagnostics\AbstractCheck;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\ManualCleanup;
use ProbeSiteDoctor\Diagnostics\Result;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Reviews the WP-Cron schedule: whether it is disabled, whether events are
 * running on time, and whether the core maintenance events exist.
 *
 * Site Doctor reads the stored schedule. It never requests wp-cron.php,
 * because that request would run the due tasks.
 */
final class CronCheck extends AbstractCheck {

	public function get_id(): string {
		return 'developer.cron';
	}

	public function get_category(): string {
		return CategoryRegistry::DEVELOPER;
	}

	public function get_label(): string {
		return __( 'Cron status', 'probe-site-doctor' );
	}

	public function get_description(): string {
		return __( 'Checks whether WP-Cron is enabled, whether scheduled events are overdue and whether core events are registered.', 'probe-site-doctor' );
	}

	public function run(): Result {
		$thresholds = $this->thresholds( array( 'overdue_warning' => 1 ) );
		$cron       = SystemInfo::cron();

		$data = array(
			'disable_wp_cron'   => $cron['disabled'],
			'alternate_wp_cron' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'events'            => $cron['total'],
			'overdue'           => count( $cron['overdue'] ),
			'overdue_events'    => $cron['overdue'],
			'missing_core'      => $cron['missing'],
			'duplicated_hooks'  => $cron['duplicates'],
			'next_event'        => $cron['next'] ? gmdate( 'c', (int) $cron['next'] ) : null,
			'note'              => __( 'wp-cron.php was not requested: doing so would run the due tasks, and Site Doctor never changes the site.', 'probe-site-doctor' ),
		);

		$overdue = count( $cron['overdue'] );

		if ( $cron['disabled'] ) {
			$severity_high = $overdue >= $thresholds['overdue_warning'];
			return $this->result(
				$severity_high ? Severity::WARNING : Severity::INFO,
				$severity_high
					? __( 'WP-Cron is disabled and events are overdue', 'probe-site-doctor' )
					: __( 'WP-Cron is disabled in wp-config.php', 'probe-site-doctor' ),
				$severity_high
					? sprintf(
						/* translators: 1: Number of overdue events, 2: Hook name. */
						__( 'DISABLE_WP_CRON is set, so WordPress does not spawn cron on page loads. %1$s event(s) are past due (oldest: %2$s), which means the replacement system cron is either missing or not reaching wp-cron.php.', 'probe-site-doctor' ),
						number_format_i18n( $overdue ),
						$cron['overdue'][0]['hook'] ?? ''
					)
					: __( 'DISABLE_WP_CRON is set, which is the recommended setup when a real system cron calls wp-cron.php on a schedule. Nothing is currently overdue, so that replacement appears to be working.', 'probe-site-doctor' ),
				$severity_high
					? __( 'Confirm the server cron entry exists and runs, for example every five minutes, and that it can reach wp-cron.php (or use WP-CLI).', 'probe-site-doctor' )
					: __( 'Keep the server cron entry documented for whoever maintains the site next.', 'probe-site-doctor' ),
				$data
			)->with_impact(
				$severity_high ? Impact::HIGH : Impact::LOW,
				$severity_high
					? __( 'Scheduled publishing, update checks, backups and e-mails stop happening until cron runs again.', 'probe-site-doctor' )
					: __( 'None while the system cron keeps running.', 'probe-site-doctor' )
			)->with_cleanup( $this->steps() );
		}

		if ( $overdue >= $thresholds['overdue_warning'] ) {
			return $this->warning(
				sprintf(
					/* translators: %s: Number of events. */
					_n( '%s scheduled event is overdue', '%s scheduled events are overdue', $overdue, 'probe-site-doctor' ),
					number_format_i18n( $overdue )
				),
				sprintf(
					/* translators: 1: Hook name, 2: How late it is. */
					__( 'WP-Cron runs on page visits, so a quiet site can fall behind — but the oldest overdue event here is %1$s, %2$s late. Common causes: no traffic, loopback requests blocked, a fatal error inside one of the callbacks, or a stuck cron lock.', 'probe-site-doctor' ),
					$cron['overdue'][0]['hook'] ?? '',
					$cron['overdue'][0]['late'] ?? ''
				),
				__( 'Run "wp cron event list --fields=hook,next_run_relative" to see the queue, then run the overdue hook manually to see whether it errors. A real system cron every five minutes fixes low-traffic sites.', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Scheduled posts, update checks and plugin background work are delayed or never run.', 'probe-site-doctor' ) )
				->with_cleanup( $this->steps() );
		}

		if ( $cron['missing'] ) {
			return $this->warning(
				__( 'Core maintenance events are not scheduled', 'probe-site-doctor' ),
				sprintf(
					/* translators: %s: Comma-separated hook names. */
					__( 'These core events have no next run: %s. WordPress normally reschedules them automatically, so their absence usually means the schedule was cleared, or a plugin unscheduled them.', 'probe-site-doctor' ),
					implode( ', ', $cron['missing'] )
				),
				__( 'Visit any page as a logged-out visitor to let WordPress reschedule them, or run "wp cron event list" to confirm. If they disappear again, look for a plugin calling wp_clear_scheduled_hook().', 'probe-site-doctor' ),
				$data
			)->with_impact( Impact::MEDIUM, __( 'Update checks and trash cleanup stop running, so the site silently drifts out of date.', 'probe-site-doctor' ) );
		}

		return $this->good(
			sprintf(
				/* translators: %s: Number of events. */
				_n( 'WP-Cron is running with %s scheduled event', 'WP-Cron is running with %s scheduled events', (int) $cron['total'], 'probe-site-doctor' ),
				number_format_i18n( (int) $cron['total'] )
			),
			sprintf(
				/* translators: %s: Date and time. */
				__( 'Nothing is overdue and the core maintenance events are registered. Next event due: %s.', 'probe-site-doctor' ),
				$cron['next'] ? gmdate( 'Y-m-d H:i', (int) $cron['next'] ) . ' UTC' : '—'
			),
			$data
		);
	}

	/**
	 * Manual instructions.
	 *
	 * @return ManualCleanup
	 */
	private function steps(): ManualCleanup {
		return ( new ManualCleanup(
			__( 'Cron is configured on the server and in wp-config.php. Site Doctor never runs, schedules or unschedules anything.', 'probe-site-doctor' ),
			array(
				__( 'Inspect the queue with WP-CLI to see what is late and why.', 'probe-site-doctor' ),
				__( 'For a reliable schedule, disable WP-Cron and call it from the system scheduler every five minutes.', 'probe-site-doctor' ),
				__( 'Run a single hook by hand to find a callback that throws.', 'probe-site-doctor' ),
			),
			false
		) )->as_hardening()
			->with_command( __( 'Inspect the queue', 'probe-site-doctor' ), 'wp cron event list --fields=hook,next_run_relative,recurrence', ManualCleanup::TYPE_WP_CLI )
			->with_command( __( 'Run one overdue hook', 'probe-site-doctor' ), 'wp cron event run REPLACE_WITH_HOOK_NAME', ManualCleanup::TYPE_WP_CLI )
			->with_command( __( 'wp-config.php, when a system cron takes over', 'probe-site-doctor' ), "define( 'DISABLE_WP_CRON', true );", ManualCleanup::TYPE_PHP )
			->with_command( __( 'crontab entry (every five minutes)', 'probe-site-doctor' ), '*/5 * * * * cd ' . ABSPATH . ' && wp cron event run --due-now >/dev/null 2>&1', ManualCleanup::TYPE_WP_CLI );
	}
}
