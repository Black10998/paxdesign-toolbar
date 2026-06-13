# Changelog

## 9.0.8 — 2026-06-13

**PAXDesign Premium Verified Badge (original identity asset)**

- Replaced generic badge with original PAXDesign SVG: blue circle, white check, soft highlight, vector quality.
- Shared component (`PDX_Verified_Badge`) used in frontend JS, account UI, and administrator Customers page.
- Hover/focus tooltip: **Verified Account** (next to name) or **Email Verified** (verification status).
- Dark Mode and Light Mode support via CSS variables; crisp alignment with user names at 14–16px sizes.
- Still server-gated only — badge renders when `verified === true` from account data.

**Install:** `releases/paxdesign-toolbar-9.0.8.zip` — tag `v9.0.8`

## 9.0.7 — 2026-06-13

**Verified badge and administrator customer management**

- Added server-driven blue verified badge (shown only when email is confirmed via `pdx_email_verified`).
- Badge appears next to the user name in the account dropdown, profile overlay, and account dashboard status.
- Badge is removed automatically when email changes or verification is revoked.
- New **PAXDesign Customer Accounts** admin page under Commerce → Customers (`manage_options` only).
- Administrators can search customers, view verification/account/payment status, orders, purchases, and subscription details.
- PaxDesign-only admin actions: suspend/activate account, grant/revoke module access, extend subscription, resend verification, internal notes.
- All admin customer actions are nonce-protected and logged to the audit trail.
- New registrations start as `pending` account status; verified email activates the account.
- Suspended customers cannot log in.

**Install:** `releases/paxdesign-toolbar-9.0.7.zip` — tag `v9.0.7`

## 9.0.6 — 2026-06-13

**Critical: customer account panel permissions and billing UI**

- Hide API Keys, Integrations, and admin License tools from customer accounts (UI + server).
- Block `/account/api-keys` and validate endpoints for non-administrators (403).
- Customer account panel now shows: Profile, Purchases, Invoices/Payments, Subscription, Logout.
- Added customer orders/invoices with order ID, transaction ID, payment status, amount, product, and invoice download.
- Premium access remains tied to payment/subscription records, not login alone.

**Install:** `releases/paxdesign-toolbar-9.0.6.zip` — tag `v9.0.6`

## 9.0.5 — 2026-06-13

**Critical: customer account isolation from WordPress admin**

- Hide WordPress Admin Bar for all non-administrator users (customers only see PaxDesign account UI).
- Block `/wp-admin` access for customers; redirect to frontend account entry (`/?pdx_account=1`).
- Register new users as PaxDesign Customer role (`pdx_customer`, or WooCommerce `customer` when active) with read-only capability.
- Normalize customer role and disable admin bar preference on login/register.
- Redirect logged-in customers away from `wp-login.php` to the frontend account experience.

**Install:** `releases/paxdesign-toolbar-9.0.5.zip` — tag `v9.0.5`

## 9.0.4 — 2026-06-13

**Auth session, account menu, and premium access hardening**

- Fixed REST cookie/nonce failures after login (root cause of "Correlation failed / Cookie-Prüfung fehlgeschlagen") via automatic nonce refresh and session sync.
- Added `admin-ajax.php` nonce refresh endpoint and retry logic in auth and dock API clients.
- Redesigned header Login/Account control: compact icon on mobile, subtle desktop button, account dropdown with My Profile, My Account, and Logout.
- Added My Profile overlay showing full name, email, account status, and login status.
- Fixed register form field overlap (labels/inputs clickable area, texture layering).
- Hardened premium access: clearer paywall messaging; correlation endpoints gate on `investigation` module; payment errors no longer show as generic correlation failures.
- Login no longer implies premium access; server-side payment/subscription checks unchanged and enforced.

**Install:** `releases/paxdesign-toolbar-9.0.4.zip` — tag `v9.0.4`

## 9.0.3 — 2026-06-13

**Root fix: login/register auth UI**

- Fixed register form name field appearing hidden due to an oversized user SVG icon without width/height constraints.
- Removed the large user icon from login/register overlay forms; auth bar button icon is unchanged.
- Added visible field labels for all login, register, forgot, and reset forms.
- Added client-side validation so errors only reference visible fields before API submission.
- Unified register layout with login: consistent width, spacing, typography, and responsive behavior on desktop, tablet, and mobile.

**Install:** `releases/paxdesign-toolbar-9.0.3.zip` — tag `v9.0.3`

## 9.0.2 — 2026-06-13

**Root-cause fix: missing frontend navigation/modules**

- Fixed a critical dock visibility failure mode where navigation could disappear entirely when visibility settings and/or module toggles resulted in an empty rendered menu.
- Added frontend render fail-safe in `PDX_Settings::should_render()` so auth-enabled sites keep the dock visible for login gating instead of silently hiding navigation.
- Added module fail-safe in frontend config/render pipeline:
  - `js_config()` now auto-recovers to full module set if all modules are disabled.
  - `render_dock_items()` now falls back to full registry instead of outputting an empty nav.
- Hardened dock CSS against theme/plugin collisions that hide dock/button display.
- Refined desktop dock positioning to a consistent top-left vertical SaaS sidebar behavior.
- Preserved mobile behavior as under-header horizontal navigation.

**Install:** `releases/paxdesign-toolbar-9.0.2.zip` — tag `v9.0.2`

## 9.0.1 — 2026-06-13

**Navigation and authentication UI refinement release**

- Redesigned desktop module navigation into a clean, vertical left-side SaaS-style rail with consistent icon/label alignment and smoother hover/active states.
- Improved mobile navigation behavior under the header with cleaner horizontal spacing, better button readability, and smooth scrolling.
- Kept all modules visible when logged out; protected modules now continue to trigger auth flow on click and return users to the requested module after login.
- Refined top-right login/account button with more premium spacing, typography, gradients, and hover/focus interaction polish.
- Cleaned login/register screen visuals by reducing texture/noise and improving hierarchy/readability while preserving the core provided design language.
- Locked frontend dock position to left-side desktop layout and under-header mobile nav for consistent UX.

**Install:** `releases/paxdesign-toolbar-9.0.1.zip` — tag `v9.0.1`

## 9.0.0 — 2026-06-13

**Enterprise authentication, account dashboard, and UI polish**

- Full login/register system with the provided PaxDesign auth UI design (gold/cream gradient forms).
- Email verification after registration with secure expiring tokens and resend functionality.
- Forgot password / reset password flow with secure email links.
- WordPress-integrated session handling (cookie auth + REST nonce CSRF protection).
- Rate limiting and brute-force protection on auth endpoints.
- Fixed header login button (top-right) — always visible, gold accent theme (replaces inconsistent blue styling).
- Account dashboard with profile management, verification status, per-user API keys, integrations status, and license/subscription scaffolding.
- Access control: protected toolbar modules require login + verified email; free modules (TrustCheck, Development, Workspaces) remain public.
- Post-login redirect back to the requested module.
- Per-user API keys override site defaults when logged in.
- Comprehensive toolbar UI fixes: text wrapping, horizontal alignment, responsive cards/tables/results across TrustCheck and all modules.

**Install:** `releases/paxdesign-toolbar-9.0.0.zip` — tag `v9.0.0`

