<?php
/**
 * Module registry — defines all available dock modules with metadata.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Module_Registry {

	private array $modules = [];

	public function __construct() {
		$this->register_defaults();
	}

	private function register_defaults(): void {
		$this->register( 'trust', [
			'label'         => 'Trust Check',
			'description'   => 'Domain reputation, SSL grade, RDAP data, and risk scoring.',
			'category'      => 'security',
			'icon'          => 'shield',
			'panel_type'    => 'tool',
			'order'         => 10,
			'default_tier'  => 'free',
			'default_price' => 0.00,
			'preview_lines' => 3,
			'capabilities'  => [ 'rdap_lookup', 'ssl_check', 'gsb_check', 'risk_score' ],
		] );

		$this->register( 'create', [
			'label'         => 'Create',
			'description'   => 'Custom digital product development — submit a project brief and receive a scoped proposal.',
			'category'      => 'services',
			'icon'          => 'plus',
			'panel_type'    => 'interactive',
			'order'         => 20,
			'default_tier'  => 'free',
			'default_price' => 0.00,
			'capabilities'  => [ 'project_brief', 'proposal_request' ],
		] );

		$this->register( 'personas', [
			'label'         => 'AI Personas',
			'description'   => 'Chat with a custom AI persona powered by GPT-4. Preview: 3 messages free.',
			'category'      => 'ai',
			'icon'          => 'user',
			'panel_type'    => 'interactive',
			'order'         => 30,
			'default_tier'  => 'preview',
			'default_price' => 9.99,
			'preview_lines' => 3,
			'capabilities'  => [ 'ai_chat', 'persona_select', 'conversation_history' ],
		] );

		$this->register( 'automation', [
			'label'         => 'Browser Automation',
			'description'   => 'Submit a URL and automation task — receive structured results or a downloadable report.',
			'category'      => 'ai',
			'icon'          => 'grid',
			'panel_type'    => 'interactive',
			'order'         => 40,
			'default_tier'  => 'paid',
			'default_price' => 19.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'task_submit', 'result_download', 'schedule' ],
		] );

		$this->register( 'osint', [
			'label'         => 'OSINT / JailBreak Agents',
			'description'   => 'Deep intelligence scan: domain, IP, social footprint, breach data. Preview: summary only.',
			'category'      => 'security',
			'icon'          => 'search',
			'panel_type'    => 'interactive',
			'order'         => 50,
			'default_tier'  => 'preview',
			'default_price' => 14.99,
			'preview_lines' => 2,
			'capabilities'  => [ 'domain_intel', 'ip_lookup', 'breach_check', 'social_footprint', 'full_report' ],
		] );

		$this->register( 'connectors', [
			'label'         => 'Connectors',
			'description'   => 'Configure live API integrations between your tools. Test connections instantly.',
			'category'      => 'services',
			'icon'          => 'link',
			'panel_type'    => 'interactive',
			'order'         => 60,
			'default_tier'  => 'paid',
			'default_price' => 24.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'api_test', 'webhook_config', 'data_map' ],
		] );

		$this->register( 'builder', [
			'label'         => 'AI Builder',
			'description'   => 'Design and deploy AI workflows visually. Preview: single-step flows only.',
			'category'      => 'ai',
			'icon'          => 'layers',
			'panel_type'    => 'interactive',
			'order'         => 70,
			'default_tier'  => 'preview',
			'default_price' => 29.99,
			'preview_lines' => 1,
			'capabilities'  => [ 'flow_builder', 'llm_config', 'rag_setup', 'deploy' ],
		] );

		$this->register( 'pipeline', [
			'label'         => 'Agent Pipeline',
			'description'   => 'Orchestrate multi-agent task chains. Define agents, tools, and handoffs.',
			'category'      => 'ai',
			'icon'          => 'pipeline',
			'panel_type'    => 'interactive',
			'order'         => 80,
			'default_tier'  => 'paid',
			'default_price' => 39.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'agent_chain', 'tool_assign', 'human_loop', 'monitor' ],
		] );
	}

	public function register( string $id, array $config ): void {
		$this->modules[ $id ] = array_merge( [
			'id'            => $id,
			'label'         => $id,
			'description'   => '',
			'category'      => 'general',
			'icon'          => 'circle',
			'panel_type'    => 'service',
			'order'         => 99,
			'default_tier'  => 'free',
			'default_price' => 0.00,
			'preview_lines' => 0,
			'capabilities'  => [],
		], $config, [ 'id' => $id ] );
	}

	/**
	 * Return module with live pricing/tier from settings merged in.
	 */
	public function get_with_pricing( string $id, PDX_Settings $settings ): ?array {
		$mod = $this->get( $id );
		if ( ! $mod ) return null;

		$mod['tier']     = $settings->get( "module_tiers.{$id}",  $mod['default_tier'] );
		$mod['price']    = (float) $settings->get( "module_prices.{$id}", $mod['default_price'] );
		$mod['currency'] = $settings->get( 'paypal.currency', 'USD' );
		return $mod;
	}

	/**
	 * All modules with live pricing merged in.
	 */
	public function all_with_pricing( PDX_Settings $settings ): array {
		$result = [];
		foreach ( $this->all() as $id => $mod ) {
			$result[ $id ] = $this->get_with_pricing( $id, $settings );
		}
		return $result;
	}

	public function all(): array {
		$mods = $this->modules;
		uasort( $mods, static fn( $a, $b ) => $a['order'] <=> $b['order'] );
		return $mods;
	}

	public function get( string $id ): ?array {
		return $this->modules[ $id ] ?? null;
	}

	public function by_category( string $cat ): array {
		return array_filter( $this->all(), static fn( $m ) => $m['category'] === $cat );
	}
}
