<?php
/**
 * Plugin Name: SafeBiz Server-Side Tracking
 * Plugin URI: https://github.com/safebiz/safebiz-server-side-tracking
 * Description: Server-side purchase tracking GA4 (Measurement Protocol) + Meta Conversions API, gate pe consimtamant, session_id join (atribuire sursa corecta), async via Action Scheduler, retry + logging + auto-update GitHub.
 * Version: 1.3.0
 * Author: SafeBiz Solutions
 * Author URI: https://safebiz.ro
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/safebiz/safebiz-server-side-tracking
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: safebiz-server-side-tracking
 *
 * Trimite purchase events server-side la GA4 + Meta, independent de browser (bypass ITP/AdBlock).
 * GATE PE CONSIMTAMANT (GDPR): GA4 doar cu analytics=GRANTED; Meta doar cu marketing=GRANTED.
 *
 * Config in wp-config.php:
 *   // GA4
 *   define('SAFEBIZ_GA4_MEASUREMENT_ID', 'G-XXXXXXXXXX');
 *   define('SAFEBIZ_GA4_API_SECRET', 'xxxxxxxxxxxxxxxxxxxx');
 *   define('SAFEBIZ_GA4_SEND_ITEMS', true);
 *   define('SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT', false);  // true => GA4 doar cu analytics=GRANTED
 *   // Meta Conversions API (v1.1.0)
 *   define('SAFEBIZ_META_PIXEL_ID', '222234967057730');
 *   define('SAFEBIZ_META_CAPI_TOKEN', '<token CAPI dedicat din Events Manager>');
 *   define('SAFEBIZ_META_GRAPH_VERSION', 'v21.0');           // optional
 *   define('SAFEBIZ_META_TEST_EVENT_CODE', '');              // gol = live; setat = Test Events
 *   define('SAFEBIZ_META_CAPI_REQUIRE_EXPLICIT_CONSENT', true); // STRICT default (GDPR)
 *
 * Order meta:
 *   _safebiz_consent_state    : { analytics, marketing, ad_user_data, ad_personalization, source, action, timestamp, captured_at }
 *   _ga_client_id             : _ga cookie snapshot
 *   _ga_session_id            : GA4 session id (din cookie _ga_<id>) — join sesiune, pastreaza sursa
 *   _fbp / _fbc               : Meta browser identifiers (capturate la checkout)
 *   _safebiz_client_ua        : user-agent original
 *   _safebiz_ga4_mp_status    : queued | sent | skipped_no_consent | error_enqueue | error_ambiguous | error_http | error_stale
 *   _safebiz_ga4_purchase_sent: '1' — flag DURABIL de LIVRARE (setat DOAR pe raspuns 2xx). Reconcilierea
 *                               externa citeste ACEST flag ca sursa de adevar "livrat", NU status-ul.
 *   _safebiz_meta_capi_status : queued | sent | skipped_no_consent | skipped_stale | error_enqueue | error_ambiguous | error_http
 *   _safebiz_meta_capi_purchase_sent : '1' — flag DURABIL de LIVRARE Meta (setat DOAR pe 200 + events_received).
 *   _safebiz_meta_capi_*      : queued_at | sent_at | error | fbtrace | event_id
 *
 * v1.3.0 (fix overcount GA4): purchase = "exactly once". Un purchase e TERMINAL (nu se mai retrimite)
 * cand a fost livrat (flag) SAU a atins o stare terminala. Retry-ul orar reia DOAR stari unde e sigur
 * ca NU s-a facut POST catre Google/Meta: error_enqueue (Action Scheduler a esuat) + queued blocat.
 * Dupa orice POST (2xx / non-2xx / is_wp_error) starea e terminala -> zero re-POST -> zero duplicate.
 * Cutoff 72h (GA4 respinge timestamp_micros backdated > 72h) -> error_stale.
 */

if ( ! defined('ABSPATH') ) exit;

// === GitHub auto-update mechanism ===
require_once __DIR__ . '/includes/class-safebiz-github-updater.php';
new SafeBiz_GitHub_Updater([
    'plugin_file'  => __FILE__,
    'github_repo'  => 'safebiz/safebiz-server-side-tracking',
    'plugin_slug'  => 'safebiz-server-side-tracking',
    'access_token' => defined('SAFEBIZ_GITHUB_TOKEN') ? SAFEBIZ_GITHUB_TOKEN : '',
]);

// === Config sanity check ===
add_action('admin_notices', function() {
    if ( ! defined('SAFEBIZ_GA4_MEASUREMENT_ID') || ! defined('SAFEBIZ_GA4_API_SECRET') ) {
        echo '<div class="notice notice-warning"><p><strong>SafeBiz Server-Side Tracking:</strong> SAFEBIZ_GA4_MEASUREMENT_ID si SAFEBIZ_GA4_API_SECRET nedefinite in wp-config.php — GA4 server-side inactiv.</p></div>';
    }
    if ( defined('SAFEBIZ_META_PIXEL_ID') && ! defined('SAFEBIZ_META_CAPI_TOKEN') ) {
        echo '<div class="notice notice-warning"><p><strong>SafeBiz Server-Side Tracking:</strong> SAFEBIZ_META_PIXEL_ID definit dar SAFEBIZ_META_CAPI_TOKEN lipseste — Meta CAPI inactiv.</p></div>';
    }
});

function safebiz_meta_graph_version() {
    return defined('SAFEBIZ_META_GRAPH_VERSION') ? SAFEBIZ_META_GRAPH_VERSION : 'v21.0';
}