## 8.10.6 — 2026-06-07

**Updater reliability hotfix (GitHub API 429 fallback)**

- Fixed update-check failures when GitHub API rate limits return HTTP 429/403.
- Added resilient fallback in updater:
  - Parse `releases/latest` redirect to detect newest version without API dependency.
  - Build direct package URL from `main/releases/paxdesign-toolbar-<version>.zip`.
  - Reuse last known cached release metadata when API and fallback both temporarily fail.
- Prevented admin Updates panel from degrading to “Could not determine / Check failed” on transient GitHub API throttling.

**Install:** `releases/paxdesign-toolbar-8.10.6.zip` — tag `v8.10.6`

## 8.10.5 — 2026-06-07

**Frontend TrustCheck UI hotfix (SVG/text/layout) + dock module button resilience**

- Fixed oversized TrustCheck warning/action SVG icons in customer-facing result cards by correcting icon sizing scope in `pdx-icons.css`.
- Removed broad panel-wide SVG scaling (`#pdx-panel svg`) from `pdx-unified-ui.css` to avoid global side effects across frontend dock modules.
- Hardened TrustCheck result text rendering (`.pdx-kv-val`, anomaly text, summary text) for horizontal readable wrapping (no compressed/vertical-looking output).
- Added explicit TrustCheck result actions:
  - **Re-scan** (run scan again for current target)
  - **New Target** (focus/select input for quick new domain/IP scan)
- Improved dock module button normalization for legacy labels/aliases (`trustcheck`, `workflow`, `agents`, `ea`) to open the correct module panel reliably.

**Install:** `releases/paxdesign-toolbar-8.10.5.zip` — tag `v8.10.5`

## 8.10.4 — 2026-06-07

**Admin SVG hotfix (Pricing) + upgrade cache/runtime cleanup**

- Fixed oversized SVG icons in `wp-admin/admin.php?page=paxdesign-toolbar-pricing`.
- Removed broad admin-wide SVG scaling from `pdx-unified-ui.css` (`.pdx-admin-wrap svg`) to prevent cross-page icon blowups.
- Added scoped Pricing icon sizing (`.pdx-pricing-tool__icon`) so module icons render at stable dimensions.
- Kept SVG/CSS behavior scoped to intended UI areas instead of global admin selectors.
- Strengthened automatic upgrade cleanup (`PDX_CachePurge::maybe_purge_on_update`) to clear stale runtime/config caches:
  - `pdx_asset_urls`
  - `pdx_js_config`
  - `pdx_purge_results`
  - increment `pdx_config_version` to force fresh config/assets consumers

**Install:** `releases/paxdesign-toolbar-8.10.4.zip` — tag `v8.10.4`

## 8.10.3 — 2026-06-07

**Final release packaging on latest `main` after full stabilization pass**

- Synced release from the latest upstream `main` baseline (backend/frontend/CSS/Queue/API hardening included).
- Published a fresh WordPress-installable package for production deployment.
- Retained recent reliability fixes around feed sync, provider diagnostics, SVG scaling, dark mode readability, and truthful error paths.

**Install:** `releases/paxdesign-toolbar-8.10.3.zip` — tag `v8.10.3`

## 8.10.2 — 2026-06-07

**Reliability, UI scaling, and dark-mode readability**

### Sync Live Feeds — critical error fix
- Fixed PHP fatal when syncing feeds: `pdx_container()->intel` property access replaced with new `pdx_intel()` helper.
- REST `/threat/feeds` wrapped in try/catch — returns structured JSON error instead of crashing WordPress.
- Feed list UI now renders live probe results from the API instead of hardcoded mock feeds.

### Shodan DNS API — tier-aware status
- HTTP 403 on Shodan DNS API reported as `skipped` (Membership tier required), not authentication failure.
- HTTP 401 still indicates invalid API key.
- Attack Surface Provider Status shows partial-success note when Host API works but DNS API is tier-blocked.

### SVG & layout
- Global responsive SVG scaling with preserved aspect ratio; AI chip loader capped to container.
- Admin API key reveal buttons sized correctly (18px icons).
- Improved text wrapping on provider rows, feed items, info boxes, and admin tables.

### Dark mode
- Raised contrast for subtle/hint text tokens (`--pdx-text-subtle`, `--pdx-text-hint`).
- WordPress admin dark color schemes inherit readable plugin label and table colors.

**Install:** `releases/paxdesign-toolbar-8.10.2.zip` — tag `v8.10.2`

## 8.10.1 — 2026-06-07

**Attack Surface — Shodan authentication, subdomain discovery, and provider diagnostics**

- Shodan host lookups now resolve domains to IP first (API requires IP, not hostname).
- New `fetch_shodan_with_status()` surfaces HTTP codes, auth failures, rate limits, and 404 (not indexed).
- Shodan DNS API + Certificate Transparency + common-prefix DNS for subdomain discovery.
- Attack surface returns `provider_status`, `vulnerabilities`, `subdomains`, `technologies`, and `warnings`.
- UI shows Provider Status panel with HTTP codes; no more silent zero-result "success".
- DNS enumeration runs independently via existing `fetch_dns()` pipeline.

**Install:** `releases/paxdesign-toolbar-8.10.1.zip` — tag `v8.10.1`

## 8.10.0 — 2026-06-07

**Intelligence reliability — verified vs partial vs incomplete assessments**

### Scoring & verdict integrity
- Removed factor bypass that allowed SSL/forensics data to produce scored verdicts when required sources (DNS, threat feeds) failed.
- Risk factors only count when their provider source status is `ok` or `partial`.
- `insufficient_data` verdict zeroes the verified risk score; optional `indicative_score` preserved for transparency.
- Threat feeds must respond before any scored verdict; clean/low require threat status `ok`.
- New `report_quality.coverage_tier`: `verified` | `partial` | `incomplete` with accurate messages.
- `contributing_sources` lists which providers were used in the final score.
- Confidence capped when coverage is partial or incomplete.

### RDAP / .at TLD
- `.at` / `co.at` / `or.at` domains no longer error when rdap.org returns 404 (not in IANA bootstrap).
- Direct registry fallback endpoints (nic.at, denic, nic.fr, nominet).
- Relaxed RDAP JSON validation for registry-specific response shapes.

### UI
- Trust Check and OSINT show Assessment Coverage panel with required source states and score contributors.
- Incomplete assessments display "—" / indicative score instead of a verified risk ring.
- Intelligence summary and recommendations respect coverage tier.

### Threat Intel feeds
- Feed sync probes NVD/CIRCL, DoH DNS, and RDAP live instead of hardcoded "active" status.

**Install:** `releases/paxdesign-toolbar-8.10.0.zip` — tag `v8.10.0`

## 8.9.4 — 2026-06-07

**Integration Audit — GeoIP and SSL Labs provider fixes**

- **GeoIP (ip-api.com):** Free tier now uses HTTP (HTTPS returns 403 “SSL unavailable”); optional Pro key in Admin → API Keys enables HTTPS via `pro.ip-api.com`.
- **SSL Labs:** Audit probe uses `mozilla.org` (with fallbacks) instead of blacklisted `example.com`; blacklist responses are reported as **partial** with an explanatory message, not a hard error.
- `fetch_geo_with_status()` returns detailed error messages for TrustCheck and the integration audit.

