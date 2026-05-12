<?php
/**
 * Runs on plugin deletion (not deactivation).
 * Removes all plugin data from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pdx_settings' );
delete_option( 'pdx_event_log' );
