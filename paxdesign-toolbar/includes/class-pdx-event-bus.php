<?php
/**
 * PDX_EventBus — internal synchronous event bus.
 *
 * Decouples platform components. Listeners registered at boot time;
 * events fired synchronously during request lifecycle.
 * Async delivery is handled by PDX_Queue (enqueue a job on fire).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_EventBus {

	private static ?self $instance  = null;
	private static array $listeners = [];
	private static array $fired     = [];

	private function __construct() {}

	/** Singleton accessor — allows bootstrap to call PDX_EventBus::instance(). */
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/* ── Registration ───────────────────────────────────── */

	public static function on( string $event, callable $listener, int $priority = 10 ): void {
		self::$listeners[ $event ][ $priority ][] = $listener;
	}

	public static function once( string $event, callable $listener, int $priority = 10 ): void {
		$wrapper = null;
		$wrapper = static function() use ( $event, $listener, &$wrapper ) {
			$listener( ...func_get_args() );
			self::off( $event, $wrapper );
		};
		self::on( $event, $wrapper, $priority );
	}

	public static function off( string $event, callable $listener ): void {
		if ( ! isset( self::$listeners[ $event ] ) ) return;
		foreach ( self::$listeners[ $event ] as $priority => $listeners ) {
			foreach ( $listeners as $i => $l ) {
				if ( $l === $listener ) {
					unset( self::$listeners[ $event ][ $priority ][ $i ] );
				}
			}
		}
	}

	/* ── Dispatch ───────────────────────────────────────── */

	public static function fire( string $event, array $payload = [] ): void {
		self::$fired[] = [ 'event' => $event, 'ts' => microtime( true ), 'payload' => $payload ];

		if ( ! isset( self::$listeners[ $event ] ) ) return;

		$sorted = self::$listeners[ $event ];
		ksort( $sorted );

		foreach ( $sorted as $listeners ) {
			foreach ( $listeners as $listener ) {
				try {
					$listener( $payload );
				} catch ( \Throwable $e ) {
					// Log but don't halt — guard against circular dependency at boot
					if ( class_exists( 'PDX_Audit', false ) ) {
						PDX_Audit::log( 'event_bus', 'listener_error', [
							'event' => $event,
							'error' => $e->getMessage(),
							'class' => get_class( $e ),
						], 'error' );
					}
				}
			}
		}

		// Also dispatch to webhooks asynchronously via queue
		if ( ! in_array( $event, [ 'audit.log', 'queue.enqueue' ], true )
			&& class_exists( 'PDX_Webhook', false ) ) {
			PDX_Webhook::dispatch( $event, $payload );
		}
	}

	/* ── Introspection ──────────────────────────────────── */

	public static function fired_events(): array {
		return self::$fired;
	}

	public static function listener_count( string $event = '' ): int {
		if ( $event ) {
			return array_sum( array_map( 'count', self::$listeners[ $event ] ?? [] ) );
		}
		$total = 0;
		foreach ( self::$listeners as $listeners ) {
			foreach ( $listeners as $group ) $total += count( $group );
		}
		return $total;
	}

	/* ── Built-in platform events ───────────────────────── */

	public static function register_platform_listeners(): void {
		// Audit every scan completion
		self::on( 'scan.completed', static function( array $p ) {
			PDX_Audit::log( $p['module'] ?? 'scan', 'scan_completed', $p );
		} );

		// Track billing usage on scan
		self::on( 'scan.completed', static function( array $p ) {
			if ( is_user_logged_in() ) {
				PDX_Billing::record_usage( get_current_user_id(), 'scan', 1, $p['module'] ?? 'unknown' );
			}
		} );

		// Audit payment events
		self::on( 'payment.captured', static function( array $p ) {
			PDX_Audit::log( 'commerce', 'payment_captured', $p, 'info' );
		} );

		// Workspace activity log
		self::on( 'workspace.created', static function( array $p ) {
			PDX_Audit::log( $p['module'] ?? 'workspace', 'workspace_created', $p );
		} );

		// Worker heartbeat failure
		self::on( 'worker.heartbeat_missed', static function( array $p ) {
			PDX_Audit::log( 'worker', 'heartbeat_missed', $p, 'warn' );
		} );

		// Critical audit events → webhook
		self::on( 'audit.critical', static function( array $p ) {
			PDX_Webhook::dispatch( 'audit.critical', $p );
		} );
	}
}