/** SHA-256 normalizat pentru Meta Advanced Matching. */
function safebiz_hash($v) {
    $v = trim((string) $v);
    return $v === '' ? '' : hash('sha256', strtolower($v));
}

/** Normalizare telefon E.164 (RO). */
function safebiz_norm_phone($p) {
    $d = preg_replace('/\D+/', '', (string) $p);
    if ( $d === '' ) return '';
    if ( strpos($d, '0') === 0 && strlen($d) === 10 ) $d = '4' . $d; // 07xxxxxxxx -> 407...
    return $d;
}

// ============================================================
// CAPTURA la checkout: _ga client_id + consent snapshot + _fbp/_fbc/UA
// ============================================================
add_action('woocommerce_checkout_create_order', 'safebiz_capture_tracking_context', 10, 2);
function safebiz_capture_tracking_context($order, $data) {
    // _ga cookie => client_id GA4
    if ( ! empty($_COOKIE['_ga']) ) {
        $parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE['_ga'])));
        if ( count($parts) >= 4 ) {
            $order->update_meta_data('_ga_client_id', $parts[2] . '.' . $parts[3]);
        }
    }

    // _ga_<SUFFIX> cookie => ga_session_id (format: GS1.1.<session_id>.<...>)
    // FARA session_id, purchase-ul server-side creeaza o sesiune NOUA neatribuita (source=(not set))
    // -> atribuirea canalelor (newsletter/ads/seo) se pierde. Cu el, purchase-ul se leaga de sesiunea
    // reala a userului si mosteneste sursa corecta.
    if ( defined('SAFEBIZ_GA4_MEASUREMENT_ID') ) {
        $ga_suffix = substr(SAFEBIZ_GA4_MEASUREMENT_ID, 2); // 'G-GJ4YQYXWLE' => 'GJ4YQYXWLE'
        if ( $ga_suffix && ! empty($_COOKIE['_ga_' . $ga_suffix]) ) {
            // field[2] = session blob. Doua formate posibile:
            //   clasic:  GS1.1.1778607197.43.1...        => "1778607197"
            //   packed:  GS1.1.s1778607197$o43$t...      => "s1778607197$o43$..." (Consent Mode/sGTM)
            // session_id GA4 = numarul de dupa 's' (sau direct), urmat de '$' (packed) sau sfarsit (clasic).
            $s_parts = explode('.', sanitize_text_field(wp_unslash($_COOKIE['_ga_' . $ga_suffix])));
            if ( isset($s_parts[2]) && preg_match('/^s?(\d+)(?:\$|$)/', $s_parts[2], $m) ) {
                $order->update_meta_data('_ga_session_id', $m[1]);
            }
        }
    }

    // Consent snapshot (cookie primar SureCookie + fallback-uri)
    $consent_state = safebiz_detect_consent_state();
    if ( ! empty($consent_state) ) {
        $order->update_meta_data('_safebiz_consent_state', $consent_state);
    }

    // Meta browser identifiers (pentru match quality + dedup)
    if ( ! empty($_COOKIE['_fbp']) ) {
        $order->update_meta_data('_fbp', sanitize_text_field(wp_unslash($_COOKIE['_fbp'])));
    }
    $fbc = '';
    if ( ! empty($_COOKIE['_fbc']) ) {
        $fbc = sanitize_text_field(wp_unslash($_COOKIE['_fbc']));
    } elseif ( ! empty($_GET['fbclid']) ) {
        // construieste _fbc format Meta: fb.1.{timestamp}.{fbclid}
        $fbc = 'fb.1.' . time() . '.' . sanitize_text_field(wp_unslash($_GET['fbclid']));
    }
    if ( $fbc ) $order->update_meta_data('_fbc', $fbc);

    if ( ! empty($_SERVER['HTTP_USER_AGENT']) ) {
        $order->update_meta_data('_safebiz_client_ua', substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 350));
    }
}

/**
 * Detectie consent state. Returneaza array cu 'analytics' + 'marketing' (GRANTED/DENIED)
 * + 'ad_user_data'/'ad_personalization' (din marketing) + metadata. Array gol = necunoscut (=> DENIED la gate).
 *
 * Ordine (post-Codex runda 3): cookie SureCookie real PRIMAR; WP Consent API doar guarded (opt-in);
 * fallback Moove/Cookiebot/Webtoffee. wp_has_consent() necondiționat = periculos (optimistic true).
 */
