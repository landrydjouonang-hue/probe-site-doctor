<?php
/**
 * Single-page profile.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Performance;

use ProbeSiteDoctor\Diagnostics\Support\Loopback;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches one page of this site as an anonymous visitor and describes what it
 * asks a browser to do: its response headers, the scripts, stylesheets,
 * images, fonts and third-party origins it references, and their sizes.
 *
 * What this is: a server-side look at the delivered HTML.
 * What this is not: a browser. Nothing here is a page-load time, and assets
 * that JavaScript adds later are invisible to it. Sizes of local files are
 * on-disk sizes before compression.
 */
final class PageProfile {

	/** Hard cap on HTML analysed. */
	private const MAX_HTML_BYTES = 3 * 1024 * 1024;

	/** How many local assets get a real request to read their cache headers. */
	private const HEADER_SAMPLES = 3;

	/**
	 * Image, font and media extensions whose on-disk size may be read.
	 */
	private const SIZEABLE = 'js|css|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm';

	/**
	 * Profiles a URL.
	 *
	 * @param string $url Absolute URL on this site.
	 * @return array<string,mixed>
	 */
	public static function fetch( string $url ): array {
		$url = self::normalize( $url );
		if ( '' === $url ) {
			return array(
				'ok'    => false,
				'error' => __( 'That URL is not part of this site.', 'probe-site-doctor' ),
				'url'   => '',
			);
		}

		$response = Loopback::get( $url, 15 );

		$profile = array(
			'ok'         => $response['ok'],
			'error'      => $response['error'],
			'status'     => $response['status'],
			'time_ms'    => $response['time_ms'],
			'url'        => $url,
			'headers'    => $response['headers'],
			'html_bytes' => strlen( $response['body'] ),
		);

		if ( ! $response['ok'] ) {
			return $profile;
		}

		$html = substr( $response['body'], 0, self::MAX_HTML_BYTES );
		if ( false === stripos( $html, '<html' ) && false === stripos( $html, '<head' ) ) {
			$profile['ok']    = false;
			$profile['error'] = __( 'That URL did not return an HTML document.', 'probe-site-doctor' );
			return $profile;
		}

		$profile += self::parse( $html, $url );

		// A second request tells us whether a page cache answered it, and how fast a warm hit is.
		$second            = Loopback::get( $url, 15 );
		$profile['repeat_time_ms'] = (int) $second['time_ms'];
		$profile['repeat_headers'] = $second['headers'];

		$profile['asset_headers'] = self::sample_asset_headers( $profile );

		return $profile;
	}

	/**
	 * Extracts everything measurable from the delivered HTML.
	 *
	 * @param string $html HTML.
	 * @param string $base Document URL, for resolving relative references.
	 * @return array<string,mixed>
	 */
	public static function parse( string $html, string $base ): array {
		$scripts   = array();
		$styles    = array();
		$images    = array();
		$fonts     = array();
		$hints     = array();
		$iframes   = 0;
		$in_body   = false;
		$inline_js = 0;
		$inline_css = 0;
		$elements  = 0;
		$modern    = false;

		$tags = new \WP_HTML_Tag_Processor( $html );

		while ( $tags->next_tag() ) {
			$elements++;
			$name = $tags->get_tag();

			if ( 'BODY' === $name ) {
				$in_body = true;
				continue;
			}

			if ( 'SCRIPT' === $name ) {
				$type = strtolower( trim( (string) $tags->get_attribute( 'type' ) ) );
				if ( '' !== $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
					continue;
				}
				$src = $tags->get_attribute( 'src' );
				if ( is_string( $src ) && '' !== trim( $src ) ) {
					$scripts[] = self::asset( self::absolute( $src, $base ) ) + array(
						'head'  => ! $in_body,
						'async' => null !== $tags->get_attribute( 'async' ),
						'defer' => null !== $tags->get_attribute( 'defer' ),
					);
				} elseif ( method_exists( $tags, 'get_modifiable_text' ) ) {
					$inline_js += strlen( (string) $tags->get_modifiable_text() );
				}
				continue;
			}

			if ( 'LINK' === $name ) {
				$rel  = ' ' . strtolower( (string) $tags->get_attribute( 'rel' ) ) . ' ';
				$href = (string) $tags->get_attribute( 'href' );
				if ( '' === trim( $href ) ) {
					continue;
				}
				if ( false !== strpos( $rel, ' stylesheet ' ) ) {
					$media    = strtolower( trim( (string) $tags->get_attribute( 'media' ) ) );
					$styles[] = self::asset( self::absolute( $href, $base ) ) + array(
						'head'  => ! $in_body,
						'media' => '' === $media ? 'all' : $media,
					);
				}
				if ( false !== strpos( $rel, ' preconnect ' ) || false !== strpos( $rel, ' dns-prefetch ' ) || false !== strpos( $rel, ' preload ' ) ) {
					$hints[] = array(
						'rel'  => trim( $rel ),
						'href' => self::absolute( $href, $base ),
						'as'   => (string) $tags->get_attribute( 'as' ),
					);
				}
				if ( preg_match( '/\.(woff2?|ttf|otf)(\?|$)/i', $href ) ) {
					$fonts[] = self::asset( self::absolute( $href, $base ) );
				}
				continue;
			}

			if ( 'STYLE' === $name && method_exists( $tags, 'get_modifiable_text' ) ) {
				$inline_css += strlen( (string) $tags->get_modifiable_text() );
				continue;
			}

			if ( 'IMG' === $name ) {
				$src = (string) $tags->get_attribute( 'src' );
				if ( '' === trim( $src ) || 0 === strpos( $src, 'data:' ) ) {
					continue;
				}
				$images[] = self::asset( self::absolute( $src, $base ) ) + array(
					'width'         => $tags->get_attribute( 'width' ),
					'height'        => $tags->get_attribute( 'height' ),
					'loading'       => strtolower( (string) $tags->get_attribute( 'loading' ) ),
					'fetchpriority' => strtolower( (string) $tags->get_attribute( 'fetchpriority' ) ),
					'srcset'        => '' !== (string) $tags->get_attribute( 'srcset' ),
					'above_fold'    => ! $in_body ? true : count( $images ) < 2,
				);
				continue;
			}

			if ( 'SOURCE' === $name ) {
				$type = strtolower( (string) $tags->get_attribute( 'type' ) );
				if ( false !== strpos( $type, 'webp' ) || false !== strpos( $type, 'avif' ) ) {
					$modern = true;
				}
				continue;
			}

			if ( 'IFRAME' === $name ) {
				$iframes++;
				continue;
			}
		}

		foreach ( $images as $image ) {
			if ( preg_match( '/\.(webp|avif)(\?|$)/i', (string) $image['url'] ) ) {
				$modern = true;
				break;
			}
		}

		$third_party = array();
		foreach ( array_merge( $scripts, $styles, $images, $fonts ) as $asset ) {
			if ( ! $asset['local'] && '' !== $asset['host'] ) {
				$third_party[ $asset['host'] ] = ( $third_party[ $asset['host'] ] ?? 0 ) + 1;
			}
		}
		arsort( $third_party );

		return array(
			'scripts'          => $scripts,
			'styles'           => $styles,
			'images'           => $images,
			'fonts'            => $fonts,
			'hints'            => $hints,
			'iframes'          => $iframes,
			'elements'         => $elements,
			'inline_js_bytes'  => $inline_js,
			'inline_css_bytes' => $inline_css,
			'third_party'      => $third_party,
			'modern_images'    => $modern,
		);
	}

