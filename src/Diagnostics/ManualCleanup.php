<?php
/**
 * Optional manual cleanup instructions.
 *
 * @package ProbeSiteDoctor
 */

namespace ProbeSiteDoctor\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Describes how a site owner could clean up a finding by hand.
 *
 * This is documentation only. Site Doctor never executes these steps or
 * commands. Any future automated cleanup must go through
 * ProbeSiteDoctor\Cleanup\CleanupRunner, which requires explicit, typed
 * confirmation.
 */
final class ManualCleanup {

	public const TYPE_SQL    = 'sql';
	public const TYPE_WP_CLI = 'wp-cli';
	public const TYPE_PHP    = 'php';

	/** Removing or changing data. */
	public const KIND_CLEANUP = 'cleanup';

	/** Updating software (core, plugins, themes, PHP). */
	public const KIND_UPDATE = 'update';

	/** Changing a configuration or hardening setting. */
	public const KIND_HARDEN = 'harden';

	/** Editing content or content settings (titles, alt text, links). */
	public const KIND_CONTENT = 'content';

	private string $summary;

	private string $kind = self::KIND_CLEANUP;

	/** @var string[] */
	private array $steps;

	private bool $destructive;

	/** @var array<int,array{label:string,command:string,type:string}> */
	private array $commands = array();

	/**
	 * Constructor.
	 *
	 * @param string   $summary     What the cleanup achieves.
	 * @param string[] $steps       Ordered, human-readable steps.
	 * @param bool     $destructive Whether the steps permanently delete or alter data.
	 */
	public function __construct( string $summary, array $steps = array(), bool $destructive = true ) {
		$this->summary     = $summary;
		$this->steps       = array_values( array_map( 'strval', $steps ) );
		$this->destructive = $destructive;
	}

	/**
	 * Returns a copy with an example command appended.
	 *
	 * @param string $label   What the command does.
	 * @param string $command Command text (shown for copying, never run).
	 * @param string $type    One of the TYPE_* constants.
	 * @return self
	 */
	public function with_command( string $label, string $command, string $type = self::TYPE_SQL ): self {
		$clone             = clone $this;
		$clone->commands[] = array(
			'label'   => $label,
			'command' => $command,
			'type'    => in_array( $type, array( self::TYPE_SQL, self::TYPE_WP_CLI, self::TYPE_PHP ), true ) ? $type : self::TYPE_SQL,
		);
		return $clone;
	}

	/**
	 * Returns a copy describing manual update steps instead of cleanup.
	 * Site Doctor never updates anything itself either.
	 *
	 * @return self
	 */
	public function as_update(): self {
		$clone       = clone $this;
		$clone->kind = self::KIND_UPDATE;
		return $clone;
	}

	/**
	 * Returns a copy describing manual configuration changes. Site Doctor
	 * never changes settings itself.
	 *
	 * @return self
	 */
	public function as_hardening(): self {
		$clone       = clone $this;
		$clone->kind = self::KIND_HARDEN;
		return $clone;
	}

	/**
	 * Returns a copy describing manual content fixes. Site Doctor never edits
	 * posts, pages, media or their metadata.
	 *
	 * @return self
	 */
	public function as_content_fix(): self {
		$clone       = clone $this;
		$clone->kind = self::KIND_CONTENT;
		return $clone;
	}

	/**
	 * Array form (storage and REST).
	 *
	 * @return array{kind:string,summary:string,steps:string[],commands:array<int,array{label:string,command:string,type:string}>,destructive:bool}
	 */
	public function to_array(): array {
		return array(
			'kind'        => $this->kind,
			'summary'     => $this->summary,
			'steps'       => $this->steps,
			'commands'    => $this->commands,
			'destructive' => $this->destructive,
		);
	}

	/**
	 * Normalises stored data for display; returns an empty array when invalid.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array<string,mixed>
	 */
	public static function normalize( $data ): array {
		if ( ! is_array( $data ) || empty( $data['summary'] ) ) {
			return array();
		}
		$commands = array();
		foreach ( (array) ( $data['commands'] ?? array() ) as $command ) {
			if ( is_array( $command ) && isset( $command['command'] ) ) {
				$commands[] = array(
					'label'   => (string) ( $command['label'] ?? '' ),
					'command' => (string) $command['command'],
					'type'    => (string) ( $command['type'] ?? self::TYPE_SQL ),
				);
			}
		}
		return array(
			'kind'        => in_array( $data['kind'] ?? '', array( self::KIND_UPDATE, self::KIND_HARDEN, self::KIND_CONTENT ), true ) ? (string) $data['kind'] : self::KIND_CLEANUP,
			'summary'     => (string) $data['summary'],
			'steps'       => array_values( array_map( 'strval', (array) ( $data['steps'] ?? array() ) ) ),
			'commands'    => $commands,
			'destructive' => ! empty( $data['destructive'] ),
		);
	}
}
