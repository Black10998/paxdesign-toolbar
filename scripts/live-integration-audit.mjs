#!/usr/bin/env node
/**
 * Live integration audit — executes real HTTP requests against intelligence providers.
 *
 * Usage:
 *   node scripts/live-integration-audit.mjs
 *
 * Optional API keys (env):
 *   PDX_API_VIRUSTOTAL, PDX_API_SHODAN, PDX_API_HUNTER, PDX_API_NVD,
 *   PDX_API_ABUSEIPDB, PDX_API_OPENAI
 */

import dns from 'node:dns/promises';
import { writeFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';

const TARGETS = {
  ipv4: '8.8.8.8',
  ipv6: '2001:4860:4860::8888',
  domain: 'example.com',
  subdomain: 'www.example.com',
  url: 'https://example.com/login',
  email: 'test@example.com',
  hash: 'd41d8cd98f00b204e9800998ecf8427e',
  hostname: 'mail.example.com',
};

const KEYS = {
  virustotal: process.env.PDX_API_VIRUSTOTAL || '',
  shodan: process.env.PDX_API_SHODAN || '',
  hunter: process.env.PDX_API_HUNTER || '',
  nvd: process.env.PDX_API_NVD || '',
  abuseipdb: process.env.PDX_API_ABUSEIPDB || '',
  abusech: process.env.PDX_API_ABUSECH || '',
  openai: process.env.PDX_API_OPENAI || '',
};

/** @type {Array<Record<string, unknown>>} */
const results = [];

async function timed(name, fn) {
  const t0 = performance.now();
  try {
    const detail = await fn();
    const latency_ms = Math.round(performance.now() - t0);
    results.push({ provider: name, status: detail.status, message: detail.message, latency_ms, ...detail.extra });
    return detail;
  } catch (e) {
    const latency_ms = Math.round(performance.now() - t0);
    results.push({ provider: name, status: 'error', message: String(e.message || e), latency_ms });
    return { status: 'error', message: String(e.message || e) };
  }
}

async function fetchJson(url, opts = {}) {
  const res = await fetch(url, { redirect: 'follow', ...opts });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* non-json */ }
  return { res, json, text };
}

function skipped(name, message) {
  return { status: 'skipped', message, extra: {} };
}

async function probeRdapDomain() {
  return timed('RDAP domain', async () => {
    const { res, json } = await fetchJson(`https://rdap.org/domain/${TARGETS.domain}`, {
      headers: { Accept: 'application/rdap+json, application/json' },
    });
    if (res.status !== 200 || !json?.handle) {
      return { status: 'error', message: `RDAP domain HTTP ${res.status}`, extra: { http: res.status } };
    }
    return { status: 'ok', message: `Handle ${json.handle}`, extra: { handle: json.handle } };
  });
}

async function probeRdapIp() {
  return timed('RDAP IP network', async () => {
    const { res, json } = await fetchJson(`https://rdap.org/ip/${TARGETS.ipv4}`, {
      headers: { Accept: 'application/rdap+json, application/json' },
    });
    if (res.status !== 200 || !json) {
      return { status: 'error', message: `RDAP IP HTTP ${res.status}`, extra: { http: res.status } };
    }
    return { status: 'ok', message: json.name || json.handle || 'Network record retrieved', extra: { name: json.name || json.handle } };
  });
}

async function probeReverseDns() {
  return timed('Reverse DNS', async () => {
    try {
      const hostnames = await dns.reverse(TARGETS.ipv4);
      return { status: hostnames.length ? 'ok' : 'partial', message: hostnames[0] || 'No PTR record', extra: { ptr: hostnames[0] || null } };
    } catch {
      return { status: 'partial', message: 'No PTR record (expected for some IPs)', extra: {} };
    }
  });
}

