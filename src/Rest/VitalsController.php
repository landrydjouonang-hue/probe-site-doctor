<?php
/**
 * REST API: field data collection.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Rest;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Core\Settings;
use ProbeSiteDoctor\Performance\Vitals;
use ProbeSiteDoctor\Storage\VitalsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /probe-site-doctor/v1:
 *
 *   POST /vitals    Receive one page view's metrics from a visitor's browser.
 *   GET  /vitals    Read the stored summary (administrators only).
 *
 * The POST route has to be public — it is called by anonymous visitors — so it
 * is deliberately narrow: it exists only while collection is enabled, accepts
 * only same-origin requests, stores only whitelisted metrics within plausible
 * ranges, keeps no visitor identifier, and is rate limited per address.
 */
final class VitalsController {

	/** Requests accepted per address per window. */
	private const RATE_LIMIT = 40;

	/** Rate-limit window. */
	private const RATE_WINDOW = 300;

	private Settings $settings;
	private VitalsRepository $vitals;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings.
	 * @param VitalsRepository $vitals   Repository.
	 */
	public function __construct( Settings $settings, VitalsRepository $vitals ) {
		$this->settings = $settings;
		$this->vitals   = $vitals;
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			ScansController::NAMESPACE,
			'/vitals',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'collect' ),
					'permission_callback' => array( $this, 'can_collect' ),
					'args'                => array(
						'path'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'device'  => array(
							'type'    => 'string',
							'enum'    => array( 'mobile', 'desktop' ),
							'default' => 'desktop',
						),
						'metrics' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'summary' ),
					'permission_callback' => static fn() => current_user_can( Capabilities::VIEW_REPORTS ),
					'args'                => array(
						'path'   => array( 'type' => 'string' ),
						'device' => array(
							'type' => 'string',
							'enum' => array( 'mobile', 'desktop' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Whether this request may report field data.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_collect( WP_REST_Request $request ) {
		if ( ! $this->settings->web_vitals_enabled() ) {
			return new WP_Error(
				'probesd_vitals_disabled',
				__( 'Field data collection is disabled.', 'probe-site-doctor' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->is_same_origin( $request ) ) {
			return new WP_Error(
				'probesd_vitals_origin',
				__( 'Field data is only accepted from this site.', 'probe-site-doctor' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->is_rate_limited() ) {
			return new WP_Error(
				'probesd_vitals_rate',
				__( 'Too many reports from this address.', 'probe-site-doctor' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Stores one page view's metrics.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function collect( WP_REST_Request $request ): WP_REST_Response {
		$metrics = array();
		foreach ( (array) $request->get_param( 'metrics' ) as $metric => $value ) {
			if ( is_numeric( $value ) ) {
				$metrics[ (string) $metric ] = (float) $value;
			}
		}

		$stored = $this->vitals->record(
			(string) $request->get_param( 'path' ),
			(string) $request->get_param( 'device' ),
			$metrics
		);

		// Housekeeping, occasionally, so the table cannot grow without bound
		// and no cron event is needed for it.
		if ( $stored > 0 && 1 === wp_rand( 1, 20 ) ) {
			$this->vitals->prune( $this->settings->web_vitals_days() );
		}

		return new WP_REST_Response( array( 'stored' => $stored ), $stored > 0 ? 201 : 200 );
	}

	/**
	 * Returns the stored summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function summary( WP_REST_Request $request ): WP_REST_Response {
		$path   = $request->get_param( 'path' );
		$device = $request->get_param( 'device' );

		return new WP_REST_Response(
			array(
				'enabled'  => $this->settings->web_vitals_enabled(),
				'days'     => $this->settings->web_vitals_days(),
				'metrics'  => $this->vitals->summary(
					is_string( $path ) && '' !== $path ? $path : null,
					is_string( $device ) && '' !== $device ? $device : null,
					$this->settings->web_vitals_days()
				),
				'paths'    => $this->vitals->paths( 20, $this->settings->web_vitals_days() ),
				'total'    => $this->vitals->total(),
				'note'     => Vitals::scope_note(),
			),
			200
		);
	}

	/**
	 * Whether the request came from this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_same_origin( WP_REST_Request $request ): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		foreach ( array( 'origin', 'referer' ) as $header ) {
			$value = (string) $request->get_header( $header );
			if ( '' === $value ) {
				continue;
			}
			if ( strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) ) === $host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Simple per-address limit. The address is hashed into a transient key and
	 * never stored.
	 *
	 * @return bool
	 */
	private function is_rate_limited(): bool {
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $address ) {
			return false;
		}

		$key   = 'probesd_vt_' . substr( md5( $address . wp_salt( 'nonce' ) ), 0, 20 );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return false;
	}
}
