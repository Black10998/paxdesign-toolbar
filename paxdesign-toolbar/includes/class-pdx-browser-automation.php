<?php
/**
 * Safe browser automation — sandbox fetch + structured extraction + AI plan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Browser_Automation {

	private const MAX_BYTES = 350000;
	private const TIMEOUT   = 20;

	/**
	 * Full automation run (sandbox + AI plan + extraction report).
	 *
	 * @return array<string, mixed>
	 */
	public static function execute( string $url, string $task, PDX_Settings $settings ): array {
		$started = microtime( true );

		if ( ! self::is_safe_url( $url ) ) {
			return [
				'status'  => 'blocked',
				'error'   => 'URL blocked by sandbox policy (private network / invalid scheme).',
				'url'     => $url,
				'task'    => $task,
				'sandbox' => [ 'allowed' => false ],
			];
		}

		$page = self::sandbox_fetch( $url );
		$extracted = is_wp_error( $page )
			? [ 'fetch_error' => $page->get_error_message() ]
			: self::extract_page_data( $page['body'] ?? '', $url, $page );

		$api_key = PDX_AI_Service::api_key( $settings );
		$plan    = [];
		if ( ! is_wp_error( $api_key ) ) {
			$plan = self::build_execution_plan( $url, $task, $extracted, $settings );
		} else {
			$plan = [
				'steps'             => [ 'Fetch page in sandbox', 'Review extracted signals', 'Complete task manually' ],
				'data_points'       => array_keys( array_filter( $extracted ) ),
				'obstacles'         => [ 'OpenAI key not configured — AI plan unavailable.' ],
				'approach'          => 'Sandbox-only extraction without LLM planning.',
				'estimated_seconds' => 30,
			];
		}

		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

		return [
			'status'           => 'completed',
			'url'              => $url,
			'task'             => $task,
			'sandbox'          => [
				'allowed'    => true,
				'mode'       => 'server_fetch',
				'no_scripts' => true,
				'note'       => 'HTML fetched server-side; JavaScript not executed (safe sandbox).',
			],
			'page_extraction'  => $extracted,
			'execution_plan'   => $plan,
			'extraction_report'=> self::build_report( $extracted, $plan ),
			'duration_ms'      => $duration_ms,
			'engine'           => 'pdx-browser-v8.1',
		];
	}

	public static function is_safe_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https' ], true ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] ?? '' );
		if ( '' === $host ) {
			return false;
		}
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '0.0.0.0', '::1' ], true ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return (bool) esc_url_raw( $url );
	}

	/**
	 * @return array{body:string,code:int,headers:array<string,string>}|WP_Error
	 */
	private static function sandbox_fetch( string $url ) {
		$resp = wp_remote_get(
			$url,
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => 3,
				'headers'     => [
					'User-Agent' => 'PaxDesign-BrowserSandbox/' . PDX_VERSION,
					'Accept'     => 'text/html,application/xhtml+xml',
				],
			]
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			$body = substr( $body, 0, self::MAX_BYTES );
		}

		return [
			'body'    => $body,
			'code'    => $code,
			'headers' => wp_remote_retrieve_headers( $resp )->getAll(),
		];
	}

	/**
	 * @param array{body:string,code:int,headers?:array} $fetch
	 * @return array<string, mixed>
	 */
	public static function extract_page_data( string $html, string $url, array $fetch = [] ): array {
		$title = '';
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
			$title = trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		$meta = [];
		if ( preg_match_all( '/<meta[^>]+name=["\']([^"\']+)["\'][^>]+content=["\']([^"\']*)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $matches, 0, 12 ) as $match ) {
				$meta[ strtolower( $match[1] ) ] = substr( $match[2], 0, 200 );
			}
		}

		$form_count = preg_match_all( '/<form\b/i', $html );
		$input_count = preg_match_all( '/<input\b/i', $html );
		$password_fields = preg_match_all( '/type=["\']password["\']/i', $html );
		$link_count = preg_match_all( '/<a\b[^>]+href=/i', $html );
		$script_count = preg_match_all( '/<script\b/i', $html );

		$has_login = $password_fields > 0 && $form_count > 0;

		$text_sample = wp_strip_all_tags( $html );
		$text_sample = preg_replace( '/\s+/', ' ', $text_sample );
		$text_sample = substr( trim( $text_sample ), 0, 1200 );

		return [
			'url'              => $url,
			'http_code'        => (int) ( $fetch['code'] ?? 0 ),
			'title'            => $title,
			'meta'             => $meta,
			'forms'            => (int) $form_count,
			'inputs'           => (int) $input_count,
			'password_fields'  => (int) $password_fields,
			'links'            => (int) $link_count,
			'scripts'          => (int) $script_count,
			'has_login_form'   => $has_login,
			'text_sample'      => $text_sample,
			'content_length'   => strlen( $html ),
		];
	}

	/**
	 * @param array<string, mixed> $extracted
	 * @return array<string, mixed>
	 */
	private static function build_execution_plan( string $url, string $task, array $extracted, PDX_Settings $settings ): array {
		$prompt = "You are a browser automation planner. Return JSON only with keys: steps (array of strings), data_points (array), obstacles (array), approach (string), estimated_seconds (int), selectors_hint (array optional).\n\nURL: {$url}\nTask: {$task}\n\nPage signals:\n" . wp_json_encode( $extracted );

		$result = PDX_AI_Service::chat_completion(
			$settings,
			[
				[ 'role' => 'system', 'content' => 'Return valid JSON only.' ],
				[ 'role' => 'user', 'content' => $prompt ],
			],
			[ 'temperature' => 0.3, 'max_tokens' => 900, 'json' => true, 'timeout' => 45 ]
		);

		if ( is_wp_error( $result ) ) {
			return [
				'steps'             => [ 'Analyze URL', 'Extract data', 'Validate results' ],
				'data_points'       => [],
				'obstacles'         => [ $result->get_error_message() ],
				'approach'          => 'Fallback plan',
				'estimated_seconds' => 60,
			];
		}

		$parsed = json_decode( $result['content'], true );
		if ( ! is_array( $parsed ) ) {
			return [
				'steps'             => [ 'Review page extraction', 'Execute task manually' ],
				'data_points'       => array_keys( $extracted ),
				'obstacles'         => [ 'Could not parse AI plan JSON' ],
				'approach'          => substr( $result['content'], 0, 500 ),
				'estimated_seconds' => 45,
				'raw'               => $result['content'],
			];
		}

		$parsed['tokens_used'] = $result['tokens_used'];
		return $parsed;
	}

	/**
	 * @param array<string, mixed> $extracted
	 * @param array<string, mixed> $plan
	 * @return array<string, mixed>
	 */
	private static function build_report( array $extracted, array $plan ): array {
		return [
			'summary'     => sprintf(
				'Page "%s" (HTTP %d) — %d forms, %d links. Login form: %s.',
				$extracted['title'] ?? '(no title)',
				(int) ( $extracted['http_code'] ?? 0 ),
				(int) ( $extracted['forms'] ?? 0 ),
				(int) ( $extracted['links'] ?? 0 ),
				! empty( $extracted['has_login_form'] ) ? 'yes' : 'no'
			),
			'steps'       => $plan['steps'] ?? [],
			'data_points' => $plan['data_points'] ?? [],
			'risk_notes'  => array_merge(
				! empty( $extracted['has_login_form'] ) ? [ 'Credential form detected — verify legitimacy before interaction.' ] : [],
				( $extracted['scripts'] ?? 0 ) > 15 ? [ 'Heavy script usage — dynamic content may differ from sandbox fetch.' ] : []
			),
		];
	}
}
