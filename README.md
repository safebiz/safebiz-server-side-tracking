# SafeBiz Server-Side Tracking

WordPress plugin pentru trimitere server-side a evenimentelor de e-commerce (`purchase`, `refund`) la Google Analytics 4 prin Measurement Protocol, independent de browser.

Bypass iOS Safari ITP / AdBlockers / payment gateway redirects / consent state pierdut client-side. Consent-aware (GDPR EDPB 02/2023 compliant), cu retry automat și logging local în order meta.

## De ce server-side

Tracking-ul client-side (GA4 via GTM Web) pierde 30-67% din conversii din motive:

- **Apple iTP** — limitează cookies first-party la 7 zile pe Safari iOS/macOS
- **AdBlockers** — uBlock, AdGuard, Brave Shield blochează GTM/GA4 scripts
- **Payment redirect** — Netopia, Stripe Checkout etc. rup sesiunea GA4 între checkout și thank-you
- **Network failures** — beacon-ul GA4 nu ajunge dacă userul închide tab înainte de send

Server-side via Measurement Protocol trimite eventul direct WP → GA4 (server la server), garantat, fără dependență de browser.

## Features

- ✅ Hook pe `woocommerce_order_status_processing` + `_completed` (capture COD + card payments)
- ✅ Filtru whitelist strict (exclude trash/cancelled/refunded contamination)
- ✅ Dedup via order meta `_safebiz_ga4_mp_status` (queued/sent/error/skipped_no_consent)
- ✅ Refund event automat la cancel/refund
- ✅ Cron hourly retry pentru status `error`
- ✅ Consent-aware: integrare cu Moove GDPR, SureCookie, Cookiebot, GDPR Cookie Compliance (Webtoffee)
- ✅ Strict opt-in mode pentru Art. 9 GDPR (adult products, health, etc.)
- ✅ Data minimization toggle (`SAFEBIZ_GA4_SEND_ITEMS=false` exclude items array)
- ✅ Endpoint regional EU (`region1.google-analytics.com`)
- ✅ Auto-update via GitHub Releases (WP native update mechanism)

## Cerințe

- WordPress ≥ 6.0
- PHP ≥ 7.4
- WooCommerce activ
- Cont GA4 cu Measurement Protocol API secret generat

## Instalare

### Metoda 1: ZIP download

1. Descarcă latest release: <https://github.com/safebiz/safebiz-server-side-tracking/releases>
2. WP Admin → Plugins → Add New → Upload Plugin → zip → Install Now → Activate

### Metoda 2: Manual SFTP

1. Clone repo: `git clone https://github.com/safebiz/safebiz-server-side-tracking.git`
2. Upload folderul `safebiz-server-side-tracking/` la `wp-content/plugins/`
3. WP Admin → Plugins → Activate

### Metoda 3: Auto-update (după v1.0.2)

Odată instalat, pluginul verifică GitHub Releases automat. Update-uri viitoare apar în WP Admin → Plugins → Update Available → Click Update.

## Configurare

Adaugă în `wp-config.php` (înainte de `/* That's all, stop editing! */`):

```php
// SafeBiz Server-Side Tracking
define('SAFEBIZ_GA4_MEASUREMENT_ID', 'G-XXXXXXXXXX');         // GA4 stream ID
define('SAFEBIZ_GA4_API_SECRET', 'xxxxxxxxxxxxxxxxxxx');      // Generat din GA4 Admin → Data Streams → MP API secrets
define('SAFEBIZ_GA4_SEND_ITEMS', true);                       // false pentru Art. 9 (no item_name leak)
define('SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT', false);        // true = strict opt-in (no fallback default)
// define('SAFEBIZ_GITHUB_TOKEN', '...');                     // opțional pentru private repo updates
```

### Generare GA4 API Secret

1. GA4 → Admin (jos stânga) → Data Streams
2. Click pe Web stream → Measurement Protocol API secrets → Create
3. Nickname: `SafeBiz Server-Side Tracking - Production`
4. Copy secret-ul (afișat o singură dată)

