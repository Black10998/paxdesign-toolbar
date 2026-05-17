<?php
/**
 * Saved AI Builder flows and Agent Pipeline definitions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Flow_Store {

	private const META_KEY = 'pdx_saved_flows';
	private const MAX_FLOWS = 100;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function load_all(): array {
		if ( ! is_user_logged_in() ) {
			$guest = sanitize_key( $_COOKIE['pdx_guest'] ?? '' );
			if ( ! $guest ) {
				return [];
			}
			$data = get_transient( 'pdx_flows_' . $guest );
			return is_array( $data ) ? $data : [];
		}
		$data = get_user_meta( get_current_user_id(), self::META_KEY, true );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $data
	 */
	private static function save_all( array $data ): void {
		if ( ! is_user_logged_in() ) {
			$guest = sanitize_key( $_COOKIE['pdx_guest'] ?? '' );
			if ( $guest ) {
				set_transient( 'pdx_flows_' . $guest, $data, WEEK_IN_SECONDS );
			}
			return;
		}
		update_user_meta( get_current_user_id(), self::META_KEY, $data );
	}

	/**
	 * @param 'builder'|'pipeline' $type
	 */
	public static function save( string $type, string $name, array $definition ): string {
		$type = in_array( $type, [ 'builder', 'pipeline' ], true ) ? $type : 'builder';
		$all  = self::load_all();
		$id   = 'flow-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );

		$all[ $id ] = [
			'flow_id'    => $id,
			'type'       => $type,
			'name'       => sanitize_text_field( $name ),
			'definition' => $definition,
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
		];

		if ( count( $all ) > self::MAX_FLOWS ) {
			uasort( $all, static fn( $a, $b ) => strcmp( (string) ( $a['updated_at'] ?? '' ), (string) ( $b['updated_at'] ?? '' ) ) );
			$all = array_slice( $all, -self::MAX_FLOWS, null, true );
		}

		self::save_all( $all );
		return $id;
	}

	/**
	 * @param 'builder'|'pipeline'|'' $type
	 * @return list<array<string, mixed>>
	 */
	public static function list( string $type = '' ): array {
		$all = self::load_all();
		$out = [];
		foreach ( $all as $flow ) {
			if ( $type && ( $flow['type'] ?? '' ) !== $type ) {
				continue;
			}
			$out[] = [
				'flow_id'    => $flow['flow_id'] ?? '',
				'type'       => $flow['type'] ?? '',
				'name'       => $flow['name'] ?? '',
				'updated_at' => $flow['updated_at'] ?? '',
			];
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) );
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( string $flow_id ): ?array {
		$all = self::load_all();
		return $all[ $flow_id ] ?? null;
	}

	public static function delete( string $flow_id ): bool {
		$all = self::load_all();
		if ( ! isset( $all[ $flow_id ] ) ) {
			return false;
		}
		unset( $all[ $flow_id ] );
		self::save_all( $all );
		return true;
	}
}
