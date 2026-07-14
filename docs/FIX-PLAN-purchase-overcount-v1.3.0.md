# Fix Plan — GA4 purchase over-count in `safebiz-server-side-tracking`

## Context

On MPSS (myprivatesexshop.ro) GA4 reports **~560 `purchase` events in 30 days** while WooCommerce has only **~10-20 real orders** — a ~28× over-count that inflates GA4 revenue ~10-20× and makes GA4 revenue/conversion reporting unusable (verified 2026-07-14 via `ga4-query.js` + `wc/v3/orders`). The `refund` event is accurate (3 vs 2) and the client-side/GTM paths were ruled out (browser ecommerce tag paused; theme `gtm_purchase_event` never fires). The defect is entirely in the **server-side Measurement Protocol path** of this plugin.

**Fingerprint:** GA4 purchase-by-date shows the identical number (73) on five unrelated dates (Jul 1/5/6/8/9) — the signature of a cron re-sending the same orders, backdated to each order's date.

**Goal:** make each order's `purchase` reach GA4 **exactly once**, permanently, without breaking the consent gate, session-join attribution, refund tracking, or the WooCommerce-less site (salon-nunta.ro). Bring GA4 purchase counts back in line with real WooCommerce orders going forward.

## Root cause (precise)

File: `safebiz-server-side-tracking.php` (v1.2.0, 643 lines).

1. **Success is accepted only on exact HTTP 204** (`safebiz_send_ga4_purchase`, line 344). GA4 MP `/mp/collect` *records the event and returns 204 for almost anything*; but a slow/dropped response under `blocking=true, timeout=10` (line 338) returns `is_wp_error` → the order is marked `error` (line 342) even though GA4 already counted the event.
2. **The hourly retry cron wipes the only dedup state, then re-sends, with no cap** (`safebiz_ga4_retry_errors`, lines 592-613): it selects `error`/stuck-`queued` orders, `update_meta_data('_safebiz_ga4_mp_status', '')` (line 609), then calls `safebiz_send_ga4_purchase()` (line 611). The send's only guard is `status === 'sent'` (line 308) — just wiped — so every still-`error` order is **re-sent every hour forever**. Because `timestamp_micros` = order `date_created` (line 329), each resend backdates to the order date → the "same 73 on 5 dates" pattern.
3. **No reset-surviving idempotency flag.** The purchase path relies solely on `_safebiz_ga4_mp_status`, the exact meta the cron wipes. `_safebiz_ga4_mp_sent_at` (line 346) is written but never read as a guard.
4. **Enqueue guard omits `error`/`''`** (line 270 checks only `queued|sent|skipped_no_consent`), so a `processing`→`completed` transition also re-sends an errored order.
5. **Meta CAPI path mirrors the bug** (cron lines 615-636, wipe line 632) but is *masked* by Meta's server-side `event_id` dedup (line 428) + 7-day staleness skip (line 416) — protections GA4 lacks.

**The correct pattern already exists in this file:** the refund path (lines 542-570) is accurate precisely because it uses a **dedicated persistent flag** `_safebiz_ga4_refund_sent === '1'` (line 547, set only on confirmed 204 at line 566) that **the crons never touch**. The fix replicates this pattern for purchase.

## Source of truth & where to patch