function safebiz_detect_consent_state() {
    // PRIMAR: cookie real SureCookie 'surecookie_user_consent' (JSON url-encoded)
    if ( ! empty($_COOKIE['surecookie_user_consent']) ) {
        $d = json_decode( urldecode( wp_unslash($_COOKIE['surecookie_user_consent']) ), true );
        if ( is_array($d) ) {
            $p = isset($d['preferences']) && is_array($d['preferences']) ? $d['preferences'] : $d;
            $analytics = ! empty($p['analytics']) || ! empty($p['statistics']);
            $marketing = ! empty($p['marketing']) || ! empty($p['advertising']);
            return [
                'analytics'          => $analytics ? 'GRANTED' : 'DENIED',
                'marketing'          => $marketing ? 'GRANTED' : 'DENIED',
                'ad_user_data'       => $marketing ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
                'source'             => 'surecookie_user_consent',
                'action'             => $d['action'] ?? null,
                'timestamp'          => $d['timestamp'] ?? null,
                'captured_at'        => current_time('mysql'),
            ];
        }
    }

    // SECUNDAR (guarded): WP Consent API DOAR daca e opt-in real
    if ( function_exists('wp_has_consent') && function_exists('wp_get_consent_type') ) {
        $ct = wp_get_consent_type();
        if ( is_string($ct) && strpos($ct, 'optin') !== false ) {
            $analytics = wp_has_consent('statistics');
            $marketing = wp_has_consent('marketing');
            return [
                'analytics'          => $analytics ? 'GRANTED' : 'DENIED',
                'marketing'          => $marketing ? 'GRANTED' : 'DENIED',
                'ad_user_data'       => $marketing ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
                'source'             => 'wp_consent_api_optin',
                'captured_at'        => current_time('mysql'),
            ];
        }
    }

    // Fallback Moove GDPR (MPSS) — cookie moove_gdpr_popup
    if ( ! empty($_COOKIE['moove_gdpr_popup']) ) {
        $decoded = json_decode( stripslashes(wp_unslash($_COOKIE['moove_gdpr_popup'])), true );
        if ( is_array($decoded) ) {
            $thirdparty = ! empty($decoded['thirdparty']);
            $advanced   = ! empty($decoded['advanced']);
            return [
                'analytics'          => $advanced ? 'GRANTED' : 'DENIED',
                'marketing'          => $thirdparty ? 'GRANTED' : 'DENIED',
                'ad_user_data'       => $thirdparty ? 'GRANTED' : 'DENIED',
                'ad_personalization' => $thirdparty ? 'GRANTED' : 'DENIED',
                'source'             => 'moove_gdpr',
                'captured_at'        => current_time('mysql'),
            ];
        }
    }

    // Fallback Cookiebot
    if ( ! empty($_COOKIE['CookieConsent']) ) {
        $raw = stripslashes(wp_unslash($_COOKIE['CookieConsent']));
        $marketing = strpos($raw, 'marketing:true') !== false;
        $analytics = strpos($raw, 'statistics:true') !== false;
        return [
            'analytics'          => $analytics ? 'GRANTED' : 'DENIED',
            'marketing'          => $marketing ? 'GRANTED' : 'DENIED',
            'ad_user_data'       => $marketing ? 'GRANTED' : 'DENIED',
            'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
            'source'             => 'cookiebot',
            'captured_at'        => current_time('mysql'),
        ];
    }

    // Fallback Webtoffee
    if ( ! empty($_COOKIE['cookielawinfo-checkbox-advertisement']) ) {
        $marketing = $_COOKIE['cookielawinfo-checkbox-advertisement'] === 'yes';
        $analytics = ! empty($_COOKIE['cookielawinfo-checkbox-analytics']) && $_COOKIE['cookielawinfo-checkbox-analytics'] === 'yes';
        return [
            'analytics'          => $analytics ? 'GRANTED' : 'DENIED',
            'marketing'          => $marketing ? 'GRANTED' : 'DENIED',
            'ad_user_data'       => $marketing ? 'GRANTED' : 'DENIED',
            'ad_personalization' => $marketing ? 'GRANTED' : 'DENIED',
            'source'             => 'webtoffee',
            'captured_at'        => current_time('mysql'),
        ];
    }

    return []; // necunoscut -> tratat DENIED la gate
}

/** Helper: extrage o categorie de consimtamant din snapshot, default DENIED. */
function safebiz_consent_val($consent_state, $key) {
    if ( is_array($consent_state) && ! empty($consent_state[$key]) && $consent_state[$key] === 'GRANTED' ) {
        return 'GRANTED';
    }
    return 'DENIED';
}

// ============================================================
// Idempotenta durabila purchase (fix v1.3.0 overcount)
// ============================================================
/**
 * Purchase GA4 e "terminal" (NU se mai retrimite) daca:
 *   - a fost LIVRAT: flag _safebiz_ga4_purchase_sent === '1' (setat DOAR pe raspuns 2xx), SAU
 *   - a atins o stare terminala fara re-POST util: skip consent / ambiguu (is_wp_error dupa POST) /
 *     http (non-2xx) / stale (>72h).
 * NB reconciliere: tool-urile externe folosesc FLAG-ul (livrare confirmata), NU acest helper.
 * Helper-ul include si stari NE-livrate — doar ca bariera anti re-POST. Flag-ul nu se pune pe esec.
 */
function safebiz_ga4_purchase_is_terminal($order) {
    if ( $order->get_meta('_safebiz_ga4_purchase_sent') === '1' ) return true;
    return in_array($order->get_meta('_safebiz_ga4_mp_status'),
        ['sent', 'skipped_no_consent', 'error_ambiguous', 'error_http', 'error_stale'], true);
}

/** Analog pentru Meta CAPI. Flag durabil _safebiz_meta_capi_purchase_sent (setat DOAR pe 200 valid). */
function safebiz_meta_purchase_is_terminal($order) {
    if ( $order->get_meta('_safebiz_meta_capi_purchase_sent') === '1' ) return true;
    return in_array($order->get_meta('_safebiz_meta_capi_status'),
        ['sent', 'skipped_no_consent', 'skipped_stale', 'error_ambiguous', 'error_http'], true);
}

// ============================================================
// DISPATCH: la processing/completed -> enqueue async (Action Scheduler) GA4 + Meta
// ============================================================
add_action('woocommerce_order_status_processing', 'safebiz_enqueue_purchase_jobs', 10, 1);
add_action('woocommerce_order_status_completed',  'safebiz_enqueue_purchase_jobs', 10, 1);

add_action('safebiz_send_ga4_purchase',  'safebiz_send_ga4_purchase', 10, 1);
add_action('safebiz_send_meta_purchase', 'safebiz_send_meta_purchase', 10, 1);

