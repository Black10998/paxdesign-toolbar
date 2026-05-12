# PaxDesign Utility Dock

Enterprise-grade WordPress plugin providing a modular SaaS utility dock with AI services, cybersecurity intelligence tools, and a full admin control panel.

## Features

- **Modular dock** — enable/disable individual tools per deployment
- **Trust Check** — domain reputation scanner (RDAP, SSL Labs, Google Safe Browsing)
- **AI Services** — AI Personas, Builder, Agent Pipeline panels
- **Security Intelligence** — OSINT Agents, Trust Check
- **Development Services** — Create, Connectors, Browser Automation
- **Full admin panel** — 7 settings pages covering all configuration
- **Role-based visibility** — show/hide per WordPress role
- **Privacy controls** — GDPR mode, analytics, data retention
- **API key management** — OpenAI, VirusTotal, Shodan, Hunter.io
- **UI customisation** — position, theme (dark/light/auto), size, accent color, custom CSS
- **Mobile responsive** — bottom sheet on small screens
- **REST API** — internal endpoints for trust checks and analytics

## Installation

1. Upload the `paxdesign-toolbar` folder to `/wp-content/plugins/`
2. Activate via **Plugins → Installed Plugins**
3. Configure via **PaxDesign** in the WordPress admin menu

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Structure

```
paxdesign-toolbar/
├── paxdesign-toolbar.php
├── uninstall.php
├── assets/css/  (dock.css, admin.css)
├── assets/js/   (dock.js, admin.js)
├── includes/    (loader, settings, frontend, admin, api, modules)
└── templates/admin/  (7 settings pages + partials)
```

## Admin Pages

| Page | Purpose |
|------|---------|
| General | Enable/disable plugin, contact URL, CTA labels |
| Modules | Toggle individual dock modules on/off |
| API Keys | OpenAI, VirusTotal, Shodan, Hunter.io credentials |
| UI & Style | Position, theme, size, accent color, custom CSS, mobile |
| Privacy | Analytics, interaction logging, GDPR mode, retention |
| Roles | Visibility by WordPress role, logged-in/out controls |
| Analytics | Event log viewer with module open stats |

## REST API

Base: `/wp-json/pdx/v1/`

| Endpoint | Method | Auth | Description |
|----------|--------|------|-------------|
| `/trust?domain=` | GET | Public | Domain trust check proxy |
| `/event` | POST | Public | Log interaction event |
| `/settings` | GET | Admin | Read all settings |
| `/settings` | POST | Admin | Update settings |

## License

GPL-2.0-or-later
