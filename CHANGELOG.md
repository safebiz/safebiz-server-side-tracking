# Changelog

Toate modificările notabile la `safebiz-server-side-tracking` sunt documentate aici. Format conform [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versionare conform [SemVer](https://semver.org/lang/ro/).

## [1.0.2] - 2026-05-12

### Added

- **Auto-update mechanism via GitHub Releases.** Plugin verifică zilnic noua versiune; update-uri apar în WP Admin → Plugins → Update Available, click → WP descarcă zip-ul release-ului automat.
- Plugin metadata: `Plugin URI`, `Update URI`, `License`, `Author URI`, `Text Domain` (conform standardelor WP.org).
- Suport opțional `SAFEBIZ_GITHUB_TOKEN` constant pentru private repo updates.
- Class `SafeBiz_GitHub_Updater` (~200 linii) în `includes/`, no external dependencies. Hook-uri:
  - `pre_set_site_transient_update_plugins` → injectează update info
  - `plugins_api` → modal "View details"
  - `upgrader_source_selection` → fix folder rename după unzip GitHub
  - `upgrader_process_complete` → clear cache after update

### Notes

- Versiune 1.0.2 nu schimbă comportamentul tracking-ului vs 1.0.1. Singura diferență e mecanismul auto-update.
- Repository: <https://github.com/safebiz/safebiz-server-side-tracking>

## [1.0.1] - 2026-05-12

Post contra-verificare Codex review (vezi `SPRINT1-STATUS-REPORT-2026-05-12.md §10`).

### Changed

- **Default consent: `GRANTED/DENIED` → `DENIED/DENIED`** când lipsește snapshot consent state.
  - **Why:** EDPB Guidelines 02/2023 — server-side NU bypass-ează consent. Fără accept explicit marketing, `ad_user_data=GRANTED` era prea agresiv legal.
  - **Impact:** Analytics revenue tracking continue să funcționeze (event ajunge la GA4), DAR GA4 nu folosește datele pentru advertising / remarketing. Override doar la consent snapshot explicit care arată `ad_user_data=GRANTED`.
- **Moove GDPR mapping strict:** `ad_personalization` acum mapează DOAR pe `thirdparty=1`, NU pe `advanced=1`.
  - **Why:** Pentru Art. 9 (adult products etc.), `advanced` poate însemna analytics/functional, nu justifică ad personalization automat. Doar acceptare marketing explicit activează ad flags.
- **Endpoint:** `www.google-analytics.com/mp/collect` → `region1.google-analytics.com/mp/collect`.
  - **Why:** EU-aligned per Google docs. Nu rezolvă singur GDPR transfer SUA, dar best-practice pentru stack EU-first.

### Notes

- Backward compatible cu v1.0.0 — constants, order meta, hook signatures = neschimbate.

## [1.0.0] - 2026-05-12

Lansare inițială. Sprint 1 deploy MonitorStup (monitorstup.ro) — validare empirică reușită pe 2 test orderi reali (transaction_id 200 și 201, value 125 RON), purchase + refund hooks confirmate via GA4 Realtime, zero duplicate.

### Added

- Hook server-side pe `woocommerce_order_status_processing` + `_completed`
- Filtru status whitelist strict `[processing, completed]` (anti-trash contamination)
- Status tracking în order meta: `queued / sent / error / skipped_no_consent`
- Dedup obligatoriu (status check înainte de send)
- Capture `_ga` client_id + consent snapshot la `woocommerce_checkout_create_order`
- Detection consent state pentru 5 CMP-uri comune
- Hook refund event pe `_refunded` + `_cancelled`
- Cron hourly retry pentru status `error`
- Admin notice dacă constants lipsesc (fail-safe)
- Plugin sanity check + deactivation hook (clear scheduled events)

### Reference

- Plan implementare: `plans/Server-Side GTM/PLAN-IMPLEMENTARE-2026-05-12.md`
- Analiză tehnică: `plans/Server-Side GTM/ANALIZA-PROBLEMA-TRACKING-2026-05-12.md` (§22.3 = cod autoritativ)
- Council decizie arhitectură: `plans/Server-Side GTM/COUNCIL-TRACKING-ARHITECTURA-2026-05-12.md`
