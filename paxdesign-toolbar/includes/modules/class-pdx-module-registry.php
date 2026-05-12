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
			'label'       => 'Trust Check',
			'description' => 'Domain reputation, SSL grade, RDAP data, and risk scoring.',
			'category'    => 'security',
			'icon'        => 'shield',
			'panel_type'  => 'tool',
			'order'       => 10,
		] );

		$this->register( 'create', [
			'label'       => 'Create',
			'description' => 'Custom digital product development services.',
			'category'    => 'services',
			'icon'        => 'plus',
			'panel_type'  => 'service',
			'order'       => 20,
		] );

		$this->register( 'personas', [
			'label'       => 'AI Personas',
			'description' => 'Custom AI agents with defined identity and knowledge bases.',
			'category'    => 'ai',
			'icon'        => 'user',
			'panel_type'  => 'service',
			'order'       => 30,
		] );

		$this->register( 'automation', [
			'label'       => 'Automation',
			'description' => 'End-to-end browser workflow automation.',
			'category'    => 'ai',
			'icon'        => 'grid',
			'panel_type'  => 'service',
			'order'       => 40,
		] );

		$this->register( 'osint', [
			'label'       => 'OSINT',
			'description' => 'Automated open-source intelligence gathering.',
			'category'    => 'security',
			'icon'        => 'search',
			'panel_type'  => 'service',
			'order'       => 50,
		] );

		$this->register( 'connectors', [
			'label'       => 'Connectors',
			'description' => 'API integrations and data bridges.',
			'category'    => 'services',
			'icon'        => 'link',
			'panel_type'  => 'service',
			'order'       => 60,
		] );

		$this->register( 'builder', [
			'label'       => 'AI Builder',
			'description' => 'Low-code AI system construction and deployment.',
			'category'    => 'ai',
			'icon'        => 'layers',
			'panel_type'  => 'service',
			'order'       => 70,
		] );

		$this->register( 'pipeline', [
			'label'       => 'Agent Pipeline',
			'description' => 'Multi-agent orchestration systems.',
			'category'    => 'ai',
			'icon'        => 'pipeline',
			'panel_type'  => 'service',
			'order'       => 80,
		] );
	}

	public function register( string $id, array $config ): void {
		$this->modules[ $id ] = array_merge( [
			'id'          => $id,
			'label'       => $id,
			'description' => '',
			'category'    => 'general',
			'icon'        => 'circle',
			'panel_type'  => 'service',
			'order'       => 99,
		], $config, [ 'id' => $id ] );
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
