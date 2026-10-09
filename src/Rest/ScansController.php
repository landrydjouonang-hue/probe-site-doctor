<?php
/**
 * REST API: scans and checks.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Rest;

use ProbeSiteDoctor\Core\Capabilities;
use ProbeSiteDoctor\Diagnostics\CheckRegistry;
use ProbeSiteDoctor\Diagnostics\Scanner;
use ProbeSiteDoctor\Diagnostics\ScanInProgressException;
use ProbeSiteDoctor\Reporting\ReportBuilder;
use ProbeSiteDoctor\Storage\ScanRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /probe-site-doctor/v1.
 *
 *   GET    /checks                       Registered checks.
 *   GET    /scans                        Scan history.
 *   POST   /scans                        Start a scan; returns the checks to run.
 *   GET    /scans/{id}                   Full report.
 *   DELETE /scans/{id}                   Delete a report.
 *   POST   /scans/{id}/checks/{check}    Run one check within a running scan.
 *   POST   /scans/{id}/complete          Finalise a scan.
 */
final class ScansController {

	public const NAMESPACE = 'probe-site-doctor/v1';

	private Scanner $scanner;
	private ScanRepository $scans;
	private ReportBuilder $reports;
	private CheckRegistry $checks;

	/**
	 * Constructor.
	 *
	 * @param Scanner        $scanner Scanner.
	 * @param ScanRepository $scans   Scans.
	 * @param ReportBuilder  $reports Reports.
	 * @param CheckRegistry  $checks  Checks.
	 */
	public function __construct( Scanner $scanner, ScanRepository $scans, ReportBuilder $reports, CheckRegistry $checks ) {
		$this->scanner = $scanner;
		$this->scans   = $scans;
		$this->reports = $reports;
		$this->checks  = $checks;
	}

	/**
	 * Registers routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/checks',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_checks' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_scans' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 20,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_scan' ),
					'permission_callback' => array( $this, 'can_run' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_scan' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_scan' ),
					'permission_callback' => array( $this, 'can_run' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans/(?P<id>\d+)/checks/(?P<check>[a-z0-9._-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_check' ),
				'permission_callback' => array( $this, 'can_run' ),
				'args'                => $id_arg + array(
					'check' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^[a-z0-9._-]+$',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scans/(?P<id>\d+)/complete',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'complete_scan' ),
				'permission_callback' => array( $this, 'can_run' ),
				'args'                => $id_arg,
			)
		);
	}

	/**
	 * Permission: read reports.
	 *
	 * @return bool
	 */
	public function can_view(): bool {
		return current_user_can( Capabilities::VIEW_REPORTS );
	}

	/**
	 * Permission: run scans / delete reports.
	 *
	 * @return bool
	 */
	public function can_run(): bool {
		return current_user_can( Capabilities::RUN_SCANS );
	}

	/**
	 * GET /checks
	 *
	 * @return WP_REST_Response
	 */
	public function list_checks(): WP_REST_Response {
		$items = array();
		foreach ( $this->checks->all() as $check ) {
			$items[] = $this->describe_check( $check->get_id() );
		}
		return rest_ensure_response( $items );
	}

	/**
	 * GET /scans
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_scans( WP_REST_Request $request ): WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$total    = $this->scans->count();
		$response = rest_ensure_response( $this->scans->paginate( $per_page, (int) $request['page'] ) );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $per_page ) ) );
		return $response;
	}

	/**
	 * POST /scans
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_scan() {
		try {
			$started = $this->scanner->start( 'manual', get_current_user_id() );
		} catch ( ScanInProgressException $e ) {
			return new WP_Error( 'probesd_scan_in_progress', $e->getMessage(), array( 'status' => 409, 'scan_id' => $e->get_scan_id() ) );
		} catch ( \RuntimeException $e ) {
			return new WP_Error( 'probesd_scan_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		$response = rest_ensure_response(
			array(
				'scan_id' => $started['scan_id'],
				'checks'  => array_map( array( $this, 'describe_check' ), $started['checks'] ),
			)
		);
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * GET /scans/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_scan( WP_REST_Request $request ) {
		$scan = $this->scans->find( (int) $request['id'] );
		if ( ! $scan ) {
			return $this->not_found();
		}
		return rest_ensure_response( $this->reports->build( $scan ) );
	}

	/**
	 * DELETE /scans/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_scan( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! $this->scans->find( $id ) ) {
			return $this->not_found();
		}
		$this->scanner->delete( $id );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * POST /scans/{id}/checks/{check}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_check( WP_REST_Request $request ) {
		try {
			$result = $this->scanner->run_check( (int) $request['id'], (string) $request['check'] );
		} catch ( \DomainException $e ) {
			return new WP_Error( 'probesd_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $result->to_array() );
	}

	/**
	 * POST /scans/{id}/complete
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_scan( WP_REST_Request $request ) {
		try {
			$scan = $this->scanner->complete( (int) $request['id'] );
		} catch ( \DomainException $e ) {
			return new WP_Error( 'probesd_invalid_request', $e->getMessage(), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'scan'       => $scan,
				'report_url' => add_query_arg(
					array(
						'page' => 'probe-site-doctor',
						'scan' => $scan['id'],
					),
					admin_url( 'admin.php' )
				),
			)
		);
	}

	/**
	 * Public description of a check.
	 *
	 * @param string $id Check ID.
	 * @return array<string,string>
	 */
	private function describe_check( string $id ): array {
		$check = $this->checks->get( $id );
		return array(
			'id'          => $id,
			'label'       => $check ? $check->get_label() : $id,
			'description' => $check ? $check->get_description() : '',
			'category'    => $check ? $check->get_category() : '',
		);
	}

	/**
	 * 404 error.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'probesd_not_found', __( 'Scan not found.', 'probe-site-doctor' ), array( 'status' => 404 ) );
	}
}
