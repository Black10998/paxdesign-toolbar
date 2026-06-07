<?php
/**
 * Static security/consistency checks for the PDX plugin (no WordPress required).
 * Usage: php scripts/audit-static-security.php
 */

$root   = dirname( __DIR__ ) . '/paxdesign-toolbar';
$errors = [];
$checks = 0;

function audit_fail( string $msg ): void {
	global $errors;
	$errors[] = $msg;
}

function audit_file_contains( string $file, string $needle, string $label ): void {
	global $checks;
	$checks++;
	if ( ! is_file( $file ) ) {
		audit_fail( "Missing file: {$file}" );
		return;
	}
	$body = file_get_contents( $file );
	if ( false === strpos( $body, $needle ) ) {
		audit_fail( "{$label}: expected `{$needle}` in {$file}" );
	}
}

function audit_file_not_contains( string $file, string $needle, string $label ): void {
	global $checks;
	$checks++;
	if ( ! is_file( $file ) ) {
		audit_fail( "Missing file: {$file}" );
		return;
	}
	$body = file_get_contents( $file );
	if ( false !== strpos( $body, $needle ) ) {
		audit_fail( "{$label}: forbidden pattern `{$needle}` still present in {$file}" );
	}
}

// Bootstrap wiring
audit_file_contains( $root . '/paxdesign-toolbar.php', 'PDX_Security::register_hooks()', 'Dev token + guest session hooks registered' );
audit_file_contains( $root . '/paxdesign-toolbar.php', 'class-pdx-integration-audit.php', 'Integration audit class required' );

// Guest access fix
audit_file_not_contains(
	$root . '/includes/commerce/class-pdx-access.php',
	'JSON_CONTAINS',
	'Guest has_access dead SQL removed'
);

// REST settings sanitization
audit_file_contains(
	$root . '/includes/api/class-pdx-rest-api.php',
	'PDX_Security::sanitize_rest_settings',
	'REST settings whitelist'
);

// Integration audit endpoint
audit_file_contains(
	$root . '/includes/api/class-pdx-rest-api.php',
	'/platform/integration-audit',
	'Integration audit REST route'
);

// Memory isolation
audit_file_contains(
	$root . '/includes/class-pdx-memory.php',
	'actor_user_id',
	'Guest memory isolation helper'
);

// Verdict integrity probe
audit_file_contains(
	$root . '/includes/class-pdx-integration-audit.php',
	'probe_verdict_integrity',
	'Verdict integrity live audit probe'
);

echo "Static audit: {$checks} checks, " . count( $errors ) . " failures\n";
foreach ( $errors as $e ) {
	echo "  FAIL: {$e}\n";
}

exit( empty( $errors ) ? 0 : 1 );
