<?php
/**
 * Hook loader — collects actions/filters and registers them in bulk.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Loader {

	private array $actions = [];
	private array $filters = [];

	public function add_action( string $hook, $component, string $callback, int $priority = 10, int $args = 1 ): void {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
	}

	public function add_filter( string $hook, $component, string $callback, int $priority = 10, int $args = 1 ): void {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'args' );
	}

	public function run(): void {
		foreach ( $this->filters as $f ) {
			add_filter( $f['hook'], [ $f['component'], $f['callback'] ], $f['priority'], $f['args'] );
		}
		foreach ( $this->actions as $a ) {
			add_action( $a['hook'], [ $a['component'], $a['callback'] ], $a['priority'], $a['args'] );
		}
	}
}
