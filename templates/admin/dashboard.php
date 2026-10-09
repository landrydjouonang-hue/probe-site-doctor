<?php
/**
 * Dashboard template.
 *
 * @package ProbeSiteDoctor
 *
 * @var array<string,mixed> $vars Provided by DashboardPage::render().
 */

use ProbeSiteDoctor\Admin\Actions;
use ProbeSiteDoctor\Admin\Notices;
use ProbeSiteDoctor\Admin\View;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Reporting\ReportDocument;
use ProbeSiteDoctor\Reporting\Score;

defined( 'ABSPATH' ) || exit;

$probesd_report = $vars['report'];
?>
<div class="wrap probesd">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Probe Site Doctor', 'probe-site-doctor' ); ?></h1>
	<a href="<?php echo esc_url( $vars['report_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Health Report', 'probe-site-doctor' ); ?></a>
	<a href="<?php echo esc_url( $vars['pagespeed_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Page Speed', 'probe-site-doctor' ); ?></a>
	<a href="<?php echo esc_url( $vars['developer_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Developer', 'probe-site-doctor' ); ?></a>
	<a href="<?php echo esc_url( $vars['history_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Scan History', 'probe-site-doctor' ); ?></a>
	<hr class="wp-header-end">

	<?php Notices::render(); ?>

	<div class="probesd-scope" role="note">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<p>
			<strong><?php esc_html_e( 'Scope:', 'probe-site-doctor' ); ?></strong>
			<?php echo esc_html( ReportDocument::scope_notice() ); ?>
		</p>
	</div>

	<?php if ( $vars['not_found'] ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'That report does not exist or is not finished. Showing the latest report instead.', 'probe-site-doctor' ); ?></p></div>
	<?php endif; ?>

	<section class="probesd-panel probesd-launcher" aria-labelledby="probesd-launcher-title">
		<div class="probesd-launcher__text">
			<h2 id="probesd-launcher-title"><?php esc_html_e( 'Website health scan', 'probe-site-doctor' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: Number of checks. */
					esc_html( _n( '%s read-only check. The scan does not change your site.', '%s read-only checks. The scan does not change your site.', (int) $vars['check_count'], 'probe-site-doctor' ) ),
					esc_html( number_format_i18n( (int) $vars['check_count'] ) )
				);
				?>
			</p>
		</div>

		<?php if ( $vars['can_run'] ) : ?>
			<form id="probesd-run-form" class="probesd-launcher__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Actions::RUN ); ?>">
				<?php wp_nonce_field( Actions::RUN ); ?>
				<button type="submit" class="button button-primary button-hero" id="probesd-run-button">
					<?php echo $probesd_report ? esc_html__( 'Run a new scan', 'probe-site-doctor' ) : esc_html__( 'Run health scan', 'probe-site-doctor' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<div class="probesd-progress" id="probesd-progress" hidden>
			<progress id="probesd-progress-bar" max="100" value="0" aria-labelledby="probesd-progress-status"></progress>
			<p id="probesd-progress-status" class="probesd-progress__status" role="status" aria-live="polite"></p>
		</div>
	</section>

	<?php if ( ! $probesd_report ) : ?>
		<section class="probesd-panel probesd-empty">
			<span class="dashicons dashicons-heart" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'No reports yet', 'probe-site-doctor' ); ?></h2>
			<p><?php esc_html_e( 'Run your first scan to get a health score and a prioritised list of recommendations.', 'probe-site-doctor' ); ?></p>
		</section>
	<?php else : ?>
		<?php
		$probesd_scan   = $probesd_report['scan'];
		$probesd_counts = $probesd_report['counts'];
		$probesd_score  = $probesd_report['score'];
		$probesd_delta  = ( null !== $probesd_score && null !== $probesd_report['previous_score'] ) ? $probesd_score - (int) $probesd_report['previous_score'] : null;
		?>

		<?php if ( ! $vars['is_latest'] && $vars['latest_id'] ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'You are viewing an older report.', 'probe-site-doctor' ); ?>
					<a href="<?php echo esc_url( $vars['dashboard_url'] ); ?>"><?php esc_html_e( 'View the latest report', 'probe-site-doctor' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<section class="probesd-summary" aria-labelledby="probesd-summary-title">
			<h2 id="probesd-summary-title" class="screen-reader-text"><?php esc_html_e( 'Report summary', 'probe-site-doctor' ); ?></h2>

			<div class="probesd-panel probesd-score probesd-band--<?php echo esc_attr( $probesd_report['band'] ); ?>">
				<div class="probesd-score__ring" style="--probesd-score: <?php echo esc_attr( (string) (int) $probesd_score ); ?>;" aria-hidden="true">
					<span><?php echo null === $probesd_score ? '–' : esc_html( number_format_i18n( $probesd_score ) ); ?></span>
				</div>
				<div class="probesd-score__text">
					<p class="probesd-score__label">
						<?php
						if ( null === $probesd_score ) {
							esc_html_e( 'Health score: not available', 'probe-site-doctor' );
						} else {
							/* translators: %s: Score out of 100. */
							printf( esc_html__( 'Health score: %s out of 100', 'probe-site-doctor' ), esc_html( number_format_i18n( $probesd_score ) ) );
						}
						?>
					</p>
					<p class="probesd-score__band"><?php echo esc_html( $probesd_report['band_label'] ); ?></p>
					<?php if ( null !== $probesd_delta ) : ?>
						<p class="probesd-score__delta">
							<?php
							if ( 0 === $probesd_delta ) {
								esc_html_e( 'No change since the previous scan.', 'probe-site-doctor' );
							} elseif ( $probesd_delta > 0 ) {
								/* translators: %s: Points. */
								printf( esc_html__( 'Up %s points since the previous scan.', 'probe-site-doctor' ), esc_html( number_format_i18n( $probesd_delta ) ) );
							} else {
								/* translators: %s: Points. */
								printf( esc_html__( 'Down %s points since the previous scan.', 'probe-site-doctor' ), esc_html( number_format_i18n( abs( $probesd_delta ) ) ) );
							}
							?>
						</p>
					<?php endif; ?>
					<p class="probesd-meta">
						<?php
						printf(
							/* translators: 1: Date and time, 2: Relative time. */
							esc_html__( 'Scanned %1$s (%2$s)', 'probe-site-doctor' ),
							esc_html( View::datetime( $probesd_scan['finished_at'] ?? $probesd_scan['started_at'] ) ),
							esc_html( View::ago( $probesd_scan['finished_at'] ?? $probesd_scan['started_at'] ) )
						);
						?>
					</p>
					<p class="probesd-score__actions">
						<a class="button" href="<?php echo esc_url( $vars['report_url'] ); ?>"><?php esc_html_e( 'View the full health report', 'probe-site-doctor' ); ?></a>
					</p>
				</div>
			</div>

			<ul class="probesd-counts">
				<?php foreach ( Severity::all() as $probesd_severity ) : ?>
					<li class="probesd-panel probesd-count probesd-sev--<?php echo esc_attr( $probesd_severity ); ?>">
						<span class="dashicons <?php echo esc_attr( Severity::icon( $probesd_severity ) ); ?>" aria-hidden="true"></span>
						<span class="probesd-count__num"><?php echo esc_html( number_format_i18n( (int) $probesd_counts[ $probesd_severity ] ) ); ?></span>
						<span class="probesd-count__label"><?php echo esc_html( Severity::label( $probesd_severity ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<p class="probesd-score__note">
				<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
				<?php echo esc_html( Score::disclaimer() ); ?>
			</p>

			<?php if ( $probesd_counts['error'] > 0 ) : ?>
				<p class="probesd-errors-note">
					<?php
					printf(
						/* translators: %s: Number of checks. */
						esc_html( _n( '%s check could not be completed and is not included in the score.', '%s checks could not be completed and are not included in the score.', (int) $probesd_counts['error'], 'probe-site-doctor' ) ),
						esc_html( number_format_i18n( (int) $probesd_counts['error'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</section>

		<section aria-labelledby="probesd-categories-title">
			<h2 id="probesd-categories-title"><?php esc_html_e( 'By area', 'probe-site-doctor' ); ?></h2>
			<ul class="probesd-categories">
				<?php foreach ( $probesd_report['categories'] as $cat_id => $probesd_category ) : ?>
					<li class="probesd-panel probesd-category probesd-band--<?php echo esc_attr( $probesd_category['band'] ); ?>">
						<a href="#probesd-cat-<?php echo esc_attr( $cat_id ); ?>">
							<span class="dashicons <?php echo esc_attr( $probesd_category['icon'] ); ?>" aria-hidden="true"></span>
							<span class="probesd-category__label"><?php echo esc_html( $probesd_category['label'] ); ?></span>
							<span class="probesd-category__score">
								<?php
								if ( null === $probesd_category['score'] ) {
									echo esc_html( $probesd_category['results'] ? __( 'Informational only', 'probe-site-doctor' ) : __( 'No checks run', 'probe-site-doctor' ) );
								} else {
									/* translators: %s: Score out of 100. */
									printf( esc_html__( '%s / 100', 'probe-site-doctor' ), esc_html( number_format_i18n( $probesd_category['score'] ) ) );
								}
								?>
							</span>
							<span class="probesd-category__issues">
								<?php
								printf(
									/* translators: %s: Number of issues. */
									esc_html( _n( '%s issue', '%s issues', (int) $probesd_category['issues'], 'probe-site-doctor' ) ),
									esc_html( number_format_i18n( (int) $probesd_category['issues'] ) )
								);
								?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<?php if ( ! empty( $probesd_report['updates'] ) ) : ?>
			<?php
			$probesd_priority_labels  = array(
				'high'    => __( 'High', 'probe-site-doctor' ),
				'medium'  => __( 'Medium', 'probe-site-doctor' ),
				'low'     => __( 'Low', 'probe-site-doctor' ),
				'blocked' => __( 'Blocked', 'probe-site-doctor' ),
			);
			$probesd_component_labels = array(
				'core'   => __( 'WordPress', 'probe-site-doctor' ),
				'plugin' => __( 'Plugin', 'probe-site-doctor' ),
				'theme'  => __( 'Theme', 'probe-site-doctor' ),
				'php'    => __( 'PHP', 'probe-site-doctor' ),
			);
			$probesd_change_labels    = array(
				'major' => __( 'major', 'probe-site-doctor' ),
				'minor' => __( 'minor', 'probe-site-doctor' ),
				'patch' => __( 'patch', 'probe-site-doctor' ),
			);
			?>
			<section class="probesd-panel probesd-updates" aria-labelledby="probesd-updates-title">
				<h2 id="probesd-updates-title"><?php esc_html_e( 'Update recommendations', 'probe-site-doctor' ); ?></h2>
				<p class="probesd-updates__notice">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php esc_html_e( 'Site Doctor never installs updates. Make a backup first, then update from Dashboard → Updates (or with the manual steps in each finding), starting with high priority. Test major versions on a staging copy.', 'probe-site-doctor' ); ?>
				</p>
				<div class="probesd-table-scroll">
					<table class="widefat striped probesd-updates__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Priority', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Component', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Name', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Installed', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Recommended', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Notes', 'probe-site-doctor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $probesd_report['updates'] as $probesd_update ) : ?>
								<?php $probesd_priority = isset( $probesd_priority_labels[ $probesd_update['priority'] ] ) ? $probesd_update['priority'] : 'low'; ?>
								<tr>
									<td><span class="probesd-priority probesd-priority--<?php echo esc_attr( $probesd_priority ); ?>"><?php echo esc_html( $probesd_priority_labels[ $probesd_priority ] ); ?></span></td>
									<td><?php echo esc_html( $probesd_component_labels[ $probesd_update['component'] ?? '' ] ?? (string) ( $probesd_update['component'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) $probesd_update['name'] ); ?></td>
									<td><?php echo esc_html( (string) ( $probesd_update['installed'] ?? '' ) ); ?></td>
									<td>
										<?php echo esc_html( (string) ( $probesd_update['available'] ?? '' ) ); ?>
										<?php if ( ! empty( $probesd_update['change'] ) && isset( $probesd_change_labels[ $probesd_update['change'] ] ) ) : ?>
											<span class="probesd-change">(<?php echo esc_html( $probesd_change_labels[ $probesd_update['change'] ] ); ?>)</span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( (string) ( $probesd_update['note'] ?? '' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>
		<?php endif; ?>

		<section class="probesd-results" aria-labelledby="probesd-results-title">
			<div class="probesd-results__header">
				<h2 id="probesd-results-title"><?php esc_html_e( 'Findings', 'probe-site-doctor' ); ?></h2>
				<div class="probesd-filter" role="group" aria-label="<?php esc_attr_e( 'Filter findings by severity', 'probe-site-doctor' ); ?>" hidden>
					<button type="button" class="button" data-probesd-filter="all" aria-pressed="true"><?php esc_html_e( 'All', 'probe-site-doctor' ); ?></button>
					<?php foreach ( Severity::all() as $probesd_severity ) : ?>
						<button type="button" class="button" data-probesd-filter="<?php echo esc_attr( $probesd_severity ); ?>" aria-pressed="false">
							<?php echo esc_html( Severity::label( $probesd_severity ) ); ?>
							<span class="probesd-filter__count">(<?php echo esc_html( number_format_i18n( (int) $probesd_counts[ $probesd_severity ] ) ); ?>)</span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="probesd-measurement" role="note" aria-labelledby="probesd-measurement-title">
				<h3 id="probesd-measurement-title"><?php esc_html_e( 'How findings are measured', 'probe-site-doctor' ); ?></h3>
				<p><?php esc_html_e( 'Site Doctor runs on your web server. It diagnoses how the site is built and configured, and how quickly the server responds. It does not measure what visitors experience in their browser. Browser metrics such as Largest Contentful Paint, Interaction to Next Paint or Cumulative Layout Shift need a browser-based tool such as PageSpeed Insights, Lighthouse or your browser’s developer tools.', 'probe-site-doctor' ); ?></p>
				<dl>
					<?php foreach ( Measurement::all() as $probesd_method ) : ?>
						<dt>
							<span class="probesd-method probesd-method--<?php echo esc_attr( $probesd_method ); ?>">
								<span class="dashicons <?php echo esc_attr( Measurement::LOOPBACK === $probesd_method ? 'dashicons-update' : 'dashicons-admin-generic' ); ?>" aria-hidden="true"></span>
								<?php echo esc_html( Measurement::label( $probesd_method ) ); ?>
							</span>
						</dt>
						<dd><?php echo esc_html( Measurement::description( $probesd_method ) ); ?></dd>
					<?php endforeach; ?>
				</dl>
			</div>

			<?php foreach ( $probesd_report['categories'] as $cat_id => $probesd_category ) : ?>
				<?php
				if ( ! $probesd_category['results'] ) {
					continue;
				}
				?>
				<div class="probesd-results__group" id="probesd-cat-<?php echo esc_attr( $cat_id ); ?>" data-probesd-group>
					<h3>
						<span class="dashicons <?php echo esc_attr( $probesd_category['icon'] ); ?>" aria-hidden="true"></span>
						<?php echo esc_html( $probesd_category['label'] ); ?>
					</h3>
					<?php if ( '' !== (string) $probesd_category['description'] ) : ?>
						<p class="probesd-group__scope"><?php echo esc_html( $probesd_category['description'] ); ?></p>
					<?php endif; ?>
					<ul class="probesd-findings">
						<?php foreach ( $probesd_category['results'] as $probesd_result ) : ?>
							<?php
							$probesd_is_error   = 'completed' !== $probesd_result['status'];
							$probesd_sev_class  = $probesd_is_error ? 'error' : $probesd_result['severity'];
							$probesd_badge      = 'error' === $probesd_result['status'] ? __( 'Check failed', 'probe-site-doctor' ) : ( 'skipped' === $probesd_result['status'] ? __( 'Skipped', 'probe-site-doctor' ) : Severity::label( $probesd_result['severity'] ) );
							$probesd_check_name = $vars['check_labels'][ $probesd_result['check_id'] ] ?? $probesd_result['check_id'];
							?>
							<li class="probesd-panel probesd-finding probesd-sev--<?php echo esc_attr( $probesd_sev_class ); ?>" data-severity="<?php echo esc_attr( $probesd_is_error ? 'none' : $probesd_result['severity'] ); ?>">
								<div class="probesd-finding__head">
									<span class="probesd-badge probesd-sev--<?php echo esc_attr( $probesd_sev_class ); ?>">
										<span class="dashicons <?php echo esc_attr( $probesd_is_error ? 'dashicons-marker' : Severity::icon( $probesd_result['severity'] ) ); ?>" aria-hidden="true"></span>
										<span class="screen-reader-text"><?php esc_html_e( 'Severity:', 'probe-site-doctor' ); ?></span>
										<?php echo esc_html( $probesd_badge ); ?>
									</span>
									<h4 class="probesd-finding__title">
										<span class="screen-reader-text"><?php esc_html_e( 'Finding:', 'probe-site-doctor' ); ?></span>
										<?php echo esc_html( $probesd_result['title'] ); ?>
									</h4>
									<span class="probesd-finding__check"><?php echo esc_html( $probesd_check_name ); ?></span>
									<span class="probesd-method probesd-method--<?php echo esc_attr( $probesd_result['measurement'] ); ?>" title="<?php echo esc_attr( Measurement::description( $probesd_result['measurement'] ) ); ?>">
										<span class="dashicons <?php echo esc_attr( Measurement::LOOPBACK === $probesd_result['measurement'] ? 'dashicons-update' : 'dashicons-admin-generic' ); ?>" aria-hidden="true"></span>
										<?php echo esc_html( Measurement::label( $probesd_result['measurement'] ) ); ?>
									</span>
								</div>
								<?php if ( '' !== $probesd_result['message'] ) : ?>
									<p class="probesd-finding__message">
										<strong><?php esc_html_e( 'Explanation:', 'probe-site-doctor' ); ?></strong>
										<?php echo esc_html( $probesd_result['message'] ); ?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $probesd_result['impact'] ) ) : ?>
									<p class="probesd-finding__impact">
										<strong><?php esc_html_e( 'Estimated impact:', 'probe-site-doctor' ); ?></strong>
										<span class="probesd-impact probesd-impact--<?php echo esc_attr( $probesd_result['impact']['level'] ); ?>"><?php echo esc_html( Impact::label( $probesd_result['impact']['level'] ) ); ?></span>
										<?php echo esc_html( $probesd_result['impact']['summary'] ); ?>
									</p>
								<?php endif; ?>
								<?php if ( '' !== $probesd_result['recommendation'] ) : ?>
									<p class="probesd-finding__recommendation">
										<strong><?php esc_html_e( 'Recommended action:', 'probe-site-doctor' ); ?></strong>
										<?php echo esc_html( $probesd_result['recommendation'] ); ?>
									</p>
								<?php endif; ?>
								<?php if ( ! empty( $probesd_result['cleanup'] ) ) : ?>
									<?php
									$probesd_cleanup   = $probesd_result['cleanup'];
									$probesd_kind      = $probesd_cleanup['kind'] ?? 'cleanup';
									$probesd_is_update = 'update' === $probesd_kind;
									$probesd_is_harden  = 'harden' === $probesd_kind;
									$probesd_is_content = 'content' === $probesd_kind;
									if ( $probesd_is_update ) {
										$probesd_cleanup_title = __( 'How to update manually', 'probe-site-doctor' );
									} elseif ( $probesd_is_harden ) {
										$probesd_cleanup_title = __( 'How to change this manually', 'probe-site-doctor' );
									} elseif ( $probesd_is_content ) {
										$probesd_cleanup_title = __( 'How to fix this manually', 'probe-site-doctor' );
									} else {
										$probesd_cleanup_title = __( 'Optional manual cleanup', 'probe-site-doctor' );
									}
									?>
									<details class="probesd-cleanup">
										<summary><?php echo esc_html( $probesd_cleanup_title ); ?></summary>
										<div class="probesd-cleanup__notice<?php echo $probesd_cleanup['destructive'] ? ' is-destructive' : ''; ?>" role="note">
											<span class="dashicons <?php echo $probesd_cleanup['destructive'] ? 'dashicons-warning' : 'dashicons-info'; ?>" aria-hidden="true"></span>
											<p>
												<?php if ( $probesd_is_update ) : ?>
													<strong><?php esc_html_e( 'Site Doctor never updates anything automatically.', 'probe-site-doctor' ); ?></strong>
													<?php esc_html_e( 'These are the steps to do it yourself. Make and test a full backup first, and try major updates on a staging copy.', 'probe-site-doctor' ); ?>
												<?php elseif ( $probesd_is_content ) : ?>
													<strong><?php esc_html_e( 'Site Doctor never edits your content.', 'probe-site-doctor' ); ?></strong>
													<?php esc_html_e( 'These are the steps to do it yourself, in the WordPress editor or media library.', 'probe-site-doctor' ); ?>
												<?php elseif ( $probesd_is_harden ) : ?>
													<strong><?php esc_html_e( 'Site Doctor never changes settings automatically.', 'probe-site-doctor' ); ?></strong>
													<?php esc_html_e( 'These are the steps to change it yourself. Back up the file or setting first, and check the site afterwards — some of these settings can lock you out or disable features you rely on.', 'probe-site-doctor' ); ?>
												<?php else : ?>
													<?php if ( $probesd_cleanup['destructive'] ) : ?>
														<strong><?php esc_html_e( 'These steps permanently delete data.', 'probe-site-doctor' ); ?></strong>
													<?php else : ?>
														<strong><?php esc_html_e( 'These steps change the database structure.', 'probe-site-doctor' ); ?></strong>
													<?php endif; ?>
													<?php esc_html_e( 'Site Doctor never runs them for you and has not deleted anything. Make and test a full backup first, and run the commands yourself only if you understand them.', 'probe-site-doctor' ); ?>
												<?php endif; ?>
											</p>
										</div>
										<p><?php echo esc_html( $probesd_cleanup['summary'] ); ?></p>
										<?php if ( $probesd_cleanup['steps'] ) : ?>
											<ol class="probesd-cleanup__steps">
												<?php foreach ( $probesd_cleanup['steps'] as $probesd_step ) : ?>
													<li><?php echo esc_html( $probesd_step ); ?></li>
												<?php endforeach; ?>
											</ol>
										<?php endif; ?>
										<?php foreach ( $probesd_cleanup['commands'] as $probesd_command ) : ?>
											<div class="probesd-command">
												<div class="probesd-command__head">
													<span class="probesd-command__type"><?php echo esc_html( strtoupper( $probesd_command['type'] ) ); ?></span>
													<span class="probesd-command__label"><?php echo esc_html( $probesd_command['label'] ); ?></span>
													<button type="button" class="button button-small probesd-copy" hidden><?php esc_html_e( 'Copy', 'probe-site-doctor' ); ?></button>
												</div>
												<pre><code><?php echo esc_html( $probesd_command['command'] ); ?></code></pre>
											</div>
										<?php endforeach; ?>
									</details>
								<?php endif; ?>
								<?php if ( $probesd_result['data'] ) : ?>
									<details class="probesd-finding__details">
										<summary><?php esc_html_e( 'Details', 'probe-site-doctor' ); ?></summary>
										<?php echo View::data( $probesd_result['data'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in View::data(). ?>
									</details>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
			<p class="probesd-filter-empty" hidden><?php esc_html_e( 'No findings match this filter.', 'probe-site-doctor' ); ?></p>
		</section>

		<?php if ( ! empty( $probesd_scan['environment'] ) ) : ?>
			<details class="probesd-panel probesd-environment">
				<summary><?php esc_html_e( 'Environment at scan time', 'probe-site-doctor' ); ?></summary>
				<?php echo View::data( $probesd_scan['environment'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in View::data(). ?>
			</details>
		<?php endif; ?>
	<?php endif; ?>

	<details class="probesd-panel probesd-catalog">
		<summary><?php esc_html_e( 'What is checked', 'probe-site-doctor' ); ?></summary>
		<?php foreach ( $vars['categories'] as $cat_id => $probesd_category ) : ?>
			<h3><?php echo esc_html( $probesd_category['label'] ); ?></h3>
			<p class="description"><?php echo esc_html( $probesd_category['description'] ); ?></p>
			<?php if ( empty( $vars['checks_by_category'][ $cat_id ] ) ) : ?>
				<p><em><?php esc_html_e( 'No checks registered in this area yet.', 'probe-site-doctor' ); ?></em></p>
			<?php else : ?>
				<ul class="probesd-catalog__list">
					<?php foreach ( $vars['checks_by_category'][ $cat_id ] as $probesd_check ) : ?>
						<?php $probesd_method = $probesd_check instanceof \ProbeSiteDoctor\Diagnostics\Contracts\ReportsMeasurement ? Measurement::normalize( $probesd_check->get_measurement() ) : Measurement::SERVER; ?>
						<li>
							<strong><?php echo esc_html( $probesd_check->get_label() ); ?></strong> — <?php echo esc_html( $probesd_check->get_description() ); ?>
							<span class="probesd-method probesd-method--<?php echo esc_attr( $probesd_method ); ?>"><?php echo esc_html( Measurement::label( $probesd_method ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php endforeach; ?>
	</details>
</div>