/**
 * Dispatch job. Return:
 *   >0  = action_id (enqueue Action Scheduler reusit)
 *    0  = enqueue ESUAT (apelantul trebuie sa nu lase order-ul 'queued' orfan)
 *   -1  = rulat sincron (Action Scheduler indisponibil) — deja procesat, nu ramane 'queued'
 */
function safebiz_dispatch_job($hook, $order_id) {
    if ( function_exists('as_enqueue_async_action') ) {
        return (int) as_enqueue_async_action($hook, [ (int) $order_id ], 'safebiz-tracking');
    }
    do_action($hook, (int) $order_id); // fallback sincron daca Action Scheduler lipseste
    return -1;
}

function safebiz_enqueue_purchase_jobs($order_id) {
    $order = wc_get_order($order_id);
    if ( ! $order ) return;
    if ( ! in_array($order->get_status(), ['processing', 'completed'], true) ) return;

    // GA4 — enqueue DOAR daca nu e deja terminal (livrat/ambiguu/http/stale/skip) si nu e deja in coada.
    // Guard #1 din 3 (enqueue). Vezi si top-of-send si foreach-ul din cron.
    if ( defined('SAFEBIZ_GA4_MEASUREMENT_ID') && defined('SAFEBIZ_GA4_API_SECRET') ) {
        if ( ! safebiz_ga4_purchase_is_terminal($order) && $order->get_meta('_safebiz_ga4_mp_status') !== 'queued' ) {
            $order->update_meta_data('_safebiz_ga4_mp_status', 'queued');
            $order->update_meta_data('_safebiz_ga4_mp_queued_at', current_time('mysql'));
            $order->save();
            if ( safebiz_dispatch_job('safebiz_send_ga4_purchase', $order_id) === 0 ) {
                // enqueue Action Scheduler esuat -> NU s-a facut POST -> error_enqueue (retry ORAR sigur, fara duplicat)
                $order->update_meta_data('_safebiz_ga4_mp_status', 'error_enqueue');
                $order->update_meta_data('_safebiz_ga4_mp_error', 'enqueue failed (Action Scheduler)');
                $order->save();
            }
        }
    }

    // Meta CAPI
    if ( defined('SAFEBIZ_META_PIXEL_ID') && defined('SAFEBIZ_META_CAPI_TOKEN') ) {
        if ( ! safebiz_meta_purchase_is_terminal($order) && $order->get_meta('_safebiz_meta_capi_status') !== 'queued' ) {
            $order->update_meta_data('_safebiz_meta_capi_status', 'queued');
            $order->update_meta_data('_safebiz_meta_capi_queued_at', current_time('mysql'));
            $order->save();
            if ( safebiz_dispatch_job('safebiz_send_meta_purchase', $order_id) === 0 ) {
                // enqueue Action Scheduler esuat -> NU s-a facut POST -> error_enqueue (retry ORAR sigur, fara duplicat)
                $order->update_meta_data('_safebiz_meta_capi_status', 'error_enqueue');
                $order->update_meta_data('_safebiz_meta_capi_error', 'enqueue failed (Action Scheduler)');
                $order->save();
            }
        }
    }
}

