<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Dynamically calculates pricing for Osaketori-ilmoitus based on ACF field hintapyynto_ot.
 * Version: 2.1.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

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
 * Calculate dynamic price: 5% of hintapyynto_ot, minimum 99 EUR.
 */
function wcdp_calculate_price(float $hintapyynto): float {
    return max(99, $hintapyynto * 0.05);
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
 * Returns 0 if unavailable or if the cart doesn't contain product 773.
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
 * Read hintapyynto_ot from the listing post.
 * Returns 0.0 if not found or not positive.
 */
function wcdp_get_hintapyynto(int $listing_post_id): float {
    if ($listing_post_id <= 0) {
        return 0.0;
    }
    if (!function_exists('get_field')) {
        return 0.0;
    }
    return (float) get_field('hintapyynto_ot', $listing_post_id);
}

/* =============================================================================
   PRICE FILTERS
============================================================================= */

/**
 * Filter product price for product ID 773.
 * Reads hintapyynto_ot from the listing post stored in WC session.
 */
function wcdp_dynamic_price($price, $product) {
    static $logged = [];

    $product_id = $product->get_id();
    if ((int) $product_id !== 773) {
        return $price;
    }

    $filter_name = current_filter();
    $should_log = !isset($logged[$filter_name]);
    if ($should_log) {
        $logged[$filter_name] = true;
    }

    $listing_id = wcdp_get_listing_post_id();
    if ($should_log) {
        wcdp_log("[{$filter_name}] Product 773. Listing post ID: {$listing_id}. Original price: {$price}");
    }

    if ($listing_id <= 0) {
        return $price;
    }

    $hintapyynto = wcdp_get_hintapyynto($listing_id);
    if ($should_log) {
        wcdp_log("[{$filter_name}] hintapyynto_ot from listing {$listing_id}: {$hintapyynto}");
    }

    if ($hintapyynto <= 0) {
        return $price;
    }

    $calculated = wcdp_calculate_price($hintapyynto);
    if ($should_log) {
        wcdp_log("[{$filter_name}] Returning dynamic price: {$calculated} (was: {$price})");
    }
    return $calculated;
}
add_filter('woocommerce_product_get_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_get_regular_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_get_sale_price', 'wcdp_dynamic_price', 9999, 2);

/**
 * Filter the displayed price HTML for product 773.
 */
function wcdp_dynamic_price_html($price_html, $product) {
    static $logged = false;

    if ((int) $product->get_id() !== 773) {
        return $price_html;
    }

    $should_log = !$logged;
    if ($should_log) {
        $logged = true;
    }

    $listing_id = wcdp_get_listing_post_id();
    if ($listing_id <= 0) {
        return $price_html;
    }

    $hintapyynto = wcdp_get_hintapyynto($listing_id);
    if ($hintapyynto <= 0) {
        return $price_html;
    }

    $calculated = wcdp_calculate_price($hintapyynto);
    $new_html = wc_price($calculated);
    if ($should_log) {
        wcdp_log("[woocommerce_get_price_html] Listing {$listing_id}, price HTML: {$new_html}");
    }
    return $new_html;
}
add_filter('woocommerce_get_price_html', 'wcdp_dynamic_price_html', 9999, 2);

/**
 * Override the cart item price for product 773 during cart totals calculation.
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
        return;
    }

    $hintapyynto = wcdp_get_hintapyynto($listing_id);
    if ($should_log) {
        wcdp_log("[cart_totals] Listing {$listing_id}, hintapyynto_ot: {$hintapyynto}");
    }

    if ($hintapyynto <= 0) {
        return;
    }

    $calculated = wcdp_calculate_price($hintapyynto);

    foreach ($cart_object->get_cart() as $cart_item) {
        if ((int) $cart_item['product_id'] !== 773) {
            continue;
        }
        $cart_item['data']->set_price($calculated);
        if ($should_log) {
            wcdp_log("[cart_totals] Set cart price to {$calculated} for product 773");
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'wcdp_cart_item_price', 9999, 1);

/* =============================================================================
   SESSION CLEANUP — clear bv_pending_post_id after payment or cancellation
============================================================================= */

/**
 * Clear session after successful payment.
 * (BV Listing Manager also clears it, but this is a safety net.)
 */
add_action('woocommerce_payment_complete', function ($order_id) {
    wcdp_log("[payment_complete] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});
add_action('woocommerce_thankyou', function ($order_id) {
    wcdp_log("[thankyou] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});

/**
 * Clear session when order reaches a terminal failed/cancelled status.
 */
add_action('woocommerce_order_status_cancelled', function ($order_id) {
    wcdp_log("[order_cancelled] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});
add_action('woocommerce_order_status_failed', function ($order_id) {
    wcdp_log("[order_failed] Order {$order_id} — clearing session.");
    wcdp_clear_session();
});

/**
 * Clear session when the cart is emptied (user removes items or starts fresh).
 */
add_action('woocommerce_cart_emptied', function () {
    wcdp_log("[cart_emptied] Cart was emptied — clearing session.");
    wcdp_clear_session();
});

/**
 * When product 773 is specifically removed from the cart, clear the session
 * since the listing checkout flow was abandoned.
 */
add_action('woocommerce_remove_cart_item', function ($cart_item_key, $cart) {
    $item = $cart->get_cart_item($cart_item_key);
    if ($item && (int) $item['product_id'] === 773) {
        wcdp_log("[remove_cart_item] Product 773 removed from cart — clearing session.");
        wcdp_clear_session();
    }
}, 10, 2);
