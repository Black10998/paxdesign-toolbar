# Changelog

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