// ============================================================
// GA4 Measurement Protocol — gate pe ANALYTICS
// ============================================================
function safebiz_send_ga4_purchase($order_id) {
    if ( ! defined('SAFEBIZ_GA4_MEASUREMENT_ID') || ! defined('SAFEBIZ_GA4_API_SECRET') ) return;
    $order = wc_get_order($order_id);
    if ( ! $order ) return;
    if ( ! in_array($order->get_status(), ['processing', 'completed'], true) ) return;
    // Guard #2 din 3 (top-of-send): idempotenta durabila. Daca purchase-ul a fost LIVRAT sau e terminal,
    // NU retrimite — supravietuieste oricarei resetari de status (bug-ul vechi stergea statusul in cron).
    if ( safebiz_ga4_purchase_is_terminal($order) ) return;

    $consent_state = $order->get_meta('_safebiz_consent_state');
    $analytics = safebiz_consent_val($consent_state, 'analytics');
    $marketing = safebiz_consent_val($consent_state, 'marketing');

    // Gate: daca cerem consimtamant explicit (recomandat GDPR) -> analytics trebuie GRANTED
    $require = defined('SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT') && SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT === true;
    if ( $require && $analytics !== 'GRANTED' ) {
        $order->update_meta_data('_safebiz_ga4_mp_status', 'skipped_no_consent');
        $order->save();
        error_log('[SafeBiz-GA4] #' . $order->get_order_number() . ' SKIP — analytics consent absent');
        return;
    }

    // CUTOFF 72h: GA4 respinge (dropeaza silentios) evenimente cu timestamp_micros backdated > 72h.
    // Retrimiterea unei comenzi mai vechi ar fi inutila -> marcheaza TERMINAL si scoate din bucla.
    $created    = $order->get_date_created();
    $created_ts = $created ? $created->getTimestamp() : time();
    if ( $created_ts < ( time() - 3 * DAY_IN_SECONDS ) ) {
        $order->update_meta_data('_safebiz_ga4_mp_status', 'error_stale');
        $order->save();
        error_log('[SafeBiz-GA4] #' . $order->get_order_number() . ' SKIP — comanda > 72h (GA4 respinge backdating)');
        return;
    }

    $payload = [
        'client_id'        => safebiz_get_client_id($order),
        'consent'          => [
            'ad_user_data'       => $marketing,   // ad signals din marketing consent
            'ad_personalization' => $marketing,
        ],
        'timestamp_micros' => (int) ($created_ts * 1000000),
        'events'           => [[ 'name' => 'purchase', 'params' => safebiz_build_purchase_params($order) ]],
    ];

    $url = sprintf('https://region1.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
        urlencode(SAFEBIZ_GA4_MEASUREMENT_ID), urlencode(SAFEBIZ_GA4_API_SECRET));

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload), 'timeout' => 10, 'blocking' => true,
    ]);

    // "exactly once": ORICE rezultat dupa POST este TERMINAL -> zero re-POST -> zero duplicate.
    // Retry-ul orar reia DOAR error_enqueue/queued-blocat (stari fara POST). Vezi cronul de mai jos.
    if ( is_wp_error($response) ) {
        // POST-ul a plecat dar raspunsul e necunoscut (timeout/retea). Evenimentul POATE a ajuns la Google
        // (MP e fire-and-accept). NU auto-retrimite (asta genera overcount-ul). Terminal ambiguu;
        // reconciliere manuala din WooCommerce daca e nevoie. NU se pune flag-ul de livrare.
        $order->update_meta_data('_safebiz_ga4_mp_status', 'error_ambiguous');
        $order->update_meta_data('_safebiz_ga4_mp_error', $response->get_error_message());
    } else {
        $code = (int) wp_remote_retrieve_response_code($response);
        if ( $code >= 200 && $code < 300 ) {
            // Raspuns 2xx = cererea a fost primita de Google (MP raspunde 2xx cand a primit requestul) -> LIVRAT.
            $order->update_meta_data('_safebiz_ga4_mp_status', 'sent');
            $order->update_meta_data('_safebiz_ga4_purchase_sent', '1'); // flag DURABIL de livrare (guard reset-proof)
            $order->update_meta_data('_safebiz_ga4_mp_sent_at', current_time('mysql'));
        } else {
            // Non-2xx: cererea a ajuns la endpoint dar a fost respinsa la nivel HTTP. Per Google, un non-2xx
            // inseamna "corecteaza cererea", NU "retrimite aceeasi cerere" -> terminal, fara re-POST.
            $order->update_meta_data('_safebiz_ga4_mp_status', 'error_http');
            $order->update_meta_data('_safebiz_ga4_mp_error', 'HTTP ' . $code . ' ' . substr(wp_remote_retrieve_body($response), 0, 200));
        }
    }
    $order->save();
}

function safebiz_build_purchase_params($order) {
    $params = [
        'transaction_id' => (string) $order->get_order_number(),
        'value'          => (float) wc_format_decimal($order->get_total(), 2),
        'currency'       => $order->get_currency(),
        'tax'            => (float) wc_format_decimal($order->get_total_tax(), 2),
        'shipping'       => (float) wc_format_decimal($order->get_shipping_total(), 2),
        'coupon'         => implode(',', $order->get_coupon_codes()),
    ];

    // GA4 session join — leaga purchase-ul de sesiunea reala a userului (capturata la checkout).
    // Fara session_id, GA4 porneste o sesiune noua fara sursa -> tranzactia apare ca (not set).
    $session_id = $order->get_meta('_ga_session_id');
    if ( ! empty($session_id) ) {
        $params['session_id']           = $session_id;
        $params['engagement_time_msec'] = 1;
    }

    if ( defined('SAFEBIZ_GA4_SEND_ITEMS') && SAFEBIZ_GA4_SEND_ITEMS === true ) {
        $items = [];
        foreach ( $order->get_items('line_item') as $item ) {
            $product = $item->get_product();
            $sku = $product && $product->get_sku() ? $product->get_sku() : (string) $item->get_product_id();
            $items[] = [
                'item_id'   => $sku,
                'item_name' => $item->get_name(),
                'price'     => (float) wc_format_decimal($order->get_item_total($item, false, true), 2),
                'quantity'  => (int) $item->get_quantity(),
            ];
        }
        $params['items'] = $items;
    }
    return $params;
}

function safebiz_get_client_id($order) {
    $client_id = $order->get_meta('_ga_client_id');
    return ! empty($client_id) ? $client_id : wp_hash('order_' . $order->get_order_number());
}

