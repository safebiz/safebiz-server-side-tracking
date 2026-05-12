<?php
/**
 * Plugin Name: SafeBiz Server-Side Tracking
 * Plugin URI: https://github.com/safebiz/safebiz-server-side-tracking
 * Description: Server-side GA4 purchase tracking via Measurement Protocol cu logging, retry si auto-update via GitHub Releases.
 * Version: 1.0.2
 * Author: SafeBiz Solutions
 * Author URI: https://safebiz.ro
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://github.com/safebiz/safebiz-server-side-tracking
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: safebiz-server-side-tracking
 *
 * Trimite purchase events server-side la GA4, independent de browser.
 * Bypass iOS Safari ITP / AdBlock / Netopia redirect / consent client-side.
 *
 * Config in wp-config.php:
 *   define('SAFEBIZ_GA4_MEASUREMENT_ID', 'G-XXXXXXXXXX');
 *   define('SAFEBIZ_GA4_API_SECRET', 'xxxxxxxxxxxxxxxxxxxx');
 *   define('SAFEBIZ_GA4_SEND_ITEMS', true);                  // false pentru Art. 9
 *   define('SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT', false);   // true pentru Art. 9 strict opt-in
 *
 * Order meta tracking:
 *   _safebiz_ga4_mp_status         : queued | sent | error | skipped_no_consent
 *   _safebiz_ga4_mp_queued_at      : timestamp marcaj queued
 *   _safebiz_ga4_mp_sent_at        : timestamp marcaj sent (204 response)
 *   _safebiz_ga4_mp_error          : mesaj eroare daca status=error
 *   _safebiz_ga4_refund_sent       : '1' daca refund event trimis
 *   _safebiz_ga4_refund_at         : timestamp refund trimis
 *   _safebiz_consent_state         : array consent snapshot (din checkout)
 *   _ga_client_id                  : _ga cookie snapshot (din checkout)
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
        echo '<div class="notice notice-error"><p><strong>SafeBiz Server-Side Tracking:</strong> SAFEBIZ_GA4_MEASUREMENT_ID si SAFEBIZ_GA4_API_SECRET trebuie definite in wp-config.php. Pluginul nu trimite nimic.</p></div>';
    }
});

// === Capture consent + _ga client_id la checkout ===
// Salveaza snapshot in order meta din $_COOKIE pentru consistency server-side.
add_action('woocommerce_checkout_create_order', 'safebiz_capture_tracking_context', 10, 2);
function safebiz_capture_tracking_context($order, $data) {
    // _ga cookie => client_id GA4 (format: GA1.2.XXXXXXXXXX.YYYYYYYYYY)
    if ( ! empty($_COOKIE['_ga']) ) {
        $ga_cookie = sanitize_text_field($_COOKIE['_ga']);
        $parts = explode('.', $ga_cookie);
        if ( count($parts) >= 4 ) {
            $client_id = $parts[2] . '.' . $parts[3];
            $order->update_meta_data('_ga_client_id', $client_id);
        }
    }

    // Consent snapshot — detectie generica pentru CMP-uri comune.
    // TODO per CMP: adapta cookie name pentru cazul concret.
    $consent_state = safebiz_detect_consent_state();
    if ( ! empty($consent_state) ) {
        $order->update_meta_data('_safebiz_consent_state', $consent_state);
    }
}

/**
 * Detectie generica consent state din cookies.
 * Returneaza array cu 'ad_user_data' si 'ad_personalization' (GRANTED/DENIED) sau array gol.
 *
 * Suporta:
 *   - Moove GDPR Cookie Compliance: cookie 'moove_gdpr_popup' (JSON)
 *   - SureCookie: cookie 'surecookie_consent' (JSON)
 *   - Cookiebot: cookie 'CookieConsent'
 *   - GDPR Cookie Consent (Webtoffee): 'cookielawinfo-checkbox-*'
 *   - Generic: cookie 'cookie_consent' = 'accepted'
 */
