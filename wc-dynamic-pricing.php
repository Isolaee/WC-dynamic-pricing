<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Dynamically calculates pricing for Osaketori-ilmoitus based on ACF field hintapyynto_ot.
 * Version: 1.1.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

/**
 * Log debug messages to the WooCommerce log (or default PHP error log).
 * View logs at WooCommerce > Status > Logs > wcdp-debug.
 */
function wcdp_log(string $message): void {
    if (function_exists('wc_get_logger')) {
        wc_get_logger()->debug($message, ['source' => 'wcdp-debug']);
    } else {
        error_log('[WCDP] ' . $message);
    }
}

/**
 * Calculate dynamic price: 5% of hintapyynto_ot, minimum 99€.
 */
function wcdp_calculate_price(float $hintapyynto): float {
    $result = max(99, $hintapyynto * 0.05);
    wcdp_log("wcdp_calculate_price: input={$hintapyynto}, 5%=" . ($hintapyynto * 0.05) . ", result={$result}");
    return $result;
}

/**
 * Filter the product price for product ID 773.
 * Hooked at priority 9999 to run after other plugins.
 */
function wcdp_dynamic_price($price, $product) {
    $product_id = $product->get_id();
    $filter_name = current_filter();

    if ((int) $product_id !== 773) {
        return $price;
    }

    wcdp_log("[{$filter_name}] Triggered for product ID {$product_id}. Original price: {$price}");

    // Check if ACF function is available
    if (!function_exists('get_field')) {
        wcdp_log("[{$filter_name}] ERROR: get_field() not available. ACF may not be loaded yet.");
        return $price;
    }

    $raw_value = get_field('hintapyynto_ot', $product_id);
    wcdp_log("[{$filter_name}] ACF raw value for 'hintapyynto_ot' (post {$product_id}): " . var_export($raw_value, true));

    $hintapyynto = (float) $raw_value;
    if ($hintapyynto <= 0) {
        wcdp_log("[{$filter_name}] hintapyynto_ot is <= 0 ({$hintapyynto}), returning original price.");
        return $price;
    }

    $calculated = wcdp_calculate_price($hintapyynto);
    wcdp_log("[{$filter_name}] Returning dynamic price: {$calculated} (was: {$price})");
    return $calculated;
}
add_filter('woocommerce_product_get_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_get_regular_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_get_sale_price', 'wcdp_dynamic_price', 9999, 2);

/**
 * Filter variation prices (in case product 773 is variable).
 */
add_filter('woocommerce_product_variation_get_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_variation_get_sale_price', 'wcdp_dynamic_price', 9999, 2);

/**
 * Filter the displayed price HTML to show the dynamic price.
 */
function wcdp_dynamic_price_html($price_html, $product) {
    $product_id = $product->get_id();

    if ((int) $product_id !== 773) {
        return $price_html;
    }

    wcdp_log("[woocommerce_get_price_html] Triggered for product ID {$product_id}. Original HTML: {$price_html}");

    if (!function_exists('get_field')) {
        wcdp_log("[woocommerce_get_price_html] ERROR: get_field() not available.");
        return $price_html;
    }

    $raw_value = get_field('hintapyynto_ot', $product_id);
    wcdp_log("[woocommerce_get_price_html] ACF raw value: " . var_export($raw_value, true));

    $hintapyynto = (float) $raw_value;
    if ($hintapyynto <= 0) {
        wcdp_log("[woocommerce_get_price_html] hintapyynto_ot is <= 0, returning original HTML.");
        return $price_html;
    }

    $calculated = wcdp_calculate_price($hintapyynto);
    $new_html = wc_price($calculated);
    wcdp_log("[woocommerce_get_price_html] Returning price HTML: {$new_html}");
    return $new_html;
}
add_filter('woocommerce_get_price_html', 'wcdp_dynamic_price_html', 9999, 2);

/**
 * Override the cart item price to ensure checkout uses the dynamic price.
 */
function wcdp_cart_item_price($cart_object) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    foreach ($cart_object->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];

        if ((int) $product_id !== 773) {
            continue;
        }

        wcdp_log("[woocommerce_before_calculate_totals] Cart item found for product {$product_id}");

        if (!function_exists('get_field')) {
            wcdp_log("[woocommerce_before_calculate_totals] ERROR: get_field() not available.");
            continue;
        }

        $raw_value = get_field('hintapyynto_ot', $product_id);
        wcdp_log("[woocommerce_before_calculate_totals] ACF raw value: " . var_export($raw_value, true));

        $hintapyynto = (float) $raw_value;
        if ($hintapyynto <= 0) {
            wcdp_log("[woocommerce_before_calculate_totals] hintapyynto_ot <= 0, skipping.");
            continue;
        }

        $calculated = wcdp_calculate_price($hintapyynto);
        $cart_item['data']->set_price($calculated);
        wcdp_log("[woocommerce_before_calculate_totals] Set cart item price to {$calculated}");
    }
}
add_action('woocommerce_before_calculate_totals', 'wcdp_cart_item_price', 9999, 1);
