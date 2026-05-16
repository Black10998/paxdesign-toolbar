<?php
/**
 * PDX_Worker — distributed execution layer for browser automation.
 *
 * Architecture:
 *   - Workers register via REST API with a unique token
 *   - Coordinator dispatches jobs from PDX_Queue to available workers
 *   - Workers send heartbeats every 30s; missed = marked offline
 *   - Results returned via REST callback; screenshots stored as attachments
 *   - Retry orchestration with exponential backoff
 *
 * Table: {prefix}pdx_workers
 *   id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   worker_id    VARCHAR(64)  NOT NULL UNIQUE
 *   token        VARCHAR(128) NOT NULL
 *   label        VARCHAR(120) NOT NULL DEFAULT 'Worker'
 *   endpoint     VARCHAR(500) NOT NULL  (worker's callback URL)
 *   status       VARCHAR(20)  NOT NULL DEFAULT 'offline'
 *   capabilities TEXT         DEFAULT NULL  (JSON: browser types, max_concurrency)
 *   last_heartbeat DATETIME   DEFAULT NULL
 *   jobs_completed INT UNSIGNED NOT NULL DEFAULT 0
 *   jobs_failed    INT UNSIGNED NOT NULL DEFAULT 0
 *   registered_at  DATETIME   NOT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Worker {

	const TABLE          = 'pdx_workers';
	const HEARTBEAT_TTL  = 90;   // seconds before marking offline
	const MAX_RETRIES    = 3;
	const BACKOFF_BASE   = 30;   // seconds

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}" . self::TABLE . " (
			id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			worker_id      VARCHAR(64)     NOT NULL,
			token          VARCHAR(128)    NOT NULL,
			label          VARCHAR(120)    NOT NULL DEFAULT 'Worker',
			endpoint       VARCHAR(500)    NOT NULL,
			status         VARCHAR(20)     NOT NULL DEFAULT 'offline',
			capabilities   TEXT            DEFAULT NULL,
			last_heartbeat DATETIME        DEFAULT NULL,
			jobs_completed INT UNSIGNED    NOT NULL DEFAULT 0,
			jobs_failed    INT UNSIGNED    NOT NULL DEFAULT 0,
			registered_at  DATETIME        NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_worker_id (worker_id),
			KEY idx_status (status)
		) {$charset};" );
	}

	/* ── Registration ───────────────────────────────────── */

	public static function register( string $label, string $endpoint, array $capabilities = [] ): array {
		global $wpdb;
		$worker_id = 'wkr-' . substr( bin2hex( random_bytes( 10 ) ), 0, 16 );
		$token     = bin2hex( random_bytes( 32 ) );

		$wpdb->insert( $wpdb->prefix . self::TABLE, [
			'worker_id'     => $worker_id,
			'token'         => hash( 'sha256', $token ),
			'label'         => sanitize_text_field( $label ),
			'endpoint'      => esc_url_raw( $endpoint ),
			'status'        => 'online',
			'capabilities'  => wp_json_encode( $capabilities ),
			'last_heartbeat'=> current_time( 'mysql' ),
			'registered_at' => current_time( 'mysql' ),
		] );

		PDX_Audit::log( 'worker', 'worker_registered', [ 'worker_id' => $worker_id, 'label' => $label ] );
		PDX_EventBus::fire( 'worker.registered', [ 'worker_id' => $worker_id ] );

		return [ 'worker_id' => $worker_id, 'token' => $token ]; // Return plain token once
	}

	public static function authenticate( string $worker_id, string $token ): bool {
		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare(
			"SELECT token FROM {$wpdb->prefix}" . self::TABLE . " WHERE worker_id = %s",
			$worker_id
		) );
		return $stored && hash_equals( $stored, hash( 'sha256', $token ) );
	}

	/* ── Heartbeat ──────────────────────────────────────── */

	public static function heartbeat( string $worker_id, array $stats = [] ): bool {
		global $wpdb;
		$updated = $wpdb->update(
			$wpdb->prefix . self::TABLE,
			[ 'status' => 'online', 'last_heartbeat' => current_time( 'mysql' ) ],
			[ 'worker_id' => $worker_id ]
		);
		return (bool) $updated;
	}

	public static function check_heartbeats(): void {
		global $wpdb;
		$stale = $wpdb->get_results( $wpdb->prepare(
			"SELECT worker_id FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE status = 'online' AND last_heartbeat < DATE_SUB(NOW(), INTERVAL %d SECOND)",
			self::HEARTBEAT_TTL
		), ARRAY_A );

		foreach ( $stale as $w ) {
			$wpdb->update( $wpdb->prefix . self::TABLE, [ 'status' => 'offline' ], [ 'worker_id' => $w['worker_id'] ] );
			PDX_EventBus::fire( 'worker.heartbeat_missed', [ 'worker_id' => $w['worker_id'] ] );
		}
	}

	/* ── Job dispatch ───────────────────────────────────── */

	public static function dispatch_job( string $job_id ): array {
		$job    = PDX_Queue::get( $job_id );
		if ( ! $job ) return [ 'error' => 'Job not found.' ];

		$worker = self::get_available_worker( $job['module'] );
		if ( ! $worker ) {
			// No worker available — job stays queued, will retry
			return [ 'status' => 'queued', 'message' => 'No workers available. Job queued for retry.' ];
		}

		PDX_Queue::start( $job_id );

		$resp = wp_remote_post( $worker['endpoint'] . '/execute', [
			'timeout' => 10,
			'headers' => [
				'Content-Type'    => 'application/json',
				'X-PDX-Worker-ID' => $worker['worker_id'],
				'X-PDX-Job-ID'    => $job_id,
				'X-PDX-Callback'  => rest_url( 'pdx/v1/worker/callback' ),
			],
			'body' => wp_json_encode( [
				'job_id'  => $job_id,
				'module'  => $job['module'],
				'type'    => $job['job_type'],
				'payload' => $job['payload'],
			] ),
		] );

		if ( is_wp_error( $resp ) ) {
			PDX_Queue::fail( $job_id, $resp->get_error_message() );
			return [ 'error' => $resp->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code !== 200 && $code !== 202 ) {
			PDX_Queue::fail( $job_id, "Worker returned HTTP {$code}" );
			return [ 'error' => "Worker HTTP {$code}" ];
		}

		PDX_Audit::log( 'worker', 'job_dispatched', [ 'job_id' => $job_id, 'worker_id' => $worker['worker_id'] ] );
		return [ 'status' => 'dispatched', 'worker_id' => $worker['worker_id'] ];
	}

	public static function receive_callback( string $job_id, array $result, bool $success ): void {
		global $wpdb;

		if ( $success ) {
			PDX_Queue::complete( $job_id, $result );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}" . self::TABLE . " SET jobs_completed = jobs_completed + 1 WHERE worker_id = %s",
				$result['worker_id'] ?? ''
			) );
		} else {
			$job = PDX_Queue::get( $job_id );
			if ( $job && (int) $job['attempts'] < self::MAX_RETRIES ) {
				// Re-queue with backoff
				$delay = self::BACKOFF_BASE * pow( 2, (int) $job['attempts'] - 1 );
				$wpdb->update( $wpdb->prefix . PDX_Queue::TABLE, [
					'status'     => 'queued',
					'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 3600 + $delay ),
				], [ 'job_id' => $job_id ] );
			} else {
				PDX_Queue::fail( $job_id, $result['error'] ?? 'Worker execution failed' );
			}
		}

		PDX_EventBus::fire( $success ? 'job.completed' : 'job.failed', [ 'job_id' => $job_id ] );
	}

	/* ── Worker queries ─────────────────────────────────── */

	public static function get_available_worker( string $module = '' ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE status = 'online' ORDER BY jobs_completed ASC LIMIT 1",
			ARRAY_A
		);
		if ( $row && $row['capabilities'] ) {
			$row['capabilities'] = json_decode( $row['capabilities'], true );
		}
		return $row ?: null;
	}

	public static function all(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT worker_id, label, endpoint, status, capabilities, last_heartbeat, jobs_completed, jobs_failed, registered_at
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY registered_at DESC",
			ARRAY_A
		) ?: [];
		foreach ( $rows as &$r ) {
			if ( $r['capabilities'] ) $r['capabilities'] = json_decode( $r['capabilities'], true );
		}
		return $rows;
	}

	public static function deregister( string $worker_id ): bool {
		global $wpdb;
		$ok = (bool) $wpdb->delete( $wpdb->prefix . self::TABLE, [ 'worker_id' => $worker_id ] );
		if ( $ok ) PDX_Audit::log( 'worker', 'worker_deregistered', [ 'worker_id' => $worker_id ] );
		return $ok;
	}

	/* ── Browser profiles ───────────────────────────────── */

	public static function browser_profiles(): array {
		return [
			[ 'id' => 'chrome_headless',  'label' => 'Chrome Headless',   'engine' => 'chromium', 'js' => true,  'stealth' => false ],
			[ 'id' => 'chrome_stealth',   'label' => 'Chrome Stealth',    'engine' => 'chromium', 'js' => true,  'stealth' => true  ],
			[ 'id' => 'firefox_headless', 'label' => 'Firefox Headless',  'engine' => 'firefox',  'js' => true,  'stealth' => false ],
			[ 'id' => 'webkit_mobile',    'label' => 'WebKit Mobile',     'engine' => 'webkit',   'js' => true,  'stealth' => false ],
			[ 'id' => 'no_js',            'label' => 'No JavaScript',     'engine' => 'chromium', 'js' => false, 'stealth' => false ],
		];
	}
}