function safebiz_detect_consent_state() {
    $state = [];

    // Moove GDPR Cookie Compliance (folosit pe MPSS)
    // Cookie: moove_gdpr_popup = JSON {"strict":1,"thirdparty":1,"advanced":1}
    if ( ! empty($_COOKIE['moove_gdpr_popup']) ) {
        $raw = stripslashes($_COOKIE['moove_gdpr_popup']);
        $decoded = json_decode($raw, true);
        if ( is_array($decoded) ) {
            $thirdparty = ! empty($decoded['thirdparty']);
            $advanced   = ! empty($decoded['advanced']);
            // Strict per Codex review 2026-05-12: pentru Art. 9 doar 'thirdparty' (marketing)
            // contribuie la consent. 'advanced' poate semnifica analytics/functional,
            // NU justifica ad_personalization=GRANTED pentru adult shop.
            $state['ad_user_data']       = $thirdparty ? 'GRANTED' : 'DENIED';
            $state['ad_personalization'] = $thirdparty ? 'GRANTED' : 'DENIED';
            return $state;
        }
    }

    // SureCookie
    if ( ! empty($_COOKIE['surecookie_consent']) ) {
        $raw = stripslashes($_COOKIE['surecookie_consent']);
        $decoded = json_decode($raw, true);
        if ( is_array($decoded) ) {
            $state['ad_user_data']       = ! empty($decoded['marketing'] ?? $decoded['advertising'] ?? null) ? 'GRANTED' : 'DENIED';
            $state['ad_personalization'] = ! empty($decoded['personalization'] ?? null) ? 'GRANTED' : 'DENIED';
            return $state;
        }
    }

    // Cookiebot
    if ( ! empty($_COOKIE['CookieConsent']) ) {
        $raw = stripslashes($_COOKIE['CookieConsent']);
        if ( strpos($raw, 'marketing:true') !== false ) {
            $state['ad_user_data'] = 'GRANTED';
        } else {
            $state['ad_user_data'] = 'DENIED';
        }
        $state['ad_personalization'] = (strpos($raw, 'preferences:true') !== false) ? 'GRANTED' : 'DENIED';
        return $state;
    }

    // GDPR Cookie Consent (Webtoffee)
    if ( ! empty($_COOKIE['cookielawinfo-checkbox-advertisement']) ) {
        $state['ad_user_data']       = ($_COOKIE['cookielawinfo-checkbox-advertisement'] === 'yes') ? 'GRANTED' : 'DENIED';
        $state['ad_personalization'] = ! empty($_COOKIE['cookielawinfo-checkbox-functional']) && $_COOKIE['cookielawinfo-checkbox-functional'] === 'yes' ? 'GRANTED' : 'DENIED';
        return $state;
    }

    // Generic fallback
    if ( ! empty($_COOKIE['cookie_consent']) ) {
        $val = strtolower($_COOKIE['cookie_consent']);
        if ( in_array($val, ['accepted', 'granted', 'yes', 'true', '1'], true) ) {
            $state['ad_user_data']       = 'GRANTED';
            $state['ad_personalization'] = 'DENIED';  // conservative default
        }
    }

    return $state;
}

// === Hook tranzitii status — AMBELE processing + completed ===
add_action('woocommerce_order_status_processing', 'safebiz_ga4_server_purchase', 10, 1);
add_action('woocommerce_order_status_completed',  'safebiz_ga4_server_purchase', 10, 1);

