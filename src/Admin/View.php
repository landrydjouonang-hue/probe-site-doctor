<?php
/**
 * Template rendering and formatting helpers.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Includes templates from /templates and formats values for display.
 */
final class View {

	/**
	 * Renders a template.
	 *
	 * @param string              $template Relative path without extension, e.g. "admin/dashboard".
	 * @param array<string,mixed> $vars     Variables exposed to the template as $vars.
	 * @return void
	 */
	public static function render( string $template, array $vars = array() ): void {
		$file = PROBESD_PATH . 'templates/' . $template . '.php';
		if ( ! preg_match( '#^[a-z0-9/_-]+$#', $template ) || ! is_readable( $file ) ) {
			return;
		}
		( static function () use ( $file, $vars ) {
			include $file;
		} )();
	}

	/**
	 * Renders a template into a string, for documents that are written to a
	 * file or a download instead of the current page.
	 *
	 * @param string              $template Relative path without extension.
	 * @param array<string,mixed> $vars     Variables exposed to the template as $vars.
	 * @return string
	 */
	public static function capture( string $template, array $vars = array() ): string {
		ob_start();
		self::render( $template, $vars );
		return (string) ob_get_clean();
	}

	/**
	 * Formats a UTC datetime in the site's timezone and format.
	 *
	 * @param string|null $gmt MySQL datetime (UTC).
	 * @return string
	 */
	public static function datetime( ?string $gmt ): string {
		if ( ! $gmt ) {
			return '—';
		}
		return (string) get_date_from_gmt( $gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * "5 minutes ago" style relative time.
	 *
	 * @param string|null $gmt MySQL datetime (UTC).
	 * @return string
	 */
	public static function ago( ?string $gmt ): string {
		if ( ! $gmt ) {
			return '';
		}
		$time = strtotime( $gmt . ' UTC' );
		if ( ! $time ) {
			return '';
		}
		/* translators: %s: Human-readable time difference, e.g. "5 minutes". */
		return sprintf( __( '%s ago', 'probe-site-doctor' ), human_time_diff( $time, time() ) );
	}

	/**
	 * Duration between two UTC datetimes, e.g. "3 s".
	 *
	 * @param string|null $start Start.
	 * @param string|null $end   End.
	 * @return string
	 */
	public static function duration( ?string $start, ?string $end ): string {
		if ( ! $start || ! $end ) {
			return '—';
		}
		$seconds = max( 0, (int) strtotime( $end . ' UTC' ) - (int) strtotime( $start . ' UTC' ) );
		/* translators: %s: Number of seconds. */
		return sprintf( _n( '%s second', '%s seconds', $seconds, 'probe-site-doctor' ), number_format_i18n( $seconds ) );
	}

	/**
	 * Renders structured result data as accessible HTML (escaped).
	 *
	 * @param array<string,mixed> $data Data.
	 * @return string
	 */
	public static function data( array $data ): string {
		if ( ! $data ) {
			return '';
		}

		$html = '<dl class="probesd-data">';
		foreach ( $data as $key => $value ) {
			$html .= '<dt>' . esc_html( self::humanize( (string) $key ) ) . '</dt>';
			$html .= '<dd>' . self::value( (string) $key, $value ) . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * Renders a single value.
	 *
	 * @param string $key   Key (used for unit hints).
	 * @param mixed  $value Value.
	 * @return string
	 */
	private static function value( string $key, $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? esc_html__( 'Yes', 'probe-site-doctor' ) : esc_html__( 'No', 'probe-site-doctor' );
		}
		if ( null === $value || '' === $value ) {
			return '—';
		}
		if ( is_int( $value ) && ( 'bytes' === $key || str_ends_with( $key, '_bytes' ) ) ) {
			return esc_html( (string) size_format( $value, 1 ) );
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && str_ends_with( $key, '_ms' ) ) {
			/* translators: %s: Number of milliseconds. */
			return esc_html( sprintf( __( '%s ms', 'probe-site-doctor' ), number_format_i18n( $value, is_float( $value ) ? 2 : 0 ) ) );
		}
		if ( is_scalar( $value ) ) {
			return esc_html( is_int( $value ) || is_float( $value ) ? number_format_i18n( $value, is_float( $value ) ? 1 : 0 ) : (string) $value );
		}
		if ( ! is_array( $value ) || ! $value ) {
			return '—';
		}

		// List of scalars.
		if ( self::is_list( $value ) && ! is_array( reset( $value ) ) ) {
			return esc_html( implode( ', ', array_map( 'strval', $value ) ) );
		}

		// List of records → table.
		if ( self::is_list( $value ) && is_array( reset( $value ) ) ) {
			$columns = array_keys( (array) reset( $value ) );
			$html    = '<table class="widefat striped probesd-data-table"><thead><tr>';
			foreach ( $columns as $column ) {
				$html .= '<th scope="col">' . esc_html( self::humanize( (string) $column ) ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $value as $row ) {
				$html .= '<tr>';
				foreach ( $columns as $column ) {
					$html .= '<td>' . self::value( (string) $column, is_array( $row ) ? ( $row[ $column ] ?? null ) : null ) . '</td>';
				}
				$html .= '</tr>';
			}
			return $html . '</tbody></table>';
		}

		return self::data( $value );
	}

	/**
	 * "memory_used_pct" → "Memory used pct".
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function humanize( string $key ): string {
		return ucfirst( str_replace( array( '_', '-' ), ' ', $key ) );
	}

	/**
	 * array_is_list() equivalent (the function is PHP 8.1+).
	 *
	 * @param array<mixed> $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}

