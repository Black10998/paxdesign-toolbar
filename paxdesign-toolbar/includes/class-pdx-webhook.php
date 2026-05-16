<?php
/**
 * PDX_Webhook — outbound webhook delivery system.
 *
 * Webhooks are stored in WP options (small dataset).
 * Delivery log is stored in a custom table.
 *
 * Table: {prefix}pdx_webhook_log
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
 *   webhook_id  VARCHAR(64)  NOT NULL
 *   event       VARCHAR(120) NOT NULL
 *   url         VARCHAR(500) NOT NULL
 *   status_code SMALLINT UNSIGNED DEFAULT NULL
 *   response    TEXT         DEFAULT NULL
 *   payload     LONGTEXT     DEFAULT NULL
 *   delivered   TINYINT(1)   NOT NULL DEFAULT 0
 *   attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0
 *   sent_at     DATETIME     NOT NULL
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Webhook {

	const OPT_KEY  = 'pdx_webhooks';
	const LOG_TABLE = 'pdx_webhook_log';

	/* ── Schema ─────────────────────────────────────────── */

	public static function install(): void {
		global $wpdb;
		$table   = $wpdb->prefix . self::LOG_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			webhook_id  VARCHAR(64)     NOT NULL,
			event       VARCHAR(120)    NOT NULL,
			url         VARCHAR(500)    NOT NULL,
			status_code SMALLINT UNSIGNED DEFAULT NULL,
			response    TEXT            DEFAULT NULL,
			payload     LONGTEXT        DEFAULT NULL,
			delivered   TINYINT(1)      NOT NULL DEFAULT 0,
			attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
			sent_at     DATETIME        NOT NULL,
			PRIMARY KEY (id),
			KEY idx_webhook (webhook_id),
			KEY idx_event   (event),
			KEY idx_sent    (sent_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/* ── Webhook CRUD ───────────────────────────────────── */

	public static function all(): array {
		return get_option( self::OPT_KEY, [] );
	}

	public static function get( string $id ): ?array {
		$all = self::all();
		return $all[ $id ] ?? null;
	}

	public static function create( array $config ): string {
		$id  = 'wh-' . substr( bin2hex( random_bytes( 8 ) ), 0, 14 );
		$all = self::all();

		$all[ $id ] = [
			'id'      => $id,
			'name'    => sanitize_text_field( $config['name'] ?? 'Webhook' ),
			'url'     => esc_url_raw( $config['url'] ?? '' ),
			'events'  => array_map( 'sanitize_key', (array) ( $config['events'] ?? [] ) ),
			'secret'  => sanitize_text_field( $config['secret'] ?? '' ),
			'active'  => (bool) ( $config['active'] ?? true ),
			'created' => time(),
		];

		update_option( self::OPT_KEY, $all );
		return $id;
	}

	public static function update( string $id, array $config ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) return false;

		if ( isset( $config['name'] ) )   $all[$id]['name']   = sanitize_text_field( $config['name'] );
		if ( isset( $config['url'] ) )    $all[$id]['url']    = esc_url_raw( $config['url'] );
		if ( isset( $config['events'] ) ) $all[$id]['events'] = array_map( 'sanitize_key', (array) $config['events'] );
		if ( isset( $config['secret'] ) ) $all[$id]['secret'] = sanitize_text_field( $config['secret'] );
		if ( isset( $config['active'] ) ) $all[$id]['active'] = (bool) $config['active'];

		return update_option( self::OPT_KEY, $all );
	}

	public static function delete( string $id ): bool {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) return false;
		unset( $all[ $id ] );
		return update_option( self::OPT_KEY, $all );
	}

	/* ── Dispatch ───────────────────────────────────────── */

	/**
	 * Fire an event to all matching active webhooks.
	 */
	public static function dispatch( string $event, array $payload = [] ): void {
		$all = self::all();
		foreach ( $all as $wh ) {
			if ( ! $wh['active'] ) continue;
			if ( ! in_array( $event, $wh['events'], true ) && ! in_array( '*', $wh['events'], true ) ) continue;
			self::deliver( $wh, $event, $payload );
		}
	}

	private static function deliver( array $wh, string $event, array $payload ): void {
		global $wpdb;

		$body = wp_json_encode( [
			'event'     => $event,
			'timestamp' => time(),
			'payload'   => $payload,
		] );

		$headers = [
			'Content-Type'    => 'application/json',
			'X-PDX-Event'     => $event,
			'X-PDX-Timestamp' => (string) time(),
		];

		if ( $wh['secret'] ) {
			$headers['X-PDX-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $wh['secret'] );
		}

		$resp = wp_remote_post( $wh['url'], [
			'timeout' => 8,
			'headers' => $headers,
			'body'    => $body,
		] );

		$code      = is_wp_error( $resp ) ? 0 : wp_remote_retrieve_response_code( $resp );
		$delivered = $code >= 200 && $code < 300;
		$response  = is_wp_error( $resp ) ? $resp->get_error_message() : substr( wp_remote_retrieve_body( $resp ), 0, 500 );

		$wpdb->insert(
			$wpdb->prefix . self::LOG_TABLE,
			[
				'webhook_id'  => $wh['id'],
				'event'       => $event,
				'url'         => $wh['url'],
				'status_code' => $code ?: null,
				'response'    => $response,
				'payload'     => $body,
				'delivered'   => $delivered ? 1 : 0,
				'attempts'    => 1,
				'sent_at'     => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s' ]
		);
	}

	/* ── Log queries ────────────────────────────────────── */

	public static function get_log( string $webhook_id = '', int $limit = 50 ): array {
		global $wpdb;
		$table  = $wpdb->prefix . self::LOG_TABLE;
		$where  = $webhook_id ? $wpdb->prepare( 'WHERE webhook_id = %s', $webhook_id ) : '';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY sent_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: [];
	}

	public static function delivery_stats(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT webhook_id, COUNT(*) as total,
			        SUM(delivered) as delivered,
			        COUNT(*)-SUM(delivered) as failed
			 FROM {$wpdb->prefix}" . self::LOG_TABLE . "
			 GROUP BY webhook_id",
			ARRAY_A
		) ?: [];
	}

	/* ── Available events ───────────────────────────────── */

	public static function available_events(): array {
		return [
			'scan.completed'       => 'Scan completed (TrustCheck / OSINT)',
			'scan.failed'          => 'Scan failed',
			'job.queued'           => 'Job added to queue',
			'job.completed'        => 'Job completed',
			'job.failed'           => 'Job failed',
			'payment.captured'     => 'Payment captured',
			'payment.refunded'     => 'Payment refunded',
			'ai.chat.message'      => 'AI chat message sent',
			'pipeline.run'         => 'Agent pipeline executed',
			'builder.deploy'       => 'AI builder workflow deployed',
			'connector.test'       => 'Connector tested',
			'workspace.created'    => 'Workspace created',
			'workspace.updated'    => 'Workspace updated',
			'audit.critical'       => 'Critical audit event',
			'*'                    => 'All events (wildcard)',
		];
	}
}
