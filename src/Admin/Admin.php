<?php
/**
 * Admin bootstrap.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers menus, assets, actions and notices.
 */
final class Admin {

	public const SLUG_DASHBOARD = 'probe-site-doctor';
	public const SLUG_REPORT    = 'probe-site-doctor-report';
	public const SLUG_PAGESPEED = 'probe-site-doctor-pagespeed';
	public const SLUG_DEVELOPER = 'probe-site-doctor-developer';
	public const SLUG_HISTORY   = 'probe-site-doctor-history';

	private Plugin $plugin;

	private ?HistoryPage $history = null;

	/**
	 * Page hook suffixes returned by add_*_page().
	 *
	 * @var array<string,string>
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks everything.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_filter( 'plugin_action_links_' . PROBESD_BASENAME, array( $this, 'action_links' ) );
		add_filter(
			'set_screen_option_probesd_scans_per_page',
			static fn( $status, $option, $value ) => max( 1, min( 100, (int) $value ) ),
			10,
			3
		);

		( new Actions( $this->plugin ) )->register();
	}

	/**
	 * Adds the menu pages.
	 *
	 * @return void
	 */
	public function menu(): void {
		$dashboard = new DashboardPage( $this->plugin );

		$this->hooks['dashboard'] = (string) add_menu_page(
			__( 'Probe Site Doctor', 'probe-site-doctor' ),
			__( 'Probe Site Doctor', 'probe-site-doctor' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG_DASHBOARD,
			array( $dashboard, 'render' ),
			'dashicons-heart',
			80
		);

		add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Probe Site Doctor Dashboard', 'probe-site-doctor' ),
			__( 'Dashboard', 'probe-site-doctor' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG_DASHBOARD,
			array( $dashboard, 'render' )
		);

		$this->hooks['report'] = (string) add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Website Health Report', 'probe-site-doctor' ),
			__( 'Health Report', 'probe-site-doctor' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG_REPORT,
			array( new ReportPage( $this->plugin ), 'render' )
		);

		$this->hooks['pagespeed'] = (string) add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Page Speed', 'probe-site-doctor' ),
			__( 'Page Speed', 'probe-site-doctor' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG_PAGESPEED,
			array( new PageSpeedPage( $this->plugin ), 'render' )
		);

		$this->hooks['developer'] = (string) add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Developer', 'probe-site-doctor' ),
			__( 'Developer', 'probe-site-doctor' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG_DEVELOPER,
			array( new DeveloperPage( $this->plugin ), 'render' )
		);

		$this->hooks['history'] = (string) add_submenu_page(
			self::SLUG_DASHBOARD,
			__( 'Scan History', 'probe-site-doctor' ),
			__( 'Scan History', 'probe-site-doctor' ),
			Capabilities::VIEW_REPORTS,
			self::SLUG_HISTORY,
			array( $this, 'render_history' )
		);

		// The list table must be built before output so screen options and bulk actions work.
		add_action( 'load-' . $this->hooks['history'], array( $this, 'load_history' ) );
	}

	/**
	 * Prepares the history page.
	 *
	 * @return void
	 */
	public function load_history(): void {
		$this->history = new HistoryPage( $this->plugin );
		$this->history->load();
	}

	/**
	 * Renders the history page.
	 *
	 * @return void
	 */
	public function render_history(): void {
		if ( $this->history ) {
			$this->history->render();
		}
	}

	/**
	 * Enqueues assets on plugin screens only.
	 *
	 * @param string $hook_suffix Current screen hook.
	 * @return void
	 */
	public function assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		wp_enqueue_style( 'probesd-admin', PROBESD_URL . 'assets/css/admin.css', array(), PROBESD_VERSION );

		if ( in_array( $hook_suffix, array( $this->hooks['developer'] ?? '', $this->hooks['pagespeed'] ?? '' ), true ) ) {
			wp_enqueue_style( 'probesd-developer', PROBESD_URL . 'assets/css/developer.css', array( 'probesd-admin' ), PROBESD_VERSION );
			wp_enqueue_script(
				'probesd-developer',
				PROBESD_URL . 'assets/js/developer.js',
				array(),
				PROBESD_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		if ( $hook_suffix === ( $this->hooks['report'] ?? '' ) ) {
			wp_enqueue_style( 'probesd-report', PROBESD_URL . 'assets/css/report.css', array( 'probesd-admin' ), PROBESD_VERSION );
			wp_enqueue_script(
				'probesd-report',
				PROBESD_URL . 'assets/js/report.js',
				array(),
				PROBESD_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}

		if ( $hook_suffix === ( $this->hooks['dashboard'] ?? '' ) ) {
			wp_enqueue_script(
				'probesd-dashboard',
				PROBESD_URL . 'assets/js/dashboard.js',
				array( 'wp-api-fetch', 'wp-i18n' ),
				PROBESD_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_set_script_translations( 'probesd-dashboard', 'probe-site-doctor', PROBESD_PATH . 'languages' );
			wp_localize_script(
				'probesd-dashboard',
				'probeSiteDoctorDashboard',
				array(
					'namespace' => \ProbeSiteDoctor\Rest\ScansController::NAMESPACE,
				)
			);
		}
	}

	/**
	 * One-time notice after activation.
	 *
	 * @return void
	 */
	public function activation_notice(): void {
		if ( ! get_transient( 'probesd_activated' ) || ! current_user_can( Capabilities::VIEW_REPORTS ) ) {
			return;
		}
		delete_transient( 'probesd_activated' );

		$url = admin_url( 'admin.php?page=' . self::SLUG_DASHBOARD );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Probe Site Doctor is active.', 'probe-site-doctor' ),
			esc_url( $url ),
			esc_html__( 'Run your first health scan', 'probe-site-doctor' )
		);
	}

	/**
	 * Adds a Dashboard link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		if ( current_user_can( Capabilities::VIEW_REPORTS ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( admin_url( 'admin.php?page=' . self::SLUG_DASHBOARD ) ),
					esc_html__( 'Dashboard', 'probe-site-doctor' )
				)
			);
		}
		return $links;
	}
}
