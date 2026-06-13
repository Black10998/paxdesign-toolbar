<?php
/**
 * PDX_Customers — administrator customer account management (PaxDesign-only permissions).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PDX_Customers {

	const META_ACCOUNT_STATUS = 'pdx_account_status';
	const META_ADMIN_NOTES    = 'pdx_admin_notes';
	const META_LAST_LOGIN     = 'pdx_last_login';

	const STATUS_ACTIVE    = 'active';
	const STATUS_SUSPENDED = 'suspended';
	const STATUS_PENDING   = 'pending';

	/** @return list<WP_User> */
	public static function list_customers( string $search = '', int $limit = 25, int $offset = 0 ): array {
		$args = [
			'number'         => $limit,
			'offset'         => $offset,
			'orderby'        => 'registered',
			'order'          => 'DESC',
			'role__not_in'   => [ 'administrator' ],
			'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
		];

		if ( '' !== $search ) {
			$args['search'] = '*' . esc_attr( $search ) . '*';
		}

		$query = new WP_User_Query( $args );
		return $query->get_results();
	}

	public static function count_customers( string $search = '' ): int {
		$args = [
			'number'       => 1,
			'count_total'  => true,
			'role__not_in' => [ 'administrator' ],
		];
		if ( '' !== $search ) {
			$args['search']         = '*' . esc_attr( $search ) . '*';
			$args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
		}
		$query = new WP_User_Query( $args );
		return (int) $query->get_total();
	}

	public static function is_customer( int $user_id ): bool {
		if ( ! $user_id || PDX_Auth::is_site_admin( $user_id ) ) {
			return false;
		}
		return true;
	}

	public static function account_status( int $user_id ): string {
		$status = (string) get_user_meta( $user_id, self::META_ACCOUNT_STATUS, true );
		if ( ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_PENDING ], true ) ) {
			return self::STATUS_ACTIVE;
		}
		return $status;
	}

	public static function set_account_status( int $user_id, string $status ): bool {
		if ( ! self::is_customer( $user_id ) ) {
			return false;
		}
		if ( ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_PENDING ], true ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META_ACCOUNT_STATUS, $status );
		PDX_Audit::log( 'customers', 'account_status_changed', [
			'user_id' => $user_id,
			'status'  => $status,
		] );
		return true;
	}

	public static function record_login( int $user_id ): void {
		if ( $user_id ) {
			update_user_meta( $user_id, self::META_LAST_LOGIN, current_time( 'mysql' ) );
		}
	}

	/** @return array<string,mixed> */
	public static function customer_row( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [];
		}

		return [
			'id'              => $user_id,
			'display_name'    => $user->display_name,
			'email'           => $user->user_email,
			'registered'      => $user->user_registered,
			'verified'        => PDX_Auth::is_email_verified( $user_id ),
			'account_status'  => self::account_status( $user_id ),
			'payment_summary' => self::payment_summary( $user_id ),
			'last_login'      => (string) get_user_meta( $user_id, self::META_LAST_LOGIN, true ),
			'orders_count'    => count( PDX_Access::get_user_orders( $user_id ) ),
		];
	}

	/** @return array<string,mixed> */
	public static function customer_detail( int $user_id ): array {
		$row = self::customer_row( $user_id );
		if ( empty( $row ) ) {
			return [];
		}

		$row['notes']       = (string) get_user_meta( $user_id, self::META_ADMIN_NOTES, true );
		$row['orders']      = PDX_Account::customer_orders( $user_id );
		$row['purchases']   = PDX_Account::customer_purchases( $user_id );
		$row['subscription']= PDX_Account::customer_subscription( $user_id );

		return $row;
	}

	/** @return array{label:string,status:string} */
	public static function payment_summary( int $user_id ): array {
		$active = array_filter(
			PDX_Access::get_user_access( $user_id ),
			static fn( $r ) => 'active' === ( $r['status'] ?? '' )
		);

		if ( ! empty( $active ) ) {
			return [ 'label' => 'Paid', 'status' => 'paid' ];
		}

		if ( class_exists( 'PDX_Billing', false ) ) {
			$sub = PDX_Billing::get_subscription( $user_id );
			$plan = (string) ( $sub['plan_id'] ?? 'free' );
			$st   = (string) ( $sub['status'] ?? 'inactive' );
			if ( 'free' !== $plan && 'active' === $st ) {
				return [ 'label' => 'Subscription Active', 'status' => 'subscription_active' ];
			}
			if ( 'free' !== $plan && 'active' !== $st ) {
				return [ 'label' => 'Subscription Expired', 'status' => 'subscription_expired' ];
			}
		}

		return [ 'label' => 'Free', 'status' => 'free' ];
	}

	public static function suspend( int $user_id ): bool {
		return self::set_account_status( $user_id, self::STATUS_SUSPENDED );
	}

	public static function activate( int $user_id ): bool {
		return self::set_account_status( $user_id, self::STATUS_ACTIVE );
	}

	public static function save_notes( int $user_id, string $notes ): bool {
		if ( ! self::is_customer( $user_id ) ) {
			return false;
		}
		update_user_meta( $user_id, self::META_ADMIN_NOTES, sanitize_textarea_field( $notes ) );
		PDX_Audit::log( 'customers', 'notes_updated', [ 'user_id' => $user_id ] );
		return true;
	}

	public static function resend_verification( int $user_id ): array {
		if ( ! self::is_customer( $user_id ) ) {
			return [ 'success' => false, 'message' => 'Invalid customer.' ];
		}
		$result = PDX_Auth::resend_verification( $user_id );
		if ( $result['success'] ?? false ) {
			PDX_Audit::log( 'customers', 'resend_verification', [ 'user_id' => $user_id ] );
		}
		return $result;
	}

	public static function grant_module( int $user_id, string $module_id, int $days = 0 ): bool {
		if ( ! self::is_customer( $user_id ) ) {
			return false;
		}
		$expires = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ) : null;
		$ok      = PDX_Access::grant_user_access( $user_id, $module_id, $expires );
		if ( $ok ) {
			PDX_Audit::log( 'customers', 'grant_access', [
				'user_id'   => $user_id,
				'module_id' => $module_id,
				'days'      => $days,
			] );
		}
		return $ok;
	}

	public static function revoke_module( int $user_id, string $module_id ): bool {
		if ( ! self::is_customer( $user_id ) ) {
			return false;
		}
		$ok = PDX_Access::revoke_user_access( $user_id, $module_id );
		if ( $ok ) {
			PDX_Audit::log( 'customers', 'revoke_access', [
				'user_id'   => $user_id,
				'module_id' => $module_id,
			] );
		}
		return $ok;
	}

	public static function extend_subscription( int $user_id, int $days ): bool {
		if ( ! self::is_customer( $user_id ) || $days < 1 || ! class_exists( 'PDX_Billing', false ) ) {
			return false;
		}
		global $wpdb;
		$table = $wpdb->prefix . PDX_Billing::T_SUBS;
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ),
			ARRAY_A
		);
		$end   = $row && ! empty( $row['current_period_end'] )
			? max( time(), strtotime( $row['current_period_end'] ) )
			: time();
		$new_end = gmdate( 'Y-m-d H:i:s', $end + ( $days * DAY_IN_SECONDS ) );

		if ( $row ) {
			$wpdb->update(
				$table,
				[
					'status'              => 'active',
					'current_period_end'  => $new_end,
					'updated_at'          => current_time( 'mysql' ),
				],
				[ 'user_id' => $user_id ]
			);
		} else {
			PDX_Billing::set_plan( $user_id, 'pro', 'month', [
				'period_end' => $new_end,
			] );
		}

		PDX_Audit::log( 'customers', 'extend_subscription', [
			'user_id' => $user_id,
			'days'    => $days,
		] );
		return true;
	}

	public static function is_login_allowed( int $user_id ): bool {
		return self::STATUS_SUSPENDED !== self::account_status( $user_id );
	}
}
