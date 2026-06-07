#!/usr/bin/env node
/**
 * Static + structural E2E workflow validation (no WordPress runtime required).
 * Validates security patterns, permission gates, and intelligence honesty rules in source.
 */

import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const ROOT = join(process.cwd(), 'paxdesign-toolbar');
/** @type {string[]} */
const failures = [];
/** @type {string[]} */
const passes = [];

function check(label, ok, detail = '') {
  if (ok) passes.push(detail ? `${label}: ${detail}` : label);
  else failures.push(detail ? `${label}: ${detail}` : label);
}

function read(rel) {
  const p = join(ROOT, rel);
  return existsSync(p) ? readFileSync(p, 'utf8') : '';
}

const rest = read('includes/api/class-pdx-rest-api.php');
const security = read('includes/class-pdx-security.php');
const intel = read('includes/class-pdx-intelligence.php');
const access = read('includes/commerce/class-pdx-access.php');
const memory = read('includes/class-pdx-memory.php');
const main = read('paxdesign-toolbar.php');
const dock = read('assets/js/dock.js');

// Security wiring
check('Dev token hooks registered', main.includes('PDX_Security::register_hooks()'));
check('REST settings sanitized', rest.includes('PDX_Security::sanitize_rest_settings'));
check('Guest access transient path', access.includes("get_transient( 'pdx_access_'") && !access.includes('JSON_CONTAINS'));
check('Memory actor isolation', memory.includes('actor_user_id'));
check('Memory REST requires actor', rest.includes('require_actor') && rest.match(/memory_store[\s\S]{0,200}require_actor/));

// IDOR / permissions
check('Workspace ownership gate', rest.includes('PDX_Workspace::user_can_access'));
check('Queue job ownership gate', rest.includes('PDX_Queue::user_can_access'));
check('Worker list admin-only', rest.includes("'permission_callback' => \$adm ] );") || rest.includes("permission_callback' => \$adm"));
check('Team RBAC on members', rest.includes('PDX_Team::user_can'));
check('Subscription tier access', rest.includes('subscription_covers_module'));

// Intelligence honesty
check('Insufficient data on failed coverage', intel.includes("return 'insufficient_data'"));
check('Threat reputation gate for clean/low', intel.includes('threat_reputation_verified'));
check('AbuseIPDB integrated', intel.includes('fetch_abuseipdb'));
check('AbuseIPDB in IP scoring', intel.includes('AbuseIPDB Reputation'));
check('Dock handles source errors', dock.includes("srcStatus") && dock.includes("formatSourceStatusNote"));

// Workflows present in REST surface
const routes = [
  '/trust', '/osint/scan', '/pay/create', '/pay/capture', '/pay/status',
  '/workspace', '/billing/plans', '/billing/status', '/threat/cve', '/memory/store',
  '/platform/integration-audit',
];
for (const route of routes) {
  check(`REST route ${route}`, rest.includes(route));
}

const report = {
  timestamp: new Date().toISOString(),
  engine: 'e2e-workflow-validation',
  version: '8.9.1',
  summary: { pass: passes.length, fail: failures.length },
  passes,
  failures,
  production_readiness: failures.length === 0 ? 'structural_pass' : 'structural_fail',
};

console.log(JSON.stringify(report, null, 2));
process.exit(failures.length ? 1 : 0);