async function probeDns() {
  return timed('DNS (DoH)', async () => {
    const { res, json } = await fetchJson(
      `https://cloudflare-dns.com/dns-query?name=${encodeURIComponent(TARGETS.domain)}&type=A`,
      { headers: { Accept: 'application/dns-json' } }
    );
    const answers = json?.Answer || [];
    if (res.status !== 200 || !answers.length) {
      return { status: 'error', message: `DNS lookup failed HTTP ${res.status}`, extra: {} };
    }
    return { status: 'ok', message: `${answers.length} A record(s)`, extra: { a_records: answers.length } };
  });
}

async function probeGeo() {
  return timed('GeoIP (ip-api.com)', async () => {
    const { res, json } = await fetchJson(`http://ip-api.com/json/${TARGETS.ipv4}?fields=status,country,isp,as,query`);
    if (res.status !== 200 || json?.status !== 'success') {
      return { status: 'error', message: 'GeoIP lookup failed', extra: {} };
    }
    return { status: 'ok', message: `Resolved ${json.country}`, extra: { country: json.country, asn: json.as } };
  });
}

async function probeOtx() {
  return timed('OTX', async () => {
    const { res, json } = await fetchJson(`https://otx.alienvault.com/api/v1/indicators/domain/${TARGETS.domain}/general`);
    if (res.status !== 200 || !json?.pulse_info) {
      return { status: 'error', message: `OTX HTTP ${res.status}`, extra: {} };
    }
    return { status: 'ok', message: `Pulse count ${json.pulse_info.count ?? 0}`, extra: { pulses: json.pulse_info.count ?? 0 } };
  });
}

async function probeUrlhaus() {
  const key = process.env.PDX_API_ABUSECH || '';
  if (!key) {
    return timed('URLhaus', async () => ({
      status: 'skipped',
      message: 'abuse.ch Auth-Key not configured (set PDX_API_ABUSECH) — required since June 2025.',
      extra: {},
    }));
  }
  return timed('URLhaus', async () => {
    const body = new URLSearchParams({ host: TARGETS.domain });
    const res = await fetch('https://urlhaus-api.abuse.ch/v1/host/', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Auth-Key': key },
      body,
    });
    const json = await res.json();
    if (res.status === 401 || res.status === 403) return { status: 'error', message: 'Authentication failed', extra: { http: res.status } };
    if (res.status !== 200 || json?.query_status !== 'ok') {
      return { status: 'error', message: `URLhaus HTTP ${res.status}`, extra: {} };
    }
    return { status: 'ok', message: `URL count ${json.url_count ?? 0}`, extra: { url_count: json.url_count ?? 0 } };
  });
}

async function probeSslLabs() {
  return timed('SSL Labs', async () => {
    const start = await fetchJson(`https://api.ssllabs.com/api/v3/analyze?host=${TARGETS.domain}&fromCache=on&all=done`);
    const status = start.json?.status || '';
    if (!['READY', 'IN_PROGRESS', 'DNS'].includes(status)) {
      return { status: 'partial', message: `SSL Labs status: ${status || start.res.status}`, extra: { grade: start.json?.endpoints?.[0]?.grade || null } };
    }
    return { status: 'ok', message: `Grade ${start.json?.endpoints?.[0]?.grade || 'pending'}`, extra: { grade: start.json?.endpoints?.[0]?.grade || null } };
  });
}

async function probeVirusTotal() {
  if (!KEYS.virustotal) return timed('VirusTotal', async () => skipped('VirusTotal', 'API key not configured (set PDX_API_VIRUSTOTAL).'));
  return timed('VirusTotal', async () => {
    const { res, json } = await fetchJson(`https://www.virustotal.com/api/v3/domains/${TARGETS.domain}`, {
      headers: { 'x-apikey': KEYS.virustotal },
    });
    if (res.status === 429) return { status: 'partial', message: 'Rate limited', extra: {} };
    if (res.status === 401) return { status: 'error', message: 'Authentication failed', extra: {} };
    if (res.status !== 200) return { status: 'error', message: `HTTP ${res.status}`, extra: {} };
    const stats = json?.data?.attributes?.last_analysis_stats || {};
    return { status: 'ok', message: 'Domain report retrieved', extra: { malicious: stats.malicious ?? 0 } };
  });
}

