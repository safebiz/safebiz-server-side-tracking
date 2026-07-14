# Changelog

Toate modificările notabile la `safebiz-server-side-tracking` sunt documentate aici. Format conform [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versionare conform [SemVer](https://semver.org/lang/ro/).

## [1.3.0] - 2026-07-14

### Fixed

- **GA4 purchase over-count (~28× pe MPSS: ~560 events/30z vs ~10-20 comenzi reale).** Cauza: cronul orar `safebiz_ga4_retry_errors` ștergea singura stare de dedup (`_safebiz_ga4_mp_status → ''`) și re-trimitea același `purchase` la nesfârșit, backdatat la data comenzii (amprentă: „73 identic pe 5 zile"). Succesul era acceptat doar pe HTTP 204 exact, deci orice timeout/răspuns lent marca `error` un eveniment deja înregistrat de GA4 → buclă infinită.

### Changed — model „exactly once" (idempotență durabilă, reset-proof)

- **Flag durabil de LIVRARE** `_safebiz_ga4_purchase_sent = '1'` (+ `_safebiz_meta_capi_purchase_sent`), setat **DOAR pe răspuns 2xx** (resp. Meta 200 + `events_received`). Replică pattern-ul corect deja existent la refund (`_safebiz_ga4_refund_sent`). Reconcilierea externă citește **acest flag** ca sursă de adevăr „livrat", nu status-ul.
- **Separare strictă „livrat" vs „terminal".** După ORICE POST, starea devine terminală → zero re-POST → zero duplicate. Vocabular nou de status:
  - `sent` (2xx, livrat) · `error_ambiguous` (`is_wp_error` după POST — poate a ajuns, NU se retrimite) · `error_http` (non-2xx — per Google „corectează cererea, nu retrimite") · `error_stale` (>72h) · `error_enqueue` (Action Scheduler a eșuat — **zero POST**, retry sigur).
- **Retry-ul orar reia DOAR stări fără POST** (`error_enqueue` + `queued` blocat >2h). Nu mai atinge `error_ambiguous/error_http/error_stale`. **Status-wipe-ul a fost eliminat.**
- **Cutoff 72h**: GA4 respinge `timestamp_micros` backdatat >72h → comenzile vechi se marchează `error_stale` terminal (nu se mai trimit inutil).
- **Gardă în 3 locuri** (enqueue, top-of-send, foreach-ul cronului) via helper `safebiz_{ga4,meta}_purchase_is_terminal()` — `meta_query` rămâne optimizare, nu bariera unică.
- **Migrare one-time la upgrade** (`safebiz_sst_db_version`): oprește bucla pentru comenzile existente. Backfill `purchase_sent='1'` **doar pe dovadă pozitivă** (`status=sent` sau `_sent_at` existent); `error` cu „enqueue failed" → `error_enqueue`; restul `error`/`''` → `error_ambiguous` terminal (NU marcat fals „livrat", ca reconcilierea să rămână onestă).

### Notes

- Corecții integrate din contra-verificarea Codex (2026-07-14): flag DOAR pe 2xx (nu pe eșec permanent); migrare restrânsă (nu marca `error` generic ca livrat); fără auto-retry după POST (incompatibil cu „exactly once"); cutoff 72h; gardă în cod, nu doar `meta_query`.
- Fără schimbări la consent gating, `session_id` join, construcția payload-ului sau refund. Guard-urile WooCommerce (`function_exists`) rămân intacte → salon-nunta.ro (fără WC) nu are fatal.
- Datele GA4 istorice inflatate NU pot fi șterse (MP nu are purge) — raportează venit din WooCommerce pentru fereastra afectată.

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
