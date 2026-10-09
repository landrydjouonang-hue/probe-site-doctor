<?php
/**
 * Check registration system.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

use ProbeSiteDoctor\Diagnostics\Contracts\Check;

defined( 'ABSPATH' ) || exit;

/**
 * Collects checks from the plugin and from third parties.
 *
 * Built-in checks are registered first; then the `probesd_register_checks`
 * action fires with the registry so add-ons can call register()/unregister().
 * The `probesd_checks` filter gets the final say.
 */
final class CheckRegistry {

	/**
	 * Registered checks keyed by ID.
	 *
	 * @var array<string,Check>
	 */
	private array $checks = array();

	/**
	 * Whether collection already happened.
	 */
	private bool $loaded = false;

	/**
	 * Category registry used for validation.
	 */
	private CategoryRegistry $categories;

	/**
	 * Built-in check class names.
	 *
	 * @var string[]
	 */
	private array $builtin;

	/**
	 * Constructor.
	 *
	 * @param CategoryRegistry $categories Categories.
	 * @param string[]         $builtin    Built-in check class names.
	 */
	public function __construct( CategoryRegistry $categories, array $builtin ) {
		$this->categories = $categories;
		$this->builtin    = $builtin;
	}

	/**
	 * Registers a check.
	 *
	 * Invalid IDs, unknown categories and duplicate IDs are rejected (with a
	 * _doing_it_wrong() notice) rather than throwing, so a faulty add-on can
	 * never take the dashboard down.
	 *
	 * @param Check $check Check instance.
	 * @return bool Whether it was registered.
	 */
	public function register( Check $check ): bool {
		$id = $check->get_id();

		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{1,98}[a-z0-9]$/', $id ) ) {
			$this->doing_it_wrong( sprintf( 'Check ID "%s" is invalid. Use 3–100 lowercase letters, digits, dots, dashes or underscores.', $id ) );
			return false;
		}
		if ( isset( $this->checks[ $id ] ) ) {
			$this->doing_it_wrong( sprintf( 'A check with the ID "%s" is already registered.', $id ) );
			return false;
		}
		if ( ! $this->categories->has( $check->get_category() ) ) {
			$this->doing_it_wrong( sprintf( 'Check "%1$s" uses the unknown category "%2$s".', $id, $check->get_category() ) );
			return false;
		}

		$this->checks[ $id ] = $check;
		return true;
	}

	/**
	 * Removes a check.
	 *
	 * @param string $id Check ID.
	 * @return void
	 */
	public function unregister( string $id ): void {
		unset( $this->checks[ $id ] );
	}

	/**
	 * Whether a check is registered.
	 *
	 * @param string $id Check ID.
	 * @return bool
	 */
	public function has( string $id ): bool {
		$this->load();
		return isset( $this->checks[ $id ] );
	}

	/**
	 * Single check.
	 *
	 * @param string $id Check ID.
	 * @return Check|null
	 */
	public function get( string $id ): ?Check {
		$this->load();
		return $this->checks[ $id ] ?? null;
	}

	/**
	 * All registered checks, ordered by category display order then label.
	 *
	 * @return array<string,Check>
	 */
	public function all(): array {
		$this->load();
		return $this->checks;
	}

	/**
	 * Checks applicable to the current environment.
	 *
	 * @param string|null $category Optional category filter.
	 * @return array<string,Check>
	 */
	public function applicable( ?string $category = null ): array {
		$checks = array();
		foreach ( $this->all() as $id => $check ) {
			if ( null !== $category && $check->get_category() !== $category ) {
				continue;
			}
			try {
				if ( $check->is_applicable() ) {
					$checks[ $id ] = $check;
				}
			} catch ( \Throwable $e ) {
				// A check that cannot decide applicability is still offered; run() will report the error.
				$checks[ $id ] = $check;
			}
		}
		return $checks;
	}

	/**
	 * Collects checks once.
	 *
	 * @return void
	 */
	private function load(): void {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		foreach ( $this->builtin as $class_name ) {
			if ( class_exists( $class_name ) ) {
				$this->register( new $class_name() );
			}
		}

		/**
		 * Fires when checks are collected. Call $registry->register( new My_Check() ).
		 *
		 * @since 0.1.0
		 *
		 * @param CheckRegistry $registry The registry.
		 */
		do_action( 'probesd_register_checks', $this );

		/**
		 * Filters the final list of checks.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,Check> $checks Checks keyed by ID.
		 */
		$filtered = apply_filters( 'probesd_checks', $this->checks );

		$checks = array();
		foreach ( (array) $filtered as $check ) {
			if ( $check instanceof Check ) {
				$checks[ $check->get_id() ] = $check;
			}
		}

		$order = array_flip( array_keys( $this->categories->all() ) );
		uasort(
			$checks,
			static function ( Check $a, Check $b ) use ( $order ) {
				$ca = $order[ $a->get_category() ] ?? PHP_INT_MAX;
				$cb = $order[ $b->get_category() ] ?? PHP_INT_MAX;
				return $ca <=> $cb ?: strcasecmp( $a->get_label(), $b->get_label() );
			}
		);

		$this->checks = $checks;
	}

	/**
	 * Emits a developer notice.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function doing_it_wrong( string $message ): void {
		_doing_it_wrong( __CLASS__ . '::register', esc_html( $message ), '0.1.0' );
	}
}
