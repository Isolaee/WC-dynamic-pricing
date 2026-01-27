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
    static $logged = false;
    $result = max(99, $hintapyynto * 0.05);
    if (!$logged) {
        $logged = true;
        wcdp_log("wcdp_calculate_price: input={$hintapyynto}, 5%=" . ($hintapyynto * 0.05) . ", result={$result}");
    }
    return $result;
}

/**
 * Filter the product price for product ID 773.
 * Hooked at priority 9999 to run after other plugins.
 */
function wcdp_dynamic_price($price, $product) {
    static $logged = [];

    $product_id = $product->get_id();
    $filter_name = current_filter();

    if ((int) $product_id !== 773) {
        return $price;
    }

    // Only log once per filter per request
    $log_key = $filter_name;
    $should_log = !isset($logged[$log_key]);
    if ($should_log) {
        $logged[$log_key] = true;
    }

    if ($should_log) {
        wcdp_log("[{$filter_name}] Triggered for product ID {$product_id}. Original price: {$price}");
    }

    // Check if ACF function is available
    if (!function_exists('get_field')) {
        if ($should_log) {
            wcdp_log("[{$filter_name}] ERROR: get_field() not available. ACF may not be loaded yet.");
        }
        return $price;
    }

    $raw_value = get_field('hintapyynto_ot', $product_id);
    if ($should_log) {
        wcdp_log("[{$filter_name}] ACF raw value for 'hintapyynto_ot' (post {$product_id}): " . var_export($raw_value, true));
    }

    $hintapyynto = (float) $raw_value;
    if ($hintapyynto <= 0) {
        if ($should_log) {
            wcdp_log("[{$filter_name}] hintapyynto_ot is <= 0 ({$hintapyynto}), returning original price.");
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
 * Filter variation prices (in case product 773 is variable).
 */
add_filter('woocommerce_product_variation_get_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_variation_get_regular_price', 'wcdp_dynamic_price', 9999, 2);
add_filter('woocommerce_product_variation_get_sale_price', 'wcdp_dynamic_price', 9999, 2);

/**
 * Filter the displayed price HTML to show the dynamic price.
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
        wcdp_log("[woocommerce_get_price_html] Triggered for product ID {$product_id}. Original HTML: {$price_html}");
    }

    if (!function_exists('get_field')) {
        if ($should_log) {
            wcdp_log("[woocommerce_get_price_html] ERROR: get_field() not available.");
        }
        return $price_html;
    }

    $raw_value = get_field('hintapyynto_ot', $product_id);
    if ($should_log) {
        wcdp_log("[woocommerce_get_price_html] ACF raw value: " . var_export($raw_value, true));
    }

    $hintapyynto = (float) $raw_value;
    if ($hintapyynto <= 0) {
        if ($should_log) {
            wcdp_log("[woocommerce_get_price_html] hintapyynto_ot is <= 0, returning original HTML.");
        }
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
 * Override the cart item price to ensure checkout uses the dynamic price.
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

    foreach ($cart_object->get_cart() as $cart_item) {
        $product_id = $cart_item['product_id'];

        if ((int) $product_id !== 773) {
            continue;
        }

        if ($should_log) {
            wcdp_log("[woocommerce_before_calculate_totals] Cart item found for product {$product_id}");
        }

        if (!function_exists('get_field')) {
            if ($should_log) {
                wcdp_log("[woocommerce_before_calculate_totals] ERROR: get_field() not available.");
            }
            continue;
        }

        $raw_value = get_field('hintapyynto_ot', $product_id);
        if ($should_log) {
            wcdp_log("[woocommerce_before_calculate_totals] ACF raw value: " . var_export($raw_value, true));
        }

        $hintapyynto = (float) $raw_value;
        if ($hintapyynto <= 0) {
            if ($should_log) {
                wcdp_log("[woocommerce_before_calculate_totals] hintapyynto_ot <= 0, skipping.");
            }
            continue;
        }

        $calculated = wcdp_calculate_price($hintapyynto);
        $cart_item['data']->set_price($calculated);
        if ($should_log) {
            wcdp_log("[woocommerce_before_calculate_totals] Set cart item price to {$calculated}");
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'wcdp_cart_item_price', 9999, 1);

/**
 * One-time diagnostic: dump all meta keys for product 773 on admin_init.
 * This runs once to help identify the correct meta key for hintapyynto_ot.
 * Check WooCommerce > Status > Logs > wcdp-debug for output.
 */
function wcdp_diagnose_meta() {
    $product_id = 773;

    // Only run this diagnostic once per deploy — use a transient as flag.
    if (get_transient('wcdp_diag_done')) {
        return;
    }
    set_transient('wcdp_diag_done', true, HOUR_IN_SECONDS);

    wcdp_log("=== DIAGNOSTIC START for post {$product_id} ===");

    // 1. Check post type
    $post_type = get_post_type($product_id);
    wcdp_log("Post type for {$product_id}: " . var_export($post_type, true));

    // 2. Check if WooCommerce uses HPOS (custom order tables) which stores product data differently
    wcdp_log("HPOS enabled: " . var_export(
        class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
            && method_exists('Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
        true
    ));

    // 3. Dump all post_meta keys for this product
    $all_meta = get_post_meta($product_id);
    if ($all_meta) {
        wcdp_log("All meta keys for post {$product_id}: " . implode(', ', array_keys($all_meta)));

        // Look for anything containing 'hinta'
        foreach ($all_meta as $key => $values) {
            if (stripos($key, 'hinta') !== false) {
                wcdp_log("  MATCH meta key '{$key}' => " . var_export($values, true));
            }
        }
    } else {
        wcdp_log("No post_meta found for post {$product_id}. Product may use custom tables (HPOS) or the post ID is wrong.");
    }

    // 4. Try get_field with different approaches
    if (function_exists('get_field')) {
        $v1 = get_field('hintapyynto_ot', $product_id);
        wcdp_log("get_field('hintapyynto_ot', {$product_id}) = " . var_export($v1, true));

        // ACF sometimes needs the post object instead of ID
        $v2 = get_field('hintapyynto_ot', get_post($product_id));
        wcdp_log("get_field('hintapyynto_ot', get_post({$product_id})) = " . var_export($v2, true));
    }

    // 5. Try direct post_meta access (bypassing ACF)
    $v3 = get_post_meta($product_id, 'hintapyynto_ot', true);
    wcdp_log("get_post_meta({$product_id}, 'hintapyynto_ot', true) = " . var_export($v3, true));

    // Also try with underscore prefix (ACF stores with _ prefix for internal reference)
    $v4 = get_post_meta($product_id, '_hintapyynto_ot', true);
    wcdp_log("get_post_meta({$product_id}, '_hintapyynto_ot', true) = " . var_export($v4, true));

    wcdp_log("=== DIAGNOSTIC END ===");
}
add_action('init', 'wcdp_diagnose_meta');
