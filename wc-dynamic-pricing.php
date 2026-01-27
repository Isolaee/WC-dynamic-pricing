<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Dynamically calculates pricing for Osaketori-ilmoitus based on ACF field hintapyynto_ot.
 * Version: 2.0.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

/**
 * Log debug messages to WooCommerce > Status > Logs > wcdp-debug.
 * Each message is logged only once per request via a static guard.
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
 * Get the listing post ID from the WC session (set by BV Listing Manager
 * when the user goes through /process-listing).
 *
 * Returns 0 if unavailable.
 */
function wcdp_get_listing_post_id(): int {
    if (!function_exists('WC') || !WC()->session) {
        return 0;
    }
    return (int) WC()->session->get('bv_pending_post_id');
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
        wcdp_log("[{$filter_name}] Product 773. Listing post ID from session: {$listing_id}. Original price: {$price}");
    }

    if ($listing_id <= 0) {
        if ($should_log) {
            wcdp_log("[{$filter_name}] No listing post ID in session, returning original price.");
        }
        return $price;
    }

    $hintapyynto = wcdp_get_hintapyynto($listing_id);
    if ($should_log) {
        wcdp_log("[{$filter_name}] hintapyynto_ot from listing post {$listing_id}: {$hintapyynto}");
    }

    if ($hintapyynto <= 0) {
        if ($should_log) {
            wcdp_log("[{$filter_name}] hintapyynto_ot <= 0, returning original price.");
        }
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

    $product_id = $product->get_id();
    if ((int) $product_id !== 773) {
        return $price_html;
    }

    $should_log = !$logged;
    if ($should_log) {
        $logged = true;
    }

    $listing_id = wcdp_get_listing_post_id();
    if ($should_log) {
        wcdp_log("[woocommerce_get_price_html] Product 773. Listing post ID: {$listing_id}");
    }

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
        wcdp_log("[woocommerce_get_price_html] Returning price HTML: {$new_html}");
    }
    return $new_html;
}
add_filter('woocommerce_get_price_html', 'wcdp_dynamic_price_html', 9999, 2);

/**
 * Override the cart item price for product 773 during cart totals calculation.
 * This ensures checkout/payment uses the dynamic price.
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
    if ($should_log) {
        wcdp_log("[woocommerce_before_calculate_totals] Listing post ID from session: {$listing_id}");
    }

    if ($listing_id <= 0) {
        if ($should_log) {
            wcdp_log("[woocommerce_before_calculate_totals] No listing post ID, skipping.");
        }
        return;
    }

    $hintapyynto = wcdp_get_hintapyynto($listing_id);
    if ($should_log) {
        wcdp_log("[woocommerce_before_calculate_totals] hintapyynto_ot from listing {$listing_id}: {$hintapyynto}");
    }

    if ($hintapyynto <= 0) {
        if ($should_log) {
            wcdp_log("[woocommerce_before_calculate_totals] hintapyynto_ot <= 0, skipping.");
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
            wcdp_log("[woocommerce_before_calculate_totals] Set cart price to {$calculated} for product 773");
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'wcdp_cart_item_price', 9999, 1);
