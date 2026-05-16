# Changelog

## 7.0.0 — 2026-05-16

**Production foundation** — faster interactions, unified intelligence UX, cleanup.

### Interaction & performance
- `runIntelPipeline()` — parallel API + staged pipeline with instant button feedback
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

**Install:** `releases/paxdesign-toolbar-7.0.0.zip` — tag `v7.0.0`

## 6.0.1 — 2026-05-16

**UI/UX fixes** — overlay cleanup, admin scaling, single navigation layer.

### Admin
- Restore module/radio/role/chart component styles (SVG sizing, overflow guards)
- Hide duplicate WordPress flyout submenu; in-app sidebar is the only nav
- Mobile sidebar backdrop + Escape to close; body scroll lock while open
- Global SVG max dimensions; module icons constrained to 20px

### Frontend dock
- Closed panel/backdrop/command overlay no longer capture clicks (`pointer-events` + `visibility`)
- `#pdx-root` scoped so only the dock rail receives pointer events

**Install:** `releases/paxdesign-toolbar-6.0.1.zip`

## 6.0.0 — 2026-05-17

**Major UI/UX redesign** — GitHub-inspired design system, yellow brand accent (`#c2ff00`).

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

**Install:** `releases/paxdesign-toolbar-6.0.0.zip` — tag `v6.0.0`

## 5.0.1 — 2026-05-17

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
