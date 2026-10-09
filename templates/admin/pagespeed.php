<?php
/**
 * Page Speed screen: per-page analysis and browser field data.
 *
 * @package ProbeSiteDoctor
 *
 * @var array<string,mixed> $vars Provided by PageSpeedPage::render().
 */

use ProbeSiteDoctor\Admin\Actions;
use ProbeSiteDoctor\Admin\Notices;
use ProbeSiteDoctor\Diagnostics\Severity;
use ProbeSiteDoctor\Performance\Vitals;

defined( 'ABSPATH' ) || exit;

$probesd_analysis = $vars['analysis'];

/**
 * Renders one field-data table.
 *
 * @param array<string,array<string,mixed>> $summary Summary per metric.
 * @param string                            $caption Caption.
 * @return void
 */
$probesd_field_table = static function ( array $summary, string $caption ): void {
	?>
	<table class="widefat striped probesd-vitals">
		<caption><?php echo esc_html( $caption ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Metric', 'probe-site-doctor' ); ?></th>
				<th scope="col"><?php esc_html_e( '75th percentile', 'probe-site-doctor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Rating', 'probe-site-doctor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Samples', 'probe-site-doctor' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it means', 'probe-site-doctor' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( Vitals::METRICS as $metric ) : ?>
				<?php $probesd_row = $summary[ $metric ] ?? array( 'p75' => null, 'samples' => 0, 'rating' => '' ); ?>
				<tr>
					<th scope="row"><?php echo esc_html( Vitals::label( $metric ) ); ?> <code><?php echo esc_html( strtoupper( $metric ) ); ?></code></th>
					<td><strong><?php echo esc_html( Vitals::format( $metric, null === $probesd_row['p75'] ? null : (float) $probesd_row['p75'] ) ); ?></strong></td>
					<td>
						<?php if ( '' !== $probesd_row['rating'] ) : ?>
							<span class="probesd-rating probesd-rating--<?php echo esc_attr( $probesd_row['rating'] ); ?>"><?php echo esc_html( Vitals::rating_label( $probesd_row['rating'] ) ); ?></span>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $probesd_row['samples'] ) ); ?></td>
					<td class="probesd-vitals__note"><?php echo esc_html( Vitals::description( $metric ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
};
?>
<div class="wrap probesd probesd-pagespeed">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Page Speed', 'probe-site-doctor' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=probe-site-doctor' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Dashboard', 'probe-site-doctor' ); ?></a>
	<hr class="wp-header-end">

	<?php Notices::render(); ?>

	<div class="probesd-scope" role="note">
		<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
		<p>
			<strong><?php esc_html_e( 'Two different things on this screen:', 'probe-site-doctor' ); ?></strong>
			<?php esc_html_e( 'The analysis below is made on the server — it fetches the page as an anonymous visitor and explains the work the page gives a browser, which is what you can actually fix. The Core Web Vitals section reports LCP, INP and CLS as measured in real visitors’ browsers, which is the only way those metrics can be known. Server-side numbers here are never presented as page-load times.', 'probe-site-doctor' ); ?>
		</p>
	</div>

	<?php if ( $vars['rejected'] ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( 'That URL is not part of this site, so it was not requested. Site Doctor only analyses pages on this domain.', 'probe-site-doctor' ); ?></p></div>
	<?php endif; ?>

	<section class="probesd-panel probesd-analyzer" aria-labelledby="probesd-analyzer-title">
		<h2 id="probesd-analyzer-title"><?php esc_html_e( 'Analyse a page', 'probe-site-doctor' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="probesd-analyzer__form">
			<input type="hidden" name="page" value="probe-site-doctor-pagespeed">
			<label for="probesd-url"><?php esc_html_e( 'Page URL', 'probe-site-doctor' ); ?></label>
			<input type="url" id="probesd-url" name="url" value="<?php echo esc_attr( $vars['url'] ); ?>" class="regular-text code" list="probesd-url-list" required>
			<datalist id="probesd-url-list">
				<?php foreach ( $vars['candidates'] as $probesd_candidate ) : ?>
					<option value="<?php echo esc_attr( $probesd_candidate['url'] ); ?>"><?php echo esc_attr( $probesd_candidate['label'] ); ?></option>
				<?php endforeach; ?>
			</datalist>
			<button type="submit" class="button button-primary" name="analyze" value="1"><?php esc_html_e( 'Analyse page', 'probe-site-doctor' ); ?></button>
		</form>
		<p class="probesd-analyzer__quick">
			<?php esc_html_e( 'Quick picks:', 'probe-site-doctor' ); ?>
			<?php foreach ( $vars['candidates'] as $probesd_candidate ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'probe-site-doctor-pagespeed', 'url' => $probesd_candidate['url'], 'analyze' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $probesd_candidate['label'] ); ?></a>
			<?php endforeach; ?>
		</p>
		<p class="description"><?php esc_html_e( 'Each analysis makes a handful of requests to your own site: the page twice (to see whether a cache answers) and up to three of its asset files to read their caching headers. Nothing is changed.', 'probe-site-doctor' ); ?></p>
		<p>
			<a class="button" href="<?php echo esc_url( $vars['psi_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open this URL in PageSpeed Insights', 'probe-site-doctor' ); ?></a>
			<span class="description"><?php esc_html_e( 'A lab test run in Google’s own browser, plus Chrome field data where Google has enough of it. Site Doctor sends your URLs nowhere — the link just opens the tool.', 'probe-site-doctor' ); ?></span>
		</p>
	</section>

	<?php if ( $probesd_analysis && empty( $probesd_analysis['ok'] ) ) : ?>
		<div class="notice notice-error inline">
			<p>
				<?php
				printf(
					/* translators: 1: URL, 2: Error message. */
					esc_html__( '%1$s could not be analysed: %2$s', 'probe-site-doctor' ),
					esc_html( (string) $probesd_analysis['url'] ),
					esc_html( (string) $probesd_analysis['error'] )
				);
				?>
			</p>
		</div>
	<?php elseif ( $probesd_analysis ) : ?>
		<?php $probesd_totals = $probesd_analysis['totals']; ?>
		<section class="probesd-panel" aria-labelledby="probesd-measured-title">
			<h2 id="probesd-measured-title"><?php esc_html_e( 'What the server sends', 'probe-site-doctor' ); ?></h2>
			<p class="probesd-analyzer__target"><code><?php echo esc_html( (string) $probesd_analysis['url'] ); ?></code></p>
			<ul class="probesd-metrics">
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( number_format_i18n( (int) $probesd_analysis['timing']['first_ms'] ) ); ?> ms</span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'Server response (first)', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( number_format_i18n( (int) $probesd_analysis['timing']['repeat_ms'] ) ); ?> ms</span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'Server response (repeat)', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( size_format( (int) $probesd_totals['total_bytes'], 1 ) ); ?></span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'HTML + local assets', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( number_format_i18n( (int) $probesd_totals['requests'] ) ); ?></span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'Referenced files', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( size_format( (int) $probesd_totals['js_bytes'], 1 ) ); ?></span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'JavaScript', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( size_format( (int) $probesd_totals['css_bytes'], 1 ) ); ?></span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'CSS', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( size_format( (int) $probesd_totals['image_bytes'], 1 ) ); ?></span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'Images', 'probe-site-doctor' ); ?></span>
				</li>
				<li>
					<span class="probesd-metrics__num"><?php echo esc_html( number_format_i18n( (int) $probesd_totals['blocking_js'] + (int) $probesd_totals['blocking_css'] ) ); ?></span>
					<span class="probesd-metrics__label"><?php esc_html_e( 'Render-blocking files', 'probe-site-doctor' ); ?></span>
				</li>
			</ul>
			<p class="description">
				<?php
				echo $probesd_analysis['timing']['cache_hit']['cached']
					? esc_html__( 'A cache answered this request:', 'probe-site-doctor' ) . ' ' . esc_html( implode( ' · ', $probesd_analysis['timing']['cache_hit']['signals'] ) )
					: esc_html__( 'No cache indicators were present in the response headers.', 'probe-site-doctor' );
				?>
				<?php esc_html_e( 'Asset sizes are on-disk sizes of local files before compression; third-party files are never requested, so their weight is unknown.', 'probe-site-doctor' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $vars['psi_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open this page in PageSpeed Insights', 'probe-site-doctor' ); ?></a>
				<span class="description"><?php esc_html_e( 'A lab test in Google’s own browser. Site Doctor does not send your URLs anywhere — the link only opens the tool.', 'probe-site-doctor' ); ?></span>
			</p>
		</section>

		<section class="probesd-panel" aria-labelledby="probesd-causes-title">
			<h2 id="probesd-causes-title"><?php esc_html_e( 'What is making this page slow', 'probe-site-doctor' ); ?></h2>
			<?php if ( ! $probesd_analysis['issues'] ) : ?>
				<p><?php esc_html_e( 'Nothing stood out. The page is served reasonably quickly, its assets are not unusually heavy and nothing obvious blocks rendering. Field data from real visitors is still the final word.', 'probe-site-doctor' ); ?></p>
			<?php else : ?>
				<ol class="probesd-causes">
					<?php foreach ( $probesd_analysis['issues'] as $probesd_issue ) : ?>
						<li class="probesd-cause probesd-sev--<?php echo esc_attr( $probesd_issue['severity'] ); ?>">
							<div class="probesd-cause__head">
								<span class="probesd-badge probesd-sev--<?php echo esc_attr( $probesd_issue['severity'] ); ?>"><?php echo esc_html( Severity::label( $probesd_issue['severity'] ) ); ?></span>
								<h3><?php echo esc_html( $probesd_issue['title'] ); ?></h3>
								<span class="probesd-affects">
									<?php
									printf(
										/* translators: %s: Metric names such as "LCP / INP". */
										esc_html__( 'usually affects %s', 'probe-site-doctor' ),
										esc_html( $probesd_issue['affects'] )
									);
									?>
								</span>
							</div>
							<p><strong><?php esc_html_e( 'Measured:', 'probe-site-doctor' ); ?></strong> <?php echo esc_html( $probesd_issue['evidence'] ); ?></p>
							<p><strong><?php esc_html_e( 'How to improve it:', 'probe-site-doctor' ); ?></strong> <?php echo esc_html( $probesd_issue['fix'] ); ?></p>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>

		<?php
		$probesd_heaviest = array(
			__( 'Scripts', 'probe-site-doctor' ) => $probesd_analysis['profile']['largest_scripts'],
			__( 'Stylesheets', 'probe-site-doctor' ) => $probesd_analysis['profile']['largest_styles'],
			__( 'Images', 'probe-site-doctor' ) => $probesd_analysis['profile']['largest_images'],
		);
		?>
		<section class="probesd-panel" aria-labelledby="probesd-heaviest-title">
			<h2 id="probesd-heaviest-title"><?php esc_html_e( 'Heaviest files on the page', 'probe-site-doctor' ); ?></h2>
			<?php foreach ( $probesd_heaviest as $probesd_label => $probesd_assets ) : ?>
				<?php if ( ! $probesd_assets ) { continue; } ?>
				<h3><?php echo esc_html( (string) $probesd_label ); ?></h3>
				<div class="probesd-table-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'File', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Size on disk', 'probe-site-doctor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $probesd_assets as $probesd_asset ) : ?>
								<tr>
									<td><code><?php echo esc_html( wp_basename( (string) wp_parse_url( (string) $probesd_asset['url'], PHP_URL_PATH ) ) ); ?></code></td>
									<td><?php echo esc_html( size_format( (int) $probesd_asset['bytes'], 1 ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
			<?php if ( $probesd_analysis['profile']['third_party'] ) : ?>
				<h3><?php esc_html_e( 'Third-party domains referenced', 'probe-site-doctor' ); ?></h3>
				<ul class="probesd-hosts">
					<?php foreach ( $probesd_analysis['profile']['third_party'] as $probesd_host => $probesd_count ) : ?>
						<li>
							<code><?php echo esc_html( (string) $probesd_host ); ?></code>
							<?php
							printf(
								/* translators: %s: Number of files. */
								esc_html( _n( '%s file', '%s files', (int) $probesd_count, 'probe-site-doctor' ) ),
								esc_html( number_format_i18n( (int) $probesd_count ) )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<section class="probesd-panel" aria-labelledby="probesd-vitals-title">
		<h2 id="probesd-vitals-title"><?php esc_html_e( 'Core Web Vitals from real visitors', 'probe-site-doctor' ); ?></h2>
		<p class="description"><?php echo esc_html( Vitals::scope_note() ); ?></p>

		<?php if ( ! $vars['field_enabled'] ) : ?>
			<p>
				<?php esc_html_e( 'Field data collection is off. When you switch it on, Site Doctor adds a small first-party script to public pages that reads the browser’s own performance entries and sends them back to this site. No third-party service is involved, nothing is stored in the visitor’s browser, and no visitor identifier, IP address or user agent is kept — only the page path, a device class, the metric and its value.', 'probe-site-doctor' ); ?>
			</p>
			<p><?php esc_html_e( 'It is off by default because it adds a script to the public site: enabling it is your decision, not the plugin’s.', 'probe-site-doctor' ); ?></p>
		<?php else : ?>
			<?php if ( $probesd_analysis && ! empty( $probesd_analysis['ok'] ) ) : ?>
				<?php
				$probesd_field_table(
					(array) $vars['field_page'],
					sprintf(
						/* translators: 1: Page path, 2: Number of days. */
						__( 'This page (%1$s), last %2$s days', 'probe-site-doctor' ),
						(string) $probesd_analysis['path'],
						number_format_i18n( (int) $vars['field_days'] )
					)
				);
				?>
			<?php endif; ?>
			<?php
			$probesd_field_table(
				(array) $vars['field_site'],
				sprintf(
					/* translators: %s: Number of days. */
					__( 'Whole site, last %s days', 'probe-site-doctor' ),
					number_format_i18n( (int) $vars['field_days'] )
				)
			);
			?>
			<div class="probesd-vitals__split">
				<?php
				$probesd_field_table( (array) $vars['field_mobile'], __( 'Mobile viewports', 'probe-site-doctor' ) );
				$probesd_field_table( (array) $vars['field_desktop'], __( 'Desktop viewports', 'probe-site-doctor' ) );
				?>
			</div>

			<?php if ( $vars['field_paths'] ) : ?>
				<h3><?php esc_html_e( 'Pages with field data', 'probe-site-doctor' ); ?></h3>
				<div class="probesd-table-scroll">
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Path', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Samples', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last report', 'probe-site-doctor' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Analyse', 'probe-site-doctor' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $vars['field_paths'] as $probesd_row ) : ?>
								<tr>
									<th scope="row"><code><?php echo esc_html( $probesd_row['path'] ); ?></code></th>
									<td><?php echo esc_html( number_format_i18n( (int) $probesd_row['samples'] ) ); ?></td>
									<td><?php echo esc_html( \ProbeSiteDoctor\Admin\View::ago( $probesd_row['last'] ) ); ?></td>
									<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'probe-site-doctor-pagespeed', 'url' => home_url( $probesd_row['path'] ), 'analyze' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Analyse this page', 'probe-site-doctor' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p><?php esc_html_e( 'No samples yet. Data appears once logged-out visitors have loaded pages — administrators are deliberately excluded, because their pages carry the admin bar and are rarely cached.', 'probe-site-doctor' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( $vars['can_manage'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="probesd-vitals__settings">
				<input type="hidden" name="action" value="<?php echo esc_attr( Actions::SETTINGS ); ?>">
				<?php wp_nonce_field( Actions::SETTINGS ); ?>
				<h3><?php esc_html_e( 'Field data settings', 'probe-site-doctor' ); ?></h3>
				<p>
					<label>
						<input type="checkbox" name="web_vitals" value="1" <?php checked( (bool) $vars['field_enabled'] ); ?>>
						<?php esc_html_e( 'Collect Core Web Vitals from visitors’ browsers (adds one small script to public pages)', 'probe-site-doctor' ); ?>
					</label>
				</p>
				<p>
					<label for="probesd-sampling"><?php esc_html_e( 'Sample percentage of page views', 'probe-site-doctor' ); ?></label>
					<input type="number" id="probesd-sampling" name="web_vitals_sampling" min="1" max="100" value="<?php echo esc_attr( (string) $vars['sampling'] ); ?>" class="small-text">
					<span class="description"><?php esc_html_e( 'Lower this on a busy site; 100% is fine for most.', 'probe-site-doctor' ); ?></span>
				</p>
				<p>
					<label for="probesd-days"><?php esc_html_e( 'Keep samples for (days)', 'probe-site-doctor' ); ?></label>
					<input type="number" id="probesd-days" name="web_vitals_days" min="1" max="365" value="<?php echo esc_attr( (string) $vars['field_days'] ); ?>" class="small-text">
					<span class="description">
						<?php
						printf(
							/* translators: %s: Number of samples. */
							esc_html__( 'Older samples are deleted automatically. Stored now: %s.', 'probe-site-doctor' ),
							esc_html( number_format_i18n( (int) $vars['field_total'] ) )
						);
						?>
					</span>
				</p>
				<p>
					<label>
						<input type="checkbox" name="clear_field_data" value="1">
						<?php esc_html_e( 'Also delete all collected field data now', 'probe-site-doctor' ); ?>
					</label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'probe-site-doctor' ); ?></button></p>
			</form>
		<?php endif; ?>
	</section>
</div>
