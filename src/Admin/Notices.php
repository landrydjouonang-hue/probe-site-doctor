<?php
/**
 * Redirect notices.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Maps whitelisted notice codes from the query string to messages, so no
 * user-supplied text is ever echoed.
 */
final class Notices {

	/**
	 * Renders the notice for the current request, if any.
	 *
	 * @return void
	 */
	public static function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$code = isset( $_GET['probesd_notice'] ) ? sanitize_key( wp_unslash( $_GET['probesd_notice'] ) ) : '';

		$messages = array(
			'scan_done'    => array( 'success', __( 'Scan complete. The report is shown below.', 'probe-site-doctor' ) ),
			'in_progress'  => array( 'warning', __( 'Another scan is currently running. Wait for it to finish and try again.', 'probe-site-doctor' ) ),
			'scan_failed'  => array( 'error', __( 'The scan could not be completed. Check the PHP error log for details.', 'probe-site-doctor' ) ),
			'deleted'      => array( 'success', __( 'Report deleted.', 'probe-site-doctor' ) ),
			'bulk_deleted' => array( 'success', __( 'Selected reports deleted.', 'probe-site-doctor' ) ),
			'not_found'    => array( 'error', __( 'Report not found.', 'probe-site-doctor' ) ),
			'settings_saved' => array( 'success', __( 'Settings saved.', 'probe-site-doctor' ) ),
			'field_data_cleared' => array( 'success', __( 'Settings saved and the collected field data was deleted.', 'probe-site-doctor' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}
}
