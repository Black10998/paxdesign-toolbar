<?php
/**
 * PDX_Auth — WordPress-integrated authentication, email verification, and session security.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Auth {

	const META_VERIFIED       = 'pdx_email_verified';
	const META_VERIFY_TOKEN   = 'pdx_verify_token';
	const META_VERIFY_EXPIRES = 'pdx_verify_expires';
	const META_RESET_TOKEN    = 'pdx_reset_token';
	const META_RESET_EXPIRES  = 'pdx_reset_expires';
	const META_FAILED_LOGINS  = 'pdx_failed_logins';
	const META_LOCKED_UNTIL   = 'pdx_locked_until';

	const VERIFY_TTL_HOURS = 24;
	const RESET_TTL_HOURS  = 1;

	/** Modules accessible without login (free tier). */
	public static function public_modules(): array {
		return apply_filters( 'pdx_public_modules', [ 'trust', 'create', 'workspace' ] );
	}

	public static function register_hooks(): void {
		add_action( 'init', [ self::class, 'handle_email_verify_link' ] );
		add_filter( 'authenticate', [ self::class, 'block_unverified_login' ], 30, 3 );
	}

	public static function is_email_verified( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, PDX_CAP ) ) {
			return true;
		}
		return (bool) get_user_meta( $user_id, self::META_VERIFIED, true );
	}

	public static function user_payload( ?int $user_id = null ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return [
				'logged_in'    => false,
				'verified'     => false,
				'display_name' => '',
				'email'        => '',
			];
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [ 'logged_in' => false, 'verified' => false ];
		}
		return [
			'logged_in'    => true,
			'id'           => $user_id,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'verified'     => self::is_email_verified( $user_id ),
			'is_admin'     => user_can( $user_id, PDX_CAP ),
		];
	}

	public static function module_requires_auth( string $module_id ): bool {
		return ! in_array( $module_id, self::public_modules(), true );
	}

	public static function can_access_module( string $module_id ): bool {
		if ( ! self::module_requires_auth( $module_id ) ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return false;
		}
		return self::is_email_verified( get_current_user_id() );
	}

	/**
	 * @return array{success:bool,message?:string,user?:array,error?:string,code?:string}
	 */
	public static function register( string $email, string $password, string $name ): array {
		$email = sanitize_email( $email );
		$name  = sanitize_text_field( $name );

		if ( ! is_email( $email ) ) {
			return [ 'success' => false, 'error' => 'invalid_email', 'message' => 'Please enter a valid email address.' ];
		}
		if ( strlen( $password ) < 8 ) {
			return [ 'success' => false, 'error' => 'weak_password', 'message' => 'Password must be at least 8 characters.' ];
		}
		if ( '' === $name ) {
			return [ 'success' => false, 'error' => 'missing_name', 'message' => 'Please enter your name.' ];
		}
		if ( email_exists( $email ) ) {
			return [ 'success' => false, 'error' => 'email_exists', 'message' => 'An account with this email already exists.' ];
		}

		$username = self::generate_username( $email );
		$user_id  = wp_create_user( $username, $password, $email );
		if ( is_wp_error( $user_id ) ) {
			return [ 'success' => false, 'error' => 'register_failed', 'message' => $user_id->get_error_message() ];
		}

		wp_update_user( [
			'ID'           => $user_id,
			'display_name' => $name,
			'first_name'   => $name,
		] );

		update_user_meta( $user_id, self::META_VERIFIED, 0 );
		self::send_verification_email( $user_id );

		PDX_Audit::log( 'auth', 'user_registered', [ 'user_id' => $user_id, 'email' => $email ] );

		return [
			'success' => true,
			'message' => 'Account created. Please check your email to verify your address.',
			'user'    => self::user_payload( $user_id ),
		];
	}

	/**
	 * @return array{success:bool,message?:string,user?:array,error?:string,code?:string}
	 */
	public static function login( string $email, string $password, bool $remember = true ): array {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return [ 'success' => false, 'error' => 'invalid_email', 'message' => 'Please enter a valid email address.' ];
		}

		$lock = self::check_brute_force( $email );
		if ( ! $lock['allowed'] ) {
			return [
				'success'     => false,
				'error'       => 'locked',
				'message'     => 'Too many failed attempts. Try again in ' . $lock['retry_after'] . ' seconds.',
				'retry_after' => $lock['retry_after'],
			];
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			self::record_failed_login( $email );
			return [ 'success' => false, 'error' => 'invalid_credentials', 'message' => 'Invalid email or password.' ];
		}

		if ( get_user_meta( $user->ID, self::META_LOCKED_UNTIL, true ) > time() ) {
			$remaining = (int) get_user_meta( $user->ID, self::META_LOCKED_UNTIL, true ) - time();
			return [
				'success'     => false,
				'error'       => 'locked',
				'message'     => 'Account temporarily locked. Try again in ' . max( 1, $remaining ) . ' seconds.',
				'retry_after' => max( 1, $remaining ),
			];
		}

		$signed = wp_signon( [
			'user_login'    => $user->user_login,
			'user_password' => $password,
			'remember'      => $remember,
		], is_ssl() );

		if ( is_wp_error( $signed ) ) {
			self::record_failed_login( $email, $user->ID );
			return [ 'success' => false, 'error' => 'invalid_credentials', 'message' => 'Invalid email or password.' ];
		}

		self::clear_failed_logins( $email, $user->ID );
		wp_set_current_user( $signed->ID );
		wp_set_auth_cookie( $signed->ID, $remember, is_ssl() );

		PDX_Audit::log( 'auth', 'user_login', [ 'user_id' => $signed->ID ] );

		return [
			'success' => true,
			'message' => 'Logged in successfully.',
			'user'    => self::user_payload( $signed->ID ),
		];
	}

	public static function logout(): array {
		$user_id = get_current_user_id();
		wp_logout();
		if ( $user_id ) {
			PDX_Audit::log( 'auth', 'user_logout', [ 'user_id' => $user_id ] );
		}
		return [ 'success' => true, 'message' => 'Logged out.' ];
	}

	/**
	 * @return array{success:bool,message?:string,error?:string}
	 */
	public static function forgot_password( string $email ): array {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return [ 'success' => true, 'message' => 'If that email exists, a reset link has been sent.' ];
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [ 'success' => true, 'message' => 'If that email exists, a reset link has been sent.' ];
		}

		$token   = bin2hex( random_bytes( 32 ) );
		$expires = time() + ( self::RESET_TTL_HOURS * HOUR_IN_SECONDS );

		update_user_meta( $user->ID, self::META_RESET_TOKEN, hash( 'sha256', $token ) );
		update_user_meta( $user->ID, self::META_RESET_EXPIRES, $expires );

		$link = add_query_arg( [
			'pdx_reset' => '1',
			'token'     => $token,
			'uid'       => $user->ID,
		], home_url( '/' ) );

		$subject = sprintf( '[%s] Reset your password', get_bloginfo( 'name' ) );
		$body    = self::email_template(
			'Reset your password',
			'Click the button below to set a new password. This link expires in ' . self::RESET_TTL_HOURS . ' hour.',
			$link,
			'Reset Password'
		);

		wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		PDX_Audit::log( 'auth', 'password_reset_requested', [ 'user_id' => $user->ID ] );

		return [ 'success' => true, 'message' => 'If that email exists, a reset link has been sent.' ];
	}

	/**
	 * @return array{success:bool,message?:string,error?:string}
	 */
	public static function reset_password( string $token, int $user_id, string $password ): array {
		if ( strlen( $password ) < 8 ) {
			return [ 'success' => false, 'error' => 'weak_password', 'message' => 'Password must be at least 8 characters.' ];
		}

		$stored  = (string) get_user_meta( $user_id, self::META_RESET_TOKEN, true );
		$expires = (int) get_user_meta( $user_id, self::META_RESET_EXPIRES, true );

		if ( ! $stored || ! $expires || $expires < time() ) {
			return [ 'success' => false, 'error' => 'expired', 'message' => 'Reset link has expired. Request a new one.' ];
		}
		if ( ! hash_equals( $stored, hash( 'sha256', $token ) ) ) {
			return [ 'success' => false, 'error' => 'invalid_token', 'message' => 'Invalid reset link.' ];
		}

		wp_set_password( $password, $user_id );
		delete_user_meta( $user_id, self::META_RESET_TOKEN );
		delete_user_meta( $user_id, self::META_RESET_EXPIRES );

		PDX_Audit::log( 'auth', 'password_reset', [ 'user_id' => $user_id ] );

		return [ 'success' => true, 'message' => 'Password updated. You can now log in.' ];
	}

	/**
	 * @return array{success:bool,message?:string,error?:string}
	 */
	public static function resend_verification( ?int $user_id = null ): array {
		$user_id = $user_id ?: get_current_user_id();
		if ( ! $user_id ) {
			return [ 'success' => false, 'error' => 'not_logged_in', 'message' => 'You must be logged in.' ];
		}
		if ( self::is_email_verified( $user_id ) ) {
			return [ 'success' => true, 'message' => 'Your email is already verified.' ];
		}

		self::send_verification_email( $user_id );
		return [ 'success' => true, 'message' => 'Verification email sent.' ];
	}

	public static function verify_email( int $user_id, string $token ): array {
		$stored  = (string) get_user_meta( $user_id, self::META_VERIFY_TOKEN, true );
		$expires = (int) get_user_meta( $user_id, self::META_VERIFY_EXPIRES, true );

		if ( ! $stored || ! $expires || $expires < time() ) {
			return [ 'success' => false, 'error' => 'expired', 'message' => 'Verification link has expired.' ];
		}
		if ( ! hash_equals( $stored, hash( 'sha256', $token ) ) ) {
			return [ 'success' => false, 'error' => 'invalid_token', 'message' => 'Invalid verification link.' ];
		}

		update_user_meta( $user_id, self::META_VERIFIED, 1 );
		delete_user_meta( $user_id, self::META_VERIFY_TOKEN );
		delete_user_meta( $user_id, self::META_VERIFY_EXPIRES );

		PDX_Audit::log( 'auth', 'email_verified', [ 'user_id' => $user_id ] );

		return [ 'success' => true, 'message' => 'Email verified successfully.' ];
	}

	public static function handle_email_verify_link(): void {
		if ( empty( $_GET['pdx_verify'] ) || empty( $_GET['token'] ) || empty( $_GET['uid'] ) ) {
			return;
		}

		$user_id = (int) $_GET['uid'];
		$token   = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		$result  = self::verify_email( $user_id, $token );

		$redirect = add_query_arg( [
			'pdx_auth'  => $result['success'] ? 'verified' : 'verify_failed',
			'pdx_msg'   => rawurlencode( $result['message'] ?? '' ),
		], home_url( '/' ) );

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function block_unverified_login( $user, $username, $password ) {
		if ( is_wp_error( $user ) || ! $user instanceof WP_User ) {
			return $user;
		}
		if ( user_can( $user, PDX_CAP ) ) {
			return $user;
		}
		return $user;
	}

	public static function auth_rate_limit( string $action ): ?array {
		$limits = [
			'login'    => [ 'capacity' => 5,  'refill' => 0.0056, 'cost' => 1 ], // ~5 per 15 min
			'register' => [ 'capacity' => 3,  'refill' => 0.00083, 'cost' => 1 ], // ~3 per hour
			'forgot'   => [ 'capacity' => 3,  'refill' => 0.00083, 'cost' => 1 ],
			'resend'   => [ 'capacity' => 2,  'refill' => 0.00028, 'cost' => 1 ], // ~2 per 2 hours
		];
		$config = $limits[ $action ] ?? $limits['login'];
		$key    = 'auth:' . $action . ':ip:' . md5( sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' ) );
		return PDX_RateLimit::check( $key, $config['capacity'], $config['refill'], $config['cost'] );
	}

	private static function send_verification_email( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		$token   = bin2hex( random_bytes( 32 ) );
		$expires = time() + ( self::VERIFY_TTL_HOURS * HOUR_IN_SECONDS );

		update_user_meta( $user_id, self::META_VERIFY_TOKEN, hash( 'sha256', $token ) );
		update_user_meta( $user_id, self::META_VERIFY_EXPIRES, $expires );

		$link = add_query_arg( [
			'pdx_verify' => '1',
			'token'      => $token,
			'uid'        => $user_id,
		], home_url( '/' ) );

		$subject = sprintf( '[%s] Verify your email', get_bloginfo( 'name' ) );
		$body    = self::email_template(
			'Verify your email',
			'Welcome! Please confirm your email address to unlock full access to PaxDesign.',
			$link,
			'Verify Email'
		);

		wp_mail( $user->user_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	private static function email_template( string $title, string $text, string $link, string $button ): string {
		$site = esc_html( get_bloginfo( 'name' ) );
		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#000;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">' .
			'<table width="100%" cellpadding="0" cellspacing="0" style="background:#000;padding:40px 20px"><tr><td align="center">' .
			'<table width="480" cellpadding="0" cellspacing="0" style="border:1px solid #ffe0a6;border-radius:8px;padding:32px;background:#111">' .
			'<tr><td style="color:#ffe0a6;font-size:20px;font-weight:700;letter-spacing:4px;text-transform:uppercase;text-align:center;padding-bottom:24px">' . esc_html( $title ) . '</td></tr>' .
			'<tr><td style="color:#aaa;font-size:14px;line-height:1.6;padding-bottom:24px">' . esc_html( $text ) . '</td></tr>' .
			'<tr><td align="center"><a href="' . esc_url( $link ) . '" style="display:inline-block;padding:14px 32px;background:#1a1a1a;border:1px solid #ffe0a6;color:#ffe0a6;text-decoration:none;font-weight:600">' . esc_html( $button ) . '</a></td></tr>' .
			'<tr><td style="color:#555;font-size:11px;padding-top:24px;text-align:center">' . $site . '</td></tr>' .
			'</table></td></tr></table></body></html>';
	}

	private static function generate_username( string $email ): string {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( ! username_exists( $base ) ) {
			return $base;
		}
		for ( $i = 1; $i <= 100; $i++ ) {
			$candidate = $base . $i;
			if ( ! username_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return $base . wp_generate_password( 4, false );
	}

	/** @return array{allowed:bool,retry_after:int} */
	private static function check_brute_force( string $email ): array {
		$ip_key = 'pdx_bf_ip_' . md5( sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip_fails = (int) get_transient( $ip_key );
		if ( $ip_fails >= 10 ) {
			return [ 'allowed' => false, 'retry_after' => 1800 ];
		}
		return [ 'allowed' => true, 'retry_after' => 0 ];
	}

	private static function record_failed_login( string $email, int $user_id = 0 ): void {
		$ip_key = 'pdx_bf_ip_' . md5( sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, 30 * MINUTE_IN_SECONDS );

		if ( $user_id ) {
			$fails = (int) get_user_meta( $user_id, self::META_FAILED_LOGINS, true ) + 1;
			update_user_meta( $user_id, self::META_FAILED_LOGINS, $fails );
			if ( $fails >= 5 ) {
				update_user_meta( $user_id, self::META_LOCKED_UNTIL, time() + 15 * MINUTE_IN_SECONDS );
			}
		}
	}

	private static function clear_failed_logins( string $email, int $user_id ): void {
		delete_transient( 'pdx_bf_ip_' . md5( sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
		delete_user_meta( $user_id, self::META_FAILED_LOGINS );
		delete_user_meta( $user_id, self::META_LOCKED_UNTIL );
	}
}
