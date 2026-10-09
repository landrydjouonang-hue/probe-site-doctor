<?php
/**
 * System information for developers.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Developer;

use ProbeSiteDoctor\Diagnostics\Support\DatabaseInfo;
use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Metadata reads for diagnostics.

/**
 * Collects the information a developer needs when troubleshooting someone
 * else's WordPress install: environment, theme, plugins, PHP extensions and
 * limits, cron, REST availability, debug settings and database details.
 *
 * Everything here is read-only. Secrets are never collected: the database
 * password, authentication keys and salts are not read, and only a whitelist
 * of constants is reported.
 */
final class SystemInfo {

	/**
	 * Per-request caches, so a page render and an export agree and the
	 * loopback probes run once.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static array $cache = array();

	/**
	 * Extensions WordPress requires, and ones it recommends.
	 *
	 * @var array<string,string[]>
	 */
	private const EXTENSIONS = array(
		'required'    => array( 'json', 'mysqli', 'mbstring', 'pcre', 'hash', 'filter', 'ctype', 'date', 'dom', 'libxml', 'openssl', 'spl', 'zlib' ),
		'recommended' => array( 'curl', 'exif', 'fileinfo', 'gd', 'iconv', 'imagick', 'intl', 'simplexml', 'sodium', 'xml', 'zip', 'bcmath', 'session', 'sockets', 'igbinary' ),
	);

	/**
	 * Constants that are safe and useful to report.
	 *
	 * @var string[]
	 */
	private const CONSTANTS = array(
		'WP_DEBUG',
		'WP_DEBUG_LOG',
		'WP_DEBUG_DISPLAY',
		'SCRIPT_DEBUG',
		'SAVEQUERIES',
		'WP_ENVIRONMENT_TYPE',
		'WP_CACHE',
		'WP_MEMORY_LIMIT',
		'WP_MAX_MEMORY_LIMIT',
		'DISABLE_WP_CRON',
		'ALTERNATE_WP_CRON',
		'WP_CRON_LOCK_TIMEOUT',
		'DISALLOW_FILE_EDIT',
		'DISALLOW_FILE_MODS',
		'AUTOMATIC_UPDATER_DISABLED',
		'WP_AUTO_UPDATE_CORE',
		'FORCE_SSL_ADMIN',
		'WP_POST_REVISIONS',
		'EMPTY_TRASH_DAYS',
		'MULTISITE',
		'SUBDOMAIN_INSTALL',
		'WP_ALLOW_MULTISITE',
		'CONCATENATE_SCRIPTS',
		'COMPRESS_SCRIPTS',
		'COMPRESS_CSS',
		'WP_DISABLE_FATAL_ERROR_HANDLER',
		'DB_CHARSET',
		'DB_COLLATE',
		'WP_CONTENT_DIR',
		'WP_PLUGIN_DIR',
		'UPLOADS',
		'WP_TEMP_DIR',
		'WP_SITEURL',
		'WP_HOME',
		'DIEONDBERROR',
		'IMAGE_EDIT_OVERWRITE',
		'MEDIA_TRASH',
		'WP_HTTP_BLOCK_EXTERNAL',
		'WP_ACCESSIBLE_HOSTS',
		'WP_PROXY_HOST',
	);