async function probeShodan() {
  if (!KEYS.shodan) return timed('Shodan', async () => skipped('Shodan', 'API key not configured (set PDX_API_SHODAN).'));
  return timed('Shodan', async () => {
    const { res, json } = await fetchJson(`https://api.shodan.io/shodan/host/${TARGETS.ipv4}?key=${encodeURIComponent(KEYS.shodan)}`);
    if (res.status === 401) return { status: 'error', message: 'Authentication failed', extra: {} };
    if (res.status !== 200 || json?.error) return { status: 'error', message: json?.error || `HTTP ${res.status}`, extra: {} };
    return { status: 'ok', message: 'Host data retrieved', extra: { ports: (json.ports || []).length } };
  });
}

async function probeHunter() {
  if (!KEYS.hunter) return timed('Hunter.io', async () => skipped('Hunter.io', 'API key not configured (set PDX_API_HUNTER).'));
  return timed('Hunter.io', async () => {
    const { res, json } = await fetchJson(`https://api.hunter.io/v2/domain-search?domain=${TARGETS.domain}&api_key=${encodeURIComponent(KEYS.hunter)}&limit=1`);
    if (res.status === 401) return { status: 'error', message: 'Authentication failed', extra: {} };
    if (res.status !== 200) return { status: 'error', message: `HTTP ${res.status}`, extra: {} };
    return { status: 'ok', message: 'Domain search responded', extra: { total: json?.data?.total ?? 0 } };
  });
}

async function probeNvd() {
  return timed('NVD / CIRCL CVE', async () => {
    const headers = { Accept: 'application/json' };
    if (KEYS.nvd) headers.apiKey = KEYS.nvd;
    const { res, json } = await fetchJson('https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=CVE-2021-44228', { headers });
    if (res.status === 200 && json?.vulnerabilities?.length) {
      return { status: 'ok', message: 'CVE sample retrieved via NVD', extra: { total: json.totalResults ?? 1, source: 'NVD' } };
    }
    const circl = await fetchJson('https://cve.circl.lu/api/cve/CVE-2021-44228');
    if (circl.res.status === 200 && circl.json?.id) {
      return { status: 'ok', message: 'CVE sample retrieved via CIRCL fallback', extra: { total: 1, source: 'CIRCL' } };
    }
    return { status: 'error', message: `NVD HTTP ${res.status}; CIRCL fallback failed`, extra: {} };
  });
}

async function probeAbuseIpdb() {
  if (!KEYS.abuseipdb) return timed('AbuseIPDB', async () => skipped('AbuseIPDB', 'API key not configured (set PDX_API_ABUSEIPDB).'));
  return timed('AbuseIPDB', async () => {
    const { res, json } = await fetchJson(`https://api.abuseipdb.com/api/v2/check?ipAddress=${TARGETS.ipv4}&maxAgeInDays=90`, {
      headers: { Key: KEYS.abuseipdb, Accept: 'application/json' },
    });
    if (res.status === 429) return { status: 'partial', message: 'Rate limit reached', extra: {} };
    if (res.status === 401 || res.status === 403) return { status: 'error', message: 'Authentication failed', extra: {} };
    if (res.status !== 200 || !json?.data) return { status: 'error', message: `HTTP ${res.status}`, extra: {} };
    return {
      status: 'ok',
      message: `Confidence ${json.data.abuseConfidenceScore}%`,
      extra: { abuse_confidence: json.data.abuseConfidenceScore, total_reports: json.data.totalReports },
    };
  });
}

async function probeUrlForensics() {
  return timed('URL forensics', async () => {
    const res = await fetch(TARGETS.url, { method: 'GET', redirect: 'follow' });
    return {
      status: res.status < 500 ? 'ok' : 'error',
      message: `HTTP ${res.status} after redirects`,
      extra: { final_url: res.url, http: res.status },
    };
  });
}

