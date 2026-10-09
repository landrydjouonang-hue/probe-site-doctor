<?php
/**
 * Environment requirements check.
 *
 * Intentionally written with conservative syntax: it is loaded before the
 * PHP version has been verified.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies the minimum PHP and WordPress versions.
 */
final class Requirements {

	/**
	 * Minimum PHP version.
	 *
	 * @var string
	 */
	private $min_php;

	/**
	 * Minimum WordPress version.
	 *
	 * @var string
	 */
	private $min_wp;

	/**
	 * Constructor.
	 *
	 * @param string $min_php Minimum PHP version.
	 * @param string $min_wp  Minimum WordPress version.
	 */
	public function __construct( $min_php, $min_wp ) {
		$this->min_php = $min_php;
		$this->min_wp  = $min_wp;
	}

	/**
	 * Whether all requirements are met.
	 *
	 * @return bool
	 */
	public function met() {
		return $this->php_ok() && $this->wp_ok();
	}

	/**
	 * Whether the PHP version is sufficient.
	 *
	 * @return bool
	 */
	public function php_ok() {
		return version_compare( PHP_VERSION, $this->min_php, '>=' );
	}

	/**
	 * Whether the WordPress version is sufficient.
	 *
	 * @return bool
	 */
	public function wp_ok() {
		return version_compare( get_bloginfo( 'version' ), $this->min_wp, '>=' );
	}

	/**
	 * Hooks the admin notice explaining the unmet requirements.
	 *
	 * @return void
	 */
	public function register_notice() {
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Renders the admin notice.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$messages = array();

		if ( ! $this->php_ok() ) {
			$messages[] = sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version. */
				__( 'Probe Site Doctor requires PHP %1$s or higher. Your server is running PHP %2$s.', 'probe-site-doctor' ),
				$this->min_php,
				PHP_VERSION
			);
		}

		if ( ! $this->wp_ok() ) {
			$messages[] = sprintf(
				/* translators: 1: Required WordPress version, 2: Current WordPress version. */
				__( 'Probe Site Doctor requires WordPress %1$s or higher. You are running WordPress %2$s.', 'probe-site-doctor' ),
				$this->min_wp,
				get_bloginfo( 'version' )
			);
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Probe Site Doctor is inactive.', 'probe-site-doctor' ) . '</strong></p>';
		foreach ( $messages as $message ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}
		echo '</div>';
	}
}
