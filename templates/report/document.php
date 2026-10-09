<?php
/**
 * Website Health Report — document body.
 *
 * Chrome-free on purpose: the same markup is shown on the admin report screen,
 * sent to the printer and written into the downloadable HTML file.
 *
 * @package ProbeSiteDoctor
 *
 * @var array<string,mixed> $vars document, check_labels, context (screen|file).
 */

use ProbeSiteDoctor\Admin\View;
use ProbeSiteDoctor\Diagnostics\Impact;
use ProbeSiteDoctor\Diagnostics\Measurement;
use ProbeSiteDoctor\Diagnostics\Severity;

defined( 'ABSPATH' ) || exit;

$probesd_document = $vars['document'];
$probesd_meta     = $probesd_document['meta'];
$probesd_summary  = $probesd_document['summary'];
$probesd_counts   = $probesd_summary['counts'];
$probesd_labels   = (array) $vars['check_labels'];
$probesd_context  = 'file' === ( $vars['context'] ?? 'screen' ) ? 'file' : 'screen';

$probesd_priority_limit = 12;
$probesd_priorities     = $probesd_summary['priorities'];
$probesd_priority_shown = array_slice( $probesd_priorities, 0, $probesd_priority_limit );

$probesd_severity_labels = array();
foreach ( Severity::all() as $probesd_sev ) {
	$probesd_severity_labels[ $probesd_sev ] = Severity::label( $probesd_sev );
}
?>
<article class="sdr sdr--<?php echo esc_attr( $probesd_context ); ?>">

	<header class="sdr__header">
		<p class="sdr__eyebrow"><?php esc_html_e( 'Website Health Report', 'probe-site-doctor' ); ?></p>
		<h1 class="sdr__site"><?php echo esc_html( '' !== $probesd_meta['site_name'] ? $probesd_meta['site_name'] : $probesd_meta['site_url'] ); ?></h1>
		<p class="sdr__url"><?php echo esc_html( $probesd_meta['site_url'] ); ?></p>

		<dl class="sdr__meta">
			<div>
				<dt><?php esc_html_e( 'Scan date', 'probe-site-doctor' ); ?></dt>
				<dd><?php echo esc_html( View::datetime( $probesd_meta['scanned_at'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Report generated', 'probe-site-doctor' ); ?></dt>
				<dd><?php echo esc_html( View::datetime( $probesd_meta['generated_at'] ) ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Requested by', 'probe-site-doctor' ); ?></dt>
				<dd><?php echo esc_html( $probesd_meta['generated_by'] ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Scan reference', 'probe-site-doctor' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: %s: Scan ID. */
						esc_html__( '#%s', 'probe-site-doctor' ),
						esc_html( number_format_i18n( $probesd_meta['scan_id'] ) )
					);
					?>
				</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Produced by', 'probe-site-doctor' ); ?></dt>
				<dd>
					<?php
					printf(
						/* translators: %s: Plugin version. */
						esc_html__( 'Probe Site Doctor %s', 'probe-site-doctor' ),
						esc_html( $probesd_meta['plugin_version'] )
					);
					?>
				</dd>
			</div>
			<?php if ( ! empty( $probesd_meta['environment']['environment_type'] ) ) : ?>
				<div>
					<dt><?php esc_html_e( 'Environment', 'probe-site-doctor' ); ?></dt>
					<dd><?php echo esc_html( (string) $probesd_meta['environment']['environment_type'] ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
	</header>

	<div class="sdr__note" role="note">
		<strong><?php esc_html_e( 'What this report covers:', 'probe-site-doctor' ); ?></strong>
		<?php echo esc_html( $probesd_summary['scope'] ); ?>
	</div>

	<section class="sdr-block" id="sdr-summary" aria-labelledby="sdr-summary-title">
		<h2 id="sdr-summary-title"><?php esc_html_e( 'Overall diagnostic summary', 'probe-site-doctor' ); ?></h2>

		<div class="sdr-overview">
			<p class="sdr-overview__score sdr-band--<?php echo esc_attr( $probesd_summary['band'] ); ?>">
				<span class="sdr-overview__number"><?php echo null === $probesd_summary['score'] ? '&ndash;' : esc_html( number_format_i18n( $probesd_summary['score'] ) ); ?></span>
				<span class="sdr-overview__out"><?php esc_html_e( 'out of 100', 'probe-site-doctor' ); ?></span>
				<span class="sdr-overview__band"><?php echo esc_html( $probesd_summary['band_label'] ); ?></span>
			</p>
			<div class="sdr-overview__text">
				<p class="sdr-overview__headline"><?php echo esc_html( $probesd_summary['headline'] ); ?></p>
				<p>
					<?php
					printf(
						/* translators: 1: Number of checks run, 2: Number of checks included in the score. */
						esc_html__( '%1$s checks ran; %2$s of them could be scored.', 'probe-site-doctor' ),
						esc_html( number_format_i18n( $probesd_summary['checks_total'] ) ),
						esc_html( number_format_i18n( $probesd_summary['scored_checks'] ) )
					);
					?>
					<?php if ( null !== $probesd_summary['delta'] ) : ?>
						<?php
						if ( 0 === $probesd_summary['delta'] ) {
							esc_html_e( 'The score is unchanged since the previous scan.', 'probe-site-doctor' );
						} elseif ( $probesd_summary['delta'] > 0 ) {
							printf(
								/* translators: %s: Number of points. */
								esc_html__( 'The score is up %s points since the previous scan.', 'probe-site-doctor' ),
								esc_html( number_format_i18n( $probesd_summary['delta'] ) )
							);
						} else {
							printf(
								/* translators: %s: Number of points. */
								esc_html__( 'The score is down %s points since the previous scan.', 'probe-site-doctor' ),
								esc_html( number_format_i18n( abs( $probesd_summary['delta'] ) ) )
							);
						}
						?>
					<?php endif; ?>
				</p>
				<ul class="sdr-counts">
					<?php foreach ( Severity::all() as $probesd_severity ) : ?>
						<li class="sdr-sev--<?php echo esc_attr( $probesd_severity ); ?>">
							<span class="sdr-counts__num"><?php echo esc_html( number_format_i18n( (int) $probesd_counts[ $probesd_severity ] ) ); ?></span>
							<span class="sdr-counts__label"><?php echo esc_html( $probesd_severity_labels[ $probesd_severity ] ); ?></span>
						</li>
					<?php endforeach; ?>
					<?php if ( $probesd_counts['skipped'] > 0 || $probesd_counts['error'] > 0 ) : ?>
						<li class="sdr-sev--none">
							<span class="sdr-counts__num"><?php echo esc_html( number_format_i18n( (int) $probesd_counts['skipped'] + (int) $probesd_counts['error'] ) ); ?></span>
							<span class="sdr-counts__label"><?php esc_html_e( 'Not evaluated', 'probe-site-doctor' ); ?></span>
						</li>
					<?php endif; ?>
				</ul>
			</div>
		</div>

		<p class="sdr-fine"><?php echo esc_html( $probesd_summary['explanation'] ); ?></p>

		<div class="sdr__note sdr__note--strong" role="note">
			<strong><?php esc_html_e( 'How to read the score:', 'probe-site-doctor' ); ?></strong>
			<?php echo esc_html( $probesd_summary['disclaimer'] ); ?>
		</div>
	</section>

	<?php if ( $probesd_priorities ) : ?>
		<section class="sdr-block" id="sdr-priorities" aria-labelledby="sdr-priorities-title">
			<h2 id="sdr-priorities-title"><?php esc_html_e( 'Priority recommendations', 'probe-site-doctor' ); ?></h2>
			<p class="sdr-fine"><?php esc_html_e( 'Critical findings first, then warnings. Each one is explained in full in its section below. Site Doctor has not changed anything on the site.', 'probe-site-doctor' ); ?></p>
			<table class="sdr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Severity', 'probe-site-doctor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Section', 'probe-site-doctor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Finding', 'probe-site-doctor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Recommended action', 'probe-site-doctor' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Impact', 'probe-site-doctor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $probesd_priority_shown as $probesd_item ) : ?>
						<tr>
							<td><span class="sdr-badge sdr-sev--<?php echo esc_attr( $probesd_item['severity'] ); ?>"><?php echo esc_html( $probesd_severity_labels[ $probesd_item['severity'] ] ?? $probesd_item['severity'] ); ?></span></td>
							<td><?php echo esc_html( $probesd_item['section'] ); ?></td>
							<td><?php echo esc_html( $probesd_item['title'] ); ?></td>
							<td><?php echo esc_html( $probesd_item['recommendation'] ); ?></td>
							<td><?php echo '' === $probesd_item['impact'] ? '&mdash;' : esc_html( Impact::label( $probesd_item['impact'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( count( $probesd_priorities ) > $probesd_priority_limit ) : ?>
				<p class="sdr-fine">
					<?php
					printf(
						/* translators: %s: Number of findings. */
						esc_html( _n( '%s further finding is listed in the sections below.', '%s further findings are listed in the sections below.', count( $probesd_priorities ) - $probesd_priority_limit, 'probe-site-doctor' ) ),
						esc_html( number_format_i18n( count( $probesd_priorities ) - $probesd_priority_limit ) )
					);
					?>
				</p>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<section class="sdr-block" id="sdr-categories" aria-labelledby="sdr-categories-title">
		<h2 id="sdr-categories-title"><?php esc_html_e( 'Section summaries', 'probe-site-doctor' ); ?></h2>
		<table class="sdr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Section', 'probe-site-doctor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'probe-site-doctor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Critical', 'probe-site-doctor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Warning', 'probe-site-doctor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Information', 'probe-site-doctor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Good', 'probe-site-doctor' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Assessment', 'probe-site-doctor' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $probesd_document['sections'] as $probesd_section ) : ?>
					<tr>
						<th scope="row"><a href="#sdr-section-<?php echo esc_attr( $probesd_section['id'] ); ?>"><?php echo esc_html( $probesd_section['label'] ); ?></a></th>
						<td class="sdr-band--<?php echo esc_attr( $probesd_section['band'] ); ?>">
							<?php
							if ( null === $probesd_section['score'] ) {
								echo esc_html( $probesd_section['checks'] ? __( 'Not scored', 'probe-site-doctor' ) : __( 'No checks', 'probe-site-doctor' ) );
							} else {
								printf(
									/* translators: %s: Score out of 100. */
									esc_html__( '%s / 100', 'probe-site-doctor' ),
									esc_html( number_format_i18n( $probesd_section['score'] ) )
								);
							}
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $probesd_section['counts'][ Severity::CRITICAL ] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $probesd_section['counts'][ Severity::WARNING ] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $probesd_section['counts'][ Severity::INFO ] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) $probesd_section['counts'][ Severity::GOOD ] ) ); ?></td>
						<td><?php echo esc_html( $probesd_section['assessment'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<?php if ( ! empty( $probesd_document['updates'] ) ) : ?>
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
		?>
		<section class="sdr-block" id="sdr-updates" aria-labelledby="sdr-updates-title">
			<h2 id="sdr-updates-title"><?php esc_html_e( 'Update recommendations', 'probe-site-doctor' ); ?></h2>
			<div class="sdr__note" role="note">
				<?php esc_html_e( 'Site Doctor never installs updates. Make and test a backup first, then update from Dashboard → Updates, starting with high priority, and try major versions on a staging copy. Update data comes from WordPress’s own last update check.', 'probe-site-doctor' ); ?>
			</div>
			<table class="sdr-table">
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
					<?php foreach ( $probesd_document['updates'] as $probesd_update ) : ?>
						<?php $probesd_priority = isset( $probesd_priority_labels[ $probesd_update['priority'] ] ) ? $probesd_update['priority'] : 'low'; ?>
						<tr>
							<td><span class="sdr-badge sdr-priority--<?php echo esc_attr( $probesd_priority ); ?>"><?php echo esc_html( $probesd_priority_labels[ $probesd_priority ] ); ?></span></td>
							<td><?php echo esc_html( $probesd_component_labels[ $probesd_update['component'] ?? '' ] ?? (string) ( $probesd_update['component'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) $probesd_update['name'] ); ?></td>
							<td><?php echo esc_html( (string) ( $probesd_update['installed'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $probesd_update['available'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $probesd_update['note'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
	<?php endif; ?>

	<?php foreach ( $probesd_document['sections'] as $probesd_section ) : ?>
		<?php
		$probesd_issues    = array();
		$probesd_passed    = array();
		$probesd_unchecked = array();
		foreach ( $probesd_section['results'] as $probesd_result ) {
			if ( 'completed' !== $probesd_result['status'] ) {
				$probesd_unchecked[] = $probesd_result;
			} elseif ( Severity::GOOD === $probesd_result['severity'] ) {
				$probesd_passed[] = $probesd_result;
			} else {
				$probesd_issues[] = $probesd_result;
			}
		}
		?>
		<section class="sdr-block sdr-section" id="sdr-section-<?php echo esc_attr( $probesd_section['id'] ); ?>" aria-labelledby="sdr-section-<?php echo esc_attr( $probesd_section['id'] ); ?>-title">
			<div class="sdr-section__head">
				<h2 id="sdr-section-<?php echo esc_attr( $probesd_section['id'] ); ?>-title"><?php echo esc_html( $probesd_section['label'] ); ?></h2>
				<p class="sdr-section__score sdr-band--<?php echo esc_attr( $probesd_section['band'] ); ?>">
					<?php
					if ( null === $probesd_section['score'] ) {
						echo esc_html( $probesd_section['checks'] ? __( 'Not scored', 'probe-site-doctor' ) : __( 'No checks', 'probe-site-doctor' ) );
					} else {
						printf(
							/* translators: %s: Score out of 100. */
							esc_html__( '%s / 100', 'probe-site-doctor' ),
							esc_html( number_format_i18n( $probesd_section['score'] ) )
						);
					}
					?>
				</p>
			</div>
			<?php if ( '' !== $probesd_section['intro'] ) : ?>
				<p class="sdr-section__intro"><?php echo esc_html( $probesd_section['intro'] ); ?></p>
			<?php endif; ?>
			<p class="sdr-section__assessment"><?php echo esc_html( $probesd_section['assessment'] ); ?></p>
			<?php if ( '' !== $probesd_section['scope'] ) : ?>
				<div class="sdr__note sdr__note--scope" role="note">
					<strong><?php esc_html_e( 'Scope:', 'probe-site-doctor' ); ?></strong>
					<?php echo esc_html( $probesd_section['scope'] ); ?>
				</div>
			<?php endif; ?>

			<?php if ( ! $probesd_section['results'] ) : ?>
				<p class="sdr-empty"><?php esc_html_e( 'This report contains no findings for this section.', 'probe-site-doctor' ); ?></p>
			<?php endif; ?>

			<?php foreach ( $probesd_issues as $probesd_result ) : ?>
				<?php $probesd_cleanup = $probesd_result['cleanup']; ?>
				<div class="sdr-finding sdr-sev--<?php echo esc_attr( $probesd_result['severity'] ); ?>">
					<div class="sdr-finding__head">
						<span class="sdr-badge sdr-sev--<?php echo esc_attr( $probesd_result['severity'] ); ?>">
							<span class="sdr-sr"><?php esc_html_e( 'Severity:', 'probe-site-doctor' ); ?></span>
							<?php echo esc_html( $probesd_severity_labels[ $probesd_result['severity'] ] ?? $probesd_result['severity'] ); ?>
						</span>
						<h3 class="sdr-finding__title"><?php echo esc_html( $probesd_result['title'] ); ?></h3>
					</div>
					<p class="sdr-finding__check">
						<?php echo esc_html( $probesd_labels[ $probesd_result['check_id'] ] ?? $probesd_result['check_id'] ); ?>
						<span class="sdr-method"><?php echo esc_html( Measurement::label( $probesd_result['measurement'] ) ); ?></span>
					</p>
					<?php if ( '' !== $probesd_result['message'] ) : ?>
						<p><strong><?php esc_html_e( 'Explanation:', 'probe-site-doctor' ); ?></strong> <?php echo esc_html( $probesd_result['message'] ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $probesd_result['impact'] ) ) : ?>
						<p>
							<strong><?php esc_html_e( 'Estimated impact:', 'probe-site-doctor' ); ?></strong>
							<span class="sdr-badge sdr-impact--<?php echo esc_attr( $probesd_result['impact']['level'] ); ?>"><?php echo esc_html( Impact::label( $probesd_result['impact']['level'] ) ); ?></span>
							<?php echo esc_html( $probesd_result['impact']['summary'] ); ?>
						</p>
					<?php endif; ?>
					<?php if ( '' !== $probesd_result['recommendation'] ) : ?>
						<p><strong><?php esc_html_e( 'Recommended action:', 'probe-site-doctor' ); ?></strong> <?php echo esc_html( $probesd_result['recommendation'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $probesd_cleanup ) ) : ?>
						<?php
						$probesd_kind = $probesd_cleanup['kind'] ?? 'cleanup';
						if ( 'update' === $probesd_kind ) {
							$probesd_cleanup_title  = __( 'How to update manually', 'probe-site-doctor' );
							$probesd_cleanup_notice = __( 'Site Doctor never updates anything automatically. Make and test a full backup first, and try major updates on a staging copy.', 'probe-site-doctor' );
						} elseif ( 'harden' === $probesd_kind ) {
							$probesd_cleanup_title  = __( 'How to change this manually', 'probe-site-doctor' );
							$probesd_cleanup_notice = __( 'Site Doctor never changes settings automatically. Back up the file or setting first and check the site afterwards — some of these settings can lock you out or disable features you rely on.', 'probe-site-doctor' );
						} elseif ( 'content' === $probesd_kind ) {
							$probesd_cleanup_title  = __( 'How to fix this manually', 'probe-site-doctor' );
							$probesd_cleanup_notice = __( 'Site Doctor never edits your content. These are the steps to do it yourself, in the WordPress editor or media library.', 'probe-site-doctor' );
						} elseif ( $probesd_cleanup['destructive'] ) {
							$probesd_cleanup_title  = __( 'Optional manual cleanup', 'probe-site-doctor' );
							$probesd_cleanup_notice = __( 'These steps permanently delete data. Site Doctor has not deleted anything and never runs them for you. Make and test a full backup first, and run them yourself only if you understand them.', 'probe-site-doctor' );
						} else {
							$probesd_cleanup_title  = __( 'Optional manual cleanup', 'probe-site-doctor' );
							$probesd_cleanup_notice = __( 'These steps change the database structure. Site Doctor never runs them for you. Make and test a full backup first, and run them yourself only if you understand them.', 'probe-site-doctor' );
						}
						?>
						<div class="sdr-manual<?php echo $probesd_cleanup['destructive'] ? ' is-destructive' : ''; ?>">
							<h4><?php echo esc_html( $probesd_cleanup_title ); ?></h4>
							<p class="sdr-manual__notice"><?php echo esc_html( $probesd_cleanup_notice ); ?></p>
							<?php if ( '' !== $probesd_cleanup['summary'] ) : ?>
								<p><?php echo esc_html( $probesd_cleanup['summary'] ); ?></p>
							<?php endif; ?>
							<?php if ( $probesd_cleanup['steps'] ) : ?>
								<ol>
									<?php foreach ( $probesd_cleanup['steps'] as $probesd_step ) : ?>
										<li><?php echo esc_html( $probesd_step ); ?></li>
									<?php endforeach; ?>
								</ol>
							<?php endif; ?>
							<?php foreach ( $probesd_cleanup['commands'] as $probesd_command ) : ?>
								<p class="sdr-command__label"><?php echo esc_html( strtoupper( $probesd_command['type'] ) ); ?> — <?php echo esc_html( $probesd_command['label'] ); ?></p>
								<pre class="sdr-command"><code><?php echo esc_html( $probesd_command['command'] ); ?></code></pre>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( $probesd_result['data'] ) : ?>
						<div class="sdr-details">
							<h4><?php esc_html_e( 'Measured values', 'probe-site-doctor' ); ?></h4>
							<?php echo View::data( $probesd_result['data'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in View::data(). ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<?php if ( $probesd_passed ) : ?>
				<h3 class="sdr-subhead">
					<?php
					printf(
						/* translators: %s: Number of checks. */
						esc_html( _n( '%s check passed', '%s checks passed', count( $probesd_passed ), 'probe-site-doctor' ) ),
						esc_html( number_format_i18n( count( $probesd_passed ) ) )
					);
					?>
				</h3>
				<table class="sdr-table sdr-table--compact">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Check', 'probe-site-doctor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'probe-site-doctor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $probesd_passed as $probesd_result ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $probesd_labels[ $probesd_result['check_id'] ] ?? $probesd_result['check_id'] ); ?></th>
								<td><?php echo esc_html( '' !== $probesd_result['message'] ? $probesd_result['message'] : $probesd_result['title'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $probesd_unchecked ) : ?>
				<h3 class="sdr-subhead"><?php esc_html_e( 'Not evaluated', 'probe-site-doctor' ); ?></h3>
				<p class="sdr-fine"><?php esc_html_e( 'These checks did not produce a result and are excluded from the score.', 'probe-site-doctor' ); ?></p>
				<table class="sdr-table sdr-table--compact">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Check', 'probe-site-doctor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'probe-site-doctor' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'probe-site-doctor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $probesd_unchecked as $probesd_result ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $probesd_labels[ $probesd_result['check_id'] ] ?? $probesd_result['check_id'] ); ?></th>
								<td><?php echo esc_html( 'error' === $probesd_result['status'] ? __( 'Check failed', 'probe-site-doctor' ) : __( 'Skipped', 'probe-site-doctor' ) ); ?></td>
								<td><?php echo esc_html( '' !== $probesd_result['message'] ? $probesd_result['message'] : $probesd_result['title'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>

	<?php if ( ! empty( $probesd_meta['environment'] ) ) : ?>
		<section class="sdr-block" id="sdr-environment" aria-labelledby="sdr-environment-title">
			<h2 id="sdr-environment-title"><?php esc_html_e( 'Environment at scan time', 'probe-site-doctor' ); ?></h2>
			<?php echo View::data( $probesd_meta['environment'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in View::data(). ?>
		</section>
	<?php endif; ?>

	<footer class="sdr__footer">
		<p>
			<?php
			printf(
				/* translators: 1: Plugin version, 2: Scan reference, 3: Date and time. */
				esc_html__( 'Generated by Probe Site Doctor %1$s from scan #%2$s on %3$s.', 'probe-site-doctor' ),
				esc_html( $probesd_meta['plugin_version'] ),
				esc_html( number_format_i18n( $probesd_meta['scan_id'] ) ),
				esc_html( View::datetime( $probesd_meta['generated_at'] ) )
			);
			?>
		</p>
		<p><?php echo esc_html( $probesd_summary['disclaimer'] ); ?></p>
	</footer>
</article>
