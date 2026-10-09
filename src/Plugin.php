<?php
/**
 * Main plugin class.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor;

use ProbeSiteDoctor\Admin\Admin;
use ProbeSiteDoctor\Cleanup\CleanupRegistry;
use ProbeSiteDoctor\Cleanup\CleanupRunner;
use ProbeSiteDoctor\Cleanup\ConfirmationGuard;
use ProbeSiteDoctor\Core\Settings;
use ProbeSiteDoctor\Core\Upgrader;
use ProbeSiteDoctor\Diagnostics\CategoryRegistry;
use ProbeSiteDoctor\Diagnostics\CheckRegistry;
use ProbeSiteDoctor\Diagnostics\Checks;
use ProbeSiteDoctor\Diagnostics\Engine;
use ProbeSiteDoctor\Diagnostics\Scanner;
use ProbeSiteDoctor\Diagnostics\Support\FrontendSnapshot;
use ProbeSiteDoctor\Performance\Vitals;
use ProbeSiteDoctor\Reporting\ReportBuilder;
use ProbeSiteDoctor\Rest\ScansController;
use ProbeSiteDoctor\Rest\VitalsController;
use ProbeSiteDoctor\Storage\ResultRepository;
use ProbeSiteDoctor\Storage\ScanRepository;
use ProbeSiteDoctor\Storage\VitalsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Composition root: builds services lazily and wires hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	/**
	 * Lazily created services.
	 *
	 * @var array<string,object>
	 */
	private array $services = array();

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wires hooks. Runs on plugins_loaded.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// No load_plugin_textdomain() call: WordPress has loaded translations for
		// plugins hosted on WordPress.org automatically since 4.6, and calling it
		// manually is what triggers the "translation loading too early" notice.
		( new Upgrader() )->register();
		FrontendSnapshot::register();

		add_action(
			'rest_api_init',
			function () {
				( new ScansController( $this->scanner(), $this->scans(), $this->reports(), $this->checks() ) )->register_routes();
				( new VitalsController( $this->settings(), $this->vitals() ) )->register_routes();
			}
		);

		// Field data is collected in visitors' browsers, so this part runs on the
		// public site — but only while an administrator has switched it on.
		$this->web_vitals()->register();

		if ( is_admin() ) {
			( new Admin( $this ) )->register();
		}

		/**
		 * Fires once the plugin is fully booted.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'probesd_loaded', $this );
	}

	/**
	 * Built-in check classes.
	 *
	 * @return string[]
	 */
	public function builtin_checks(): array {
		return array(
			Checks\Performance\PageCacheCheck::class,
			Checks\Performance\ObjectCacheCheck::class,
			Checks\Performance\OpcacheCheck::class,
			Checks\Performance\LargeImagesCheck::class,
			Checks\Performance\ImageOptimizationCheck::class,
			Checks\Performance\RenderBlockingAssetsCheck::class,
			Checks\Performance\AssetWeightCheck::class,
			Checks\Database\QueryPerformanceCheck::class,
			Checks\Database\DatabaseSizeCheck::class,
			Checks\Database\LargeTablesCheck::class,
			Checks\Database\TableHealthCheck::class,
			Checks\Database\AutoloadedOptionsCheck::class,
			Checks\Database\RevisionsCheck::class,
			Checks\Database\TransientsCheck::class,
			Checks\Database\PostmetaVolumeCheck::class,
			Checks\Database\OrphanedMetaCheck::class,
			Checks\Database\DatabaseBloatCheck::class,
			Checks\Configuration\WordPressVersionCheck::class,
			Checks\Configuration\PhpVersionCheck::class,
			Checks\Configuration\DebugModeCheck::class,
			Checks\Configuration\FileEditingCheck::class,
			Checks\Configuration\HttpsCheck::class,
			Checks\Configuration\AdminAccountsCheck::class,
			Checks\Configuration\XmlRpcCheck::class,
			Checks\Configuration\RestApiCheck::class,
			Checks\Configuration\VersionVisibilityCheck::class,
			Checks\Configuration\SecurityIndicatorsCheck::class,
			Checks\Extensions\OutdatedPluginsCheck::class,
			Checks\Extensions\OutdatedThemesCheck::class,
			Checks\Extensions\InactivePluginsCheck::class,
			Checks\Extensions\InactiveThemesCheck::class,
			Checks\Extensions\CompatibilityCheck::class,
			Checks\Seo\SearchVisibilityCheck::class,
			Checks\Seo\IndexingCheck::class,
			Checks\Seo\SitemapCheck::class,
			Checks\Seo\PageTitlesCheck::class,
			Checks\Seo\MetaDescriptionsCheck::class,
			Checks\Seo\ImageAltTextCheck::class,
			Checks\Seo\InternalLinksCheck::class,
			Checks\Seo\PermalinkStructureCheck::class,
			Checks\Developer\EnvironmentCheck::class,
			Checks\Developer\ActiveComponentsCheck::class,
			Checks\Developer\PhpExtensionsCheck::class,
			Checks\Developer\MemoryLimitCheck::class,
			Checks\Developer\WpMemoryLimitCheck::class,
			Checks\Developer\CronCheck::class,
			Checks\Developer\RestApiStatusCheck::class,
			Checks\Developer\DebugLogCheck::class,
			Checks\Developer\DatabaseConfigurationCheck::class,
		);
	}

	public function settings(): Settings {
		return $this->service( Settings::class, static fn() => new Settings() );
	}

	public function categories(): CategoryRegistry {
		return $this->service( CategoryRegistry::class, static fn() => new CategoryRegistry() );
	}

	public function checks(): CheckRegistry {
		return $this->service( CheckRegistry::class, fn() => new CheckRegistry( $this->categories(), $this->builtin_checks() ) );
	}

	public function engine(): Engine {
		return $this->service( Engine::class, static fn() => new Engine() );
	}

	public function scans(): ScanRepository {
		return $this->service( ScanRepository::class, static fn() => new ScanRepository() );
	}

	public function results(): ResultRepository {
		return $this->service( ResultRepository::class, static fn() => new ResultRepository() );
	}

	public function scanner(): Scanner {
		return $this->service(
			Scanner::class,
			fn() => new Scanner( $this->checks(), $this->engine(), $this->scans(), $this->results(), $this->settings() )
		);
	}

	public function cleanup_tasks(): CleanupRegistry {
		return $this->service( CleanupRegistry::class, static fn() => new CleanupRegistry() );
	}

	public function confirmation_guard(): ConfirmationGuard {
		return $this->service( ConfirmationGuard::class, static fn() => new ConfirmationGuard() );
	}

	public function cleanup_runner(): CleanupRunner {
		return $this->service( CleanupRunner::class, fn() => new CleanupRunner( $this->confirmation_guard() ) );
	}

	public function vitals(): VitalsRepository {
		return $this->service( VitalsRepository::class, static fn() => new VitalsRepository() );
	}

	public function web_vitals(): Vitals {
		return $this->service( Vitals::class, fn() => new Vitals( $this->settings() ) );
	}

	public function reports(): ReportBuilder {
		return $this->service( ReportBuilder::class, fn() => new ReportBuilder( $this->scans(), $this->results(), $this->categories() ) );
	}

	/**
	 * Returns a shared service, creating it on first use.
	 *
	 * @template T of object
	 * @param class-string<T> $id      Service ID.
	 * @param callable():T    $factory Factory.
	 * @return T
	 */
	private function service( string $id, callable $factory ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/**
	 * Prevents direct instantiation.
	 */
	private function __construct() {}
}
