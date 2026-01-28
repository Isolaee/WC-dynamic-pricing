<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Dynamically calculates pricing for Osaketori-ilmoitus based on ACF field hintapyynto_ot.
 * Version: 2.2.0
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
        wcdp_log("[cart_totals] Listing {$listing_id}, hintapyynto_ot: {$hintapyynto}");
    }

    if ($hintapyynto <= 0) {
        if ($should_log) {
            wcdp_log("[cart_totals] hintapyynto_ot <= 0, skipping.");
        }
        return;
    }

    $calculated = wcdp_calculate_price($hintapyynto);

    foreach ($cart_object->get_cart() as $cart_item) {
        if ((int) $cart_item['product_id'] !== 773) {
            continue;
        }
        $cart_item['data']->set_price($calculated);
        if ($should_log) {
            wcdp_log("[cart_totals] Set cart price to {$calculated} for product 773 (listing {$listing_id})");
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
    if ($item && (int) $item['product_id'] === 773) {
        wcdp_log("[remove_cart_item] Product 773 removed from cart — clearing session.");
        wcdp_clear_session();
    }
}, 10, 2);
