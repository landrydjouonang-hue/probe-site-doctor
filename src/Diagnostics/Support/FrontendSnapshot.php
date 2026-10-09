<?php
/**
 * Cached analysis of the homepage HTML.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the homepage once per scan through a loopback request and extracts
 * the scripts and stylesheets referenced in the delivered HTML.
 *
 * Checks run in separate requests, so the parsed result is cached in a
 * short-lived transient that is cleared whenever a new scan starts.
 *
 * Limitations, also stated in check messages:
 * - only the homepage is analysed;
 * - assets injected later by JavaScript are not seen;
 * - sizes are on-disk sizes of local files before compression.
 */
final class FrontendSnapshot {

	public const TRANSIENT = 'probesd_frontend_snapshot';

	/** Snapshot lifetime; a scan normally finishes well within it. */
	private const TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * A failed fetch is remembered only briefly: a momentary timeout must not
	 * make every later check report "skipped" for the next ten minutes.
	 */
	private const FAILURE_TTL = 30;

	/** Hard cap on HTML analysed, so a huge page cannot exhaust memory. */
	private const MAX_HTML_BYTES = 3 * 1024 * 1024;

	/**
	 * Hooks cache invalidation.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'probesd_scan_started', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Drops the cached snapshot.
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Returns the snapshot, fetching it if needed.
	 *
	 * @return array<string,mixed> Keys: ok, error, status, time_ms, url, html_bytes, scripts, styles, inline_script_bytes, inline_style_bytes, generators, versioned_assets, title, meta_description, meta_robots, canonical, images, images_without_alt, internal_links.
	 */
	public static function get(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['ok'] ) ) {
			return $cached;
		}

		$response = Loopback::get();
		$snapshot = array(
			'ok'      => $response['ok'],
			'error'   => $response['error'],
			'status'  => $response['status'],
			'time_ms' => $response['time_ms'],
			'url'     => $response['url'],
		);

		if ( $response['ok'] ) {
			$html = substr( $response['body'], 0, self::MAX_HTML_BYTES );
			if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<head' ) ) {
				$snapshot['ok']    = false;
				$snapshot['error'] = __( 'The homepage did not return an HTML document.', 'probe-site-doctor' );
			} else {
				$snapshot += array( 'html_bytes' => strlen( $response['body'] ) ) + self::parse( $html );
			}
		}

		set_transient( self::TRANSIENT, $snapshot, empty( $snapshot['ok'] ) ? self::FAILURE_TTL : self::TTL );
		return $snapshot;
	}

	/**
	 * Extracts script and stylesheet references.
	 *
	 * @param string $html HTML.
	 * @return array<string,mixed>
	 */
	public static function parse( string $html ): array {
		$scripts       = array();
		$styles        = array();
		$inline_script = 0;
		$inline_style  = 0;
		$in_body       = false;
		$generators    = array();
		$title         = null;
		$description   = null;
		$robots        = null;
		$canonical     = null;
		$images        = 0;
		$no_alt        = array();
		$links         = array();

		$tags = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {
			$name = $tags->get_tag();

			if ( 'BODY' === $name ) {
				$in_body = true;
				continue;
			}

			if ( 'SCRIPT' === $name ) {
				$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );
				// Only executable JavaScript matters (skip JSON, templates, speculation rules…).
				if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
					continue;
				}
				$src = $tags->get_attribute( 'src' );
				if ( is_string( $src ) && '' !== trim( $src ) ) {
					$scripts[] = array(
						'url'    => self::absolute( $src ),
						'head'   => ! $in_body,
						'async'  => null !== $tags->get_attribute( 'async' ),
						'defer'  => null !== $tags->get_attribute( 'defer' ),
						'module' => 'module' === $type,
					);
				} elseif ( method_exists( $tags, 'get_modifiable_text' ) ) {
					$inline_script += strlen( (string) $tags->get_modifiable_text() );
				}
				continue;
			}

			if ( 'LINK' === $name ) {
				$rel  = ' ' . strtolower( (string) $tags->get_attribute( 'rel' ) ) . ' ';
				$href = $tags->get_attribute( 'href' );
				if ( false !== strpos( $rel, ' stylesheet ' ) && is_string( $href ) && '' !== trim( $href ) ) {
					$media    = strtolower( trim( (string) $tags->get_attribute( 'media' ) ) );
					$styles[] = array(
						'url'   => self::absolute( $href ),
						'head'  => ! $in_body,
						'media' => '' === $media ? 'all' : $media,
					);
				}
				if ( false !== strpos( $rel, ' canonical ' ) && null === $canonical ) {
					$canonical = (string) $href;
				}
				continue;
			}

			if ( 'STYLE' === $name && method_exists( $tags, 'get_modifiable_text' ) ) {
				$inline_style += strlen( (string) $tags->get_modifiable_text() );
				continue;
			}

			if ( 'META' === $name ) {
				$meta_name = strtolower( trim( (string) $tags->get_attribute( 'name' ) ) );
				// Version disclosure: the generator meta tag names the exact WordPress version.
				if ( 'generator' === $meta_name ) {
					$generators[] = (string) $tags->get_attribute( 'content' );
				}
				if ( 'description' === $meta_name && null === $description ) {
					$description = (string) $tags->get_attribute( 'content' );
				}
				if ( 'robots' === $meta_name && null === $robots ) {
					$robots = (string) $tags->get_attribute( 'content' );
				}
				continue;
			}

			if ( 'TITLE' === $name && null === $title && method_exists( $tags, 'get_modifiable_text' ) ) {
				$title = trim( (string) $tags->get_modifiable_text() );
				continue;
			}

			if ( 'IMG' === $name ) {
				$images++;
				$alt  = $tags->get_attribute( 'alt' );
				$role = strtolower( trim( (string) $tags->get_attribute( 'role' ) ) );
				// A missing alt attribute is a problem; alt="" on a decorative image is valid.
				if ( null === $alt && 'presentation' !== $role && count( $no_alt ) < 20 ) {
					$no_alt[] = wp_basename( (string) wp_parse_url( self::absolute( (string) $tags->get_attribute( 'src' ) ), PHP_URL_PATH ) );
				}
				continue;
			}

			if ( 'A' === $name ) {
				$href = (string) $tags->get_attribute( 'href' );
				if ( '' !== $href && ! preg_match( '#^(mailto:|tel:|javascript:|\#)#i', $href ) ) {
					$url  = self::absolute( $href );
					$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					if ( $host === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) && count( $links ) < 100 ) {
						$links[ strtok( $url, '#' ) ] = true;
					}
				}
				continue;
			}

		}

		foreach ( $scripts as $i => $script ) {
			$scripts[ $i ] += self::locate( $script['url'] );
		}
		foreach ( $styles as $i => $style ) {
			$styles[ $i ] += self::locate( $style['url'] );
		}

		// Assets carrying a ?ver= query string also disclose versions.
		$versioned = 0;
		foreach ( array_merge( $scripts, $styles ) as $asset ) {
			if ( $asset['local'] && preg_match( '/[?&]ver=/', (string) $asset['url'] ) ) {
				$versioned++;
			}
		}

		return array(
			'scripts'             => $scripts,
			'styles'              => $styles,
			'inline_script_bytes' => $inline_script,
			'inline_style_bytes'  => $inline_style,
			'generators'          => $generators,
			'versioned_assets'    => $versioned,
			'title'               => $title,
			'meta_description'    => $description,
			'meta_robots'         => $robots,
			'canonical'           => $canonical,
			'images'              => $images,
			'images_without_alt'  => $no_alt,
			'internal_links'      => array_keys( $links ),
		);
	}

	/**
	 * Resolves protocol-relative and root-relative URLs.
	 *
	 * @param string $url URL as written in the HTML.
	 * @return string
	 */
	private static function absolute( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES );
		if ( 0 === strpos( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$home = wp_parse_url( home_url() );
			return ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			// Document-relative; the analysed document is the homepage.
			return home_url( '/' ) . ltrim( $url, './' );
		}
		return $url;
	}

	/**
	 * Host, whether the asset is local, and its on-disk size when it is.
	 *
	 * @param string $url Absolute URL.
	 * @return array{host:string,local:bool,bytes:int|null}
	 */
	private static function locate( string $url ): array {
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
		$local     = '' !== $host && $host === $site_host;

		return array(
			'host'  => $host,
			'local' => $local,
			'bytes' => $local ? self::local_size( $url ) : null,
		);
	}

	/**
	 * Maps a local asset URL to a file and returns its size.
	 *
	 * Only files inside the WordPress or wp-content directory are considered, and only
	 * .js/.css files, so arbitrary paths are never stat-ed.
	 *
	 * @param string $url Absolute URL on the site host.
	 * @return int|null
	 */
	private static function local_size( string $url ): ?int {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '/\.(js|css)$/i', $path ) ) {
			return null;
		}

		$map = array(
			wp_parse_url( content_url( '/' ), PHP_URL_PATH ) => trailingslashit( WP_CONTENT_DIR ),
			wp_parse_url( includes_url( '/' ), PHP_URL_PATH ) => ABSPATH . WPINC . '/',
			wp_parse_url( site_url( '/' ), PHP_URL_PATH )    => ABSPATH,
		);

		foreach ( $map as $url_prefix => $dir ) {
			$url_prefix = (string) $url_prefix;
			if ( '' === $url_prefix || 0 !== strpos( $path, $url_prefix ) ) {
				continue;
			}
			$relative = rawurldecode( substr( $path, strlen( $url_prefix ) ) );
			if ( false !== strpos( $relative, '..' ) ) {
				return null;
			}
			$file   = wp_normalize_path( $dir . $relative );
			$inside = 0 === strpos( $file, wp_normalize_path( ABSPATH ) ) || 0 === strpos( $file, wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) ) );
			if ( $inside && is_file( $file ) ) {
				return (int) filesize( $file );
			}
			return null;
		}

		return null;
	}
}
