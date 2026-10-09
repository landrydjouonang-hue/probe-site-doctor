<?php
/**
 * Plugin settings.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the single settings option.
 */
final class Settings {

	public const OPTION = 'probesd_settings';

	/**
	 * Default values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'retention'           => 20,
			// Field data collection is off until someone switches it on: it adds a
			// small script to the public site, so it is never enabled silently.
			'web_vitals'          => false,
			'web_vitals_sampling' => 100,
			'web_vitals_days'     => 30,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Number of completed scans to keep (1–200).
	 *
	 * @return int
	 */
	public function retention(): int {
		/**
		 * Filters how many completed scans are kept.
		 *
		 * @since 0.1.0
		 *
		 * @param int $retention Number of scans.
		 */
		$retention = (int) apply_filters( 'probesd_scan_retention', (int) $this->all()['retention'] );
		return max( 1, min( 200, $retention ) );
	}

	/**
	 * Whether the field-data collector may run on the public site.
	 *
	 * @return bool
	 */
	public function web_vitals_enabled(): bool {
		/**
		 * Filters whether browser field data is collected.
		 *
		 * @since 0.8.0
		 *
		 * @param bool $enabled Whether the collector is enabled.
		 */
		return (bool) apply_filters( 'probesd_web_vitals_enabled', (bool) $this->all()['web_vitals'] );
	}

	/**
	 * Percentage of page views that report field data (1–100).
	 *
	 * @return int
	 */
	public function web_vitals_sampling(): int {
		return max( 1, min( 100, (int) $this->all()['web_vitals_sampling'] ) );
	}

	/**
	 * Days of field data to keep (1–365).
	 *
	 * @return int
	 */
	public function web_vitals_days(): int {
		return max( 1, min( 365, (int) $this->all()['web_vitals_days'] ) );
	}

	/**
	 * Saves the settings a screen may change, ignoring anything else.
	 *
	 * @param array<string,mixed> $input Raw input.
	 * @return array<string,mixed> The stored settings.
	 */
	public function save( array $input ): array {
		$current = $this->all();

		if ( array_key_exists( 'retention', $input ) ) {
			$current['retention'] = max( 1, min( 200, (int) $input['retention'] ) );
		}
		if ( array_key_exists( 'web_vitals', $input ) ) {
			$current['web_vitals'] = (bool) $input['web_vitals'];
		}
		if ( array_key_exists( 'web_vitals_sampling', $input ) ) {
			$current['web_vitals_sampling'] = max( 1, min( 100, (int) $input['web_vitals_sampling'] ) );
		}
		if ( array_key_exists( 'web_vitals_days', $input ) ) {
			$current['web_vitals_days'] = max( 1, min( 365, (int) $input['web_vitals_days'] ) );
		}

		update_option( self::OPTION, $current, false );

		return $current;
	}
}
