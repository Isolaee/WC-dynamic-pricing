<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Step-based pricing for Osaketori-ilmoitus based on ACF field hintapyynto.
 * Version: 3.0.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

/**
 * Plugin constants — adjust these values as needed.
 */
define('WCDP_TARGET_PRODUCT_IDS', [773, 2834]); // WooCommerce product IDs for dynamic pricing

/**
 * Step-based pricing tiers.
 * Each entry: [threshold, price] — sorted ascending by threshold.
 * Price is determined by the highest threshold that hintapyynto meets or exceeds.
 */
define('WCDP_PRICING_TIERS', [
    [0,       0],    // 0€ up to 100k€
    [100000,  29],   // 29€ from 100k€
    [300000,  69],   // 69€ from 300k€
    [600000,  129],  // 129€ from 600k€
    [1000000, 199],  // 199€ from 1M€
]);

/**
 * Log debug messages to WooCommerce > Status > Logs > wcdp-debug.
 */
function wcdp_log(string $message): void {
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->debug($message, ['source' => 'wcdp-debug']);
    } else {
        error_log('[WCDP] ' . $message);
    }
}

/**
 * Calculate dynamic price based on step-based pricing tiers.
 */
function wcdp_calculate_price(float $hintapyynto): float {
    $price = 0;
    foreach (WCDP_PRICING_TIERS as [$threshold, $tier_price]) {
        if ($hintapyynto >= $threshold) {
            $price = $tier_price;
        }
    }
    return (float) $price;
}

/**
 * Clear the bv_pending_post_id from the WC session.
 */
function wcdp_clear_session(): void {
    if (function_exists('WC') && WC()->session) {
        $old = WC()->session->get('bv_pending_post_id');
        if ($old) {
            WC()->session->__unset('bv_pending_post_id');
            wcdp_log("[session_clear] Cleared bv_pending_post_id (was: {$old})");
        }
    }
}

/**
 * Get the listing post ID from the WC session (set by BV Listing Manager
 * when the user goes through /process-listing).
 *
 * Returns 0 if unavailable or stale.
 */
function wcdp_get_listing_post_id(): int {
    if (!function_exists('WC') || !WC()->session) {
        return 0;
    }

    $listing_id = (int) WC()->session->get('bv_pending_post_id');
    if ($listing_id <= 0) {
        return 0;
    }

    // Verify the listing post still exists and is a valid draft/pending post.
    $post = get_post($listing_id);
    if (!$post || $post->post_type !== 'post') {
        wcdp_log("[get_listing_post_id] Post {$listing_id} not found or wrong type, clearing session.");
        wcdp_clear_session();
        return 0;
    }

    // If the listing is already published, the payment already went through —
    // the session is stale from a previous transaction.
    if ($post->post_status === 'publish') {
        wcdp_log("[get_listing_post_id] Post {$listing_id} is already published (stale session), clearing.");
        wcdp_clear_session();
        return 0;
    }

    return $listing_id;
}

/**
 * Read hintapyynto from the listing post.
 * Returns 0.0 if not found or not positive.
 */
function wcdp_get_hintapyynto(int $listing_post_id): float {
    if ($listing_post_id <= 0) {
        return 0.0;
    }
    if (!function_exists('get_field')) {
        return 0.0;
    }
    return (float) get_field('hintapyynto', $listing_post_id);
}

/* =============================================================================
   CART / CHECKOUT PRICE OVERRIDE
   The product page always shows the original WC product price.
   Dynamic pricing only applies inside the cart and checkout.
============================================================================= */

/**
 * Override the cart item price for product 773 during cart totals calculation.
 * This is the sole pricing hook — product page is never affected.
 */