- **Edit here (git clone = repo working copy):** `C:\Users\takac\OneDrive\SafeHost\Vs Code\Master C\plugins\safebiz-server-side-tracking\` — remote `github.com/safebiz/safebiz-server-side-tracking`, HEAD tagged `v1.2.0`, clean tree.
- **Do NOT edit** the stale `wp_files/` mirrors (`projects/mpss/...` = 1.0.3, `projects/monitorstup/...` = 1.1.0) — they never reach clients.
- **Deploy model:** edit clone → bump `Version:` → commit/push `main` → tag `vX.Y.Z` + GitHub Release (attach built `.zip` asset) → the in-plugin `SafeBiz_GitHub_Updater` shows "update available" → **manual** `wp plugin update` per site (auto-update is OFF fleet-wide).
- **Blast radius — 4 sites on v1.2.0:** monitorstup, lcpackagingshop, salon-nunta (**no WooCommerce**), mpss. GA4 MP is live on monitorstup/lcpackagingshop/mpss; Meta CAPI only on monitorstup. All new code must keep the existing `function_exists('wc_get_orders')` / WC guards so salon-nunta never fatals.

## The fix (design)

Single file changed: `safebiz-server-side-tracking.php`. Mirror the refund flag pattern and make "we already POSTed this order" **terminal and reset-proof**.

### A. Persistent idempotency flag + terminal-on-send — `safebiz_send_ga4_purchase` (lines 303-352)
- **Top guard** (after the status/existence checks, ~line 308): `if ( $order->get_meta('_safebiz_ga4_purchase_sent') === '1' ) return;` — survives the cron's status wipe. This alone stops confirmed-sent orders from ever re-sending.
- **On a received HTTP response** (broaden line 344 from `=== 204` to any 2xx `>= 200 && < 300`): set `_safebiz_ga4_mp_status = 'sent'`, set **`_safebiz_ga4_purchase_sent = '1'`**, keep `_safebiz_ga4_mp_sent_at`. Reaching Google = event recorded → terminal. (Fixes the delivered-but-marked-error case for any non-204 2xx.)
- **On `is_wp_error` or non-2xx** (delivery unknown): increment `_safebiz_ga4_mp_retries`; keep `status='error'` **only while `retries < SAFEBIZ_MP_MAX_RETRIES`** (new constant, default `5`, overridable in wp-config). At the cap → `status='error_permanent'` **and set `_safebiz_ga4_purchase_sent='1'`** to stop the loop. This bounds the genuinely-ambiguous timeout case to ≤5 sends instead of ∞.

### B. Retry cron — `safebiz_ga4_retry_errors` (lines 592-613)
- **Delete the status-wipe** (line 609). The send function now self-guards via the flag + status, so no wipe is needed.
- **Exclude already-terminal orders** in the `meta_query`: skip any order with `_safebiz_ga4_purchase_sent = '1'` (add a `NOT EXISTS`/`!= '1'` clause) and drop `error_permanent` (only re-drive `error` + stuck-`queued`).
- Keep `limit => 20`, hourly, and the WC guard (line 593).

### C. Enqueue guard — `safebiz_enqueue_purchase_jobs` (line 270)
- Add the flag to the skip condition so a sent order never re-enqueues on the 2nd status transition: skip when `_safebiz_ga4_purchase_sent === '1'` (in addition to the existing status list).

### D. Meta CAPI mirror (lines 397-466, 615-636)
- Apply the same three changes with `_safebiz_meta_capi_purchase_sent` + retry counter + cron no-wipe/exclusion. Lower urgency (event_id dedup + staleness already mask it) but keeps the two paths symmetric and correct. Preserve the existing `skipped_stale` behavior.

### E. One-time data migration (stops the loop for existing orders on upgrade)
- On load, compare an option `safebiz_sst_version` to the plugin version; on bump to `1.3.0`, **back-fill `_safebiz_ga4_purchase_sent = '1'` for every order that already has `_safebiz_ga4_mp_sent_at` set or `status IN ('sent','error')`** (they were POSTed ≥1 time — assume delivered). Same for Meta. Run WC-guarded and batched (bounded query or a one-shot Action Scheduler action) so large stores don't time out. This immediately neutralises the currently-looping orders across the fleet without waiting for the cron.

### F. Housekeeping
- Bump header `Version: 1.2.0` → **`1.3.0`**; update `CHANGELOG.md` and the updater `@version` if present.
- No change to consent gating, `session_id` join, payload building, or refund logic.

## Key design decisions (for Codex review)
- **"Received response ⇒ terminal sent" (even non-204).** Rationale: `/mp/collect` is non-idempotent and fire-and-accept; re-POSTing a possibly-delivered event is exactly what caused the over-count. Trade-off: a rare order that returns a hard 4xx (auth/validation) is marked sent though not recorded — acceptable vs. 28× inflation; surfaced via `_safebiz_ga4_mp_error`.
- **Retry only bounded, only on `is_wp_error`/non-2xx, capped at 5, then terminal.** Bounds the ambiguous-timeout duplication to ≤5 and guarantees the loop always ends. Alternative (retry only true enqueue-failures, cap 1) is safer against duplicates but risks dropping genuine transient network failures — cap=5 is the recommended middle ground; Codex may tune the constant.
- **Reuse the refund flag pattern** (`_safebiz_ga4_refund_sent`) rather than invent new machinery — minimal, proven, easy to review.

## Files to modify
- `plugins/safebiz-server-side-tracking/safebiz-server-side-tracking.php` (all logic above)
- `plugins/safebiz-server-side-tracking/CHANGELOG.md` (v1.3.0 entry)
- (both under the git clone at `C:\Users\takac\OneDrive\SafeHost\Vs Code\Master C\plugins\safebiz-server-side-tracking\`)

## Verification (pre-release, local)
1. `php -l safebiz-server-side-tracking.php` (PHP 8.3 CLI is on PATH locally).
2. `wp-phpstan` skill (static analysis; add a minimal `phpstan.neon` with WP stubs) + `wat/tools/wp-plugin-analyze.py` to diff the hook/meta map before vs after (confirm no hook lost, no new REST route).
3. Desk-check the state machine: prove `_safebiz_ga4_purchase_sent` is (a) set on every terminal path, (b) read before every send and enqueue, (c) never written to `''` by either cron; and that the retry cap is reached in bounded steps.

## Verification (post-deploy, per site — use existing tools)
1. `wat/tools/file-verify-check.js` — confirm the deployed file hash matches the released version on each site.
2. `wat/tools/reconcile-wc-ga4.js` + `wat/tools/dual-tracking-verify.js` — confirm each real order still produces exactly one GA4 MP purchase (and Meta CAPI on monitorstup) and that counts reconcile with WooCommerce.
3. `wat/tools/ga4-query.js --client mpss --dimensions date --metrics ecommercePurchases --days 14` — watch the "identical N per day on order dates" pattern disappear and daily purchases converge toward real order volume.
4. Regressions to confirm: refund still fires (own flag, untouched); salon-nunta has no fatal (plugin active, no WC → crons cleared at `init`); Meta CAPI still 1 event/order.

## Immediate mitigation (independent of rollout)
Because per-site rollout is manual and MPSS may lag, optionally stop the bleeding now by unscheduling the MPSS retry cron on the live site via the safe wrapper: `wat/tools/wp-cli-safe.sh` → `wp cron event delete safebiz_ga4_retry_errors`. The code fix makes this permanent; the historical inflated GA4 data cannot be deleted (GA4 has no purge for MP events) — report revenue from WooCommerce for the affected window.

## Handoff step (after approval)
Per request: copy this plan into the plugin repo folder (`C:\Users\takac\OneDrive\SafeHost\Vs Code\Master C\plugins\safebiz-server-side-tracking\`, e.g. `docs/FIX-purchase-overcount-v1.3.0.md`) so it travels with the code to Codex for review, then implement.

---

## Review Codex - contra-verificare documentata (2026-07-14)

### Verdict scurt

Directia este corecta, dar planul NU trebuie implementat exact asa cum este scris. Root cause-ul principal este confirmat: retry-ul GA4 sterge statusul si poate re-trimite acelasi purchase, iar guard-ul curent nu supravietuieste resetarii. Patch-ul v1.3.0 este GO numai dupa corectiile obligatorii de mai jos, in special separarea dintre "sent" si "terminal/nu mai retrimite".

### Confirmat

- `safebiz_send_ga4_purchase()` are doar guard pe `_safebiz_ga4_mp_status === 'sent'` la linia 308; nu exista `_safebiz_ga4_purchase_sent` in codul curent.
- Pe raspuns GA4: `is_wp_error` marcheaza `error` la liniile 341-343, succesul acceptat este doar HTTP 204 la liniile 344-346, iar orice alt cod HTTP devine `error` la liniile 347-349.
- Cronul `safebiz_ga4_retry_errors` cauta `error` si `queued` blocat, apoi sterge statusul la linia 609 si apeleaza din nou `safebiz_send_ga4_purchase()` la linia 611. Asta confirma mecanismul de bucla.
- Enqueue-ul poate seta `error` fara niciun POST extern cand Action Scheduler enqueue esueaza: liniile 274-278 (`enqueue failed (Action Scheduler)`). Asta este important pentru migratie.
- Refund-ul are pattern mai sigur: cere `_safebiz_ga4_mp_status === 'sent'` si foloseste flag dedicat `_safebiz_ga4_refund_sent` la liniile 546-566.
- Meta CAPI are aceeasi stergere de status in cron la linia 632 si retry la linia 634, plus guard doar pe status `sent` la linia 402.
- Baseline-ul local se reproduce: `wp-plugin-analyze.py --section hooks` raporteaza 12 actions + 3 filters; `cron_jobs` raporteaza 2 cronuri; `woo_hooks` raporteaza 5 hook-uri Woo; `rest_routes` nu raporteaza rute.
- `php -l` trece pentru `safebiz-server-side-tracking.php` si `includes/class-safebiz-github-updater.php`.
- Repo-ul are remote `https://github.com/safebiz/safebiz-server-side-tracking.git`, iar HEAD este taguit `v1.2.0`.
- Starea curenta NU este clean: `git status --short` arata `?? docs/`. Documentatia de plan este untracked si trebuie inclusa intentionat in commit sau curatata inainte de release.
- Inventarul local confirma plugin v1.2.0 activ in `site-structure.md` pentru `mpss`, `monitorstup`, `lcpackagingshop` si `salonnunta`. Pentru salonnunta, auditurile locale marcheaza WooCommerce ca negasit/inactiv.
- Mirror-urile `wp_files` locale sunt intr-adevar mai vechi/test: MPSS are `1.0.3-test-sessionid2`, monitorstup are `1.1.0` / `1.0.0`. Nu sunt sursa buna pentru patch.

