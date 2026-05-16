<?php
/**
 * PDX_SSE — Server-Sent Events endpoint for real-time streaming.
 *
 * Endpoint: GET /wp-json/pdx/v1/stream
 * Query params:
 *   channels[] = queue|activity|scan:{job_id}|job:{job_id}
 *   token      = wp_rest nonce
 *
 * The client opens a persistent EventSource connection.
 * The server polls internal state and pushes events as SSE frames.
 * Heartbeat every 15s keeps the connection alive through proxies.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_SSE {

	const POLL_INTERVAL = 2;    // seconds between polls
	const MAX_RUNTIME   = 55;   // seconds before graceful close (before PHP timeout)
	const HEARTBEAT_INT = 15;   // seconds between heartbeat pings

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_rest_route( 'pdx/v1', '/sse', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'stream' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function stream( WP_REST_Request $req ): void {
		// Disable WP's default JSON response — we take over output
		if ( ob_get_level() ) ob_end_clean();

		// SSE headers
		header( 'Content-Type: text/event-stream; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Accel-Buffering: no' );   // Nginx: disable proxy buffering
		header( 'Connection: keep-alive' );
		header( 'Access-Control-Allow-Origin: ' . esc_url( home_url() ) );

		// Accept both ?channel=activity (singular) and ?channels[]=... (plural)
		$channel  = $req->get_param( 'channel' );
		$channels = $channel
			? [ sanitize_key( $channel ) ]
			: (array) ( $req->get_param( 'channels' ) ?? [ 'activity' ] );
		$user_id  = is_user_logged_in() ? get_current_user_id() : 0;
		$start    = time();
		$last_hb  = 0;
		$cursors  = [];  // per-channel last-seen IDs

		// Send initial connection event
		$this->send_event( 'connected', [
			'ts'       => time(),
			'channels' => $channels,
			'user_id'  => $user_id,
		] );

		while ( true ) {
			$now = time();

			// Graceful close before PHP max_execution_time
			if ( $now - $start >= self::MAX_RUNTIME ) {
				$this->send_event( 'close', [ 'reason' => 'timeout', 'reconnect' => true ] );
				break;
			}

			// Heartbeat
			if ( $now - $last_hb >= self::HEARTBEAT_INT ) {
				$this->send_comment( 'heartbeat ' . $now );
				$last_hb = $now;
			}

			// Poll each channel
			foreach ( $channels as $channel ) {
				$parts  = explode( ':', $channel, 2 );
				$type   = $parts[0];
				$param  = $parts[1] ?? '';

				switch ( $type ) {
					case 'queue':
						$this->poll_queue( $user_id, $cursors );
						break;
					case 'activity':
						$this->poll_activity( $user_id, $cursors );
						break;
					case 'job':
						if ( $param ) $this->poll_job( $param, $cursors );
						break;
					case 'scan':
						if ( $param ) $this->poll_scan( $param, $cursors );
						break;
					case 'workers':
						if ( current_user_can( PDX_CAP ) ) $this->poll_workers( $cursors );
						break;
				}
			}

			// Flush output buffer
			if ( function_exists( 'fastcgi_finish_request' ) ) {
				// Can't use fastcgi_finish_request for streaming
			}
			flush();

			// Check if client disconnected
			if ( connection_aborted() ) break;

			sleep( self::POLL_INTERVAL );

			// Refresh WP DB connection to avoid stale reads
			global $wpdb;
			$wpdb->check_connection( false );
		}

		exit;
	}

	/* ── Channel pollers ────────────────────────────────── */

	private function poll_queue( int $user_id, array &$cursors ): void {
		$jobs = PDX_Queue::get_user_jobs( '', 10, 0 );
		$key  = 'queue';

		$new = array_filter( $jobs, function( $j ) use ( $key, &$cursors ) {
			$sig = $j['job_id'] . ':' . $j['status'] . ':' . $j['progress'];
			if ( ( $cursors[ $key ][ $j['job_id'] ] ?? '' ) === $sig ) return false;
			$cursors[ $key ][ $j['job_id'] ] = $sig;
			return true;
		} );

		if ( ! empty( $new ) ) {
			$this->send_event( 'queue.update', [ 'jobs' => array_values( $new ) ] );
		}
	}

	private function poll_activity( int $user_id, array &$cursors ): void {
		$last_id = $cursors['activity_last_id'] ?? 0;
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, ts, module, action, severity, actor_email
			 FROM {$wpdb->prefix}pdx_audit
			 WHERE id > %d ORDER BY id ASC LIMIT 20",
			$last_id
		), ARRAY_A );

		if ( ! empty( $rows ) ) {
			$cursors['activity_last_id'] = (int) end( $rows )['id'];
			$this->send_event( 'activity.update', [ 'events' => $rows ] );
		}
	}

	private function poll_job( string $job_id, array &$cursors ): void {
		$job = PDX_Queue::get( $job_id );
		if ( ! $job ) return;

		$sig = $job['status'] . ':' . $job['progress'];
		$key = 'job:' . $job_id;
		if ( ( $cursors[ $key ] ?? '' ) === $sig ) return;
		$cursors[ $key ] = $sig;

		$this->send_event( 'job.update', [
			'job_id'   => $job_id,
			'status'   => $job['status'],
			'progress' => $job['progress'],
			'error'    => $job['error'],
			'result'   => $job['status'] === 'done' ? $job['result'] : null,
		] );
	}

	private function poll_scan( string $scan_id, array &$cursors ): void {
		// Scan progress is stored in transient during active scans
		$progress = get_transient( 'pdx_scan_progress_' . $scan_id );
		if ( ! $progress ) return;

		$sig = md5( serialize( $progress ) );
		$key = 'scan:' . $scan_id;
		if ( ( $cursors[ $key ] ?? '' ) === $sig ) return;
		$cursors[ $key ] = $sig;

		$this->send_event( 'scan.progress', array_merge( [ 'scan_id' => $scan_id ], $progress ) );
	}

	private function poll_workers( array &$cursors ): void {
		$workers = PDX_Worker::all();
		$sig     = md5( serialize( array_column( $workers, 'status' ) ) );
		if ( ( $cursors['workers'] ?? '' ) === $sig ) return;
		$cursors['workers'] = $sig;
		$this->send_event( 'workers.update', [ 'workers' => $workers ] );
	}

	/* ── SSE frame helpers ──────────────────────────────── */

	private function send_event( string $event, array $data, ?string $id = null ): void {
		if ( $id ) echo "id: {$id}\n";
		echo "event: {$event}\n";
		echo 'data: ' . wp_json_encode( $data ) . "\n\n";
	}

	private function send_comment( string $comment ): void {
		echo ": {$comment}\n\n";
	}
}