function safebiz_ga4_server_purchase($order_id) {
    if ( ! defined('SAFEBIZ_GA4_MEASUREMENT_ID') || ! defined('SAFEBIZ_GA4_API_SECRET') ) {
        return;
    }

    $order = wc_get_order($order_id);
    if ( ! $order ) return;

    // FILTRU STATUS WHITELIST (fix bug #196 trash contamination)
    $valid_statuses = ['processing', 'completed'];
    if ( ! in_array($order->get_status(), $valid_statuses, true) ) {
        return;
    }

    // DEDUP cu status tracking (NU flag orb)
    $current_status = $order->get_meta('_safebiz_ga4_mp_status');
    if ( in_array($current_status, ['queued', 'sent', 'skipped_no_consent'], true) ) {
        return;
    }

    // CONSENT GATE — strict opt-in pentru Art. 9 sites
    $consent_state = $order->get_meta('_safebiz_consent_state');
    if ( defined('SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT') && SAFEBIZ_GA4_REQUIRE_EXPLICIT_CONSENT === true ) {
        if ( ! is_array($consent_state) || empty($consent_state['ad_user_data']) || $consent_state['ad_user_data'] !== 'GRANTED' ) {
            $order->update_meta_data('_safebiz_ga4_mp_status', 'skipped_no_consent');
            $order->update_meta_data('_safebiz_ga4_mp_queued_at', current_time('mysql'));
            $order->save();
            error_log('[SafeBiz-GA4] Order #' . $order->get_order_number() . ' SKIPPED — no explicit consent');
            return;
        }
    }

    // Marchez QUEUED inainte de POST (idempotency)
    $order->update_meta_data('_safebiz_ga4_mp_status', 'queued');
    $order->update_meta_data('_safebiz_ga4_mp_queued_at', current_time('mysql'));
    $order->save();

    // BUILD PAYLOAD
    // Default consent conservator per Codex review 2026-05-12:
    // Fara consent snapshot explicit, NU asumam GRANTED. Trimitem purchase pentru
    // analytics minim cu ad flags DENIED (mai sigur legal, evita marketing leak).
    $consent_to_send = is_array($consent_state) && ! empty($consent_state['ad_user_data'])
        ? $consent_state
        : [
            'ad_user_data'       => 'DENIED',
            'ad_personalization' => 'DENIED',
        ];

    $payload = [
        'client_id'        => safebiz_get_client_id($order),
        'consent'          => $consent_to_send,
        'timestamp_micros' => (int) ($order->get_date_created()->getTimestamp() * 1000000),
        'events'           => [[
            'name'   => 'purchase',
            'params' => safebiz_build_purchase_params($order),
        ]],
    ];

    // POST blocking pentru verificare response real
    // Endpoint regional EU per Codex review 2026-05-12 — mai aliniat stack EU-first
    $url = sprintf(
        'https://region1.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
        urlencode(SAFEBIZ_GA4_MEASUREMENT_ID),
        urlencode(SAFEBIZ_GA4_API_SECRET)
    );

    $response = wp_remote_post($url, [
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode($payload),
        'timeout'  => 10,
        'blocking' => true,
    ]);

    // VERIFICARE RESPONSE REAL
    if ( is_wp_error($response) ) {
        $order->update_meta_data('_safebiz_ga4_mp_status', 'error');
        $order->update_meta_data('_safebiz_ga4_mp_error', $response->get_error_message());
        error_log('[SafeBiz-GA4] Order #' . $order->get_order_number() . ' ERR: ' . $response->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ( $code === 204 ) {
            $order->update_meta_data('_safebiz_ga4_mp_status', 'sent');
            $order->update_meta_data('_safebiz_ga4_mp_sent_at', current_time('mysql'));
        } else {
            $order->update_meta_data('_safebiz_ga4_mp_status', 'error');
            $order->update_meta_data('_safebiz_ga4_mp_error', 'HTTP ' . $code . ' body: ' . substr(wp_remote_retrieve_body($response), 0, 200));
            error_log('[SafeBiz-GA4] Order #' . $order->get_order_number() . ' HTTP ' . $code);
        }
    }
    $order->save();
}

/**
 * Build params purchase — fara PII, configurabil pentru Art. 9 sites.
 */
function safebiz_build_purchase_params($order) {
    $params = [
        'transaction_id' => (string) $order->get_order_number(),
        'value'          => (float) wc_format_decimal($order->get_total(), 2),
        'currency'       => $order->get_currency(),
        'tax'            => (float) wc_format_decimal($order->get_total_tax(), 2),
        'shipping'       => (float) wc_format_decimal($order->get_shipping_total(), 2),
        'coupon'         => implode(',', $order->get_coupon_codes()),
    ];

    // Items array — EXCLUSE pentru site-uri Art. 9 (MPSS)
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

/**
 * Get client_id — incearca _ga cookie din order meta, fallback la hash.
 */
function safebiz_get_client_id($order) {
    $client_id = $order->get_meta('_ga_client_id');
    if ( ! empty($client_id) ) return $client_id;

    return wp_hash('order_' . $order->get_order_number());
}

// === Refund event la cancelled/refunded ===
add_action('woocommerce_order_status_refunded',  'safebiz_ga4_refund_event', 10, 1);
add_action('woocommerce_order_status_cancelled', 'safebiz_ga4_refund_event', 10, 1);

function safebiz_ga4_refund_event($order_id) {
    if ( ! defined('SAFEBIZ_GA4_MEASUREMENT_ID') || ! defined('SAFEBIZ_GA4_API_SECRET') ) {
        return;
    }

    $order = wc_get_order($order_id);
    if ( ! $order ) return;
    if ( $order->get_meta('_safebiz_ga4_mp_status') !== 'sent' ) return;
    if ( $order->get_meta('_safebiz_ga4_refund_sent') === '1' ) return;

    $consent_state = $order->get_meta('_safebiz_consent_state');
    // Default conservator per Codex review (vezi safebiz_ga4_server_purchase)
    $consent_to_send = is_array($consent_state) && ! empty($consent_state['ad_user_data'])
        ? $consent_state
        : [
            'ad_user_data'       => 'DENIED',
            'ad_personalization' => 'DENIED',
        ];

    $payload = [
        'client_id' => safebiz_get_client_id($order),
        'consent'   => $consent_to_send,
        'events'    => [[
            'name'   => 'refund',
            'params' => [
                'transaction_id' => (string) $order->get_order_number(),
                'value'          => (float) wc_format_decimal($order->get_total(), 2),
                'currency'       => $order->get_currency(),
            ],
        ]],
    ];

    // Endpoint regional EU per Codex review 2026-05-12 — mai aliniat stack EU-first
    $url = sprintf(
        'https://region1.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
        urlencode(SAFEBIZ_GA4_MEASUREMENT_ID),
        urlencode(SAFEBIZ_GA4_API_SECRET)
    );

    $response = wp_remote_post($url, [
        'headers'  => ['Content-Type' => 'application/json'],
        'body'     => wp_json_encode($payload),
        'timeout'  => 10,
        'blocking' => true,
    ]);

    if ( ! is_wp_error($response) && wp_remote_retrieve_response_code($response) === 204 ) {
        $order->update_meta_data('_safebiz_ga4_refund_sent', '1');
        $order->update_meta_data('_safebiz_ga4_refund_at', current_time('mysql'));
        $order->save();
    } else {
        $code = is_wp_error($response) ? 'WP_ERROR' : wp_remote_retrieve_response_code($response);
        error_log('[SafeBiz-GA4] Refund #' . $order->get_order_number() . ' FAIL HTTP ' . $code);
    }
}

// === Cron hourly — retry pentru status='error' ===
add_action('init', function() {
    if ( ! wp_next_scheduled('safebiz_ga4_retry_errors') ) {
        wp_schedule_event(time(), 'hourly', 'safebiz_ga4_retry_errors');
    }
});

add_action('safebiz_ga4_retry_errors', function() {
    $args = [
        'limit'      => 20,
        'status'     => ['wc-processing', 'wc-completed'],
        'meta_key'   => '_safebiz_ga4_mp_status',
        'meta_value' => 'error',
    ];
    $orders = wc_get_orders($args);
    foreach ( $orders as $order ) {
        // Reseteaza status pentru re-send
        $order->update_meta_data('_safebiz_ga4_mp_status', '');
        $order->save();
        safebiz_ga4_server_purchase($order->get_id());
    }
});

// === Cleanup la deactivation ===
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('safebiz_ga4_retry_errors');
});