**Install:** `releases/paxdesign-toolbar-8.9.4.zip` — tag `v8.9.4`

## 8.9.3 — 2026-06-07

**Integration Audit — partial results and resilient provider probes**

- Audit endpoint always returns HTTP 200 with full provider JSON when the runner completes (no more 503 on individual provider errors).
- Each provider probe wrapped in try/catch — one failure no longer terminates the audit.
- Admin UI renders provider table even when some probes error; shows warning with server message instead of generic failure.
- Improved error messages for VirusTotal/Shodan HTTP failures; server-side `error_log` for probe exceptions.

**Install:** `releases/paxdesign-toolbar-8.9.3.zip` — tag `v8.9.3`

## 8.9.2 — 2026-06-07

**Admin — Live Integration Audit REST authentication**

- Platform audit button now calls REST with `X-WP-Nonce` (`wp_rest`) instead of opening an unauthenticated URL.
- In-page audit results: provider summary table + JSON output + admin error messages.
- Audit UI hidden for users without `manage_options`; REST returns clear capability errors.
- Platform stats raw JSON uses the same authenticated fetch pattern.

**Install:** `releases/paxdesign-toolbar-8.9.2.zip` — tag `v8.9.2`

## 8.9.1 — 2026-06-07

**Final validation — AbuseIPDB, URLhaus auth, live audit tooling**

### Intelligence
- **AbuseIPDB** integrated for IP reputation (check API, scoring, UI source panel, integration audit probe).
- **URLhaus** updated for mandatory abuse.ch Auth-Key (required since June 2025) — prevents silent 401 failures.
- New Admin → API Keys fields: AbuseIPDB, abuse.ch Auth-Key (URLhaus).

### Validation tooling
- `node scripts/live-integration-audit.mjs` — executes real HTTP probes against all providers.
- `node scripts/e2e-workflow-validation.mjs` — structural workflow/security validation (26 checks).
- `docs/FINAL-AUDIT-REPORT.md` — production readiness assessment.
- `docs/live-integration-audit-results.json` — machine-readable live probe output.

**Install:** `releases/paxdesign-toolbar-8.9.1.zip` — tag `v8.9.1`

## 8.9.0 — 2026-06-06

**Phase 3 platform audit — live validation, guest isolation, and admin hardening**

### Live integration validation
- New `PDX_Integration_Audit` probes RDAP (domain + IP/WHOIS), reverse DNS, DNS, GeoIP, OTX/URLhaus, SSL Labs, VirusTotal, Shodan, Hunter, NVD/CVE, URL forensics, and OpenAI key presence against real targets.
- Target-type matrix validates IPv4, IPv6, domain, subdomain, URL, email, hash, and hostname normalization.
- Verdict integrity probe confirms failed required sources never produce Clean/Low Risk.
- Admin REST: `GET /platform/integration-audit` (admin-only). CLI: `wp eval-file scripts/run-platform-audit.php`.
- Static audit script: `php scripts/audit-static-security.php`.

### Security hardening
- `PDX_Security::register_hooks()` wired at bootstrap — Bearer dev token auth + guest session cookie on REST/frontend.
- Guest PayPal access check fixed (`has_access()` transient path; removed broken SQL).
- Guest memory isolated per session (pseudo user_id from session hash).
- Workspace/queue records bind `session_id` via `ensure_guest_session()` on create.
- REST `POST /settings` whitelists and sanitizes allowed keys only.
- Memory REST endpoints require a valid actor session.

### Admin audit
- API Keys page no longer claims keys are encrypted at rest.
- Pricing UI includes Subscription tier (matches backend).
- Removed duplicate billing save handler (`admin_post_pdx_save_settings`).
- Platform admin page links to live integration audit.
- Subscription-tier modules now check active SaaS plan (Pro/Team/Enterprise) in REST access and `/pay/status`.

**Note:** AbuseIPDB is not integrated in this release; audit documents the gap explicitly.

**Install:** `releases/paxdesign-toolbar-8.9.0.zip` — tag `v8.9.0`

## 8.8.0 — 2026-06-06

**Phase 2 platform audit — security, permissions, and API correctness**

### REST security (IDOR + auth)
- Workspace get/update now require ownership (`PDX_Workspace::user_can_access`).
- Queue job status now requires job ownership (`PDX_Queue::user_can_access`).
- Team members, cases, and case notes require login + team RBAC (`PDX_Team::user_can`).
- Worker callback requires worker ID + token authentication (same as heartbeat).
- Worker list and global queue stats restricted to administrators.
- SSE job channels require job ownership; activity audit stream restricted to administrators.
- PayPal capture validates order initiator (logged-in user or guest session binding).

### Abuse prevention
- New `PDX_Security` helper: actor identity, resource ownership, SSRF-safe outbound URL validation.
- Connector test endpoint blocks private/internal URL targets.
- `quota_check()` wired to scans, AI chat, builder, pipeline, automation, threat intel, connectors.
- Usage tracking metric fixed: `scans_per_day` (was `scan`).

### API correctness
- Threat CVE/surface/feeds return HTTP 503 when upstream intelligence fails (not HTTP 200 with hidden errors).
- UI surfaces CVE/surface/feed failures instead of empty success states.

**Install:** `releases/paxdesign-toolbar-8.8.0.zip` — tag `v8.8.0`

## 8.7.5 — 2026-06-06

**Platform audit — accuracy, UI honesty, and security hardening**

### Intelligence accuracy
- IP scans: separate **IP Network Registration** (RDAP) from **Reverse DNS (PTR)** — domain WHOIS never shown for IP targets.
- Recompute `report_quality` after URL forensics rescore so verdicts reflect full pipeline coverage.
- IP confidence weights use `ip_network` + `reverse_dns` instead of irrelevant domain RDAP/DNS slots.
- Server narratives distinguish IP network registration failures from domain WHOIS failures.

### UI / UX honesty
- Scan banners use `report_quality` — warn when sources failed, not just when verdict is `insufficient_data`.
- Threat panels show **Not verified** instead of ✓ No when feeds did not respond.
- Type-aware Intelligence Sources panel (IP, hash, email, domain/URL) with neutral styling for skipped sources.
- Pipeline treats HTTP/API failures (`_ok === false`) as errors, not success.
- Recommendations no longer say “No immediate action required” unless the report is reliable.

### Security
- PayPal capture validates `module_id` against the pending order record before granting access.

**Install:** `releases/paxdesign-toolbar-8.7.5.zip` — tag `v8.7.5`

## 8.7.4 — 2026-06-06

**Intelligence engine — type-aware routing and scoring**

### Target detection
- IPv6 addresses classified and parsed correctly (no colon truncation bugs).
- Email targets use the domain part for DNS/RDAP/threat lookups; full address preserved for display.
- URL forensics preserve path and query (not just `https://host/`).

### Intelligence routing (by target type)
- **IP:** RDAP IP, reverse DNS (PTR), GeoIP, OTX IPv4/IPv6, URLhaus host, Shodan, VT IP endpoint.
- **Domain/subdomain:** RDAP domain, DNS, SSL Labs, GeoIP, OTX domain, URLhaus host, VT domain.
- **URL:** Domain sources plus URLhaus URL lookup and full redirect/path forensics.
- **Email:** MX/SPF/DMARC via DNS on domain, domain RDAP/SSL, domain reputation feeds.
- **Hash:** OTX file + VT files endpoint only (no invalid domain/WHOIS lookups).