### Corectii obligatorii inainte de implementare

1. **Nu folosi `_safebiz_ga4_purchase_sent` ca flag pentru esec permanent.** Daca il setezi si pe `error_permanent`, numele devine mincinos si reconcilierile viitoare vor interpreta fals ca eveniment livrat. Recomandare: `_safebiz_ga4_purchase_sent = 1` doar pe raspuns 2xx; pentru oprirea retry-ului foloseste status `error_permanent` si/sau flag separat `_safebiz_ga4_purchase_terminal = 1`.

2. **Migratia `status IN ('sent','error')` este prea larga.** `error` poate insemna `enqueue failed (Action Scheduler)` si deci zero POST catre Google. Daca migratia marcheaza toate `error` ca trimise, poate pierde permanent comenzi care nu au fost niciodata trimise. Recomandare minima: backfill `purchase_sent` doar pentru `_safebiz_ga4_mp_status = 'sent'` sau `_safebiz_ga4_mp_sent_at` existent. Pentru `error`, separa cel putin `enqueue failed` de erorile aparute dupa `wp_remote_post`; pentru necunoscute, marcheaza `error_ambiguous_terminal`, nu `sent`.

3. **Retry automat dupa un POST GA4 este incompatibil cu promisiunea "exact once".** Google spune oficial ca Measurement Protocol returneaza 2xx daca requestul HTTP este primit si ca, daca nu primesti 2xx, trebuie corectata cererea, nu retrimisa aceeasi cerere. In plus, MP nu returneaza erori HTTP pentru payload malformed/invalid/neprocesat, deci raspunsul nu este un ACK semantic complet. Recomandare: retry automat doar pentru stari unde stim ca nu s-a facut POST (`queued` blocat inainte de send, `enqueue failed`). Pentru `is_wp_error` dupa `wp_remote_post`, trateaza ca ambiguu si inchide automat, sau accepta explicit in document ca poti produce duplicate.