// ============================================================
// META CONVERSIONS API — gate STRICT pe MARKETING
// ============================================================
function safebiz_send_meta_purchase($order_id) {
    if ( ! defined('SAFEBIZ_META_PIXEL_ID') || ! defined('SAFEBIZ_META_CAPI_TOKEN') ) return;
    $order = wc_get_order($order_id);
    if ( ! $order ) return;
    if ( ! in_array($order->get_status(), ['processing', 'completed'], true) ) return;
    // Guard #2 din 3 (top-of-send): idempotenta durabila Meta (reset-proof).
    if ( safebiz_meta_purchase_is_terminal($order) ) return;

    // GATE STRICT: marketing consent obligatoriu (default true; configurabil)
    $require = ! defined('SAFEBIZ_META_CAPI_REQUIRE_EXPLICIT_CONSENT') || SAFEBIZ_META_CAPI_REQUIRE_EXPLICIT_CONSENT === true;
    $marketing = safebiz_consent_val($order->get_meta('_safebiz_consent_state'), 'marketing');
    if ( $require && $marketing !== 'GRANTED' ) {
        $order->update_meta_data('_safebiz_meta_capi_status', 'skipped_no_consent');
        $order->save();
        error_log('[SafeBiz-Meta] #' . $order->get_order_number() . ' SKIP — marketing consent absent');
        return;
    }

    // event_time = data conversiei; skip daca > 7 zile (Meta respinge)
    $event_time = safebiz_order_event_time($order);
    if ( $event_time < ( time() - 7 * DAY_IN_SECONDS ) ) {
        $order->update_meta_data('_safebiz_meta_capi_status', 'skipped_stale');
        $order->save();
        return;
    }

    // event_id = "wc_order_" + ORDER NUMBER (= transaction_id pe care GTM Kit il pune in browser)
    // pentru dedup corect browser pixel <-> server CAPI. NU get_id() (difera de number cand exista
    // numerotare secventiala WC: ex id=55617 dar number=208).
    $event = [
        'event_name'       => 'Purchase',
        'event_time'       => $event_time,
        'event_id'         => 'wc_order_' . $order->get_order_number(),
        'action_source'    => 'website',
        'event_source_url' => safebiz_order_source_url($order),
        'user_data'        => safebiz_meta_user_data($order),
        'custom_data'      => safebiz_meta_custom_data($order),
    ];

    $body = [ 'data' => [ $event ] ];
    if ( defined('SAFEBIZ_META_TEST_EVENT_CODE') && SAFEBIZ_META_TEST_EVENT_CODE !== '' ) {
        $body['test_event_code'] = SAFEBIZ_META_TEST_EVENT_CODE;
    }

    $url = sprintf('https://graph.facebook.com/%s/%s/events?access_token=%s',
        safebiz_meta_graph_version(), urlencode(SAFEBIZ_META_PIXEL_ID), urlencode(SAFEBIZ_META_CAPI_TOKEN));

    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($body), 'timeout' => 15, 'blocking' => true,
    ]);

    $order->update_meta_data('_safebiz_meta_capi_event_id', $event['event_id']);
    // "exactly once" (simetric cu GA4): dupa POST orice rezultat e TERMINAL. Meta are si dedup nativ pe
    // event_id, dar nu ne bazam pe el — garzile durabile fac calea corecta la sursa.
    if ( is_wp_error($response) ) {
        // POST plecat, raspuns necunoscut -> ambiguu; NU auto-retrimite. Fara flag de livrare.
        $order->update_meta_data('_safebiz_meta_capi_status', 'error_ambiguous');
        $order->update_meta_data('_safebiz_meta_capi_error', $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $resp = json_decode(wp_remote_retrieve_body($response), true);
        if ( $code === 200 && is_array($resp) && ! empty($resp['events_received']) ) {
            $order->update_meta_data('_safebiz_meta_capi_status', 'sent');
            $order->update_meta_data('_safebiz_meta_capi_purchase_sent', '1'); // flag DURABIL de livrare
            $order->update_meta_data('_safebiz_meta_capi_sent_at', current_time('mysql'));
            $order->update_meta_data('_safebiz_meta_capi_fbtrace', $resp['fbtrace_id'] ?? '');
        } else {
            // Raspuns primit dar nevalidat de Meta -> terminal, fara re-POST (evita duplicate).
            $order->update_meta_data('_safebiz_meta_capi_status', 'error_http');
            $order->update_meta_data('_safebiz_meta_capi_error', 'HTTP ' . $code . ' ' . substr(wp_remote_retrieve_body($response), 0, 300));
            error_log('[SafeBiz-Meta] #' . $order->get_order_number() . ' HTTP ' . $code);
        }
    }
    $order->save();
}

/** event_time real: date_paid -> date_completed -> date_created. */
function safebiz_order_event_time($order) {
    foreach ( ['get_date_paid', 'get_date_completed', 'get_date_created'] as $m ) {
        if ( method_exists($order, $m) ) {
            $d = $order->$m();
            if ( $d ) return $d->getTimestamp();
        }
    }
    return time();
}

function safebiz_order_source_url($order) {
    $entry = $order->get_meta('_wc_order_attribution_session_entry');
    if ( $entry ) return $entry;
    return function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');
}

function safebiz_meta_user_data($order) {
    $ud = [];
    if ( $order->get_billing_email() )      $ud['em']      = [ safebiz_hash($order->get_billing_email()) ];
    $ph = safebiz_norm_phone($order->get_billing_phone());
    if ( $ph )                              $ud['ph']      = [ safebiz_hash($ph) ];
    if ( $order->get_billing_first_name() ) $ud['fn']      = [ safebiz_hash($order->get_billing_first_name()) ];
    if ( $order->get_billing_last_name() )  $ud['ln']      = [ safebiz_hash($order->get_billing_last_name()) ];
    if ( $order->get_billing_city() )       $ud['ct']      = [ safebiz_hash(preg_replace('/\s+/', '', $order->get_billing_city())) ];
    if ( $order->get_billing_state() )      $ud['st']      = [ safebiz_hash($order->get_billing_state()) ];
    if ( $order->get_billing_postcode() )   $ud['zp']      = [ safebiz_hash($order->get_billing_postcode()) ];
    if ( $order->get_billing_country() )    $ud['country'] = [ safebiz_hash($order->get_billing_country()) ];

    // external_id stabil: customer_id sau hash email
    $cid = $order->get_customer_id();
    if ( $cid ) {
        $ud['external_id'] = [ safebiz_hash('cust_' . $cid) ];
    } elseif ( $order->get_billing_email() ) {
        $ud['external_id'] = [ safebiz_hash($order->get_billing_email()) ];
    }

    if ( $order->get_customer_ip_address() ) $ud['client_ip_address'] = $order->get_customer_ip_address();
    $ua = $order->get_meta('_safebiz_client_ua') ?: $order->get_customer_user_agent();
    if ( $ua ) $ud['client_user_agent'] = $ua;
    if ( $order->get_meta('_fbp') ) $ud['fbp'] = $order->get_meta('_fbp');
    if ( $order->get_meta('_fbc') ) $ud['fbc'] = $order->get_meta('_fbc');
    return $ud;
}