### Scoring / UX
- Verdict `insufficient_data` when required sources fail — no false “Clean” on failed scans.
- Type-aware confidence weights; skipped irrelevant sources do not penalize score.
- UI distinguishes Clean vs Low Risk vs Insufficient Data (no green “safe” styling on failures).

**Install:** `releases/paxdesign-toolbar-8.7.4.zip` — tag `v8.7.4`

## 8.7.3 — 2026-06-06

**UI responsiveness + mobile layout fixes**

### Performance
- Open dock panels immediately from cached access data instead of waiting for `/pay/status` on every click.
- Show a compact loading shell only when access data is not yet available.
- Defer non-critical init requests (billing, workers, queue, teams, SSE) until the browser is idle.
- Show scan/analysis results as soon as the API responds — staged pipeline animation no longer blocks for 9+ seconds.
- Faster panel transitions (200ms) and quicker pipeline stage timing.

### Mobile / responsive
- Remove `100vw !important` panel width that caused horizontal overflow and left-shift on mobile.
- Fix command palette and graph/investigation panel widths for small viewports.
- Reset admin dashboard negative margin on narrow screens; clip horizontal overflow.
- Stabilize scroll lock without layout jump; prevent module header negative margins from bleeding on mobile.

**Install:** `releases/paxdesign-toolbar-8.7.3.zip` — tag `v8.7.3`

## 8.7.2 — 2026-06-06

**WordPress Plugins screen update fix (8.6.8 → latest)**

### Updater
- Fix false “already up to date” failure on wp-admin plugin upgrades when a stale `no_update` row cached an older `new_version`.
- Rebuild update bucket placement from live GitHub release metadata on every transient read/write instead of trusting stored `new_version` values.
- Always return Update URI payloads when GitHub metadata is valid so WordPress can populate both `response` and `no_update` correctly.

**Install:** `releases/paxdesign-toolbar-8.7.2.zip` — tag `v8.7.2`

## 8.7.1 — 2026-06-06

**External API resilience + WordPress update reliability**

### API / intelligence
- Stop logging every outbound HTTP request; only log actionable failures (skip expected 401/403/404/429 and transient transport errors).
- Surface third-party failures in `source_status` instead of server error logs.
- Cache full scan results for 1 hour and threat-feed probes for 5 minutes to reduce duplicate API calls.
- Fix URLhaus feed probe to validate HTTP status before reporting success.
- Improve paid API status messages (missing key, auth failure, rate limit, no data).
- Validate GEO responses by HTTP status; remove noisy NVD/CIRCL miss logging.

### Updater
- Do not inject GitHub update offers when the installed version is already current.
- Enforce correct `response` / `no_update` bucket placement on every transient scrub.
- Recover successful installs when WordPress reports failure but the plugin is healthy and up to date.
- Refresh PaxDesign update metadata without deleting the entire `update_plugins` transient (avoids side effects on other plugins).

**Install:** `releases/paxdesign-toolbar-8.7.1.zip` — tag `v8.7.1`

## 8.7.0 — 2026-06-06

**Unified monochrome design system across customer-facing UI**

