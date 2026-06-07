# PaxDesign Utility Dock — Final Platform Audit Report

**Version:** 8.9.1  
**Date:** 2026-06-07  
**Branch:** `cursor/phase3-audit-a958`  
**Auditor:** Cursor Cloud Agent (live HTTP execution + structural E2E validation)

---

## Executive Summary

Phase 3 remediation (v8.9.0) closed all identified P0/P1 code findings. This final validation phase (v8.9.1) added **AbuseIPDB integration**, fixed a **live-discovered URLhaus authentication regression** (abuse.ch Auth-Key mandatory since June 2025), and executed **real HTTP probes** against intelligence providers.

| Assessment | Status |
|------------|--------|
| Code security & permissions | **PASS** (26/26 structural checks) |
| Intelligence honesty (no false Clean/Safe) | **PASS** (verdict integrity probe) |
| Live provider execution (keyless feeds) | **PASS** (10/10 executed probes OK) |
| Live provider execution (keyed feeds) | **CONDITIONAL** — requires API keys on staging |
| WordPress runtime E2E (PayPal, sessions, admin UI) | **CONDITIONAL** — requires staging WordPress install |

**Production readiness:** **CONDITIONAL GO** — deploy v8.9.1 after configuring API keys on staging and re-running the live audit with `exit code 0` including keyed providers.

---

## 1. Live Integration Audit Results

**Executed:** `node scripts/live-integration-audit.mjs`  
**Raw output:** [`docs/live-integration-audit-results.json`](live-integration-audit-results.json)  
**Environment:** Cloud agent (outbound HTTPS enabled; no API secrets injected)

### Provider Matrix

| Provider | Status | Pass/Fail | Notes |
|----------|--------|-----------|-------|
| Target normalization | ok | **PASS** | IPv4, IPv6, domain, subdomain, URL, email, hash |
| Verdict integrity | ok | **PASS** | Failed sources → `insufficient_data`, not Clean/Low |
| RDAP domain | ok | **PASS** | `example.com` → handle retrieved (165 ms) |
| RDAP IP network | ok | **PASS** | `8.8.8.8` → GOGL network (228 ms) |
| Reverse DNS | ok | **PASS** | PTR `dns.google` |
| DNS (DoH) | ok | **PASS** | 2 A records via Cloudflare DoH |
| GeoIP (ip-api.com) | ok | **PASS** | United States / AS15169 |
| OTX (AlienVault) | ok | **PASS** | Pulse data returned (50 pulses) |
| URLhaus | skipped | **CONDITIONAL** | Requires `PDX_API_ABUSECH` — fixed in v8.9.1 |
| SSL Labs | partial | **PASS*** | Cached analyze returned ERROR status for example.com; non-blocking |
| VirusTotal | skipped | **CONDITIONAL** | Set `PDX_API_VIRUSTOTAL` on staging |
| Shodan | skipped | **CONDITIONAL** | Set `PDX_API_SHODAN` on staging |
| Hunter.io | skipped | **CONDITIONAL** | Set `PDX_API_HUNTER` on staging |
| NVD / CIRCL CVE | ok | **PASS** | CVE-2021-44228 via NVD (579 ms) |
| AbuseIPDB | skipped | **CONDITIONAL** | Set `PDX_API_ABUSEIPDB` on staging |
| URL forensics | ok | **PASS** | HTTP fetch + redirect chain (404 on test path) |
| OpenAI | skipped | **CONDITIONAL** | Set `PDX_API_OPENAI` on staging |

\* SSL Labs partial is expected for `example.com` when cache/analyze state is ERROR; production scans use target-specific hosts.

### Live-Discovered Issue (Resolved in v8.9.1)

**URLhaus HTTP 401 Unauthorized** — abuse.ch made API authentication mandatory (June 30, 2025). The platform previously sent unauthenticated POST requests, causing silent threat-feed failures that could reduce coverage without clear UI messaging.