	/**
	 * Clears the per-request cache (used by tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cache = array();
	}

	/**
	 * Every group, ready to display or export.
	 *
	 * Each group: id, label, note, fields (label => value) and rows
	 * (list of records for tabular data).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function groups(): array {
		$groups = array(
			'environment' => self::group( __( 'Environment', 'probe-site-doctor' ), '', self::environment() ),
			'theme'       => self::group( __( 'Active theme', 'probe-site-doctor' ), '', self::theme() ),
			'plugins'     => self::group(
				__( 'Active plugins', 'probe-site-doctor' ),
				__( 'Must-use plugins and drop-ins load before or outside the normal plugin list and are easy to miss.', 'probe-site-doctor' ),
				self::plugins()
			),
			'extensions'  => self::group(
				__( 'PHP extensions', 'probe-site-doctor' ),
				__( '“Required” means WordPress itself needs it; “recommended” means core or common plugins use it when present.', 'probe-site-doctor' ),
				self::extensions()
			),
			'php'         => self::group( __( 'PHP configuration and memory limit', 'probe-site-doctor' ), '', self::php() ),
			'wp_memory'   => self::group(
				__( 'WordPress memory limit', 'probe-site-doctor' ),
				__( 'WordPress raises the PHP memory limit to WP_MEMORY_LIMIT when it can. It cannot raise it above a hard server limit.', 'probe-site-doctor' ),
				self::wp_memory()
			),
			'cron'        => self::group(
				__( 'Cron status', 'probe-site-doctor' ),
				__( 'Site Doctor reads the schedule only. It never requests wp-cron.php, because that would run the due tasks.', 'probe-site-doctor' ),
				self::cron()
			),
			'rest'        => self::group(
				__( 'REST API status', 'probe-site-doctor' ),
				__( 'Tested with anonymous GET requests from the server to its own REST routes.', 'probe-site-doctor' ),
				self::rest()
			),
			'debug'       => self::group( __( 'Debug information', 'probe-site-doctor' ), '', self::debug() ),
			'database'    => self::group( __( 'Database information', 'probe-site-doctor' ), '', self::database() ),
			'constants'   => self::group(
				__( 'Defined constants', 'probe-site-doctor' ),
				__( 'A whitelist of configuration constants. Authentication keys, salts and the database password are never read.', 'probe-site-doctor' ),
				array( 'fields' => self::constants() )
			),
		);

		/**
		 * Filters the developer system information groups.
		 *
		 * @since 0.8.0
		 *
		 * @param array<string,array<string,mixed>> $groups Groups keyed by ID.
		 */
		return (array) apply_filters( 'probesd_system_info', $groups );
	}

	/**
	 * Environment: WordPress, server, PHP and paths.
	 *
	 * @return array<string,mixed>
	 */
	public static function environment(): array {
		global $wp_version;

		$uploads = wp_get_upload_dir();

		return array(
			'fields' => array(
				__( 'WordPress version', 'probe-site-doctor' )    => (string) $wp_version,
				__( 'Site URL', 'probe-site-doctor' )             => site_url(),
				__( 'Home URL', 'probe-site-doctor' )             => home_url(),
				__( 'Environment type', 'probe-site-doctor' )     => wp_get_environment_type(),
				__( 'Multisite', 'probe-site-doctor' )            => is_multisite(),
				__( 'Locale', 'probe-site-doctor' )               => get_locale(),
				__( 'Timezone', 'probe-site-doctor' )             => wp_timezone_string(),
				__( 'Permalink structure', 'probe-site-doctor' )  => (string) get_option( 'permalink_structure' ) ?: __( 'plain (default)', 'probe-site-doctor' ),
				__( 'HTTPS', 'probe-site-doctor' )                => 0 === strpos( home_url(), 'https://' ),
				__( 'PHP version', 'probe-site-doctor' )          => PHP_VERSION,
				__( 'PHP SAPI', 'probe-site-doctor' )             => PHP_SAPI,
				__( 'PHP architecture', 'probe-site-doctor' )     => PHP_INT_SIZE * 8 . '-bit',
				__( 'Server software', 'probe-site-doctor' )      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
				__( 'Operating system', 'probe-site-doctor' )     => PHP_OS_FAMILY . ' (' . php_uname( 'r' ) . ')',
				__( 'Database server', 'probe-site-doctor' )      => self::database()['fields'][ __( 'Server', 'probe-site-doctor' ) ] ?? '',
				__( 'ABSPATH', 'probe-site-doctor' )              => ABSPATH,
				__( 'wp-content directory', 'probe-site-doctor' ) => WP_CONTENT_DIR,
				__( 'Uploads directory', 'probe-site-doctor' )    => (string) ( $uploads['basedir'] ?? '' ),
				__( 'Uploads writable', 'probe-site-doctor' )     => wp_is_writable( (string) ( $uploads['basedir'] ?? '' ) ),
				__( 'Page cache drop-in', 'probe-site-doctor' )   => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
				__( 'Object cache drop-in', 'probe-site-doctor' ) => file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
				__( 'Persistent object cache', 'probe-site-doctor' ) => (bool) wp_using_ext_object_cache(),
				__( 'External HTTP blocked', 'probe-site-doctor' ) => defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL,
				__( 'Plugin version', 'probe-site-doctor' )       => PROBESD_VERSION,
			),
		);
	}

	/**
	 * Active theme, its parent and update state.
	 *
	 * @return array<string,mixed>
	 */
	public static function theme(): array {
		$theme   = wp_get_theme();
		$parent  = $theme->parent();
		$updates = self::update_response( 'update_themes' );

		$fields = array(
			__( 'Name', 'probe-site-doctor' )             => $theme->get( 'Name' ),
			__( 'Version', 'probe-site-doctor' )          => $theme->get( 'Version' ),
			__( 'Author', 'probe-site-doctor' )           => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
			__( 'Stylesheet directory', 'probe-site-doctor' ) => $theme->get_stylesheet(),
			__( 'Template directory', 'probe-site-doctor' ) => $theme->get_template(),
			__( 'Child theme', 'probe-site-doctor' )      => (bool) $parent,
			__( 'Parent theme', 'probe-site-doctor' )     => $parent ? $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) : '—',
			__( 'Block theme', 'probe-site-doctor' )      => method_exists( $theme, 'is_block_theme' ) && $theme->is_block_theme(),
			__( 'Requires WordPress', 'probe-site-doctor' ) => (string) $theme->get( 'RequiresWP' ) ?: '—',
			__( 'Requires PHP', 'probe-site-doctor' )     => (string) $theme->get( 'RequiresPHP' ) ?: '—',
			__( 'Update available', 'probe-site-doctor' ) => isset( $updates[ $theme->get_stylesheet() ] )
				? (string) ( $updates[ $theme->get_stylesheet() ]['new_version'] ?? __( 'yes', 'probe-site-doctor' ) )
				: false,
		);

		if ( $parent ) {
			$fields[ __( 'Parent update available', 'probe-site-doctor' ) ] = isset( $updates[ $parent->get_stylesheet() ] )
				? (string) ( $updates[ $parent->get_stylesheet() ]['new_version'] ?? __( 'yes', 'probe-site-doctor' ) )
				: false;
		}

		return array( 'fields' => $fields );
	}

	/**
	 * Active plugins, must-use plugins and drop-ins.
	 *
	 * @return array<string,mixed>
	 */
	public static function plugins(): array {
		self::load_plugin_functions();

		$all     = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$updates = self::update_response( 'update_plugins' );
		$auto    = (array) get_site_option( 'auto_update_plugins', array() );

		$rows = array();
		foreach ( $all as $file => $plugin ) {
			$is_active = in_array( $file, $active, true ) || in_array( $file, $network, true );
			if ( ! $is_active ) {
				continue;
			}
			$rows[] = array(
				'plugin'  => (string) $plugin['Name'],
				'version' => (string) $plugin['Version'],
				'author'  => wp_strip_all_tags( (string) $plugin['Author'] ),
				'update'  => isset( $updates[ $file ] ) ? (string) ( $updates[ $file ]['new_version'] ?? '—' ) : '—',
				'scope'   => in_array( $file, $network, true ) ? __( 'network', 'probe-site-doctor' ) : __( 'site', 'probe-site-doctor' ),
				'auto'    => in_array( $file, $auto, true ),
				'file'    => (string) $file,
			);
		}
		usort( $rows, static fn( $a, $b ) => strcasecmp( $a['plugin'], $b['plugin'] ) );

		$mu       = function_exists( 'get_mu_plugins' ) ? (array) get_mu_plugins() : array();
		$dropins  = function_exists( 'get_dropins' ) ? (array) get_dropins() : array();
		$mu_names = array();
		foreach ( $mu as $file => $plugin ) {
			$mu_names[] = ( '' !== (string) $plugin['Name'] ? (string) $plugin['Name'] : (string) $file )
				. ( '' !== (string) $plugin['Version'] ? ' ' . $plugin['Version'] : '' );
		}

		return array(
			'fields' => array(
				__( 'Active plugins', 'probe-site-doctor' )   => count( $rows ),
				__( 'Installed plugins', 'probe-site-doctor' ) => count( $all ),
				__( 'Updates available', 'probe-site-doctor' ) => count( array_intersect_key( $updates, array_flip( array_merge( $active, $network ) ) ) ),
				__( 'Must-use plugins', 'probe-site-doctor' ) => $mu_names ? implode( ', ', $mu_names ) : __( 'none', 'probe-site-doctor' ),
				__( 'Drop-ins', 'probe-site-doctor' )         => $dropins ? implode( ', ', array_keys( $dropins ) ) : __( 'none', 'probe-site-doctor' ),
			),
			'rows'   => $rows,
		);
	}

	/**
	 * Loaded extensions and the status of required/recommended ones.
	 *
	 * @return array<string,mixed>
	 */
	public static function extensions(): array {
		$loaded = array_map( 'strtolower', (array) get_loaded_extensions() );
		sort( $loaded );
		$have = array_flip( $loaded );

		$rows    = array();
		$missing = array(
			'required'    => array(),
			'recommended' => array(),
		);

		foreach ( self::EXTENSIONS as $level => $names ) {
			foreach ( $names as $name ) {
				// Either extension satisfies image handling.
				$present = isset( $have[ $name ] )
					|| ( 'gd' === $name && isset( $have['imagick'] ) )
					|| ( 'imagick' === $name && isset( $have['gd'] ) );
				if ( ! $present ) {
					$missing[ $level ][] = $name;
				}
				$rows[] = array(
					'extension' => $name,
					'level'     => 'required' === $level ? __( 'Required', 'probe-site-doctor' ) : __( 'Recommended', 'probe-site-doctor' ),
					'status'    => $present ? __( 'Loaded', 'probe-site-doctor' ) : __( 'Missing', 'probe-site-doctor' ),
					'version'   => $present && isset( $have[ $name ] ) ? (string) ( phpversion( $name ) ?: '—' ) : '—',
				);
			}
		}

		return array(
			'fields'  => array(
				__( 'Extensions loaded', 'probe-site-doctor' )    => count( $loaded ),
				__( 'Required missing', 'probe-site-doctor' )     => $missing['required'] ? implode( ', ', $missing['required'] ) : __( 'none', 'probe-site-doctor' ),
				__( 'Recommended missing', 'probe-site-doctor' )  => $missing['recommended'] ? implode( ', ', $missing['recommended'] ) : __( 'none', 'probe-site-doctor' ),
				__( 'Image library', 'probe-site-doctor' )        => self::image_library(),
				__( 'All loaded extensions', 'probe-site-doctor' ) => implode( ', ', $loaded ),
			),
			'rows'    => $rows,
			'missing' => $missing,
			'loaded'  => $loaded,
		);
	}

	/**
	 * PHP limits and configuration relevant to WordPress.
	 *
	 * @return array<string,mixed>
	 */
	public static function php(): array {
		$memory_limit = (string) ini_get( 'memory_limit' );
		$opcache      = function_exists( 'opcache_get_status' );
		$opcache_on   = false;
		if ( $opcache ) {
			$status     = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Disabled OPcache raises a notice.
			$opcache_on = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		}

		return array(
			'fields' => array(
				__( 'memory_limit', 'probe-site-doctor' )        => $memory_limit,
				__( 'Memory in use', 'probe-site-doctor' )       => size_format( memory_get_usage( true ), 1 ),
				__( 'Peak memory', 'probe-site-doctor' )         => size_format( memory_get_peak_usage( true ), 1 ),
				__( 'max_execution_time', 'probe-site-doctor' )  => (string) ini_get( 'max_execution_time' ) . 's',
				__( 'max_input_vars', 'probe-site-doctor' )      => (string) ini_get( 'max_input_vars' ),
				__( 'max_input_time', 'probe-site-doctor' )      => (string) ini_get( 'max_input_time' ),
				__( 'post_max_size', 'probe-site-doctor' )       => (string) ini_get( 'post_max_size' ),
				__( 'upload_max_filesize', 'probe-site-doctor' ) => (string) ini_get( 'upload_max_filesize' ),
				__( 'max_file_uploads', 'probe-site-doctor' )    => (string) ini_get( 'max_file_uploads' ),
				__( 'Effective upload limit', 'probe-site-doctor' ) => size_format( wp_max_upload_size(), 1 ),
				__( 'display_errors', 'probe-site-doctor' )      => (string) ini_get( 'display_errors' ),
				__( 'error_reporting', 'probe-site-doctor' )     => (string) ini_get( 'error_reporting' ),
				__( 'error_log', 'probe-site-doctor' )           => (string) ini_get( 'error_log' ) ?: '—',
				__( 'allow_url_fopen', 'probe-site-doctor' )     => (bool) ini_get( 'allow_url_fopen' ),
				__( 'OPcache', 'probe-site-doctor' )             => $opcache_on,
				__( 'cURL version', 'probe-site-doctor' )        => function_exists( 'curl_version' ) ? (string) ( curl_version()['version'] ?? '' ) : '—',
				__( 'OpenSSL version', 'probe-site-doctor' )     => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : '—',
				__( 'Disabled functions', 'probe-site-doctor' )  => (string) ini_get( 'disable_functions' ) ?: __( 'none', 'probe-site-doctor' ),
				__( 'PHP timezone', 'probe-site-doctor' )        => (string) ini_get( 'date.timezone' ) ?: date_default_timezone_get(),
			),
			'limit_bytes' => self::bytes_from_ini( $memory_limit ),
			'opcache'     => $opcache_on,
		);
	}

	/**
	 * WordPress memory limits versus the PHP limit.
	 *
	 * @return array<string,mixed>
	 */
	public static function wp_memory(): array {
		$php      = self::bytes_from_ini( (string) ini_get( 'memory_limit' ) );
		$wp       = defined( 'WP_MEMORY_LIMIT' ) ? self::bytes_from_ini( (string) WP_MEMORY_LIMIT ) : 0;
		$wp_max   = defined( 'WP_MAX_MEMORY_LIMIT' ) ? self::bytes_from_ini( (string) WP_MAX_MEMORY_LIMIT ) : 0;
		$unlimited = -1 === $php;

		return array(
			'fields'     => array(
				__( 'WP_MEMORY_LIMIT', 'probe-site-doctor' )     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : __( 'not defined (WordPress default)', 'probe-site-doctor' ),
				__( 'WP_MAX_MEMORY_LIMIT', 'probe-site-doctor' ) => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : __( 'not defined (WordPress default)', 'probe-site-doctor' ),
				__( 'PHP memory_limit', 'probe-site-doctor' )    => $unlimited ? __( 'unlimited', 'probe-site-doctor' ) : size_format( $php, 0 ),
				__( 'Effective limit', 'probe-site-doctor' )     => $unlimited ? __( 'unlimited', 'probe-site-doctor' ) : size_format( max( $php, 0 ), 0 ),
				__( 'Memory in use', 'probe-site-doctor' )       => size_format( memory_get_usage( true ), 1 ),
				__( 'Peak memory', 'probe-site-doctor' )         => size_format( memory_get_peak_usage( true ), 1 ),
			),
			'php_bytes'  => $php,
			'wp_bytes'   => $wp,
			'max_bytes'  => $wp_max,
			'used_bytes' => memory_get_peak_usage( true ),
		);
	}

	/**
	 * Cron schedule state, read from the stored schedule only.
	 *
	 * @return array<string,mixed>
	 */
	public static function cron(): array {
		if ( isset( self::$cache['cron'] ) ) {
			return self::$cache['cron'];
		}

		$crons    = _get_cron_array();
		$crons    = is_array( $crons ) ? $crons : array();
		$now      = time();
		$total    = 0;
		$overdue  = array();
		$next     = 0;
		$hooks    = array();

		foreach ( $crons as $timestamp => $events ) {
			$timestamp = (int) $timestamp;
			foreach ( (array) $events as $hook => $instances ) {
				$count  = count( (array) $instances );
				$total += $count;

				$hooks[ (string) $hook ] = ( $hooks[ (string) $hook ] ?? 0 ) + $count;
				if ( 0 === $next || $timestamp < $next ) {
					$next = $timestamp;
				}
				// Late by more than five minutes: WordPress fires cron on page loads, so small delays are normal.
				if ( $timestamp < $now - 300 && count( $overdue ) < 20 ) {
					$overdue[] = array(
						'hook'      => (string) $hook,
						'scheduled' => gmdate( 'Y-m-d H:i:s', $timestamp ),
						'late'      => human_time_diff( $timestamp, $now ),
					);
				}
			}
		}

		$core_events = array( 'wp_version_check', 'wp_update_plugins', 'wp_update_themes', 'wp_scheduled_delete', 'wp_scheduled_auto_draft_delete' );
		$missing     = array_values( array_filter( $core_events, static fn( $hook ) => ! wp_next_scheduled( $hook ) ) );
		$duplicates  = array_keys( array_filter( $hooks, static fn( $n ) => $n > 1 ) );
		$disabled    = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$data = array(
			'fields'     => array(
				__( 'DISABLE_WP_CRON', 'probe-site-doctor' )    => $disabled,
				__( 'ALTERNATE_WP_CRON', 'probe-site-doctor' )  => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
				__( 'Scheduled events', 'probe-site-doctor' )   => $total,
				__( 'Distinct hooks', 'probe-site-doctor' )     => count( $hooks ),
				__( 'Next event due', 'probe-site-doctor' )     => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : __( 'none scheduled', 'probe-site-doctor' ),
				__( 'Overdue events', 'probe-site-doctor' )     => count( $overdue ),
				__( 'Missing core events', 'probe-site-doctor' ) => $missing ? implode( ', ', $missing ) : __( 'none', 'probe-site-doctor' ),
				__( 'Duplicated hooks', 'probe-site-doctor' )   => $duplicates ? implode( ', ', array_slice( $duplicates, 0, 10 ) ) : __( 'none', 'probe-site-doctor' ),
				__( 'Cron lock timeout', 'probe-site-doctor' )  => defined( 'WP_CRON_LOCK_TIMEOUT' ) ? (string) WP_CRON_LOCK_TIMEOUT . 's' : '60s',
				__( 'Registered schedules', 'probe-site-doctor' ) => implode( ', ', array_keys( (array) wp_get_schedules() ) ),
			),
			'rows'       => $overdue,
			'total'      => $total,
			'overdue'    => $overdue,
			'missing'    => $missing,
			'duplicates' => $duplicates,
			'disabled'   => $disabled,
			'next'       => $next,
		);

		self::$cache['cron'] = $data;
		return $data;
	}

	/**
	 * REST API availability, tested with anonymous loopback requests.
	 *
	 * @return array<string,mixed>
	 */
	public static function rest(): array {
		if ( isset( self::$cache['rest'] ) ) {
			return self::$cache['rest'];
		}

		$root  = Loopback::get( rest_url(), 8 );
		$types = Loopback::get( rest_url( 'wp/v2/types' ), 8 );

		$decoded    = json_decode( $root['body'], true );
		$namespaces = is_array( $decoded ) && isset( $decoded['namespaces'] ) ? (array) $decoded['namespaces'] : array();
		$routes     = is_array( $decoded ) && isset( $decoded['routes'] ) ? count( (array) $decoded['routes'] ) : 0;
		$root_json  = is_array( $decoded );

		$data = array(
			'fields'      => array(
				__( 'REST URL', 'probe-site-doctor' )             => rest_url(),
				__( 'Root response', 'probe-site-doctor' )        => $root['status'] ? sprintf( 'HTTP %d', $root['status'] ) : ( $root['error'] ?: __( 'no response', 'probe-site-doctor' ) ),
				__( 'Root returns JSON', 'probe-site-doctor' )    => $root_json,
				__( 'Registered namespaces', 'probe-site-doctor' ) => $namespaces ? implode( ', ', array_map( 'strval', $namespaces ) ) : '—',
				__( 'Routes advertised', 'probe-site-doctor' )    => $routes,
				__( 'Core route wp/v2/types', 'probe-site-doctor' ) => $types['status'] ? sprintf( 'HTTP %d', $types['status'] ) : ( $types['error'] ?: __( 'no response', 'probe-site-doctor' ) ),
				__( 'Site Doctor namespace', 'probe-site-doctor' ) => in_array( 'probe-site-doctor/v1', array_map( 'strval', $namespaces ), true ),
				__( 'Response time (server)', 'probe-site-doctor' ) => $root['time_ms'] . ' ms',
			),
			'ok'          => $root_json && $root['status'] >= 200 && $root['status'] < 300,
			'status'      => (int) $root['status'],
			'error'       => (string) $root['error'],
			'namespaces'  => array_map( 'strval', $namespaces ),
			'types_status' => (int) $types['status'],
			'time_ms'     => (int) $root['time_ms'],
		);

		self::$cache['rest'] = $data;
		return $data;
	}

	/**
	 * Debug settings and the debug log.
	 *
	 * @return array<string,mixed>
	 */
	public static function debug(): array {
		$log_path = self::debug_log_path();
		$exists   = '' !== $log_path && is_file( $log_path );
		$bytes    = $exists ? (int) filesize( $log_path ) : 0;

		return array(
			'fields'    => array(
				__( 'WP_DEBUG', 'probe-site-doctor' )         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				__( 'WP_DEBUG_LOG', 'probe-site-doctor' )     => defined( 'WP_DEBUG_LOG' ) ? ( is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : (bool) WP_DEBUG_LOG ) : false,
				__( 'WP_DEBUG_DISPLAY', 'probe-site-doctor' ) => ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY,
				__( 'SCRIPT_DEBUG', 'probe-site-doctor' )     => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
				__( 'SAVEQUERIES', 'probe-site-doctor' )      => defined( 'SAVEQUERIES' ) && SAVEQUERIES,
				__( 'Fatal error handler', 'probe-site-doctor' ) => ! ( defined( 'WP_DISABLE_FATAL_ERROR_HANDLER' ) && WP_DISABLE_FATAL_ERROR_HANDLER ),
				__( 'Log file', 'probe-site-doctor' )         => '' !== $log_path ? $log_path : __( 'not configured', 'probe-site-doctor' ),
				__( 'Log exists', 'probe-site-doctor' )       => $exists,
				__( 'Log size', 'probe-site-doctor' )         => $exists ? size_format( $bytes, 1 ) : '—',
				__( 'Log last written', 'probe-site-doctor' ) => $exists ? gmdate( 'Y-m-d H:i:s', (int) filemtime( $log_path ) ) . ' UTC' : '—',
				__( 'Log inside web root', 'probe-site-doctor' ) => $exists && self::inside_webroot( $log_path ),
			),
			'log_path'  => $log_path,
			'log_bytes' => $bytes,
			'log_mtime' => $exists ? (int) filemtime( $log_path ) : 0,
			'exists'    => $exists,
			'public'    => $exists && self::inside_webroot( $log_path ),
		);
	}

	/**
	 * Database server, connection and table facts.
	 *
	 * @return array<string,mixed>
	 */
	public static function database(): array {
		if ( isset( self::$cache['database'] ) ) {
			return self::$cache['database'];
		}

		global $wpdb;

		$version  = (string) $wpdb->get_var( 'SELECT VERSION()' );
		$is_maria = false !== stripos( $version, 'mariadb' );
		// MariaDB reports "5.5.5-10.x.y-MariaDB" for MySQL compatibility; the real version follows the prefix.
		$normalized = 0 === strpos( $version, '5.5.5-' ) ? substr( $version, 6 ) : $version;
		$clean      = (string) preg_replace( '/[^0-9.].*$/', '', $normalized );
		$variables = array();
		foreach ( (array) $wpdb->get_results( "SHOW VARIABLES WHERE Variable_name IN ('max_allowed_packet','innodb_buffer_pool_size','wait_timeout','sql_mode','character_set_server','collation_server','max_connections','innodb_version')", ARRAY_A ) as $row ) {
			$variables[ (string) $row['Variable_name'] ] = (string) $row['Value'];
		}

		$tables    = DatabaseInfo::tables();
		$bytes     = array_sum( array_column( $tables, 'bytes' ) );
		$engines   = array_count_values( array_filter( array_column( $tables, 'engine' ) ) );
		$non_utf8  = array();
		$collation = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_COLLATION AS collation FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND TABLE_TYPE = %s',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%',
				'BASE TABLE'
			),
			ARRAY_A
		);
		foreach ( $collation as $row ) {
			if ( 0 !== stripos( (string) $row['collation'], 'utf8mb4' ) && count( $non_utf8 ) < 20 ) {
				$non_utf8[] = (string) $row['name'];
			}
		}

		$rows = array();
		foreach ( array_slice( $tables, 0, 10 ) as $table ) {
			$rows[] = array(
				'table'  => $table['name'],
				'engine' => $table['engine'],
				'rows'   => $table['rows'],
				'size'   => size_format( $table['bytes'], 1 ),
			);
		}

		$data = array(
			'fields'    => array(
				__( 'Server', 'probe-site-doctor' )            => ( $is_maria ? 'MariaDB ' : 'MySQL ' ) . $clean,
				__( 'Server version string', 'probe-site-doctor' ) => $version,
				// The mysqli extension version, not mysqli_get_client_info(): reading the
				// client library directly is a restricted function for plugins.
				__( 'mysqli extension', 'probe-site-doctor' )  => extension_loaded( 'mysqli' ) ? (string) phpversion( 'mysqli' ) : '—',
				__( 'Database name', 'probe-site-doctor' )     => DB_NAME,
				__( 'Host', 'probe-site-doctor' )              => DB_HOST,
				__( 'Table prefix', 'probe-site-doctor' )      => $wpdb->prefix,
				__( 'Charset', 'probe-site-doctor' )           => (string) $wpdb->charset,
				__( 'Collation', 'probe-site-doctor' )         => (string) $wpdb->collate ?: ( $variables['collation_server'] ?? '—' ),
				__( 'Tables (this site)', 'probe-site-doctor' ) => count( $tables ),
				__( 'Total size', 'probe-site-doctor' )        => size_format( $bytes, 1 ),
				__( 'Storage engines', 'probe-site-doctor' )   => implode( ', ', array_map( static fn( $engine, $n ) => $engine . ' (' . $n . ')', array_keys( $engines ), $engines ) ),
				__( 'Tables not utf8mb4', 'probe-site-doctor' ) => $non_utf8 ? implode( ', ', $non_utf8 ) : __( 'none', 'probe-site-doctor' ),
				__( 'max_allowed_packet', 'probe-site-doctor' ) => isset( $variables['max_allowed_packet'] ) ? size_format( (int) $variables['max_allowed_packet'], 1 ) : '—',
				__( 'innodb_buffer_pool_size', 'probe-site-doctor' ) => isset( $variables['innodb_buffer_pool_size'] ) ? size_format( (int) $variables['innodb_buffer_pool_size'], 1 ) : '—',
				__( 'max_connections', 'probe-site-doctor' )   => $variables['max_connections'] ?? '—',
				__( 'wait_timeout', 'probe-site-doctor' )      => isset( $variables['wait_timeout'] ) ? $variables['wait_timeout'] . 's' : '—',
				__( 'sql_mode', 'probe-site-doctor' )          => ( $variables['sql_mode'] ?? '' ) ?: __( 'empty', 'probe-site-doctor' ),
			),
			'rows'      => $rows,
			'server'    => $is_maria ? 'mariadb' : 'mysql',
			'version'   => (string) $clean,
			'bytes'     => (int) $bytes,
			'tables'    => count( $tables ),
			'non_utf8'  => $non_utf8,
			'variables' => $variables,
		);

		self::$cache['database'] = $data;
		return $data;
	}

	/**
	 * Whitelisted constants and their values.
	 *
	 * @return array<string,mixed>
	 */
	public static function constants(): array {
		$out = array();
		foreach ( self::CONSTANTS as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}
			// Belt and braces: never report anything that looks like a secret.
			if ( preg_match( '/(KEY|SALT|PASSWORD|SECRET|TOKEN|NONCE_KEY)$/', $name ) ) {
				continue;
			}
			$value = constant( $name );
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$out[ $name ] = is_scalar( $value ) || is_bool( $value ) || null === $value ? $value : gettype( $value );
		}
		return $out;
	}

	/**
	 * Parses a PHP ini size such as "256M" into bytes. Returns -1 for no limit.
	 *
	 * @param string $value Ini value.
	 * @return int
	 */
	public static function bytes_from_ini( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		if ( '-1' === $value ) {
			return -1;
		}
		return (int) wp_convert_hr_to_bytes( $value );
	}

	/**
	 * Configured debug log path, if any.
	 *
	 * @return string
	 */
	public static function debug_log_path(): string {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG && '1' !== WP_DEBUG_LOG ) {
			return (string) WP_DEBUG_LOG;
		}
		$ini = (string) ini_get( 'error_log' );
		if ( '' !== $ini && 'syslog' !== $ini && is_file( $ini ) ) {
			return $ini;
		}
		$default = WP_CONTENT_DIR . '/debug.log';
		return is_file( $default ) ? $default : ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? $default : '' );
	}

	/**
	 * Which image library PHP offers.
	 *
	 * @return string
	 */
	private static function image_library(): string {
		$libs = array();
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			$libs[] = 'Imagick ' . ( defined( 'Imagick::IMAGICK_EXTVER' ) ? (string) \Imagick::IMAGICK_EXTVER : (string) phpversion( 'imagick' ) );
		}
		if ( function_exists( 'gd_info' ) ) {
			$info   = (array) gd_info();
			$libs[] = 'GD ' . (string) ( $info['GD Version'] ?? '' );
		}
		return $libs ? implode( ' · ', $libs ) : __( 'none', 'probe-site-doctor' );
	}

	/**
	 * Update response of a site transient, keyed by file/stylesheet.
	 *
	 * Reads only what WordPress has already cached: no request is made.
	 *
	 * @param string $transient update_plugins|update_themes.
	 * @return array<string,array<string,mixed>>
	 */
	private static function update_response( string $transient ): array {
		$data     = get_site_transient( $transient );
		$response = is_object( $data ) ? (array) ( $data->response ?? array() ) : array();

		$out = array();
		foreach ( $response as $key => $item ) {
			$out[ (string) $key ] = is_object( $item ) ? (array) $item : (array) $item;
		}
		return $out;
	}

	/**
	 * Whether a path sits inside the web root.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function inside_webroot( string $path ): bool {
		$path = wp_normalize_path( $path );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
			if ( 0 === strpos( $path, wp_normalize_path( trailingslashit( $root ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Loads the admin plugin functions when they are not present.
	 *
	 * @return void
	 */
	private static function load_plugin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Wraps collected data as a presentable group.
	 *
	 * @param string              $label Group label.
	 * @param string              $note  Optional explanation.
	 * @param array<string,mixed> $data  Collected data (fields, rows).
	 * @return array<string,mixed>
	 */
	private static function group( string $label, string $note, array $data ): array {
		return array(
			'label'  => $label,
			'note'   => $note,
			'fields' => (array) ( $data['fields'] ?? array() ),
			'rows'   => (array) ( $data['rows'] ?? array() ),
		);
	}
}
