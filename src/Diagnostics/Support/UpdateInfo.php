<?php
/**
 * Read-only access to WordPress's cached update data.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises the update_plugins / update_themes / update_core site
 * transients and the automatic-update settings.
 *
 * It never contacts WordPress.org and never triggers an update check or an
 * update. The data is as fresh as WordPress's own last check.
 */
final class UpdateInfo {

	/** Update data older than this is reported as possibly stale. */
	public const STALE_AFTER = 3 * DAY_IN_SECONDS;

	/**
	 * Plugins: installed, active state, available update and compatibility metadata.
	 *
	 * @return array{available:bool,last_checked:int,plugins:array<string,array<string,mixed>>}
	 */
	public static function plugins(): array {
		self::load_admin_functions();

		$transient = get_site_transient( 'update_plugins' );
		$response  = is_object( $transient ) ? (array) ( $transient->response ?? array() ) : array();
		$no_update = is_object( $transient ) ? (array) ( $transient->no_update ?? array() ) : array();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );
		$auto_on   = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'plugin' );

		$plugins = array();
		foreach ( get_plugins() as $file => $header ) {
			$update = isset( $response[ $file ] ) ? (object) $response[ $file ] : null;
			$known  = null !== $update || isset( $no_update[ $file ] );

			$plugins[ $file ] = array(
				'file'          => $file,
				'slug'          => dirname( $file ) === '.' ? basename( $file, '.php' ) : dirname( $file ),
				'name'          => wp_strip_all_tags( (string) $header['Name'] ),
				'version'       => (string) $header['Version'],
				'active'        => is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ),
				'requires_wp'   => (string) ( $header['RequiresWP'] ?? '' ),
				'requires_php'  => (string) ( $header['RequiresPHP'] ?? '' ),
				'requires'      => array_filter( array_map( 'trim', explode( ',', (string) ( $header['RequiresPlugins'] ?? '' ) ) ) ),
				'tested'        => self::readme_tested( $file ),
				'wporg'         => $known,
				'auto_update'   => $auto_on && in_array( $file, $auto, true ),
				'update'        => $update ? array(
					'version'      => (string) ( $update->new_version ?? '' ),
					'tested'       => (string) ( $update->tested ?? '' ),
					'requires_wp'  => (string) ( $update->requires ?? '' ),
					'requires_php' => (string) ( $update->requires_php ?? '' ),
				) : null,
			);
		}

		return array(
			'available'    => is_object( $transient ),
			'last_checked' => is_object( $transient ) ? (int) ( $transient->last_checked ?? 0 ) : 0,
			'plugins'      => $plugins,
		);
	}

	/**
	 * Themes: installed, active/parent state and available update.
	 *
	 * @return array{available:bool,last_checked:int,themes:array<string,array<string,mixed>>}
	 */
	public static function themes(): array {
		self::load_admin_functions();

		$transient = get_site_transient( 'update_themes' );
		$response  = is_object( $transient ) ? (array) ( $transient->response ?? array() ) : array();
		$auto      = (array) get_site_option( 'auto_update_themes', array() );
		$auto_on   = function_exists( 'wp_is_auto_update_enabled_for_type' ) && wp_is_auto_update_enabled_for_type( 'theme' );
		$active    = get_stylesheet();
		$parent    = get_template();

		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$update          = isset( $response[ $slug ] ) ? (array) $response[ $slug ] : null;
			$themes[ $slug ] = array(
				'slug'             => (string) $slug,
				'name'             => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
				'version'          => (string) $theme->get( 'Version' ),
				'active'           => $slug === $active,
				'parent_of_active' => $slug === $parent && $slug !== $active,
				'is_default'       => 0 === strpos( (string) $slug, 'twenty' ),
				'requires_wp'      => (string) $theme->get( 'RequiresWP' ),
				'requires_php'     => (string) $theme->get( 'RequiresPHP' ),
				'auto_update'      => $auto_on && in_array( $slug, $auto, true ),
				'update'           => $update ? array(
					'version'      => (string) ( $update['new_version'] ?? '' ),
					'requires_wp'  => (string) ( $update['requires'] ?? '' ),
					'requires_php' => (string) ( $update['requires_php'] ?? '' ),
				) : null,
			);
		}

		return array(
			'available'    => is_object( $transient ),
			'last_checked' => is_object( $transient ) ? (int) ( $transient->last_checked ?? 0 ) : 0,
			'themes'       => $themes,
		);
	}

	/**
	 * Core update offers.
	 *
	 * @return array{available:bool,last_checked:int,installed:string,latest:string,branch_update:?string,major_update:?string}
	 */
	public static function core(): array {
		$installed = (string) get_bloginfo( 'version' );
		$branch    = implode( '.', array_slice( explode( '.', $installed ), 0, 2 ) );
		$transient = get_site_transient( 'update_core' );

		$latest = $installed;
		$patch  = null;
		if ( is_object( $transient ) ) {
			foreach ( (array) ( $transient->updates ?? array() ) as $offer ) {
				if ( empty( $offer->current ) || ! in_array( $offer->response ?? '', array( 'upgrade', 'autoupdate', 'latest' ), true ) ) {
					continue;
				}
				$version = (string) $offer->current;
				if ( version_compare( $version, $latest, '>' ) ) {
					$latest = $version;
				}
				if ( 0 === strpos( $version, $branch . '.' ) && version_compare( $version, $installed, '>' ) && ( null === $patch || version_compare( $version, $patch, '>' ) ) ) {
					$patch = $version;
				}
			}
		}

		return array(
			'available'     => is_object( $transient ) && isset( $transient->updates ),
			'last_checked'  => is_object( $transient ) ? (int) ( $transient->last_checked ?? 0 ) : 0,
			'installed'     => $installed,
			'latest'        => $latest,
			'branch_update' => $patch,
			'major_update'  => version_compare( $latest, $installed, '>' ) && self::branch( $latest ) !== $branch ? $latest : null,
		);
	}

	/**
	 * Automatic update configuration for core (read-only).
	 *
	 * @return array{disabled:bool,minor:bool,major:bool,file_mods:bool}
	 */
	public static function core_auto_updates(): array {
		$updater_file = ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		if ( ! class_exists( 'WP_Automatic_Updater' ) && is_readable( $updater_file ) ) {
			require_once $updater_file;
		}
		$disabled = class_exists( 'WP_Automatic_Updater' ) ? ( new \WP_Automatic_Updater() )->is_disabled() : false;

		$setting = defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null;
		$minor   = false !== $setting;
		$major   = in_array( $setting, array( true, 'beta', 'rc', 'development', 'branch-development' ), true )
			|| ( null === $setting && 'enabled' === get_site_option( 'auto_update_core_major' ) );

		return array(
			'disabled'  => $disabled,
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading WordPress core filters to report what core would do.
			'minor'     => ! $disabled && (bool) apply_filters( 'allow_minor_auto_core_updates', $minor ),
			'major'     => ! $disabled && (bool) apply_filters( 'allow_major_auto_core_updates', $major ),
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'file_mods' => ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ),
		);
	}

	/**
	 * Kind of version change: major (first number), minor (second) or patch.
	 *
	 * @param string $from Installed version.
	 * @param string $to   New version.
	 * @return string major|minor|patch
	 */
	public static function change( string $from, string $to ): string {
		$a = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', $from ) . '.0.0' ) );
		$b = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', $to ) . '.0.0' ) );
		if ( $a[0] !== $b[0] ) {
			return 'major';
		}
		return $a[1] !== $b[1] ? 'minor' : 'patch';
	}

	/**
	 * WordPress feature branch ("7.1" from "7.1.2").
	 *
	 * @param string $version Version.
	 * @return string
	 */
	public static function branch( string $version ): string {
		return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
	}

	/**
	 * How many WordPress feature releases lie between two versions
	 * (WordPress numbers them x.0–x.9, so 6.9 → 7.0 is one release).
	 *
	 * @param string $older Older version.
	 * @param string $newer Newer version.
	 * @return int
	 */
	public static function releases_between( string $older, string $newer ): int {
		$index = static function ( string $v ): int {
			$parts = array_map( 'intval', explode( '.', $v . '.0' ) );
			return $parts[0] * 10 + $parts[1];
		};
		return max( 0, $index( $newer ) - $index( $older ) );
	}

	/**
	 * Sorts update recommendations: high, medium, blocked, low, then by name.
	 *
	 * Each recommendation: priority (high|medium|low|blocked), component
	 * (core|plugin|theme|php), name, installed, available, change, note.
	 *
	 * @param array<int,array<string,mixed>> $recommendations Recommendations.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_recommendations( array $recommendations ): array {
		$order = array(
			'high'    => 0,
			'medium'  => 1,
			'blocked' => 2,
			'low'     => 3,
		);
		usort(
			$recommendations,
			static fn( $a, $b ) => ( $order[ $a['priority'] ?? '' ] ?? 9 ) <=> ( $order[ $b['priority'] ?? '' ] ?? 9 ) ?: strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) )
		);
		return $recommendations;
	}

	/**
	 * Whether update data is older than STALE_AFTER.
	 *
	 * @param int $last_checked Timestamp.
	 * @return bool
	 */
	public static function is_stale( int $last_checked ): bool {
		return $last_checked > 0 && ( time() - $last_checked ) > self::STALE_AFTER;
	}

	/**
	 * "Tested up to" from a plugin's local readme.txt (no remote request).
	 *
	 * @param string $file Plugin basename.
	 * @return string
	 */
	private static function readme_tested( string $file ): string {
		$dir = dirname( WP_PLUGIN_DIR . '/' . $file );
		if ( '.' === dirname( $file ) ) {
			return '';
		}
		foreach ( array( 'readme.txt', 'README.txt', 'README.md', 'readme.md' ) as $name ) {
			$path = $dir . '/' . $name;
			if ( is_readable( $path ) ) {
				$headers = get_file_data( $path, array( 'tested' => 'Tested up to' ) );
				return trim( (string) $headers['tested'] );
			}
		}
		return '';
	}

	/**
	 * Loads get_plugins() and friends outside wp-admin.
	 *
	 * @return void
	 */
	private static function load_admin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'wp_is_auto_update_enabled_for_type' ) && is_readable( ABSPATH . 'wp-admin/includes/update.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
	}
}
