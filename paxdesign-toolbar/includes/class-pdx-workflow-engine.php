<?php
/**
 * AI Builder + Agent Pipeline execution engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Workflow_Engine {

	/**
	 * @return array<string, mixed>
	 */
	public static function run_builder_flow( PDX_Settings $settings, array $steps, string $input, ?string $job_id = null ): array {
		$started  = microtime( true );
		$context  = $input;
		$outputs  = [];
		$tokens   = 0;
		$total    = max( 1, count( $steps ) );

		foreach ( $steps as $i => $step ) {
			if ( $job_id ) {
				PDX_Queue::update_progress( $job_id, (int) floor( ( ( $i + 1 ) / $total ) * 100 ) );
			}

			$type   = sanitize_key( $step['type'] ?? 'llm' );
			$prompt = sanitize_textarea_field( $step['prompt'] ?? '' );
			$cond   = sanitize_key( $step['condition'] ?? '' );

			if ( 'if_empty' === $cond && '' === trim( $context ) ) {
				$outputs[] = [ 'step' => $i + 1, 'type' => $type, 'skipped' => true, 'output' => $context ];
				continue;
			}

			if ( 'llm' === $type && $prompt ) {
				$full = $prompt . ( $context ? "\n\nContext:\n" . $context : '' );
				$res  = PDX_AI_Service::chat_completion(
					$settings,
					[ [ 'role' => 'user', 'content' => $full ] ],
					[ 'max_tokens' => 700, 'temperature' => 0.5 ]
				);
				if ( is_wp_error( $res ) ) {
					$output = '[Step failed: ' . $res->get_error_message() . ']';
				} else {
					$output = $res['content'];
					$tokens += (int) $res['tokens_used'];
				}
				$context   = $output;
				$outputs[] = [ 'step' => $i + 1, 'type' => $type, 'output' => $output ];
			} else {
				$context   = self::apply_transform( $type, $context, $prompt );
				$outputs[] = [ 'step' => $i + 1, 'type' => $type, 'output' => $context ];
			}
		}

		return [
			'steps_executed' => count( $outputs ),
			'outputs'        => $outputs,
			'final_output'   => $context,
			'tokens_used'    => $tokens,
			'duration_ms'    => (int) round( ( microtime( true ) - $started ) * 1000 ),
		];
	}

	public static function apply_transform( string $type, string $context, string $prompt = '' ): string {
		switch ( $type ) {
			case 'transform':
			case 'uppercase':
				return strtoupper( $context );
			case 'lowercase':
				return strtolower( $context );
			case 'trim':
				return trim( $context );
			case 'json_pretty':
				$decoded = json_decode( $context, true );
				return is_array( $decoded ) ? wp_json_encode( $decoded, JSON_PRETTY_PRINT ) : $context;
			case 'extract_links':
				preg_match_all( '#https?://[^\s<>"\']+#i', $context, $m );
				return implode( "\n", array_unique( $m[0] ?? [] ) );
			default:
				return $context;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function run_pipeline( PDX_Settings $settings, array $agents, string $objective, ?string $job_id = null ): array {
		$started  = microtime( true );
		$handoff  = $objective;
		$trace    = [];
		$tokens   = 0;
		$total    = max( 1, count( $agents ) );

		$personas = [
			'researcher'  => 'You are a research agent. Gather and synthesize information thoroughly.',
			'analyst'     => 'You are an analysis agent. Identify patterns, risks, and insights.',
			'writer'      => 'You are a writing agent. Produce clear, professional output.',
			'critic'      => 'You are a quality-control agent. Review and improve the previous output.',
			'coordinator' => 'You are a coordinator agent. Summarize and structure the final deliverable.',
			'security'    => 'You are a security operations agent. Focus on threats, IOCs, and defensive actions.',
		];

		foreach ( $agents as $idx => $agent ) {
			if ( $job_id ) {
				PDX_Queue::update_progress( $job_id, (int) floor( ( ( $idx + 1 ) / $total ) * 100 ) );
			}

			$role   = sanitize_key( $agent['role'] ?? 'coordinator' );
			$name   = sanitize_text_field( $agent['name'] ?? $role );
			$system = $personas[ $role ] ?? $personas['coordinator'];
			$prompt = "Objective: {$objective}\n\nPrevious agent output:\n{$handoff}\n\nYour task: Process this and produce your contribution.";

			$res = PDX_AI_Service::chat_completion(
				$settings,
				[
					[ 'role' => 'system', 'content' => $system ],
					[ 'role' => 'user', 'content' => $prompt ],
				],
				[ 'max_tokens' => 800, 'temperature' => 0.6 ]
			);

			if ( is_wp_error( $res ) ) {
				$output = '[Agent failed: ' . $res->get_error_message() . ']';
			} else {
				$output = $res['content'];
				$tokens += (int) $res['tokens_used'];
			}

			$trace[] = [
				'agent'       => $role,
				'name'        => $name,
				'output'      => $output,
				'tokens_used' => is_wp_error( $res ) ? 0 : (int) ( $res['tokens_used'] ?? 0 ),
			];
			$handoff = $output;
		}

		return [
			'agents_run'   => count( $trace ),
			'handoffs'     => max( 0, count( $trace ) - 1 ),
			'trace'        => $trace,
			'final_output' => $handoff,
			'objective'    => $objective,
			'tokens_used'  => $tokens,
			'duration_ms'  => (int) round( ( microtime( true ) - $started ) * 1000 ),
		];
	}
}