function safebiz_meta_custom_data($order) {
    $content_ids = []; $contents = [];
    foreach ( $order->get_items('line_item') as $item ) {
        $product = $item->get_product();
        $pid = $product ? (string) $product->get_id() : (string) $item->get_product_id();
        $qty = (int) $item->get_quantity();
        $content_ids[] = $pid;
        $contents[] = [
            'id'         => $pid,
            'quantity'   => $qty,
            'item_price' => $qty ? round((float) $item->get_total() / $qty, 2) : (float) $item->get_total(),
        ];
    }
    return [
        'currency'     => $order->get_currency(),
        'value'        => (float) wc_format_decimal($order->get_total(), 2),
        'content_type' => 'product',
        'content_ids'  => $content_ids,
        'contents'     => $contents,
        'order_id'     => (string) $order->get_id(),
    ];
}

// ============================================================
// Refund event GA4 (neschimbat — Meta refund out of scope v1.1.0)
// ============================================================
add_action('woocommerce_order_status_refunded',  'safebiz_ga4_refund_event', 10, 1);
add_action('woocommerce_order_status_cancelled', 'safebiz_ga4_refund_event', 10, 1);

function safebiz_ga4_refund_event($order_id) {
    if ( ! defined('SAFEBIZ_GA4_MEASUREMENT_ID') || ! defined('SAFEBIZ_GA4_API_SECRET') ) return;
    $order = wc_get_order($order_id);
    if ( ! $order ) return;
    if ( $order->get_meta('_safebiz_ga4_mp_status') !== 'sent' ) return;
    if ( $order->get_meta('_safebiz_ga4_refund_sent') === '1' ) return;

    $marketing = safebiz_consent_val($order->get_meta('_safebiz_consent_state'), 'marketing');
    $payload = [
        'client_id' => safebiz_get_client_id($order),
        'consent'   => ['ad_user_data' => $marketing, 'ad_personalization' => $marketing],
        'events'    => [[ 'name' => 'refund', 'params' => [
            'transaction_id' => (string) $order->get_order_number(),
            'value'          => (float) wc_format_decimal($order->get_total(), 2),
            'currency'       => $order->get_currency(),
        ]]],
    ];
    $url = sprintf('https://region1.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
        urlencode(SAFEBIZ_GA4_MEASUREMENT_ID), urlencode(SAFEBIZ_GA4_API_SECRET));
    $response = wp_remote_post($url, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode($payload), 'timeout' => 10, 'blocking' => true,
    ]);
    if ( ! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204 ) {
        $order->update_meta_data('_safebiz_ga4_refund_sent', '1');
        $order->update_meta_data('_safebiz_ga4_refund_at', current_time('mysql'));
        $order->save();
    }
}

// ============================================================
// Cron orar — retry GA4 + Meta status=error
// ============================================================
add_action('init', function() {
    // GATE WooCommerce: pluginul poate fi activ si pe site-uri FARA WC (ex: salon-nunta.ro).
    // Callback-urile de retry cheama wc_get_orders() -> fatal fara WC. Programeaza cronurile DOAR
    // cand WC e prezent; daca WC lipseste (sau a fost dezinstalat), curata orice eveniment ramas.
    if ( ! function_exists('wc_get_orders') ) {
        wp_clear_scheduled_hook('safebiz_ga4_retry_errors');
        wp_clear_scheduled_hook('safebiz_meta_capi_retry_errors');
        return;
    }
    if ( ! wp_next_scheduled('safebiz_ga4_retry_errors') ) {
        wp_schedule_event(time(), 'hourly', 'safebiz_ga4_retry_errors');
    }
    if ( ! wp_next_scheduled('safebiz_meta_capi_retry_errors') ) {
        wp_schedule_event(time(), 'hourly', 'safebiz_meta_capi_retry_errors');
    }
});

// FIX v1.3.0: retry-ul reia DOAR stari unde e sigur ca NU s-a facut POST catre Google:
//   - error_enqueue: Action Scheduler nu a pornit jobul (zero POST)
//   - queued blocat > 2h: jobul nu a rulat niciodata (zero POST)
// NU mai reia error_ambiguous/error_http/error_stale (POST-ul poate a ajuns -> ar face duplicate).
// FARA status-wipe: send() se auto-protejeaza prin guard-ul terminal durabil.
add_action('safebiz_ga4_retry_errors', function() {
    if ( ! function_exists('wc_get_orders') ) return; // guard WC (defensiv, pe langa gate-ul de la init)
    $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - 2 * HOUR_IN_SECONDS);
    $orders = wc_get_orders([
        'limit'  => 20, 'status' => ['wc-processing', 'wc-completed'],
        'meta_query' => [
            'relation' => 'OR',
            [ 'key' => '_safebiz_ga4_mp_status', 'value' => 'error_enqueue' ],
            [
                'relation' => 'AND',
                [ 'key' => '_safebiz_ga4_mp_status',    'value' => 'queued' ],
                [ 'key' => '_safebiz_ga4_mp_queued_at', 'value' => $cutoff, 'compare' => '<' ],
            ],
        ],
    ]);
    foreach ( $orders as $order ) {
        // Guard #3 din 3 (cron foreach): bariera de cod, nu ne bazam doar pe meta_query.
        if ( safebiz_ga4_purchase_is_terminal($order) ) continue;
        safebiz_send_ga4_purchase($order->get_id());
    }
});

