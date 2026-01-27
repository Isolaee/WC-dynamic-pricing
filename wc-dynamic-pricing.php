<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Dynamically calculates pricing for Osaketori-ilmoitus based on ACF field hintapyynto_ot.
 * Version: 1.0.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

/**
 * Calculate dynamic price: 5% of hintapyynto_ot, minimum 99€.
 */
function wcdp_calculate_price(float $hintapyynto): float {
    return max(99, $hintapyynto * 0.05);
}

/**
 * Filter the product price for product ID 773.
 */
function wcdp_dynamic_price($price, $product) {
    if ((int) $product->get_id() !== 773) {
        return $price;
    }

    $hintapyynto = (float) get_field('hintapyynto_ot', $product->get_id());
    if ($hintapyynto <= 0) {
        return $price;
    }

    return wcdp_calculate_price($hintapyynto);
}
add_filter('woocommerce_product_get_price', 'wcdp_dynamic_price', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'wcdp_dynamic_price', 10, 2);

/**
 * Filter the displayed price HTML to show the dynamic price.
 */
function wcdp_dynamic_price_html($price_html, $product) {
    if ((int) $product->get_id() !== 773) {
        return $price_html;
    }

    $hintapyynto = (float) get_field('hintapyynto_ot', $product->get_id());
    if ($hintapyynto <= 0) {
        return $price_html;
    }

    $calculated = wcdp_calculate_price($hintapyynto);
    return wc_price($calculated);
}
add_filter('woocommerce_get_price_html', 'wcdp_dynamic_price_html', 10, 2);
