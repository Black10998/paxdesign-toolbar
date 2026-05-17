<?php
/**
 * Instrumented outbound HTTP for intelligence connectors.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Http {

	/** @var list<array<string, mixed>> */
	private static array $debug_log = [];

	public static function reset_debug_log(): void {
		self::$debug_log = [];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function get_debug_log(): array {
		return self::$debug_log;
	}

	/**
	 * @return array{response:array|WP_Error,log:array<string,mixed>}
	 */
	public static function get( string $url, array $args = [], string $source = 'http' ): array {
		$args['method'] = 'GET';
		return self::request( $url, $args, $source );
	}

	/**
	 * @return array{response:array|WP_Error,log:array<string,mixed>}
	 */
	public static function post( string $url, array $args = [], string $source = 'http' ): array {
		$args['method'] = 'POST';
		return self::request( $url, $args, $source );
	}

	/**
	 * @return array{response:array|WP_Error,log:array<string,mixed>}
	 */
	public static function request( string $url, array $args, string $source ): array {
		$defaults = [
			'timeout'     => 20,
			'redirection' => 3,
			'headers'     => [
				'User-Agent' => 'PaxDesign-Toolbar/' . PDX_VERSION . ' (WordPress; +' . home_url( '/' ) . ')',
			],
			'sslverify'   => true,
		];

		$args = wp_parse_args( $args, $defaults );
		$args['headers'] = array_merge( $defaults['headers'], (array) ( $args['headers'] ?? [] ) );
		$args            = apply_filters( 'pdx_http_request_args', $args, $url, $source );

		$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$started = microtime( true );

		if ( 'POST' === $method ) {
			$response = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		$log = [
			'source'      => $source,
			'method'      => $method,
			'url'         => $url,
			'timeout'     => (int) ( $args['timeout'] ?? 20 ),
			'sslverify'   => (bool) ( $args['sslverify'] ?? true ),
			'duration_ms' => $duration_ms,
			'http_code'   => null,
			'error'       => null,
			'parse_status'=> 'n/a',
		];

		if ( is_wp_error( $response ) ) {
			$log['error'] = $response->get_error_message();
			$log['parse_status'] = 'transport_error';
			self::$debug_log[] = $log;
			self::write_log( $log );
			return [ 'response' => $response, 'log' => $log ];
		}

		$log['http_code'] = (int) wp_remote_retrieve_response_code( $response );
		$body             = wp_remote_retrieve_body( $response );
		if ( '' === $body && $log['http_code'] >= 400 ) {
			$log['parse_status'] = 'http_error';
			$log['error']        = 'HTTP ' . $log['http_code'];
		} else {
			$log['parse_status'] = $log['http_code'] >= 200 && $log['http_code'] < 300 ? 'ok' : 'http_error';
			if ( $log['http_code'] >= 400 ) {
				$log['error'] = 'HTTP ' . $log['http_code'];
			}
		}

		self::$debug_log[] = $log;
		self::write_log( $log );

		return [ 'response' => $response, 'log' => $log ];
	}

	/**
	 * Merge HTTP log fields into a source_status array.
	 *
	 * @param array<string, mixed> $status
	 * @param array<string, mixed> $log
	 * @return array<string, mixed>
	 */
	public static function enrich_status( array $status, array $log ): array {
		$status['request_url'] = $log['url'] ?? null;
		$status['http_code']   = $log['http_code'] ?? null;
		$status['duration_ms'] = $log['duration_ms'] ?? null;
		$status['timeout']     = $log['timeout'] ?? null;
		$status['parse_status'] = $log['parse_status'] ?? null;
		if ( ! empty( $log['error'] ) && empty( $status['message'] ) ) {
			$status['message'] = (string) $log['error'];
		} elseif ( ! empty( $log['error'] ) ) {
			$status['message'] .= ' (' . $log['error'] . ')';
		}
		return $status;
	}

	/**
	 * @param array<string, mixed> $log
	 */
	private static function write_log( array $log ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		$code = $log['http_code'] ?? '—';
		$err  = $log['error'] ? ' err=' . $log['error'] : '';
		error_log(
			sprintf(
				'[PDX][%s] %s %s → HTTP %s (%dms)%s',
				$log['source'] ?? 'http',
				$log['method'] ?? 'GET',
				$log['url'] ?? '',
				(string) $code,
				(int) ( $log['duration_ms'] ?? 0 ),
				$err
			)
		);
	}
}