### Design
- New global design tokens (`pdx-tokens.css` v7) — gradient chrome, glass surfaces, white accent, monochrome palette only.
- New unified component layer (`pdx-unified-ui.css`) — buttons, tags, forms, cards, status indicators, and interaction patterns from the reference design applied across dock panels, admin, and all module screens.
- Removed legacy lime/GitHub color accents; all modules, loaders, icons, and status states use the same visual hierarchy (#ffffff, #f3f6fd, #7e7e7e, #555555, #363636, #292929, #1b1b1b, #8b8b8b, #888888).
- Default accent color updated to `#ffffff`; fully responsive on desktop, tablet, and mobile.

**Install:** `releases/paxdesign-toolbar-8.7.0.zip` — tag `v8.7.0`

## 8.6.2 — 2026-05-17

**Cinematic AI analysis loader (TrustCheck, OSINT, Investigation, Graph, Threat Intel)**

### UX
- New chip + data-flow SVG loader with glassmorphism and per-module accent colors (`pdx-ai-analysis-loader.js` / `.css`).
- TrustCheck now uses the full staged deep pipeline (no fast skip); minimum ~9.2s analysis display so loaders do not flash away.
- Staged labels rotate during analysis; Investigation correlate uses investigation (cyan) theme.

**Install:** `releases/paxdesign-toolbar-8.6.2.zip` — tag `v8.6.2`

## 8.6.1 — 2026-05-17

**Updater PHP 8.1 — complete null scrub + Update URI hook**

### Root cause
- Plugin declares `Update URI: https://github.com/...` but did not implement WordPress’s `update_plugins_github.com` filter, so core built incomplete update rows on `wp_update_plugins()` before our transient scrub ran.
- `inject_update()` error path removed `response` rows only, leaving corrupt `no_update` entries with `null` fields.
- `icons` / `banners` and extra object properties were not coerced; core `esc_url()` / `add_query_arg()` still received `null`.

### Fix
- `filter_update_plugins_uri()` — official Update URI injection path with normalized `version`, `package`, `url`, `slug`, `tested`, `requires`.
- `finalize_update_transient()` at priority 9999 on read and write of `update_plugins`.
- `clear_pdx_update_transient_entries()` on GitHub fetch failure (both buckets).
- `sanitize_url_map()`, `coerce_null_scalars_on_object()`, full `sanitize_plugins_api_object()`.
- Drop invalid PDX rows from `no_update` as well as `response`.
- Extended `scripts/test-updater-php81-deprecations.php` (fetch failure, Update URI filter, `esc_url` paths).

**Install:** `releases/paxdesign-toolbar-8.6.1.zip` — tag `v8.6.1`

## 8.6.0 — 2026-05-17

**Dock icon identity + floating transparent toolbar**

### Icons
- Rebuilt all 14 module dock SVGs with distinct silhouettes (no shared magnifier, multi-user, or node-grid language between modules).
- Fixed action alias bug where module IDs like `threat` could resolve to action icons instead of module icons.
- Connector/zap icons differentiated; action icons remain unique per UI context.

### Dock chrome
- Removed per-button dark/hover/active tile backgrounds — icons float on transparent buttons (stroke-only glyphs).
- Dock CSS unified across `dock.css`, `pdx-dock-ui.css`, `pdx-module-chrome.css`, and `pdx-icons.css`.

**Install:** `releases/paxdesign-toolbar-8.6.0.zip` — tag `v8.6.0`

## 8.5.2 — 2026-05-17

**Updater PHP 8.1 fix — no null values in `update_plugins` transient**

### Root cause
- Orphan `update_plugins` rows for deleted versioned folders (`paxdesign-toolbar-8.x.x/...`) could remain in the database with `null` `url`, `package`, `plugin`, or `slug` fields.
- WordPress core calls `strpos()` / `str_replace()` on those fields → PHP 8.1+ deprecation warnings in `debug.log`.
- `canonical_plugin_basename()` could recurse when the canonical folder was missing.

### Fix
- Scrub every `paxdesign-toolbar*` row in `response` / `no_update`: coerce all string fields, drop orphan keys, remove invalid `response` rows.
- Run repair on `plugins_loaded`, `admin_init`, and after inject/sanitize filters.
- Rebuild update metadata after activation (`refresh_plugin_update_metadata()`).
- Re-normalize cached GitHub release data when legacy entries contain `null`.
- `scripts/test-updater-php81-deprecations.php` — asserts zero deprecations in scrub/inject paths.

**Not tagged for release yet** — verify on Hostinger with `WP_DEBUG_LOG` before publishing `v8.5.2`.

## 8.5.1 — 2026-05-17

**SVG icon system — unique transparent action icons**

### Icon system
- Split **module dock icons** (`pdx-mod-icon`) from **action/UI icons** (`pdx-icon`) — removed legacy aliasing that reused the same glyph for different buttons (e.g. shared `folder`, `shield`, `alert` across unrelated actions).
- Each report summary, OSINT evidence source, paywall, billing header, command-palette result, connector type, and pipeline finding has its own modern stroke SVG.
- New `pdx-icons.css`: transparent icons (no background boxes), consistent stroke width/size on desktop dock and mobile, full red (`#f85149`) for danger/warning/risk icons.
- `create` module icon no longer uses a semi-transparent background rectangle.
- PHP `PDX_Icons::icon_html()` mirrors JS; REST command search and connector definitions use unique icon slugs.

**Install:** `releases/paxdesign-toolbar-8.5.1.zip` — tag `v8.5.1`

## 8.5.0 — 2026-05-17

**Canonical install path — fixes duplicate `paxdesign-toolbar-x.y.z` folders (Hostinger 409)**

### Root cause
- Some hosts created `paxdesign-toolbar-8.4.3/` when the ZIP lacked a proper `paxdesign-toolbar/` wrapper or when WordPress updated the wrong plugin directory.
- That produced two plugin folders, broken activation, and `409 Conflict` on upgrade.

### Permanent fix
- Release ZIPs are built with a guaranteed single root folder: `paxdesign-toolbar/` (verified in CI and `verify-release-zip.ps1`).
- Upgrader **always** installs to `wp-content/plugins/paxdesign-toolbar/` (`upgrader_package_options` + `upgrader_install_package`).
- Extracted packages rename any `paxdesign-toolbar-x.y.z` working folder to `paxdesign-toolbar` before install.
- On every load: merge duplicates into canonical, delete versioned folders, repair `active_plugins`.
- Plugins screen shows **one** PaxDesign entry (`all_plugins` filter).
- Update transients register only the canonical basename.

**Install:** `releases/paxdesign-toolbar-8.5.0.zip` — tag `v8.5.0`

**After update:** Delete any extra `wp-content/plugins/paxdesign-toolbar-*` folders manually if they remain, then activate **PaxDesign Utility Dock** once from the canonical `paxdesign-toolbar` row.

## 8.4.3 — 2026-05-17

**Updater architecture fix — stable WordPress update notifications**

- Plugin basename now always matches the loaded install path (fixes update row key mismatch with canonical folder)
- `inject_update()` correctly sets both `response` and `no_update` transients; no silent early-return leaving stale data
- Duplicate versioned folder basenames pruned from update transients; active plugin list repaired on activate/upgrade
- `is_our_plugin()` no longer returns true for unrelated upgrades when hook data is missing
- Post-upgrade/activate cache refresh calls `wp_update_plugins()` so available updates rebuild immediately
- Activation hook keeps update metadata in sync without breaking active plugin state

**Install:** `releases/paxdesign-toolbar-8.4.3.zip` — tag `v8.4.3`

## 8.4.2 — 2026-05-17

**Dock icon polish — unified toolbar/sidebar colors**

- Inactive dock SVG icons use one calm muted color (desktop sidebar + mobile top dock)
- Active/selected dock icon uses brand accent `#C2FF00` only
- Per-module rainbow colors scoped to panel content, not the home dock toolbar

**Install:** `releases/paxdesign-toolbar-8.4.2.zip` — tag `v8.4.2`

## 8.4.1 — 2026-05-17

**Hotfix — dock panels open reliably (fixes v8.4.0 `setPanelModuleTheme` crash)**

### Panel / theme registry
- `setPanelModuleTheme()` no longer throws on missing DOM or unknown module IDs; resolves `#pdx-panel` / `#pdx-panel-inner` at runtime with safe fallbacks
- Canonical `PDX_KNOWN_MODULES` list + `MODULE_ACCENTS` map for all 14 dock modules (personas, builder, automation, connectors, team, etc.)
- `normalizeModuleId()` used consistently in `openPanel()`, `renderPanel()`, and icon helpers
- Replaced fragile `global` references with `window` for strict-mode compatibility

### Interaction reliability
- Panel render generation counter prevents stale `/pay/status` responses from painting the wrong module after rapid dock clicks
- `injectCloseBtnGlobal()` refreshes panel inner element before injecting close control

**Install:** `releases/paxdesign-toolbar-8.4.1.zip` — tag `v8.4.1`

**After update:** Plugins → Updates, then hard-refresh (Ctrl+F5) or clear page cache.

## 8.4.0 — 2026-05-17

**Full cleanup — unique module icons, reliable button handlers, canonical assets**

### Icons & frontend
- New `PDX_Icons` PHP class — dock buttons render unique SVGs per module ID (no shared shield/search/user icons)
- `pdx-module-icons.js` keyed by module ID; removed alias collapsing (OSINT vs Investigation, Graph vs Automation, etc.)
- Dock applies icons from `data-module` directly; panel chrome uses per-module accent colors including Connectors, Create, Workspace
- Panel headers use `modIcon(moduleId)` for consistent identity inside modals

### Button / tab fixes
- `bindClickOnce()` prevents duplicate click handlers when switching Threat Intel, Investigation, and other tabs
- CVE lookup, attack surface, correlate, and timeline actions wire reliably on first open and after tab changes

### Module registry
- Each module’s `icon` field matches its module ID for admin and config consistency

**Install:** `releases/paxdesign-toolbar-8.4.0.zip` — tag `v8.4.0`

**After update:** Plugins → Updates, then hard-refresh the site (Ctrl+F5) or clear Hostinger/page cache.

## 8.3.2 — 2026-05-17

**Updater stabilization — single release ZIP, architecture hardening**

- Repository ships only the current release ZIP (older builds removed from `releases/`)
- Updater architecture refinements on top of 8.3.1

**Install:** `releases/paxdesign-toolbar-8.3.2.zip` — tag `v8.3.2`

## 8.3.1 — 2026-05-17

**Updater hotfix — PHP 8.1 null deprecations on Plugins screen**

- Sanitize `update_plugins` transient on read so `url`, `package`, and related fields are never `null` when WordPress calls `esc_url()` / path helpers
- Build complete update metadata object (`requires`, `tested`, `icons`, etc.) for GitHub releases
- Remove invalid cached update rows with empty package URLs
- Harden admin updates panel GitHub link when release URL is missing

**Install:** `releases/paxdesign-toolbar-8.3.1.zip` — tag `v8.3.1`

## 8.3.0 — 2026-05-17

**Visible intelligence UX — module identity, scan atmosphere, phishing surface**

- New `pdx-module-chrome.css` — per-module dock glow, themed panel headers, cyber grid atmosphere
- Dock buttons now use **module-specific SVGs** via `pdx-module-icons.js` (replaces generic PHP icons at runtime)
- Stronger `pdx-intel-activity` animations during TrustCheck, OSINT, threat correlation, and feed sync
- Trust/OSINT/Threat panels use themed headers, capability tags, and module accent colors
- Prominent **Phishing & URL intelligence** hero card on TrustCheck results (scores, redirects, credential forms, verdict)
- Asset cache busting uses `PDX_VERSION` + file modification time (stops stale JS/CSS after update)
- `svgIcon()` routes all legacy names (`shield`, `search`, `alert`, etc.) through module icon aliases

**Install:** `releases/paxdesign-toolbar-8.3.0.zip` — tag `v8.3.0`

**After update:** hard-refresh the site (Ctrl+F5) or clear Hostinger cache so new CSS/JS load.

## 8.2.1 — 2026-05-17

**Critical updater fix — wp-admin update not applying new version**

- `plugin_dir()`, backup, verify, and rollback now use the **live** install path (`PDX_DIR`), not a hardcoded `wp-content/plugins/paxdesign-toolbar/` folder
- `verify_install` reads the WordPress install **destination** (where files were written), fixing false version-mismatch rollbacks
- `plugin_basename()` prefers the canonical folder when present so `update_plugins` targets the correct directory
- Auto-migrates versioned folders (`paxdesign-toolbar-x.y.z`) to `paxdesign-toolbar/` before upgrade
- Recovery no longer restores an **older** backup over a newer partial install; skips restore while upgrade is in progress
- `is_upgrade_successful()` no longer reports success when the version did not change
- Updates panel shows install path and non-canonical folder warning

**Install:** `releases/paxdesign-toolbar-8.2.1.zip` — tag `v8.2.1`

**If 8.2.0 never applied:** update to **8.2.1** via FTP (upload ZIP) or wp-admin once on **8.2.0+** with this fix.

## 8.2.0 — 2026-05-17

**Intelligence engine & scan UX**

- New `PDX_Phishing_Heuristics` — path patterns, redirect intent, landing-page signals, brand impersonation, punycode/TLD risk, infrastructure fingerprinting
- URL analyzer and scan orchestrator use expanded HTML/behavioral phishing signals; intelligence risk scoring includes path, landing, redirect, and infrastructure factors
- Per-module SVG icons (`pdx-module-icons.js`) and animated intelligence activity UI during TrustCheck, OSINT, threat correlation, and feed sync pipelines
- Fixed invalid `motion.div` HTML typos in dock pipeline templates; `pdx-module-icons` script enqueue order corrected

**Install:** `releases/paxdesign-toolbar-8.2.0.zip` — tag `v8.2.0`

## 8.1.5 — 2026-05-17

**Critical hotfix — REST API parse error blocked activation**

- Fixed extra `]` in `register_rest_route()` for `/threat/cve`, `/threat/surface`, `/threat/feeds` (`class-pdx-rest-api.php` line 229)
- `scripts/lint-php.ps1` — `php -l` on every plugin PHP file (runs automatically in `build-release.ps1`)
- GitHub Actions PHP lint on push and before tagged releases

**Install:** `releases/paxdesign-toolbar-8.1.5.zip` — tag `v8.1.5`

## 8.1.4 — 2026-05-17

**Updater hotfix — PHP 8.1 null deprecations + maintenance after fatal**

- Update metadata (`url`, `package`, `tested`, changelog) never passes `null` into WordPress (fixes `strpos`/`str_replace` deprecations during plugin update)
- `PDX_Recovery` registers shutdown handler to remove `.maintenance` even when the updater fatals mid-request

**Install:** `releases/paxdesign-toolbar-8.1.4.zip` — tag `v8.1.4`

**Stuck on pre-8.1.3 updater:** FTP-replace `includes/class-pdx-updater.php` and `includes/class-pdx-recovery.php` from this release, delete `/.maintenance`, then update via wp-admin.

## 8.1.3 — 2026-05-17

**Critical updater fix — maintenance mode cleanup**

- Removed invalid `WP_Upgrader::release_maintenance_mode()` call (fatal on wp-admin plugin update)
- Maintenance cleanup now only deletes `ABSPATH/.maintenance` via `PDX_Recovery::release_maintenance_file()` / `wp_delete_file()`
- Shutdown and stale-maintenance hooks wrapped in try/catch so cleanup cannot fatal again

**Install:** `releases/paxdesign-toolbar-8.1.3.zip` — tag `v8.1.3`

**If you are still on 7.1.10:** update to **7.1.11** first (fixes the maintenance fatal in the running updater), then to **8.1.3**. Tag `v7.1.11`.

## 8.1.2 — 2026-05-17

**Production updater hotfix (wp-admin 7.x → 8.x)**

- Version-aware upgrade success detection (no longer requires installed >= GitHub `latest` when updating to an intermediate release)
- `includes/pdx-upgrade-manifest.php` — per-release required files + legacy file removal after update
- `PDX_Recovery` loads manifest dynamically (no false unhealthy state on 8.0.x vs 8.1.x file lists)
- Records `target` version during upgrade; validates package version in `verify_install`
- `scripts/simulate-wp-upgrade.ps1` and `scripts/verify-release-zip.ps1` for pre-release QA
- Build script runs ZIP validation automatically

**Install:** `releases/paxdesign-toolbar-8.1.2.zip` — tag `v8.1.2`

## 8.0.1 — 2026-05-17

**Production updater hotfix for 8.0.x line** (same updater fixes as 8.1.2, 8.0 feature set only)

Use this if you must stay on the 8.0 intelligence release before 8.1.x AI modules.

**Install:** `releases/paxdesign-toolbar-8.0.1.zip` — tag `v8.0.1`

## 8.1.0 — 2026-05-17

**Enterprise AI modules — Personas, Builder, Pipeline, Automation**

### AI Services / Personas
- New `PDX_AI_Service` — centralized OpenAI chat with memory injection
- `PDX_Conversation` — persistent threads (logged-in + guest session)
- `POST /ai/chat` accepts `thread_id`, `history`, `stream` (chunked client playback)
- `GET /ai/conversations`, `GET /ai/conversations/{id}`, `POST /ai/export`
- Personas dock: restores history, simulated streaming replies, server export

### AI Builder
- `PDX_Workflow_Engine` — LLM chains + transforms (uppercase, lowercase, trim, json_pretty, extract_links)
- `PDX_Flow_Store` — save/load builder flows (`GET/POST /builder/flows`)
- Job progress updates during multi-step runs; tokens + duration in results

### Agent Pipeline
- Multi-agent handoffs with trace, `handoffs`, `tokens_used`, `duration_ms`
- Save/load pipelines via `GET/POST /pipeline/flows`
- Security analyst role added to agent roster

### Browser Automation
- `PDX_Browser_Automation` — safe URL policy, server-side HTML sandbox fetch, page extraction report
- AI execution plan JSON + structured extraction report
- Worker dispatch attempted when available; completes with sandbox results when not

### Platform
- Removed dead `live_config()` REST handler
- `dock-v81.js` — template Use buttons, saved flow loader, streaming helper
- Lighter UI: reduced backdrop blur and pulse glow; mobile panel/layout pass

**Install:** `releases/paxdesign-toolbar-8.1.0.zip` — tag `v8.1.0`

## 8.0.0 — 2026-05-17

**Enterprise intelligence engine (v8)**

### TrustCheck & OSINT
- New `PDX_Scan_Orchestrator` — unified deep scan pass for TrustCheck and OSINT REST endpoints
- New `PDX_Url_Analyzer` — redirect chain inspection (up to 8 hops), HTML/JS/form signals, phishing heuristics
- Forensic block on reports: redirect hops, phishing score/verdict/reasons, ASN/registrar/email-auth correlation
- Risk scoring re-computed with forensic factors; IOC ingestion into `PDX_Correlation`
- Extended forensic timeline events on scan completion

### Threat Intel
- New `GET /pdx/v1/threat/feeds` — live OTX/URLhaus probe aggregation via `PDX_Threat_Feeds`
- Threat Intel **Feeds** tab syncs real server-side feed status (replaces static placeholder list)

### Frontend
- TrustCheck: fast scan mode (no heavy staged animation), URL forensics results panel
- Pipeline animation speed reduced (`PDX_PIPELINE_SPEED` 0.28)
- Live threat feed list rendering from API

### Platform
- Recovery health check includes v8 required PHP classes
- Module registry: TrustCheck capabilities updated; investigation, graph, memory, team modules registered

**Install:** `releases/paxdesign-toolbar-8.0.0.zip` — tag `v8.0.0`

## 7.1.10 — 2026-05-17

**Critical: fix site fatal after failed update + production updater safety**

### Recovery
- New `PDX_Recovery` layer loads before full bootstrap — releases `.maintenance`, restores from updater backup if files are missing
- Skips broken bootstrap (no fatal) when required PHP files are missing; shows admin notice instead
- `plugins_loaded` bootstrap wrapped in try/catch with automatic rollback

### Updater
- Removed dangerous `bootstrap_recovery()` auto-success finalization on every page load (caused corrupt state / repeated destructive cleanup)
- Deferred cleanup runs once, clears state first, health-checks before deleting folders, rolls back on failure
- Keeps backup until deferred cleanup succeeds
- `plugin_passes_health_check()` validates required files before any destructive operation

### Build
- Release ZIP now compresses staging root so `paxdesign-toolbar/` folder is always at archive root

**Install:** `releases/paxdesign-toolbar-7.1.10.zip` — tag `v7.1.10`

## 7.1.9 — 2026-05-17

**Hotfix: JavaScript target normalization stack overflow**

### Frontend
- Fixed infinite recursion between `normalizePdxTarget()` and `detectTargetType()` (RangeError on all scans)
- Split strip → normalize → classify: `stripPdxIndicator`, `normalizePdxTarget`, `detectTargetTypeFromString`
- Recursion depth guard and safe fallback on errors
- Exposed `window.PDXTargetUtil` for reuse across dock modules
- Timeline and Automation use the same global normalization path

**Install:** `releases/paxdesign-toolbar-7.1.9.zip` — tag `v7.1.9`

## 7.1.8 — 2026-05-17

**Production-grade updater finalization** — seamless updates on Hostinger without manual cleanup.

### Updater
- Single `finalize_upgrade_transaction()` exit point: maintenance release, artifact cleanup, rollback
- Explicit `temp_backup => false` (disables WP 6.3 move_dir to `upgrade-temp-backup` that fails on shared hosting)
- Pre-cleans `upgrade-temp-backup` and `wp-content/upgrade/paxdesign-toolbar*` before install
- Deferred duplicate-folder consolidation until `shutdown` (avoids file-lock races during `post_install`)
- Shutdown guard: always releases `.maintenance` if upgrade aborts mid-request
- Reconciles false `WP_Error` when plugin files updated successfully (cleanup-only failures)
- Clears stale `.maintenance` immediately when no upgrade is in progress

**Install:** `releases/paxdesign-toolbar-7.1.8.zip` — tag `v7.1.8`

## 7.1.7 — 2026-05-17

**Global target normalization** — fixes intelligence failures when URLs include query strings (e.g. `domain.com?utm_source=…`).

### Backend
- `PDX_Target` — strips protocol, query, fragment, and path; validates hostname/IP/email/hash before any API call
- `PDX_Http` — instrumented outbound requests with per-source debug log (`http_code`, `duration_ms`, `parse_status`, errors)
- TrustCheck, OSINT, Threat Surface, Investigation correlate/timeline, and Automation use normalization on all REST entry points
- `source_status` enriched with `request_url`, HTTP code, timeout, and real failure messages (no silent placeholders)

### Frontend
- `normalizePdxTarget()` used by TrustCheck, OSINT, Threat Intel surface, Investigation, Infrastructure Graph, Automation
- UI shows normalized vs raw target when input contained query parameters
- Intelligence Sources panel shows detailed error notes from the backend

**Install:** `releases/paxdesign-toolbar-7.1.7.zip` — tag `v7.1.7`

## 7.1.6 — 2026-05-17

**TrustCheck intelligence pipeline** — real data, consistent scoring, explicit failures.

### TrustCheck / Intelligence
- DNS (Google DoH), RDAP with parent-domain fallback, SSL Labs polling, OTX + URLhaus threat feeds
- Risk score aligned with verdict; `insufficient_data` when sources fail (no fake “critical” + 0 score)
- Server-side summary and recommendations from `build_narrative()`
- `source_status` per source; UI shows errors instead of misleading “Clean”
- Geolocation resolves domain → IP before lookup

**Install:** `releases/paxdesign-toolbar-7.1.6.zip` — tag `v7.1.6`

## 7.1.5 — 2026-05-17

**Duplicate plugin folder fix** — only one `paxdesign-toolbar` install in Plugins.

### Updater
- Automatic cleanup of stale `paxdesign-toolbar-*` folders (and nested copies)
- Runs on load, Plugins screen, after updates, and after plugin delete
- Merges mis-installed versioned folders into `wp-content/plugins/paxdesign-toolbar`
- Repairs `active_plugins` so only `paxdesign-toolbar/paxdesign-toolbar.php` stays active
- Clears duplicate entries from `upgrade-temp-backup`
- Safer ZIP normalization in upgrade working dir (copy fallback if move fails)

**Install:** `releases/paxdesign-toolbar-7.1.5.zip` — tag `v7.1.5`

## 7.1.4 — 2026-05-17

**Hostinger / shared-hosting update fix** — no longer depends on WordPress moving the plugin into `upgrade-temp-backup`.

### Updater
- Root cause: `upgrader_source_selection` was deleting the live plugin before WordPress could back it up
- Skip core `temp_backup` move (fails on Hostinger) via `upgrader_package_options`; use copy-based backup instead
- Fallback backup paths: `wp-content/upgrade`, `uploads/pdx-upgrades`, system temp
- Native PHP copy fallback when `copy_dir()` / Filesystem API is limited
- Update continues with a clear notice if backup cannot be created (no hard fail)
- Cleans stray `paxdesign-toolbar-*` folders; always releases `.maintenance` on failure

**Install:** `releases/paxdesign-toolbar-7.1.4.zip` — tag `v7.1.4`

## 7.1.3 — 2026-05-17

**Production-grade GitHub updater** — maintenance recovery, safer installs, and transparent update checks.

### Updater
- Stale `.maintenance` cleanup on `admin_init`, `shutdown`, and after upgrades
- Manual maintenance clear from admin; backup before install and rollback on failure
- GitHub zipball folder rename via `upgrader_source_selection`
- Post-install verification (`paxdesign-toolbar.php` + version header)
- Clears `pdx_github_release` and `update_plugins` transients after updates
- Longer GitHub API/download timeouts (45s)

### Admin
- **Updates** panel on General: installed vs latest, status badge, last checked
- **Check for Updates** button (force GitHub refresh + cache clear)
- Plugin row link to updates panel

**Install:** `releases/paxdesign-toolbar-7.1.3.zip` — tag `v7.1.3`

## 7.1.2 — 2026-05-17

**Admin-wide compact layout pass** — readability, sidebar density, and control column fixes.

### Admin (all pages)
- Brighter typography tokens and hint text for readable contrast on dark backgrounds
- Compact sidebar (188px): tighter nav padding, 14px icons, 12px labels
- Settings rows use flex layout with fixed control column — toggles no longer clip or overflow
- Sections use `overflow: visible`; module rows align controls on the right
- Reduced vertical spacing; full-width content area (no narrow max-width trap)
- WP form-table and notice text inherit readable colors

**Install:** `releases/paxdesign-toolbar-7.1.2.zip` — tag `v7.1.2`

## 7.1.1 — 2026-05-17

**Admin & frontend UX refinement** — enterprise settings layout on the v7.1 production baseline.

### Admin
- 8px spacing rhythm and flatter section surfaces (less dashboard card chrome)
- Settings rows: label + description left, toggle right with proper alignment
- Reusable `settings-toggle` partial; module list as structured rows
- Stats use consistent `pdx-stat-card` grid; restored mobile sidebar backdrop + Escape close

### Frontend dock
- Responsive panel width tokens (`clamp` on desktop, `dvh` on mobile)
- Premium scroll: smooth behavior, stable gutter, styled scrollbar, safe-area padding
- Flatter result surfaces inside analysis panels

**Install:** `releases/paxdesign-toolbar-7.1.1.zip` — tag `v7.1.1`

## 7.1.0 ÔÇö 2026-05-16

**Production foundation** ÔÇö scroll, state, motion, updates.

### Panel UX
- Single-scroll architecture for analysis panels (desktop/tablet/mobile)
- Smooth panel open/close fade + slide; reduced-motion respected
- Touch: swipe-to-close only from header/drag handle (no content scroll hijack)
- Session restore for Trust/OSINT/Investigation results via `sessionStorage` per user

### Visual
- GitHub-style minimal palette: yellow accent only on primary actions and key indicators
- Calmer metrics, badges, and chips (no rainbow AI look)

### Admin
- Compact sidebar links, `pdx-toggle--sm` for module cards, aligned enable toggles

### Updates
- GitHub release updater ÔÇö one-click **Update** in WordPress Plugins screen
- CI workflow publishes ZIP on `v*` tags

**Install:** `releases/paxdesign-toolbar-7.1.0.zip` ÔÇö tag `v7.1.0`

## 7.0.0 ÔÇö 2026-05-16

**Production foundation** ÔÇö faster interactions, unified intelligence UX, cleanup.

### Interaction & performance
- `runIntelPipeline()` ÔÇö parallel API + staged pipeline with instant button feedback
- Faster pipeline timing (~32% shorter stages), 50ms timer ticks, rAF stage start
- `pdx-btn--busy` / `pdx-btn--pressed` micro-interactions on all primary actions
- Double-click guards on scan/run/correlate/build/pipeline/graph actions

### Intelligence UX (all major actions)
- Deep pipeline: Trust, OSINT, Threat (CVE/surface), Builder, Pipeline, Automation, Connectors, Investigation (correlate/timeline), Graph
- Threat Feeds: live sync pipeline + completion banner
- Job History tab wired to queue API

### Cleanup
- Removed dead `animateScanStages()` and unused `.pdx-scanning` CSS
- Removed duplicate WP admin flyout navigation (6.0.1)

**Install:** `releases/paxdesign-toolbar-7.0.0.zip` ÔÇö tag `v7.0.0`

## 6.0.1 ÔÇö 2026-05-16

**UI/UX fixes** ÔÇö overlay cleanup, admin scaling, single navigation layer.

### Admin
- Restore module/radio/role/chart component styles (SVG sizing, overflow guards)
- Hide duplicate WordPress flyout submenu; in-app sidebar is the only nav
- Mobile sidebar backdrop + Escape to close; body scroll lock while open
- Global SVG max dimensions; module icons constrained to 20px

### Frontend dock
- Closed panel/backdrop/command overlay no longer capture clicks (`pointer-events` + `visibility`)
- `#pdx-root` scoped so only the dock rail receives pointer events

**Install:** `releases/paxdesign-toolbar-6.0.1.zip`

## 6.0.0 ÔÇö 2026-05-17

**Major UI/UX redesign** ÔÇö GitHub-inspired design system, yellow brand accent (`#c2ff00`).

### Design system
- New `pdx-tokens.css` (dark/light, shared tokens)
- New `pdx-dock-ui.css` (dock + panel + pipeline polish layer)
- Rebuilt `admin.css` with sidebar navigation + top bar
- Default accent color: `#c2ff00`

### Admin
- Sidebar navigation grouped by Core / Commerce / Platform / Compliance
- Mobile collapsible sidebar, light/dark theme toggle
- Consistent cards, tables, forms, stats

### Frontend dock
- Visible panel close (X) on all viewports
- Refined dock rail, modals, command palette, notifications
- Live pipeline running state (`pdx-dp--running`) + log highlights

**Install:** `releases/paxdesign-toolbar-6.0.0.zip` ÔÇö tag `v6.0.0`

## 5.0.1 ÔÇö 2026-05-17

**Install:** use `releases/paxdesign-toolbar-5.0.1.zip` or tag `v5.0.1`.

### Frontend
- Fix `apiFetch` to surface 402 payment-required responses
- Mobile modal: scroll lock, focus restore, close button fix
- Wire live activity feed (SSE `activity.update` batches)
- Queue badge on dock (initial `/queue/stats` + SSE updates)
- Worker nodes panel in Workspace module
- Pause SSE when browser tab is hidden
- Remove dead `animateScanStages` helper

### Admin
- Unified v5 shell on legacy admin pages (billing, platform, workers, teams, webhooks, audit, dev-tokens, cache)
- Fix billing form save action and Stripe `sanitize_tab`
- Fix platform audit chart field names (`total` / datetime `hour`)
- Accent color and mobile breakpoint passed to dock CSS variables

### Cache
- Bump `PDX_VERSION` to **5.0.1** so WordPress enqueues `dock.js?ver=5.0.1` (required after update)

## 5.0.0

Initial v5 dock rebuild, state sync, and UI stabilization.