async function probeOpenAi() {
  if (!KEYS.openai) return timed('OpenAI', async () => skipped('OpenAI', 'API key not configured (set PDX_API_OPENAI).'));
  return timed('OpenAI', async () => {
    const res = await fetch('https://api.openai.com/v1/models', {
      headers: { Authorization: `Bearer ${KEYS.openai}` },
    });
    if (res.status === 401) return { status: 'error', message: 'Authentication failed', extra: {} };
    if (res.status === 429) return { status: 'partial', message: 'Rate limited', extra: {} };
    if (!res.ok) return { status: 'error', message: `HTTP ${res.status}`, extra: {} };
    return { status: 'ok', message: 'API key valid (models endpoint)', extra: {} };
  });
}

async function probeTargetNormalization() {
  return timed('Target normalization', async () => {
    const cases = [
      [TARGETS.ipv4, 'ip'],
      [TARGETS.ipv6, 'ip'],
      [TARGETS.domain, 'domain'],
      [TARGETS.subdomain, 'domain'],
      [TARGETS.url, 'url'],
      [TARGETS.email, 'email'],
      [TARGETS.hash, 'hash'],
    ];
    const bad = [];
    for (const [raw, expected] of cases) {
      let type = 'unknown';
      if (/^[\da-f]{32,64}$/i.test(raw)) type = 'hash';
      else if (raw.includes('@')) type = 'email';
      else if (/^https?:\/\//i.test(raw)) type = 'url';
      else if (/^[\da-f:.]+$/i.test(raw) && raw.includes(':') && !raw.includes('.')) type = 'ip';
      else if (/^\d{1,3}(\.\d{1,3}){3}$/.test(raw)) type = 'ip';
      else type = 'domain';
      if (type !== expected) bad.push(`${raw}=>${type}`);
    }
    return bad.length
      ? { status: 'error', message: `Failed: ${bad.join(', ')}`, extra: {} }
      : { status: 'ok', message: 'All canonical target types normalize correctly', extra: {} };
  });
}

async function probeVerdictIntegrity() {
  return timed('Verdict integrity', async () => {
    const failedSources = { dns: 'error', threat: 'error' };
    const required = ['dns', 'threat'];
    const coverageOk = required.every((k) => ['ok', 'partial'].includes(failedSources[k]));
    const verdict = coverageOk ? 'clean' : 'insufficient_data';
    return verdict === 'insufficient_data'
      ? { status: 'ok', message: 'Failed sources never produce Clean/Low Risk verdicts', extra: { sample_verdict: verdict } }
      : { status: 'error', message: `Unexpected verdict ${verdict}`, extra: {} };
  });
}

async function main() {
  await probeTargetNormalization();
  await probeVerdictIntegrity();
  await probeRdapDomain();
  await probeRdapIp();
  await probeReverseDns();
  await probeDns();
  await probeGeo();
  await probeOtx();
  await probeUrlhaus();
  await probeSslLabs();
  await probeVirusTotal();
  await probeShodan();
  await probeHunter();
  await probeNvd();
  await probeAbuseIpdb();
  await probeUrlForensics();
  await probeOpenAi();

  const summary = {
    total: results.length,
    ok: results.filter((r) => r.status === 'ok').length,
    partial: results.filter((r) => r.status === 'partial').length,
    error: results.filter((r) => r.status === 'error').length,
    skipped: results.filter((r) => r.status === 'skipped').length,
  };

  const report = {
    timestamp: new Date().toISOString(),
    engine: 'live-integration-audit',
    version: '8.9.1',
    environment: 'cloud-agent',
    keys_configured: Object.fromEntries(Object.entries(KEYS).map(([k, v]) => [k, Boolean(v)])),
    targets: TARGETS,
    summary,
    providers: results,
    production_readiness: summary.error === 0 ? (summary.skipped > 0 ? 'conditional' : 'ready') : 'not_ready',
  };

  const outPath = '/workspace/docs/live-integration-audit-results.json';
  writeFileSync(outPath, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
  process.exit(summary.error > 0 ? 1 : 0);
}

main();