4. **Adauga cutoff GA4 pentru evenimente vechi.** Google documenteaza `timestamp_micros` ca backdate pana la 72h. Codul actual foloseste `date_created` la linia 329. Retry-ul pentru comenzi mai vechi de 72h nu ar trebui sa mai trimita `purchase`; marcheaza `skipped_stale` / `error_stale` si scoate din cron.

5. **Nu te baza doar pe `meta_query` pentru skip-ul flagului.** `NOT EXISTS` + `!= '1'` este usor de scris gresit in query-uri nested. Pune guard-ul critic in trei locuri: enqueue, top of send, si in `foreach` din cron inainte de apelul de send. Query-ul poate ramane optimizare, nu bariera unica.

6. **Meta CAPI: simetria este buna, dar formularea "masked by event_id dedup" trebuie tratata ca partial confirmata.** Codul seteaza `event_id` si are skip de 7 zile, iar documentatia Meta cere `event_id`/`event_name` pentru deduplicare si limiteaza `event_time` pentru web events la 7 zile. Totusi, nu transforma asta intr-o garantie suficienta pentru re-POST infinit. Aplica aceleasi guard-uri durabile si elimina status-wipe-ul.

### Neconfirmat / necesita verificare live

- Nu am re-rulat `ga4-query.js` si `wc/v3/orders`, deci cifrele `~560`, `~10-20` si fingerprint-ul `73 identic pe 5 zile` raman dovezi preluate din analiza existenta, nu reconfirmate in acest audit.
- Nu am verificat live ca browser/GTM purchase este inca oprit pe toate site-urile.
- Nu am verificat live ca auto-update este OFF fleet-wide; codul updater confirma doar mecanismul de update prin GitHub Releases.
- Nu am rulat `file-verify-check.js`, `reconcile-wc-ga4.js`, `dual-tracking-verify.js` sau `ga4-query.js` pentru ca auditul cerut este pre-implementare si fara credentials/live reads.