add_action('safebiz_meta_capi_retry_errors', function() {
    if ( ! function_exists('wc_get_orders') ) return; // guard WC (defensiv, pe langa gate-ul de la init)
    $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - 2 * HOUR_IN_SECONDS);
    $orders = wc_get_orders([
        'limit'  => 20, 'status' => ['wc-processing', 'wc-completed'],
        'meta_query' => [
            'relation' => 'OR',
            [ 'key' => '_safebiz_meta_capi_status', 'value' => 'error_enqueue' ],
            [
                'relation' => 'AND',
                [ 'key' => '_safebiz_meta_capi_status',    'value' => 'queued' ],
                [ 'key' => '_safebiz_meta_capi_queued_at', 'value' => $cutoff, 'compare' => '<' ],
            ],
        ],
    ]);
    foreach ( $orders as $order ) {
        if ( safebiz_meta_purchase_is_terminal($order) ) continue;
        safebiz_send_meta_purchase($order->get_id());
    }
});

// ============================================================
// Migrare one-time la 1.3.0 — opreste bucla pentru comenzile EXISTENTE + backfill onest al flagului
// ============================================================
// Reclasifica statusurile vechi in vocabularul nou SI pune flag-ul de livrare DOAR pe dovada pozitiva.
// Corectie Codex: NU marca 'error' generic ca livrat — poate fi enqueue-failure (zero POST) si am pierde
// permanent comenzi netrimise. Regula:
//   - status 'sent' SAU exista _sent_at  -> purchase_sent='1' (livrare confirmata)
//   - status 'error' cu eroare 'enqueue failed' -> error_enqueue (fara POST -> retry sigur)
//   - status 'error' (POSTat >=1) sau '' (sters de cronul vechi mid-flight) -> error_ambiguous (TERMINAL,
//     NU marcat livrat) -> bucla se opreste, reconcilierea ramane onesta.
add_action('init', 'safebiz_sst_maybe_migrate_130', 20);
function safebiz_sst_maybe_migrate_130() {
    if ( ! function_exists('wc_get_orders') ) return; // fara WC (salon-nunta) nu exista comenzi de migrat
    if ( version_compare( (string) get_option('safebiz_sst_db_version', '0'), '1.3.0', '>=' ) ) return;

    $page = 1;
    do {
        $orders = wc_get_orders([
            'limit'  => 100, 'page' => $page, 'return' => 'objects',
            'status' => ['wc-processing', 'wc-completed', 'wc-refunded', 'wc-cancelled', 'wc-on-hold'],
            'meta_query' => [
                'relation' => 'OR',
                [ 'key' => '_safebiz_ga4_mp_status',    'compare' => 'EXISTS' ],
                [ 'key' => '_safebiz_meta_capi_status', 'compare' => 'EXISTS' ],
            ],
        ]);
        if ( empty($orders) ) break;
        foreach ( $orders as $order ) { safebiz_sst_migrate_order_130($order); }
        $page++;
    } while ( count($orders) === 100 && $page <= 100 ); // plafon dur 10.000 comenzi (magazinele flotei sunt mici)

    update_option('safebiz_sst_db_version', '1.3.0', false);
}

/** Idempotent: actioneaza DOAR pe stari legacy; comenzile deja in vocabular nou / cu flag sunt sarite. */
function safebiz_sst_migrate_order_130($order) {
    $changed = false;

    // ---- GA4 ----
    if ( $order->get_meta('_safebiz_ga4_purchase_sent') !== '1' ) {
        $st = $order->get_meta('_safebiz_ga4_mp_status');
        if ( $st === 'sent' || $order->get_meta('_safebiz_ga4_mp_sent_at') ) {
            $order->update_meta_data('_safebiz_ga4_purchase_sent', '1');
            if ( $st !== 'sent' ) $order->update_meta_data('_safebiz_ga4_mp_status', 'sent');
            $changed = true;
        } elseif ( $st === 'error' || $st === '' ) {
            $err = (string) $order->get_meta('_safebiz_ga4_mp_error');
            $order->update_meta_data('_safebiz_ga4_mp_status',
                ( $st === 'error' && stripos($err, 'enqueue failed') !== false ) ? 'error_enqueue' : 'error_ambiguous');
            $changed = true;
        }
    }

    // ---- Meta CAPI ----
    if ( $order->get_meta('_safebiz_meta_capi_purchase_sent') !== '1' ) {
        $mst = $order->get_meta('_safebiz_meta_capi_status');
        if ( $mst === 'sent' || $order->get_meta('_safebiz_meta_capi_sent_at') ) {
            $order->update_meta_data('_safebiz_meta_capi_purchase_sent', '1');
            if ( $mst !== 'sent' ) $order->update_meta_data('_safebiz_meta_capi_status', 'sent');
            $changed = true;
        } elseif ( $mst === 'error' || $mst === '' ) {
            $merr = (string) $order->get_meta('_safebiz_meta_capi_error');
            $order->update_meta_data('_safebiz_meta_capi_status',
                ( $mst === 'error' && stripos($merr, 'enqueue failed') !== false ) ? 'error_enqueue' : 'error_ambiguous');
            $changed = true;
        }
    }

    if ( $changed ) $order->save();
}

// === Cleanup la deactivation ===
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('safebiz_ga4_retry_errors');
    wp_clear_scheduled_hook('safebiz_meta_capi_retry_errors');
});