	/**
	 * Totals derived from a profile.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @return array<string,int|bool>
	 */
	public static function totals( array $profile ): array {
		$css_bytes   = (int) array_sum( array_map( static fn( $a ) => (int) $a['bytes'], $profile['styles'] ) );
		$js_bytes    = (int) array_sum( array_map( static fn( $a ) => (int) $a['bytes'], $profile['scripts'] ) );
		$image_bytes = (int) array_sum( array_map( static fn( $a ) => (int) $a['bytes'], $profile['images'] ) );

		$blocking_js  = array_values(
			array_filter( $profile['scripts'], static fn( $s ) => $s['head'] && ! $s['async'] && ! $s['defer'] )
		);
		$blocking_css = array_values(
			array_filter( $profile['styles'], static fn( $s ) => $s['head'] && in_array( $s['media'], array( 'all', 'screen' ), true ) )
		);

		return array(
			'requests'          => count( $profile['scripts'] ) + count( $profile['styles'] ) + count( $profile['images'] ) + count( $profile['fonts'] ) + 1,
			'css_files'         => count( $profile['styles'] ),
			'js_files'          => count( $profile['scripts'] ),
			'image_files'       => count( $profile['images'] ),
			'font_files'        => count( $profile['fonts'] ),
			'css_bytes'         => $css_bytes,
			'js_bytes'          => $js_bytes,
			'image_bytes'       => $image_bytes,
			'html_bytes'        => (int) $profile['html_bytes'],
			'total_bytes'       => $css_bytes + $js_bytes + $image_bytes + (int) $profile['html_bytes'],
			'blocking_js'       => count( $blocking_js ),
			'blocking_css'      => count( $blocking_css ),
			'third_party_hosts' => count( (array) $profile['third_party'] ),
			'inline_bytes'      => (int) $profile['inline_js_bytes'] + (int) $profile['inline_css_bytes'],
			'measured_assets'   => count(
				array_filter(
					array_merge( $profile['styles'], $profile['scripts'], $profile['images'] ),
					static fn( $a ) => null !== $a['bytes']
				)
			),
		);
	}

	/**
	 * Candidate pages a developer is most likely to test.
	 *
	 * @return array<int,array{label:string,url:string}>
	 */
	public static function candidates(): array {
		$pages = array(
			array(
				'label' => __( 'Home page', 'probe-site-doctor' ),
				'url'   => home_url( '/' ),
			),
		);

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page && 'publish' === get_post_status( $posts_page ) ) {
			$pages[] = array(
				'label' => __( 'Blog index', 'probe-site-doctor' ),
				'url'   => (string) get_permalink( $posts_page ),
			);
		}

