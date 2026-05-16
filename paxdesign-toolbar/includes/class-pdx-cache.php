<?php
/**
 * PDX_Cache — multi-layer caching abstraction.
 *
 * Layer 1: In-process PHP array (request lifetime)
 * Layer 2: WordPress object cache (memcached/redis if available)
 * Layer 3: Transients (DB fallback)
 *
 * All keys are namespaced under 'pdx_'.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class PDX_Cache {

	const NS      = 'pdx_';
	const GROUP   = 'pdx';

	private static array $local = [];
	private static array $stats = [ 'hits' => 0, 'misses' => 0, 'writes' => 0 ];

	/* ── Read ───────────────────────────────────────────── */

	public static function get( string $key, $default = null ) {
		$full = self::NS . $key;

		// L1: local
		if ( array_key_exists( $full, self::$local ) ) {
			self::$stats['hits']++;
			return self::$local[ $full ];
		}

		// L2: object cache
		$val = wp_cache_get( $full, self::GROUP );
		if ( $val !== false ) {
			self::$local[ $full ] = $val;
			self::$stats['hits']++;
			return $val;
		}

		// L3: transient
		$val = get_transient( $full );
		if ( $val !== false ) {
			self::$local[ $full ] = $val;
			wp_cache_set( $full, $val, self::GROUP, 300 );
			self::$stats['hits']++;
			return $val;
		}

		self::$stats['misses']++;
		return $default;
	}

	/* ── Write ──────────────────────────────────────────── */

	public static function set( string $key, $value, int $ttl = 300 ): void {
		$full = self::NS . $key;
		self::$local[ $full ] = $value;
		wp_cache_set( $full, $value, self::GROUP, $ttl );
		set_transient( $full, $value, $ttl );
		self::$stats['writes']++;
	}

	public static function remember( string $key, int $ttl, callable $callback ) {
		$val = self::get( $key );
		if ( $val !== null ) return $val;
		$val = $callback();
		self::set( $key, $val, $ttl );
		return $val;
	}

	/* ── Invalidation ───────────────────────────────────── */

	public static function delete( string $key ): void {
		$full = self::NS . $key;
		unset( self::$local[ $full ] );
		wp_cache_delete( $full, self::GROUP );
		delete_transient( $full );
	}

	public static function flush_prefix( string $prefix ): void {
		global $wpdb;
		$like = $wpdb->esc_like( '_transient_' . self::NS . $prefix ) . '%';
		$keys = $wpdb->get_col( $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		) );
		foreach ( $keys as $k ) {
			$transient_key = str_replace( '_transient_', '', $k );
			delete_transient( $transient_key );
		}
		// Clear local matching keys
		foreach ( array_keys( self::$local ) as $k ) {
			if ( str_starts_with( $k, self::NS . $prefix ) ) unset( self::$local[ $k ] );
		}
	}

	/* ── Stats ──────────────────────────────────────────── */

	public static function stats(): array {
		$total = self::$stats['hits'] + self::$stats['misses'];
		return array_merge( self::$stats, [
			'hit_rate'    => $total > 0 ? round( self::$stats['hits'] / $total * 100, 1 ) : 0,
			'local_keys'  => count( self::$local ),
			'backend'     => wp_using_ext_object_cache() ? 'object_cache' : 'transients',
		] );
	}

	/* ── Scan-specific helpers ──────────────────────────── */

	public static function get_scan( string $target, string $module ): ?array {
		return self::get( "scan_{$module}_" . md5( $target ) );
	}

	public static function set_scan( string $target, string $module, array $data, int $ttl = 3600 ): void {
		self::set( "scan_{$module}_" . md5( $target ), $data, $ttl );
	}

	public static function invalidate_scan( string $target, string $module ): void {
		self::delete( "scan_{$module}_" . md5( $target ) );
	}
}
