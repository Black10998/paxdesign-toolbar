<?php
/**
 * Central OpenAI service — chat, builder steps, pipeline agents.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_AI_Service {

	public const DEFAULT_MODEL = 'gpt-4o-mini';

	/**
	 * @return string|WP_Error
	 */
	public static function api_key( PDX_Settings $settings ) {
		$key = (string) $settings->get( 'api_keys.openai', '' );
		if ( '' === $key ) {
			return new WP_Error( 'pdx_no_api_key', 'OpenAI API key not configured.' );
		}
		return $key;
	}

	/**
	 * @param list<array{role:string,content:string}> $messages
	 * @return array{content:string,model:string,tokens_used:int,raw:array<string,mixed>}|WP_Error
	 */
	public static function chat_completion( PDX_Settings $settings, array $messages, array $opts = [] ): array|WP_Error {
		$api_key = self::api_key( $settings );
		if ( is_wp_error( $api_key ) ) {
			return $api_key;
		}

		$model       = sanitize_text_field( $opts['model'] ?? self::DEFAULT_MODEL );
		$max_tokens  = max( 64, min( 4096, (int) ( $opts['max_tokens'] ?? 800 ) ) );
		$temperature = isset( $opts['temperature'] ) ? (float) $opts['temperature'] : 0.7;
		$timeout     = max( 15, min( 90, (int) ( $opts['timeout'] ?? 45 ) ) );

		$body = [
			'model'       => $model,
			'messages'    => $messages,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		];

		if ( ! empty( $opts['json'] ) ) {
			$body['response_format'] = [ 'type' => 'json_object' ];
		}

		$resp = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'timeout' => $timeout,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'pdx_ai_unavailable', 'AI service unavailable.' );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $data['error']['message'] ?? "OpenAI HTTP {$code}";
			return new WP_Error( 'pdx_ai_error', (string) $msg );
		}

		$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		if ( '' === $content ) {
			return new WP_Error( 'pdx_ai_empty', 'No response from AI.' );
		}

		$usage = (int) ( $data['usage']['total_tokens'] ?? 0 );

		return [
			'content'      => $content,
			'model'        => (string) ( $data['model'] ?? $model ),
			'tokens_used'  => $usage,
			'raw'          => is_array( $data ) ? $data : [],
		];
	}

	public static function persona_system_prompt( string $persona ): string {
		$map = [
			'assistant'  => 'You are a helpful, professional AI assistant for PaxDesign enterprise security and operations clients.',
			'analyst'    => 'You are a senior cybersecurity and threat intelligence analyst. Provide structured, evidence-based insights with clear risk framing.',
			'developer'  => 'You are an expert software engineer. Give precise, production-ready technical answers with security awareness.',
			'strategist' => 'You are a strategic consultant. Think in frameworks, outcomes, ROI, and executive-ready recommendations.',
		];
		return $map[ $persona ] ?? $map['assistant'];
	}

	/**
	 * @param list<array{role:string,content:string}> $history
	 * @return list<array{role:string,content:string}>
	 */
	public static function build_persona_messages( string $persona, string $message, array $history = [], string $memory_context = '' ): array {
		$system = self::persona_system_prompt( $persona );
		if ( $memory_context ) {
			$system .= "\n\n" . $memory_context;
		}

		$messages = [ [ 'role' => 'system', 'content' => $system ] ];

		foreach ( array_slice( $history, -20 ) as $turn ) {
			$role = in_array( $turn['role'] ?? '', [ 'user', 'assistant', 'system' ], true ) ? $turn['role'] : 'user';
			$content = sanitize_textarea_field( (string) ( $turn['content'] ?? '' ) );
			if ( $content ) {
				$messages[] = [ 'role' => $role, 'content' => $content ];
			}
		}

		$messages[] = [ 'role' => 'user', 'content' => sanitize_textarea_field( $message ) ];

		return $messages;
	}

	/**
	 * Chunk reply for simulated streaming on the client.
	 *
	 * @return list<string>
	 */
	public static function chunk_for_stream( string $text, int $chunk_size = 48 ): array {
		$chunks = [];
		$len    = strlen( $text );
		for ( $i = 0; $i < $len; $i += $chunk_size ) {
			$chunks[] = substr( $text, $i, $chunk_size );
		}
		return $chunks ?: [ '' ];
	}
}