### Surse folosite

- Cod local: `plugins/safebiz-server-side-tracking/safebiz-server-side-tracking.php`, linii 262-352, 397-466, 542-613.
- Cod local: `plugins/safebiz-server-side-tracking/includes/class-safebiz-github-updater.php`, linii 17-24 si 127-164.
- Comenzi locale: `php -l`, `wp-plugin-analyze.py --section hooks|cron_jobs|woo_hooks|rest_routes`, `git status --short --branch`, `git remote -v`, `git tag --points-at HEAD`.
- Inventar local: `C:\MasterC-data\projects\mpss\site-structure.md`, `monitorstup\site-structure.md`, `lcpackagingshop\site-structure.md`, `salonnunta\site-structure.md`, plus changelog-urile locale.
- Google Analytics Measurement Protocol reference: https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference
- Google Analytics Measurement Protocol validation server: https://developers.google.com/analytics/devguides/collection/protocol/ga4/validating-events
- Meta Conversions API docs: https://developers.facebook.com/documentation/ads-commerce/conversions-api/using-the-api si https://developers.facebook.com/documentation/ads-commerce/conversions-api/guides/end-to-end-implementation

### Propunere Codex

Implementeaza v1.3.0 cu un state machine mai strict:

- `queued`: nu s-a confirmat inca executia jobului.
- `sent`: doar dupa raspuns HTTP 2xx GA4 / raspuns valid Meta.
- `error_enqueue`: enqueue nu a pornit POST-ul; retry permis.
- `error_ambiguous`: `wp_remote_post` a esuat fara raspuns; nu auto-reposta purchase GA4 fara decizie explicita.
- `error_permanent` / `skipped_stale`: terminal, fara retry.

