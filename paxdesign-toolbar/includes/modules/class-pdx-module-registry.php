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

		/* ── Security ─────────────────────────────────────── */

		$this->register( 'trust', [
			'label'         => 'TrustCheck',
			'description'   => 'Multi-source domain intelligence: RDAP, SSL Labs, risk scoring, anomaly detection, behavioral analysis, and scan history.',
			'category'      => 'security',
			'icon'          => 'shield',
			'panel_type'    => 'tool',
			'order'         => 10,
			'default_tier'  => 'free',
			'default_price' => 0.00,
			'preview_lines' => 3,
			'capabilities'  => [ 'rdap_lookup', 'ssl_check', 'risk_score', 'anomaly_detection', 'behavioral_score', 'scan_history', 'workspace_save', 'export_report' ],
			'badge'         => null,
		] );

		$this->register( 'osint', [
			'label'         => 'OSINT Agents',
			'description'   => 'Deep intelligence: domain, IP geolocation, VirusTotal, Shodan, email discovery, IOC extraction, timeline reconstruction.',
			'category'      => 'security',
			'icon'          => 'search',
			'panel_type'    => 'interactive',
			'order'         => 20,
			'default_tier'  => 'preview',
			'default_price' => 14.99,
			'preview_lines' => 2,
			'capabilities'  => [ 'domain_intel', 'ip_lookup', 'virustotal', 'shodan', 'hunter_email', 'ioc_extraction', 'timeline', 'behavioral_score', 'full_report', 'workspace_save' ],
			'badge'         => 'Preview',
		] );

		$this->register( 'threat', [
			'label'         => 'Threat Intel',
			'description'   => 'Real-time threat feed aggregation, CVE lookup, infrastructure graph, and attack surface mapping.',
			'category'      => 'security',
			'icon'          => 'alert',
			'panel_type'    => 'interactive',
			'order'         => 30,
			'default_tier'  => 'paid',
			'default_price' => 19.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'cve_lookup', 'threat_feeds', 'infra_graph', 'attack_surface', 'ioc_search', 'export_stix' ],
			'badge'         => 'New',
		] );

		/* ── AI ───────────────────────────────────────────── */

		$this->register( 'personas', [
			'label'         => 'AI Personas',
			'description'   => 'Chat with specialized AI personas: Assistant, Analyst, Developer, Strategist. Persistent memory, conversation history, export.',
			'category'      => 'ai',
			'icon'          => 'user',
			'panel_type'    => 'interactive',
			'order'         => 40,
			'default_tier'  => 'preview',
			'default_price' => 9.99,
			'preview_lines' => 3,
			'capabilities'  => [ 'ai_chat', 'persona_select', 'conversation_history', 'ai_memory', 'export_chat', 'workspace_save' ],
			'badge'         => 'Preview',
		] );

		$this->register( 'builder', [
			'label'         => 'AI Builder',
			'description'   => 'Visual AI workflow builder: chain LLM steps, transformations, and logic. Run flows, save templates, deploy pipelines.',
			'category'      => 'ai',
			'icon'          => 'layers',
			'panel_type'    => 'interactive',
			'order'         => 50,
			'default_tier'  => 'preview',
			'default_price' => 29.99,
			'preview_lines' => 1,
			'capabilities'  => [ 'flow_builder', 'llm_config', 'step_chain', 'template_library', 'flow_run', 'workspace_save', 'export_flow' ],
			'badge'         => 'Preview',
		] );

		$this->register( 'pipeline', [
			'label'         => 'Agent Pipeline',
			'description'   => 'Orchestrate multi-agent task chains with role-based agents, handoffs, and full execution traces.',
			'category'      => 'ai',
			'icon'          => 'pipeline',
			'panel_type'    => 'interactive',
			'order'         => 60,
			'default_tier'  => 'paid',
			'default_price' => 39.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'agent_chain', 'role_assign', 'handoff_trace', 'template_library', 'pipeline_run', 'workspace_save', 'export_trace' ],
			'badge'         => null,
		] );

		$this->register( 'automation', [
			'label'         => 'Browser Automation',
			'description'   => 'AI-assisted browser task analysis: submit a URL and task, receive structured execution plans and data extraction reports.',
			'category'      => 'ai',
			'icon'          => 'grid',
			'panel_type'    => 'interactive',
			'order'         => 70,
			'default_tier'  => 'paid',
			'default_price' => 19.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'task_submit', 'ai_analysis', 'step_breakdown', 'result_export', 'job_queue', 'workspace_save' ],
			'badge'         => null,
		] );

		/* ── Services ─────────────────────────────────────── */

		$this->register( 'connectors', [
			'label'         => 'Connectors',
			'description'   => 'Live API integration testing: REST, Slack, Airtable, Notion, GitHub, Zapier. Test connections, inspect responses, configure webhooks.',
			'category'      => 'services',
			'icon'          => 'link',
			'panel_type'    => 'interactive',
			'order'         => 80,
			'default_tier'  => 'paid',
			'default_price' => 24.99,
			'preview_lines' => 0,
			'capabilities'  => [ 'api_test', 'webhook_config', 'response_inspect', 'latency_check', 'connector_library' ],
			'badge'         => null,
		] );

		$this->register( 'create', [
			'label'         => 'Development',
			'description'   => 'Custom digital product development — submit a project brief and receive a scoped proposal within 24 hours.',
			'category'      => 'services',
			'icon'          => 'plus',
			'panel_type'    => 'interactive',
			'order'         => 90,
			'default_tier'  => 'free',
			'default_price' => 0.00,
			'capabilities'  => [ 'project_brief', 'proposal_request', 'budget_estimate' ],
			'badge'         => null,
		] );

		$this->register( 'workspace', [
			'label'         => 'Workspaces',
			'description'   => 'Persistent saved projects, investigation boards, scan history, and AI memory across all modules.',
			'category'      => 'services',
			'icon'          => 'folder',
			'panel_type'    => 'interactive',
			'order'         => 100,
			'default_tier'  => 'free',
			'default_price' => 0.00,
			'capabilities'  => [ 'saved_projects', 'scan_history', 'ai_memory', 'search', 'export', 'pin', 'archive' ],
			'badge'         => null,
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
			'badge'         => null,
		], $config, [ 'id' => $id ] );
	}

	public function get_with_pricing( string $id, PDX_Settings $settings ): ?array {
		$mod = $this->get( $id );
		if ( ! $mod ) return null;
		$mod['tier']     = $settings->get( "module_tiers.{$id}",  $mod['default_tier'] );
		$mod['price']    = (float) $settings->get( "module_prices.{$id}", $mod['default_price'] );
		$mod['currency'] = $settings->get( 'paypal.currency', 'USD' );
		return $mod;
	}

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

	public function categories(): array {
		$cats = [];
		foreach ( $this->all() as $mod ) {
			$cats[ $mod['category'] ] = true;
		}
		return array_keys( $cats );
	}
}
