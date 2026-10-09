<?php
/**
 * Website Health Report screen.
 *
 * @package ProbeSiteDoctor
 *
 * @var array<string,mixed> $vars Provided by ReportPage::render().
 */

use ProbeSiteDoctor\Admin\Actions;
use ProbeSiteDoctor\Admin\Notices;
use ProbeSiteDoctor\Admin\View;

defined( 'ABSPATH' ) || exit;

$probesd_document = $vars['document'];
?>
<div class="wrap probesd probesd-report">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Website Health Report', 'probe-site-doctor' ); ?></h1>
	<a href="<?php echo esc_url( $vars['dashboard_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Dashboard', 'probe-site-doctor' ); ?></a>
	<a href="<?php echo esc_url( $vars['history_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Scan History', 'probe-site-doctor' ); ?></a>
	<hr class="wp-header-end">

	<?php Notices::render(); ?>

	<?php if ( $vars['not_found'] ) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'That scan does not exist or has not finished, so no report could be generated. Showing the latest report instead.', 'probe-site-doctor' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="probesd-report__toolbar">
		<?php if ( $vars['scans'] ) : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="probesd-report__picker">
				<input type="hidden" name="page" value="<?php echo esc_attr( \ProbeSiteDoctor\Admin\Admin::SLUG_REPORT ); ?>">
				<label for="probesd-report-scan"><?php esc_html_e( 'Report for scan', 'probe-site-doctor' ); ?></label>
				<select name="scan" id="probesd-report-scan">
					<?php foreach ( $vars['scans'] as $probesd_option ) : ?>
						<option value="<?php echo esc_attr( (string) $probesd_option['id'] ); ?>" <?php selected( (int) $vars['selected_id'], (int) $probesd_option['id'] ); ?>>
							<?php
							printf(
								/* translators: 1: Date and time, 2: Score or dash, 3: Scan ID. */
								esc_html__( '%1$s — score %2$s (#%3$s)', 'probe-site-doctor' ),
								esc_html( View::datetime( $probesd_option['finished_at'] ?? $probesd_option['started_at'] ) ),
								esc_html( null === $probesd_option['score'] ? '—' : number_format_i18n( (int) $probesd_option['score'] ) ),
								esc_html( number_format_i18n( (int) $probesd_option['id'] ) )
							);
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Generate report', 'probe-site-doctor' ); ?></button>
			</form>
		<?php endif; ?>

		<div class="probesd-report__tools">
			<?php if ( $probesd_document ) : ?>
				<button type="button" class="button button-primary" id="probesd-print"><?php esc_html_e( 'Print / Save as PDF', 'probe-site-doctor' ); ?></button>
				<a class="button" href="<?php echo esc_url( $vars['download_url'] ); ?>"><?php esc_html_e( 'Download HTML', 'probe-site-doctor' ); ?></a>
			<?php endif; ?>
			<?php if ( $vars['can_run'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="probesd-report__run">
					<input type="hidden" name="action" value="<?php echo esc_attr( Actions::RUN ); ?>">
					<input type="hidden" name="probesd_redirect" value="report">
					<?php wp_nonce_field( Actions::RUN ); ?>
					<button type="submit" class="button"><?php echo $probesd_document ? esc_html__( 'Run a new scan', 'probe-site-doctor' ) : esc_html__( 'Run a scan', 'probe-site-doctor' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<?php if ( $probesd_document ) : ?>
			<p class="probesd-report__hint">
				<?php esc_html_e( 'Printing produces the same document; use your browser’s “Save as PDF” destination to keep a copy. The download is a single HTML file with its styling included, so it can be archived or emailed and printed later.', 'probe-site-doctor' ); ?>
				<?php if ( ! $vars['is_latest'] ) : ?>
					<a href="<?php echo esc_url( $vars['report_url'] ); ?>"><?php esc_html_e( 'Report on the latest scan', 'probe-site-doctor' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( ! $probesd_document ) : ?>
		<div class="probesd-panel probesd-empty">
			<span class="dashicons dashicons-media-text" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'No report to show yet', 'probe-site-doctor' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: Number of checks. */
					esc_html( _n( 'A report is generated from a completed scan of %s read-only check. Run a scan to produce one.', 'A report is generated from a completed scan of %s read-only checks. Run a scan to produce one.', (int) $vars['check_count'], 'probe-site-doctor' ) ),
					esc_html( number_format_i18n( (int) $vars['check_count'] ) )
				);
				?>
			</p>
			<p><a href="<?php echo esc_url( $vars['dashboard_url'] ); ?>"><?php esc_html_e( 'Go to the dashboard', 'probe-site-doctor' ); ?></a></p>
		</div>
	<?php else : ?>
		<?php
		View::render(
			'report/document',
			array(
				'document'     => $probesd_document,
				'check_labels' => $vars['check_labels'],
				'context'      => 'screen',
			)
		);
		?>
	<?php endif; ?>
</div>