Cu asta pastrezi obiectivul real: oprirea overcount-ului si o cale clara de reconciliere, fara sa numesti "sent" un eveniment care poate nu a ajuns niciodata.

---

## Implementare aplicata (v1.3.0) — 2026-07-14

Toate cele 6 corectii obligatorii Codex au fost integrate. Cod modificat: `safebiz-server-side-tracking.php` (+ `CHANGELOG.md`).

| Corectie Codex | Cum a fost aplicata |
|---|---|
| 1. Nu suprasolicita `purchase_sent` pe esec permanent | Flag `_safebiz_ga4_purchase_sent='1'` setat **DOAR pe 2xx**. Oprirea retry-ului se face prin **status terminal** (`error_ambiguous`/`error_http`/`error_stale`), nu prin flag. Reconcilierea citeste flagul = livrare confirmata. |
| 2. Migrare prea larga (`error` = poate zero POST) | Backfill flag **doar pe dovada pozitiva** (`status=sent` sau `_sent_at`). `error` cu "enqueue failed" → `error_enqueue`; restul `error`/`''` → `error_ambiguous` terminal (NU marcat livrat). |
| 3. Fara auto-retry dupa POST | `is_wp_error` dupa POST → `error_ambiguous` **terminal** (nu se retrimite). Retry-ul orar reia DOAR `error_enqueue` + `queued` blocat (stari fara POST). Mecanismul de "cap 5" din planul initial a fost **eliminat** in favoarea "post-POST = terminal". |
| 4. Cutoff GA4 72h | Comenzi `date_created > 72h` → `error_stale` terminal, scoase din bucla. |
| 5. Garda in 3 locuri, nu doar `meta_query` | Helper `safebiz_{ga4,meta}_purchase_is_terminal()` apelat la: (a) enqueue, (b) top-of-send, (c) `foreach`-ul cronului. `meta_query` = optimizare. |
| 6. Meta CAPI simetric + fara wipe | Aceleasi garzi durabile (`_safebiz_meta_capi_purchase_sent`) + status-wipe eliminat din cronul Meta. |

### State machine final (GA4 purchase)

```
                          POST wp_remote_post
queued ──job──> send() ─────────┬──────────── 2xx ──> sent + purchase_sent=1   [TERMINAL, livrat]
   │  (>2h blocat)               ├──────── non-2xx ──> error_http               [TERMINAL, ne-livrat]
   │                             └──── is_wp_error ──> error_ambiguous          [TERMINAL, ne-livrat]
   │
enqueue fail ──> error_enqueue  [retry orar OK: zero POST]
>72h ──────────> error_stale    [TERMINAL]
no consent ────> skipped_no_consent [TERMINAL]

Retry orar reia DOAR: error_enqueue + queued-blocat.  Nimic post-POST nu se retrimite.
```

### Verificari rulate (pre-release, local)
- `php -l safebiz-server-side-tracking.php` → **No syntax errors**.
- `wp-plugin-analyze.py` hooks/cron/woo/rest: identic cu baseline v1.2.0 **cu exceptia** `init` 1→2 (hook nou de migrare, intentionat). 3 filtre, 2 cronuri, 5 woo-hooks, 0 rute REST — neschimbate. Niciun hook pierdut.
- Refund neatins (`_safebiz_ga4_refund_sent`, HTTP 204) — pattern-ul corect de referinta.

### Ramas (necesita GO taki — actiuni pe productie)
1. **Deploy**: commit + push `main` → tag `v1.3.0` + GitHub Release (zip) → `wp plugin update` manual per site (mpss, monitorstup, lcpackagingshop, salon-nunta). Auto-update e OFF.
2. **Mitigare imediata optionala** pe MPSS inainte de rollout: `wp cron event delete safebiz_ga4_retry_errors` (opreste sangerarea acum). Fix-ul o face permanenta.
3. Post-deploy per site: `file-verify-check.js`, `reconcile-wc-ga4.js`, `dual-tracking-verify.js`, `ga4-query.js --days 14` (amprenta "73 identic" trebuie sa dispara).