## Cum funcționează

### Flow `purchase`

```
Customer plasează order
    ↓
WC status: pending → processing (sau direct completed)
    ↓
Hook woocommerce_order_status_processing fires
    ↓
Plugin verifică:
    - status în whitelist [processing, completed]?
    - _safebiz_ga4_mp_status != sent/queued/skipped?
    - consent state (dacă REQUIRE_EXPLICIT_CONSENT=true)?
    ↓
Marchează _safebiz_ga4_mp_status = queued
    ↓
Build MP payload + POST la region1.google-analytics.com/mp/collect
    ↓
GA4 răspunde 204 No Content → marchează status = sent
                    sau eroare → status = error (cron retry hourly)
```

### Flow `refund`

```
Admin schimbă status order → refunded / cancelled
    ↓
Hook fires DOAR dacă _safebiz_ga4_mp_status == sent (no orphan refunds)
    ↓
POST event "refund" cu același transaction_id + value
    ↓
204 OK → _safebiz_ga4_refund_sent = 1
```

### Consent mapping

Plugin detectează automat consent state din cookies HTTP setate de CMP comune:

| CMP | Cookie | Strategie mapping → MP `consent` |
|---|---|---|
| **Moove GDPR Cookie Compliance** | `moove_gdpr_popup` (JSON) | `thirdparty=1` → `ad_user_data=GRANTED` + `ad_personalization=GRANTED` |
| **SureCookie** | `surecookie_consent` (JSON) — *NOTE: localStorage default, cookie e fallback* | `marketing=true` → `ad_user_data=GRANTED` |
| **Cookiebot** | `CookieConsent` (string) | `marketing:true` → `GRANTED` |
| **GDPR Cookie Consent (Webtoffee)** | `cookielawinfo-checkbox-*` | individual category cookies |
| **Generic** | `cookie_consent=accepted` | fallback minim |

Default fără snapshot: `ad_user_data=DENIED`, `ad_personalization=DENIED` (legal safe, EDPB-aligned).

## Verificare post-install

1. **WP Admin → Plugins** → SafeBiz Server-Side Tracking = Active
2. **NU apare admin notice roșu** (înseamnă constants OK)
3. Plasează test order → verifică WP Admin → Orders → Custom Fields:
    - `_safebiz_ga4_mp_status = sent`
    - `_safebiz_ga4_mp_sent_at = <timestamp>`
4. GA4 Realtime → Conversions → eveniment `purchase` cu transaction_id corect
5. Cancel test order → verifică `_safebiz_ga4_refund_sent = 1`

## Cunoscut + nu (FAQ)

**Q: De ce default consent e DENIED, nu GRANTED?**
A: Conservator legal sub EDPB Guidelines 02/2023. Server-side NU bypass-ează consent. Dacă userul nu a acceptat explicit, trimitem analytics minim cu ad flags DENIED → GA4 nu folosește datele pentru advertising. Override cu `ad_user_data=GRANTED` în snapshot doar la consent explicit.

**Q: De ce regional endpoint?**
A: `region1.google-analytics.com` e EU-aligned pentru data minimization (deși nu rezolvă singur transferul SUA — DPF + SCC tot necesare).

**Q: De ce nu folosesc PUC (Plugin Update Checker) library?**
A: Custom updater ~150 linii, no external dependencies, transparent, easy to audit. PUC e excelentă dar peste-feature-uită pentru cazul nostru.

**Q: Cum gestionez duplicate dacă am deja tracking client-side?**
A: Vezi `docs/DUPLICATE-PREVENTION.md` (în repo) — există 3 strategii: pause tag GTM Purchase (curat), block trigger conditional (păstrează funnel), accept duplicate temporar (Sprint).

## License

GPL-2.0-or-later. Vezi [LICENSE](./LICENSE).

## Credits

Dezvoltat de [SafeBiz Solutions](https://safebiz.ro). Issues + PRs welcome.

Documentation completă: [github.com/safebiz/safebiz-server-side-tracking](https://github.com/safebiz/safebiz-server-side-tracking)
