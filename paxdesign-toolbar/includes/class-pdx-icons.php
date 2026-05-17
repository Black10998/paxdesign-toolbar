<?php
/**
 * Canonical SVG icons — module dock + unique action/UI icons.
 * Keep in sync with assets/js/pdx-module-icons.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PDX_Icons {

	/** @var array<string, string> module_id => inner path markup */
	private const MODULE_PATHS = [
		'trust'         => '<path d="M12 2l8 4v6c0 5-4 9-8 10-5-3.5-8-9-8-10V6l8-4z"/><path d="M9 12l2.5 2.5L15 10"/>',
		'osint'         => '<circle cx="11" cy="11" r="7"/><path d="M3 11h16"/><path d="M11 4a12 12 0 0 1 0 14"/><path d="M16 16l5 5"/>',
		'threat'        => '<path d="M12 3l8 14H4L12 3z"/><path d="M12 9v3"/><circle cx="12" cy="16" r="1"/>',
		'personas'      => '<circle cx="9" cy="9" r="3"/><path d="M4 20c0-3.5 2.5-6 5-6"/><path d="M15 7h5v6h-4l-2 2V7z"/>',
		'builder'       => '<path d="M5 17l7-7"/><path d="M13 6l6 5-2 2-5-5-2 2-3-3z"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/>',
		'pipeline'      => '<circle cx="5" cy="12" r="2"/><path d="M7 12h3l2-4 2 4h3"/><circle cx="19" cy="12" r="2"/><path d="M12 5v14"/>',
		'automation'    => '<rect x="4" y="6" width="16" height="11" rx="2"/><path d="M4 9h16"/><path d="M14 14l4 4"/><path d="M18 11v7"/>',
		'connectors'    => '<path d="M7 9h3v4H7z"/><path d="M14 9h3v4h-3z"/><path d="M10 13h4v2h-4z"/><path d="M12 15v4"/>',
		'create'        => '<path d="M6 5h12v14H6z"/><path d="M9 11l-2 2 2 2"/><path d="M13 15h4"/>',
		'investigation' => '<rect x="4" y="5" width="16" height="14" rx="1"/><circle cx="8" cy="10" r="1.5"/><circle cx="15" cy="9" r="1.5"/><circle cx="11" cy="15" r="1.5"/><path d="M8 10l7-1M15 9l-4 6"/>',
		'graph'         => '<circle cx="12" cy="12" r="2"/><path d="M12 4v4M12 16v4M4 12h4M16 12h4"/><path d="M6.8 6.8l2.8 2.8M14.4 14.4l2.8 2.8M17.2 6.8l-2.8 2.8M6.8 17.2l2.8-2.8"/>',
		'memory'        => '<path d="M9 7c-2 0-3.5 1.5-3.5 3.5S7 14 9 14c0 2 2 3.5 3 3.5s3-1.5 3-3.5S14 14 15 14c2 0 3.5-1.5 3.5-3.5S17 7 15 7c0-2-2-3-3-3s-3 1-3 3"/>',
		'team'          => '<circle cx="12" cy="7" r="2.5"/><circle cx="7" cy="13" r="2"/><circle cx="17" cy="13" r="2"/><path d="M5 19c0-2 3.2-3.5 7-3.5s7 1.5 7 3.5"/>',
		'workspace'     => '<path d="M9 6h9l2 2v10H9z"/><path d="M7 8h9l2 2v10H7z"/><path d="M5 10h9l2 2v10H5z"/>',
		'circle'        => '<circle cx="12" cy="12" r="7"/><path d="M12 8v4l3 2"/>',
	];

	/** @var array<string, string> action slug => inner path markup */
	private const ACTION_PATHS = [
		'alert'                 => '<path d="M12 2l9 16H3L12 2z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'alert-octagon'         => '<path d="M12 2l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V6l8-4z"/><path d="M12 8v5"/><path d="M12 16h.01"/>',
		'check'                 => '<path d="M5 13l4 4L19 7"/>',
		'info'                  => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
		'lock-paywall'          => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="16" r="1"/>',
		'billing'               => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 15h2"/><path d="M12 15h5"/>',
		'cmd-search'            => '<circle cx="10" cy="10" r="5"/><path d="M21 21l-4-4"/><path d="M17 7h3M18.5 5.5v3"/>',
		'report-trust'          => '<path d="M7 4h10v16H7z"/><path d="M9 8h6"/><path d="M12 3l2 2h-4l2-2z"/><path d="M10 14l1.5 1.5L14 12"/>',
		'report-osint'          => '<path d="M7 4h10v16H7z"/><path d="M9 8h6"/><circle cx="15" cy="15" r="3"/><path d="M17 17l2 2"/>',
		'report-builder'        => '<path d="M7 4h10v16H7z"/><path d="M9 8h2v2H9z"/><path d="M13 8h2v6h-2z"/><path d="M9 14h2v2H9z"/>',
		'report-pipeline'       => '<path d="M7 4h10v16H7z"/><circle cx="10" cy="11" r="1.5"/><circle cx="14" cy="11" r="1.5"/><path d="M11.5 11h1"/><circle cx="12" cy="16" r="1.5"/>',
		'report-automation'     => '<path d="M7 4h10v16H7z"/><path d="M9 9h6"/><path d="M10 13h4"/><path d="M12 16v2"/><path d="M10 18h4"/>',
		'report-connectors'     => '<path d="M7 4h10v16H7z"/><circle cx="10" cy="12" r="1.5"/><circle cx="14" cy="12" r="1.5"/><path d="M11.5 12h1"/>',
		'report-correlation'    => '<path d="M7 4h10v16H7z"/><circle cx="10" cy="11" r="1.5"/><circle cx="14" cy="9" r="1.5"/><circle cx="13" cy="15" r="1.5"/><path d="M11.5 11l2-2M11.5 11.5l1.5 3.5"/>',
		'report-timeline'       => '<path d="M7 4h10v16H7z"/><path d="M10 8v8"/><circle cx="10" cy="8" r="1"/><circle cx="10" cy="14" r="1"/><circle cx="10" cy="17" r="1"/>',
		'report-cve'            => '<path d="M7 4h10v16H7z"/><path d="M12 8v4"/><path d="M12 15h.01"/>',
		'report-attack-surface' => '<path d="M7 4h10v16H7z"/><circle cx="12" cy="12" r="3"/><path d="M12 5v2M12 17v2M5 12h2M17 12h2"/>',
		'scan-new'              => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
		'investigation-new'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M8 9h8"/><path d="M8 13h5"/><circle cx="17" cy="17" r="2"/>',
		'workspace-folder'      => '<path d="M3 7h6l2 2h10v10H3z"/><path d="M3 7v12"/>',
		'ioc-threat'            => '<path d="M8 3l-2 4 4 1-3 3 4-1 1 4 4-1-1 4 4 1-3-3 4 1 2 4"/><circle cx="12" cy="12" r="2"/>',
		'audit-log'             => '<path d="M8 4h11v16H8z"/><path d="M5 7h3v14H5z"/><path d="M11 9h5"/><path d="M11 13h5"/><path d="M11 17h3"/>',
		'connector-rest'        => '<path d="M8 9H5a2 2 0 0 0 0 4h3"/><path d="M16 15h3a2 2 0 0 0 0-4h-3"/><path d="M8 12h8"/>',
		'connector-webhook'     => '<path d="M13 2L3 14h7l-1 8 10-12h-7l1-8z"/>',
		'connector-openai'      => '<rect x="5" y="5" width="14" height="14" rx="2"/><path d="M9 9h6v6H9z"/><path d="M12 5v3M12 16v3M5 12h3M16 12h3"/>',
		'connector-slack'       => '<path d="M6 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/><path d="M10 10V6a2 2 0 1 0-4 0 2 2 0 0 0 4 0z"/><path d="M14 10h4a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/><path d="M14 14v4a2 2 0 1 0 4 0 2 2 0 0 0-4 0z"/>',
		'connector-airtable'    => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M4 10h16"/><path d="M10 10v9"/><path d="M14 10v9"/>',
		'connector-notion'      => '<path d="M6 4h12v16l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6"/><path d="M9 12h4"/>',
		'connector-github'      => '<path d="M9 18c-4 1-4-2-4-2s-1-3 2-4"/><circle cx="6" cy="6" r="2"/><circle cx="18" cy="8" r="2"/><path d="M8 18l4-6 4 4 4-2"/>',
		'connector-zapier'      => '<path d="M13 2L3 14h6l-2 8 10-14h-6l2-6z"/>',
	];

	private const DANGER_ACTIONS = [
		'alert',
		'alert-octagon',
		'x-circle',
		'virus-scan',
		'breach-check',
		'abuse-ch',
		'report-threat',
		'report-cve',
		'ioc-threat',
	];

	public static function is_danger_action( string $key ): bool {
		return in_array( $key, self::DANGER_ACTIONS, true );
	}

	public static function module_html( string $module_id, string $class = '' ): string {
		$key  = isset( self::MODULE_PATHS[ $module_id ] ) ? $module_id : 'trust';
		$path = self::MODULE_PATHS[ $key ];
		$cls  = trim( 'pdx-mod-icon pdx-mod-icon--' . $key . ( $class ? ' ' . $class : '' ) );

		return self::svg_wrap( $cls, $path );
	}

	public static function action_html( string $action_key, string $class = '' ): string {
		if ( ! isset( self::ACTION_PATHS[ $action_key ] ) ) {
			return self::action_html( 'info', $class );
		}
		$path = self::ACTION_PATHS[ $action_key ];
		$cls  = 'pdx-icon';
		if ( self::is_danger_action( $action_key ) ) {
			$cls .= ' pdx-icon--danger';
		}
		if ( $class ) {
			$cls .= ' ' . $class;
		}

		return self::svg_wrap( trim( $cls ), $path );
	}

	/** Resolve action icons first, then module dock icons. */
	public static function icon_html( string $key, string $class = '' ): string {
		if ( isset( self::ACTION_PATHS[ $key ] ) ) {
			return self::action_html( $key, $class );
		}
		if ( isset( self::MODULE_PATHS[ $key ] ) ) {
			return self::module_html( $key, $class );
		}
		return self::module_html( 'trust', $class );
	}

	private static function svg_wrap( string $class, string $path ): string {
		return sprintf(
			'<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
			esc_attr( $class ),
			$path
		);
	}

	/** @return array<string, string> */
	public static function all_module_ids(): array {
		return array_keys( self::MODULE_PATHS );
	}
}
