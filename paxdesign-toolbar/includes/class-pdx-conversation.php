<?php
/**
 * Persistent AI conversation threads (personas + export).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Conversation {

	private const META_KEY = 'pdx_conversations';
	private const MAX_THREADS = 50;
	private const MAX_MESSAGES = 200;

	public static function owner_id(): string {
		if ( is_user_logged_in() ) {
			return 'u:' . get_current_user_id();
		}
		$guest = $_COOKIE['pdx_guest'] ?? '';
		if ( ! $guest ) {
			$guest = 'g:' . substr( hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 16 );
		}
		return sanitize_key( $guest );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function load_all(): array {
		$owner = self::owner_id();
		if ( str_starts_with( $owner, 'u:' ) ) {
			$user_id = (int) substr( $owner, 2 );
			$data    = get_user_meta( $user_id, self::META_KEY, true );
			return is_array( $data ) ? $data : [];
		}
		$data = get_transient( 'pdx_conv_' . $owner );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $data
	 */
	private static function save_all( array $data ): void {
		$owner = self::owner_id();
		if ( str_starts_with( $owner, 'u:' ) ) {
			$user_id = (int) substr( $owner, 2 );
			update_user_meta( $user_id, self::META_KEY, $data );
			return;
		}
		set_transient( 'pdx_conv_' . $owner, $data, WEEK_IN_SECONDS );
	}

	public static function get_or_create( string $persona, ?string $thread_id = null ): string {
		$all = self::load_all();
		if ( $thread_id && isset( $all[ $thread_id ] ) ) {
			return $thread_id;
		}

		$thread_id = 'thr-' . substr( bin2hex( random_bytes( 8 ) ), 0, 12 );
		$all[ $thread_id ] = [
			'thread_id'  => $thread_id,
			'persona'    => sanitize_key( $persona ),
			'created_at' => gmdate( 'c' ),
			'updated_at' => gmdate( 'c' ),
			'messages'   => [],
		];

		if ( count( $all ) > self::MAX_THREADS ) {
			uasort( $all, static fn( $a, $b ) => strcmp( (string) ( $a['updated_at'] ?? '' ), (string) ( $b['updated_at'] ?? '' ) ) );
			$all = array_slice( $all, -self::MAX_THREADS, null, true );
		}

		self::save_all( $all );
		return $thread_id;
	}

	/**
	 * @return list<array{role:string,content:string,ts?:string}>
	 */
	public static function get_messages( string $thread_id ): array {
		$all = self::load_all();
		$thread = $all[ $thread_id ] ?? null;
		if ( ! is_array( $thread ) ) {
			return [];
		}
		return is_array( $thread['messages'] ?? null ) ? $thread['messages'] : [];
	}

	public static function append( string $thread_id, string $role, string $content ): void {
		$all = self::load_all();
		if ( ! isset( $all[ $thread_id ] ) ) {
			return;
		}

		$messages   = $all[ $thread_id ]['messages'] ?? [];
		$messages[]   = [
			'role'    => in_array( $role, [ 'user', 'assistant', 'system' ], true ) ? $role : 'user',
			'content' => sanitize_textarea_field( $content ),
			'ts'      => gmdate( 'c' ),
		];

		if ( count( $messages ) > self::MAX_MESSAGES ) {
			$messages = array_slice( $messages, -self::MAX_MESSAGES );
		}

		$all[ $thread_id ]['messages']   = $messages;
		$all[ $thread_id ]['updated_at'] = gmdate( 'c' );

		self::save_all( $all );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list_threads( ?string $persona = null ): array {
		$all = self::load_all();
		$out = [];
		foreach ( $all as $thread ) {
			if ( $persona && ( $thread['persona'] ?? '' ) !== $persona ) {
				continue;
			}
			$msgs = $thread['messages'] ?? [];
			$last = ! empty( $msgs ) ? end( $msgs ) : null;
			$out[] = [
				'thread_id'  => $thread['thread_id'] ?? '',
				'persona'    => $thread['persona'] ?? '',
				'updated_at' => $thread['updated_at'] ?? '',
				'preview'    => $last ? substr( (string) ( $last['content'] ?? '' ), 0, 80 ) : '',
				'count'      => count( $msgs ),
			];
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) );
		return $out;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function export_thread( string $thread_id ): ?array {
		$all = self::load_all();
		$thread = $all[ $thread_id ] ?? null;
		if ( ! is_array( $thread ) ) {
			return null;
		}
		return [
			'exported_at' => gmdate( 'c' ),
			'engine'      => 'pdx-v8.1',
			'thread'      => $thread,
		];
	}
}