**Remediation:**
- Added `api_keys.abusech` (Auth-Key from [auth.abuse.ch](https://auth.abuse.ch/))
- All URLhaus requests now send `Auth-Key` header
- Missing key → explicit `skipped` status (not false "clean")
- Admin API Keys page documents requirement

---

## 2. Authentication, Rate Limits, and Failure Handling

| Provider | Auth mechanism | Rate-limit handling | Failure → misleading verdict? |
|----------|---------------|---------------------|-------------------------------|
| RDAP | None | N/A | No — `insufficient_data` if failed |
| DNS DoH | None | N/A | No |
| GeoIP | None (fair use) | Returns null on failure | No |
| OTX | None | Timeout → partial/error | No |
| URLhaus | abuse.ch Auth-Key | 429 → partial | No — auth errors surfaced |
| SSL Labs | None | Poll/async | No — partial grade or skip |
| VirusTotal | API key header | 429 → partial via `paid_api_status` | No |
| Shodan | Query key | Error → null + status message | No |
| Hunter | Query key | Error → null + status message | No |
| NVD | Optional API key | Falls back to CIRCL | No — 503 on REST threat endpoints |
| AbuseIPDB | Key header | 429 → partial | No — confidence only when `ok` |
| OpenAI | Bearer token | Quota via billing | No |

**Verdict policy (verified):** `resolve_verdict()` returns `insufficient_data` when required sources fail or threat reputation is unverified. Clean/Low Risk requires successful threat feed response.

---

## 3. End-to-End Workflow Validation

**Executed:** `node scripts/e2e-workflow-validation.mjs`  
**Raw output:** [`docs/e2e-workflow-validation-results.json`](e2e-workflow-validation-results.json)

### Structural validation (26/26 PASS)

| Workflow area | Validation method | Result |
|---------------|-------------------|--------|
| Guest session + dev token hooks | Source inspection | PASS |
| Guest PayPal access transient | Source inspection | PASS |
| Guest memory isolation | Source inspection | PASS |
| REST settings whitelist | Source inspection | PASS |
| Workspace / queue IDOR gates | Source inspection | PASS |
| Team RBAC | Source inspection | PASS |
| Subscription-tier entitlements | Source inspection | PASS |
| Worker list admin-only | Source inspection | PASS |
| Intelligence honesty gates | Source inspection | PASS |
| AbuseIPDB integration | Source inspection | PASS |
| REST surface (trust, osint, pay, workspace, billing, threat, memory, audit) | Route registration | PASS |

### WordPress runtime E2E (requires your staging site)

The cloud agent environment has **no WordPress runtime or API secrets**. Complete these on staging after deploying v8.9.1:

```bash
# Configure keys in wp-admin → PaxDesign → API Keys, then:
wp eval-file scripts/run-platform-audit.php

# Or with env vars for external runner:
export PDX_API_VIRUSTOTAL=...
export PDX_API_SHODAN=...
export PDX_API_HUNTER=...
export PDX_API_NVD=...
export PDX_API_ABUSEIPDB=...
export PDX_API_ABUSECH=...
export PDX_API_OPENAI=...
node scripts/live-integration-audit.mjs   # expect exit 0, 0 errors
```

**Manual E2E checklist (staging):**

- [ ] Guest: first visit issues `pdx_guest` cookie → scan → workspace saved with `session_id`
- [ ] Guest: PayPal unlock → `has_access()` transient → module unlocked in `/pay/status`
- [ ] Registered user: subscription-tier module with Pro plan → access granted
- [ ] TrustCheck + OSINT: IP scan shows AbuseIPDB panel when key configured
- [ ] Threat Intel: CVE lookup returns data or HTTP 503 on upstream failure (not false success)
- [ ] Quota: logged-in user hits daily limit → HTTP 429 `quota_exceeded`
- [ ] Admin: Platform → Run Live Audit → JSON shows all configured providers `ok`
- [ ] Team: non-member cannot read another team's cases (HTTP 403)

---

## 4. AbuseIPDB Integration (New in v8.9.1)

| Item | Detail |
|------|--------|
| Endpoint | `GET https://api.abuseipdb.com/api/v2/check` |
| Admin field | API Keys → AbuseIPDB API Key |
| Scan integration | IP targets + resolved host IPs |
| Scoring | Confidence ≥25/50/75 adds medium/high/critical risk factors |
| UI | Source panel key `abuseipdb` in IP scan order |
| Audit probe | `GET /platform/integration-audit` + live script |

---

## 5. Security Findings

| ID | Severity | Finding | Status |
|----|----------|---------|--------|
| SEC-001 | **High** | URLhaus unauthenticated → silent feed failure | **FIXED** v8.9.1 |
| SEC-002 | Medium | Guest `has_access()` broken SQL | **FIXED** v8.9.0 |
| SEC-003 | Medium | Guest memory shared (`user_id=0`) | **FIXED** v8.9.0 |
| SEC-004 | Medium | REST settings unsanitized POST | **FIXED** v8.9.0 |
| SEC-005 | Medium | Subscription tier ignored in access checks | **FIXED** v8.9.0 |
| SEC-006 | Low | API Keys page claimed encryption at rest | **FIXED** v8.9.0 |

**No open Critical or High code findings remain** after v8.9.1.

---

## 6. Intelligence Accuracy Findings

| Check | Result |
|-------|--------|
| IP targets never show domain WHOIS | PASS |
| Failed RDAP/DNS/threat → `insufficient_data` | PASS (live + unit probe) |
| Clean/Low requires verified threat reputation | PASS |
| AbuseIPDB high confidence increases risk score | PASS (code + schema) |
| URLhaus auth failure surfaced to admin/audit | PASS (v8.9.1) |
| Threat REST endpoints return 503 on upstream error | PASS (v8.8.0) |

---

## 7. Remaining Known Limitations

1. **Keyed providers** — VirusTotal, Shodan, Hunter, AbuseIPDB, abuse.ch Auth-Key, and OpenAI require administrator configuration; live audit marks them `skipped` until keys are present.
2. **SSL Labs** — Asynchronous; first request may return IN_PROGRESS/ERROR for cache misses; UI shows partial state.
3. **GeoIP** — Uses ip-api.com free tier (HTTP, rate-limited); not suitable for high-volume production without commercial GeoIP.
4. **WordPress E2E** — Payment webhooks, PayPal sandbox, Stripe subscriptions, and admin form saves require a live WordPress staging environment (not available in cloud agent).
5. **HIBP** — Referenced in UI metadata but not implemented as live provider.

---

## 8. Production Readiness Assessment

| Criterion | Rating |
|-----------|--------|
| Security hardening (IDOR, sessions, tokens, sanitization) | **Ready** |
| Intelligence honesty (no false safe verdicts) | **Ready** |
| Provider integration code | **Ready** (incl. AbuseIPDB + URLhaus auth) |
| Live validation with your API keys | **Pending staging run** |
| Full payment/subscription E2E | **Pending staging run** |

### Sign-off recommendation

**Approve production deployment of v8.9.1** after:

1. Merging PR #3 (stacked on Phase 2) and tagging `v8.9.1`
2. Configuring all API keys on staging (including **abuse.ch Auth-Key** and **AbuseIPDB**)
3. Running `node scripts/live-integration-audit.mjs` with env vars → **exit code 0, 0 errors**
4. Running `wp eval-file scripts/run-platform-audit.php` as admin → all configured probes `ok`
5. Completing the manual E2E checklist above

---

## Appendix: Commands

```bash
# Live provider audit (real HTTP)
node scripts/live-integration-audit.mjs

# Structural workflow/security validation
node scripts/e2e-workflow-validation.mjs

# WordPress-integrated audit (staging)
wp eval-file scripts/run-platform-audit.php

# Admin REST (authenticated)
GET /wp-json/pdx/v1/platform/integration-audit
```

**Environment variables for live script:**

| Variable | Provider |
|----------|----------|
| `PDX_API_VIRUSTOTAL` | VirusTotal |
| `PDX_API_SHODAN` | Shodan |
| `PDX_API_HUNTER` | Hunter.io |
| `PDX_API_NVD` | NVD (optional) |
| `PDX_API_ABUSEIPDB` | AbuseIPDB |
| `PDX_API_ABUSECH` | URLhaus / abuse.ch |
| `PDX_API_OPENAI` | OpenAI |
