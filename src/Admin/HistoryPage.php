<?php
/**
 * Scan history screen.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lists stored scans and handles bulk deletion.
 */
final class HistoryPage {

	private Plugin $plugin;

	private ?ScanListTable $table = null;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Runs on load-{page}: processes bulk actions, sets up screen options.
	 *
	 * @return void
	 */
	public function load(): void {
		if ( ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Scans per page', 'probe-site-doctor' ),
				'default' => 20,
				'option'  => 'probesd_scans_per_page',
			)
		);

		$this->table = new ScanListTable( $this->plugin->scans() );

		if ( 'delete' === $this->table->current_action() ) {
			$this->bulk_delete();
		}
	}

	/**
	 * Handles the bulk delete action.
	 *
	 * @return void
	 */
	private function bulk_delete(): void {
		if ( ! current_user_can( Capabilities::RUN_SCANS ) ) {
			wp_die( esc_html__( 'You are not allowed to delete reports.', 'probe-site-doctor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bulk-scans' );

		$ids = isset( $_REQUEST['scan'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['scan'] ) ) : array();
		foreach ( array_filter( $ids ) as $id ) {
			$this->plugin->scanner()->delete( $id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => Admin::SLUG_HISTORY,
					'probesd_notice' => 'bulk_deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->table ) {
			return;
		}
		$this->table->prepare_items();
		?>
		<div class="wrap probesd">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Scan History', 'probe-site-doctor' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::SLUG_DASHBOARD ) ); ?>" class="page-title-action"><?php esc_html_e( 'Dashboard', 'probe-site-doctor' ); ?></a>
			<hr class="wp-header-end">
			<?php Notices::render(); ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: Number of scans. */
					esc_html__( 'The %s most recent completed scans are kept; older ones and interrupted scans are removed automatically when a scan completes.', 'probe-site-doctor' ),
					esc_html( number_format_i18n( $this->plugin->settings()->retention() ) )
				);
				?>
			</p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG_HISTORY ); ?>">
				<?php $this->table->display(); ?>
			</form>
		</div>
		<?php
	}
}