function wcdp_cart_item_price($cart_object) {
    static $logged = false;

    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    $should_log = !$logged;
    if ($should_log) {
        $logged = true;
    }

    $listing_id = wcdp_get_listing_post_id();
    if ($listing_id <= 0) {
        if ($should_log) {
            wcdp_log("[cart_totals] No listing post ID in session, skipping.");
        }
        return;
    }

    $hintapyynto = wcdp_get_hintapyynto($listing_id);
    if ($should_log) {
        wcdp_log("[cart_totals] Listing {$listing_id}, hintapyynto: {$hintapyynto}");
    }

    if ($hintapyynto <= 0) {
        if ($should_log) {
            wcdp_log("[cart_totals] hintapyynto <= 0, skipping.");
        }
        return;
    }

    $calculated = wcdp_calculate_price($hintapyynto);

    foreach ($cart_object->get_cart() as $cart_item) {
        if (!in_array((int) $cart_item['product_id'], WCDP_TARGET_PRODUCT_IDS, true)) {
            continue;
        }
        $cart_item['data']->set_price($calculated);
        if ($should_log) {
            wcdp_log("[cart_totals] Set cart price to {$calculated} for product {$cart_item['product_id']} (listing {$listing_id})");
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'wcdp_cart_item_price', 9999, 1);

/* =============================================================================
   SESSION CLEANUP — clear bv_pending_post_id after payment or cancellation
============================================================================= */

add_action('woocommerce_payment_complete', function ($order_id) {
    wcdp_log("[payment_complete] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});
add_action('woocommerce_thankyou', function ($order_id) {
    wcdp_log("[thankyou] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});

add_action('woocommerce_order_status_cancelled', function ($order_id) {
    wcdp_log("[order_cancelled] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});
add_action('woocommerce_order_status_failed', function ($order_id) {
    wcdp_log("[order_failed] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});

add_action('woocommerce_cart_emptied', function () {
    wcdp_log("[cart_emptied] Cart was emptied — clearing session.");
    wcdp_clear_session();
});

add_action('woocommerce_remove_cart_item', function ($cart_item_key, $cart) {
    $item = $cart->get_cart_item($cart_item_key);
    if ($item && in_array((int) $item['product_id'], WCDP_TARGET_PRODUCT_IDS, true)) {
        wcdp_log("[remove_cart_item] Product 773 removed from cart — clearing session.");
        wcdp_clear_session();
    }
}, 10, 2);

/* =============================================================================
   ADMIN SETTINGS PAGE
   WooCommerce > Settings > Dynamic Pricing
============================================================================= */

/**
 * Add "Dynamic Pricing" tab to WooCommerce settings.
 */
add_filter('woocommerce_settings_tabs_array', function ($tabs) {
    $tabs['wcdp_settings'] = __('Dynamic Pricing', 'wc-dynamic-pricing');
    return $tabs;
}, 50);

/**
 * Output the settings fields for the Dynamic Pricing tab.
 */
add_action('woocommerce_settings_tabs_wcdp_settings', function () {
    woocommerce_admin_fields(wcdp_get_settings());
});

/**
 * Save the settings when the Dynamic Pricing tab is saved.
 */
add_action('woocommerce_update_options_wcdp_settings', function () {
    woocommerce_update_options(wcdp_get_settings());
});

/**
 * Define the settings fields for the Dynamic Pricing tab.
 */
function wcdp_get_settings(): array {
    $tier_desc = __('Step-based pricing tiers (based on hintapyyntö):', 'wc-dynamic-pricing') . '<br>';
    foreach (WCDP_PRICING_TIERS as [$threshold, $tier_price]) {
        $threshold_fmt = number_format($threshold, 0, ',', ' ');
        if ($threshold === 0) {
            $tier_desc .= "• {$tier_price}€ — under 100 000€<br>";
        } else {
            $tier_desc .= "• {$tier_price}€ — from {$threshold_fmt}€<br>";
        }
    }
    $tier_desc .= '<br>' . __('To change tiers, edit WCDP_PRICING_TIERS in the plugin code.', 'wc-dynamic-pricing');

    return [
        [
            'title' => __('Dynamic Pricing Settings', 'wc-dynamic-pricing'),
            'type'  => 'title',
            'desc'  => $tier_desc,
            'id'    => 'wcdp_settings_section',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'wcdp_settings_section',
        ],
    ];
}