		foreach ( array( 'post' => __( 'Latest post', 'probe-site-doctor' ), 'page' => __( 'Latest page', 'probe-site-doctor' ), 'product' => __( 'Latest product', 'probe-site-doctor' ) ) as $type => $label ) {
			if ( 'product' === $type && ! post_type_exists( 'product' ) ) {
				continue;
			}
			$latest = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 1,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'suppress_filters' => false,
					'fields'           => 'ids',
				)
			);
			if ( $latest ) {
				$permalink = (string) get_permalink( (int) $latest[0] );
				if ( '' !== $permalink && home_url( '/' ) !== $permalink ) {
					$pages[] = array(
						'label' => $label,
						'url'   => $permalink,
					);
				}
			}
		}

		$seen = array();
		$out  = array();
		foreach ( $pages as $page ) {
			if ( isset( $seen[ $page['url'] ] ) ) {
				continue;
			}
			$seen[ $page['url'] ] = true;
			$out[]                = $page;
		}

		return $out;
	}

	/**
	 * Accepts only URLs served by this site.
	 *
	 * @param string $url URL.
	 * @return string Normalised URL, or '' when it is not local.
	 */
	public static function normalize( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return home_url( '/' );
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}
		$url  = esc_url_raw( $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $url || $host !== $home ) {
			return '';
		}
		return $url;
	}

	/**
	 * Path used to key field data, so the same page matches across devices.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		return '/' === $path ? $path : untrailingslashit( $path );
	}

	/**
	 * Requests a few local assets to read their caching and compression headers.
	 *
	 * @param array<string,mixed> $profile Profile.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sample_asset_headers( array $profile ): array {
		$candidates = array();
		foreach ( array_merge( $profile['styles'], $profile['scripts'] ) as $asset ) {
			if ( $asset['local'] && count( $candidates ) < self::HEADER_SAMPLES ) {
				$candidates[] = $asset['url'];
			}
		}

		$samples = array();
		foreach ( $candidates as $url ) {
			$response  = Loopback::get( $url, 8 );
			$samples[] = array(
				'url'          => $url,
				'status'       => $response['status'],
				'cache_control' => (string) ( $response['headers']['cache-control'] ?? '' ),
				'expires'      => (string) ( $response['headers']['expires'] ?? '' ),
				'encoding'     => (string) ( $response['headers']['content-encoding'] ?? '' ),
				'etag'         => isset( $response['headers']['etag'] ),
			);
		}
		return $samples;
	}

	/**
	 * Describes one referenced asset.
	 *
	 * @param string $url Absolute URL.
	 * @return array{url:string,host:string,local:bool,bytes:int|null}
	 */
	private static function asset( string $url ): array {
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host = strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) );
		$local     = '' !== $host && $host === $site_host;

		return array(
			'url'   => $url,
			'host'  => $host,
			'local' => $local,
			'bytes' => $local ? self::local_size( $url ) : null,
		);
	}

	/**
	 * On-disk size of a local asset, or null when it cannot be resolved.
	 *
	 * Only files inside WordPress or wp-content are considered, and only known
	 * asset extensions, so arbitrary paths are never stat-ed.
	 *
	 * @param string $url Absolute URL on this host.
	 * @return int|null
	 */
	private static function local_size( string $url ): ?int {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( ! preg_match( '/\.(' . self::SIZEABLE . ')$/i', $path ) ) {
			return null;
		}

		$map = array(
			(string) wp_parse_url( content_url( '/' ), PHP_URL_PATH )  => trailingslashit( WP_CONTENT_DIR ),
			(string) wp_parse_url( includes_url( '/' ), PHP_URL_PATH ) => ABSPATH . WPINC . '/',
			(string) wp_parse_url( site_url( '/' ), PHP_URL_PATH )     => ABSPATH,
		);

		foreach ( $map as $prefix => $dir ) {
			if ( '' === $prefix || 0 !== strpos( $path, $prefix ) ) {
				continue;
			}
			$relative = rawurldecode( substr( $path, strlen( $prefix ) ) );
			if ( false !== strpos( $relative, '..' ) ) {
				return null;
			}
			$file   = wp_normalize_path( $dir . $relative );
			$inside = 0 === strpos( $file, wp_normalize_path( ABSPATH ) )
				|| 0 === strpos( $file, wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) ) );

			return $inside && is_file( $file ) ? (int) filesize( $file ) : null;
		}

		return null;
	}

	/**
	 * Resolves a reference against the analysed document.
	 *
	 * @param string $url  URL as written.
	 * @param string $base Document URL.
	 * @return string
	 */
	private static function absolute( string $url, string $base ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES );
		if ( 0 === strpos( $url, '//' ) ) {
			return ( 0 === strpos( $base, 'https' ) ? 'https:' : 'http:' ) . $url;
		}
		$parts = wp_parse_url( $base );
		$root  = ( $parts['scheme'] ?? 'http' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ) {
			$dir = rtrim( dirname( (string) ( $parts['path'] ?? '/' ) ), '/' );
			return $root . $dir . '/' . ltrim( $url, './' );
		}
		return $url;
	}
}
