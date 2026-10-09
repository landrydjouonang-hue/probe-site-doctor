<?php
/**
 * Developer screen: system information and the exportable diagnostic report.
 *
 * @package ProbeSiteDoctor
 *
 * @var array<string,mixed> $vars Provided by DeveloperPage::render().
 */

use ProbeSiteDoctor\Admin\Notices;

defined( 'ABSPATH' ) || exit;

/**
 * Formats one value for display.
 *
 * @param mixed $probesd_value Value.
 * @return string
 */
$probesd_format = static function ( $probesd_value ): string {
	if ( is_bool( $probesd_value ) ) {
		return $probesd_value ? esc_html__( 'Yes', 'probe-site-doctor' ) : esc_html__( 'No', 'probe-site-doctor' );
	}
	if ( null === $probesd_value || '' === $probesd_value ) {
		return '&mdash;';
	}
	if ( is_array( $probesd_value ) ) {
		return esc_html( implode( ', ', array_map( 'strval', $probesd_value ) ) );
	}
	return esc_html( (string) $probesd_value );
};
?>
<div class="wrap probesd probesd-developer">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Developer', 'probe-site-doctor' ); ?></h1>
	<a href="<?php echo esc_url( $vars['dashboard_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Dashboard', 'probe-site-doctor' ); ?></a>
	<a href="<?php echo esc_url( $vars['pagespeed_url'] ); ?>" class="page-title-action"><?php esc_html_e( 'Page Speed', 'probe-site-doctor' ); ?></a>
	<hr class="wp-header-end">

	<?php Notices::render(); ?>

	<div class="probesd-scope" role="note">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<p>
			<strong><?php esc_html_e( 'For whoever maintains this site:', 'probe-site-doctor' ); ?></strong>
			<?php esc_html_e( 'Everything below is read from this install, live, at page load. Authentication keys, salts and the database password are never read, and the debug log’s contents are never included — only its size and timestamp. Copy or download it as a diagnostic report to attach to a support ticket.', 'probe-site-doctor' ); ?>
		</p>
	</div>

	<section class="probesd-panel probesd-export" aria-labelledby="probesd-export-title">
		<h2 id="probesd-export-title"><?php esc_html_e( 'Exportable diagnostic report', 'probe-site-doctor' ); ?></h2>
		<p>
			<?php
			echo $vars['has_scan']
				? esc_html__( 'The report contains the system information below, plus the findings of the latest health scan that still need attention.', 'probe-site-doctor' )
				: esc_html__( 'The report contains the system information below. Run a health scan to include its findings as well.', 'probe-site-doctor' );
			?>
		</p>
		<p class="probesd-export__actions">
			<button type="button" class="button button-primary" id="probesd-copy-diagnostics" data-copied="<?php esc_attr_e( 'Copied', 'probe-site-doctor' ); ?>"><?php esc_html_e( 'Copy report to clipboard', 'probe-site-doctor' ); ?></button>
			<a class="button" href="<?php echo esc_url( $vars['download_md'] ); ?>"><?php esc_html_e( 'Download .md', 'probe-site-doctor' ); ?></a>
			<a class="button" href="<?php echo esc_url( $vars['download_json'] ); ?>"><?php esc_html_e( 'Download .json', 'probe-site-doctor' ); ?></a>
		</p>
		<details class="probesd-export__preview">
			<summary><?php esc_html_e( 'Preview the report text', 'probe-site-doctor' ); ?></summary>
			<textarea id="probesd-diagnostics" rows="18" readonly spellcheck="false" aria-label="<?php esc_attr_e( 'Diagnostic report', 'probe-site-doctor' ); ?>"><?php echo esc_textarea( $vars['markdown'] ); ?></textarea>
		</details>
	</section>

	<nav class="probesd-jump" aria-label="<?php esc_attr_e( 'Jump to a section', 'probe-site-doctor' ); ?>">
		<?php foreach ( $vars['groups'] as $id => $probesd_group ) : ?>
			<a href="#probesd-info-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $probesd_group['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php foreach ( $vars['groups'] as $id => $probesd_group ) : ?>
		<section class="probesd-panel probesd-info" id="probesd-info-<?php echo esc_attr( $id ); ?>" aria-labelledby="probesd-info-<?php echo esc_attr( $id ); ?>-title">
			<h2 id="probesd-info-<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $probesd_group['label'] ); ?></h2>
			<?php if ( '' !== (string) $probesd_group['note'] ) : ?>
				<p class="description"><?php echo esc_html( $probesd_group['note'] ); ?></p>
			<?php endif; ?>

			<?php if ( $probesd_group['fields'] ) : ?>
				<dl class="probesd-info__fields">
					<?php foreach ( $probesd_group['fields'] as $probesd_label => $probesd_value ) : ?>
						<div>
							<dt><?php echo esc_html( (string) $probesd_label ); ?></dt>
							<dd><?php echo $probesd_format( $probesd_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			<?php endif; ?>

			<?php if ( $probesd_group['rows'] ) : ?>
				<?php $probesd_columns = array_keys( (array) reset( $probesd_group['rows'] ) ); ?>
				<div class="probesd-table-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<?php foreach ( $probesd_columns as $probesd_column ) : ?>
									<th scope="col"><?php echo esc_html( ucfirst( str_replace( array( '_', '-' ), ' ', (string) $probesd_column ) ) ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $probesd_group['rows'] as $probesd_row ) : ?>
								<tr>
									<?php foreach ( $probesd_columns as $probesd_column ) : ?>
										<td><?php echo $probesd_format( is_array( $probesd_row ) ? ( $probesd_row[ $probesd_column ] ?? null ) : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?></td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
