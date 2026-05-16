<?php
/**
 * PDX_Container — lightweight service container / dependency injector.
 *
 * Supports: singleton bindings, factory bindings, aliasing, tagging.
 * Replaces direct `new Class()` calls throughout the platform.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Container {

	private static ?self $instance = null;

	private array $bindings   = [];
	private array $singletons = [];
	private array $instances  = [];
	private array $tags       = [];
	private array $aliases    = [];

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/* ── Binding ────────────────────────────────────────── */

	public function bind( string $abstract, callable $factory ): void {
		$this->bindings[ $abstract ] = $factory;
	}

	public function singleton( string $abstract, callable $factory ): void {
		$this->singletons[ $abstract ] = $factory;
	}

	public function instance( string $abstract, object $obj ): void {
		$this->instances[ $abstract ] = $obj;
	}

	public function alias( string $abstract, string $alias ): void {
		$this->aliases[ $alias ] = $abstract;
	}

	public function tag( string $tag, array $abstracts ): void {
		$this->tags[ $tag ] = array_merge( $this->tags[ $tag ] ?? [], $abstracts );
	}

	/* ── Resolution ─────────────────────────────────────── */

	public function make( string $abstract ): mixed {
		// Resolve alias
		$abstract = $this->aliases[ $abstract ] ?? $abstract;

		// Already instantiated singleton
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}

		// Singleton factory
		if ( isset( $this->singletons[ $abstract ] ) ) {
			$this->instances[ $abstract ] = ( $this->singletons[ $abstract ] )( $this );
			return $this->instances[ $abstract ];
		}

		// Regular factory
		if ( isset( $this->bindings[ $abstract ] ) ) {
			return ( $this->bindings[ $abstract ] )( $this );
		}

		// Attempt direct instantiation
		if ( class_exists( $abstract ) ) {
			return new $abstract();
		}

		throw new \RuntimeException( "PDX_Container: cannot resolve [{$abstract}]" );
	}

	public function tagged( string $tag ): array {
		return array_map( [ $this, 'make' ], $this->tags[ $tag ] ?? [] );
	}

	public function has( string $abstract ): bool {
		$abstract = $this->aliases[ $abstract ] ?? $abstract;
		return isset( $this->instances[ $abstract ] )
			|| isset( $this->singletons[ $abstract ] )
			|| isset( $this->bindings[ $abstract ] );
	}

	/* ── Introspection ──────────────────────────────────── */

	public function registered(): array {
		return array_unique( array_merge(
			array_keys( $this->bindings ),
			array_keys( $this->singletons ),
			array_keys( $this->instances )
		) );
	}
}

/** Global helper */
function pdx( string $abstract ): mixed {
	return PDX_Container::instance()->make( $abstract );
}
