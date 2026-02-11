<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Step-based pricing for Osaketori-ilmoitus based on ACF field hintapyynto.
 * Version: 3.0.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

/**
 * Plugin constants — defaults used as fallback if no DB option exists.
 */
define('WCDP_DEFAULT_PRODUCT_IDS', [773, 2834]);

/**
 * Default step-based pricing tiers (fallback if no DB option exists).
 * Each entry: [threshold, price] — sorted ascending by threshold.
 */
define('WCDP_DEFAULT_TIERS', [
    [0,       0],
    [100000,  29],
    [300000,  69],
    [600000,  129],
    [1000000, 199],
]);

/**
 * Get the pricing tiers from the database, falling back to defaults.
 * Returns array of [threshold, price] pairs sorted by threshold ascending.
 */
function wcdp_get_pricing_tiers(): array {
    $saved = get_option('wcdp_pricing_tiers');
    if (!is_array($saved) || empty($saved)) {
        return WCDP_DEFAULT_TIERS;
    }
    // Ensure sorted by threshold ascending.
    usort($saved, fn($a, $b) => $a[0] <=> $b[0]);
    return $saved;
}

/**
 * Get the target WooCommerce product IDs from the database, falling back to defaults.
 */
function wcdp_get_target_product_ids(): array {
    $saved = get_option('wcdp_target_product_ids');
    if (!is_array($saved) || empty($saved)) {
        return WCDP_DEFAULT_PRODUCT_IDS;
    }
    return array_map('intval', $saved);
}

/**
 * Get premium ACF fields from the database.
 * Returns array of ['field' => string, 'label' => string, 'price' => int].
 */
function wcdp_get_premium_fields(): array {
    $saved = get_option('wcdp_premium_fields');
    if (!is_array($saved) || empty($saved)) {
        return [];
    }
    return $saved;
}

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
    foreach (wcdp_get_pricing_tiers() as [$threshold, $tier_price]) {
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
        if (!in_array((int) $cart_item['product_id'], wcdp_get_target_product_ids(), true)) {
            continue;
        }
        $cart_item['data']->set_price($calculated);
        if ($should_log) {
            wcdp_log("[cart_totals] Set cart price to {$calculated} for product {$cart_item['product_id']} (listing {$listing_id})");
        }
    }
}
add_action('woocommerce_before_calculate_totals', 'wcdp_cart_item_price', 9999, 1);

/**
 * Add premium field fees as separate line items in the cart.
 */
add_action('woocommerce_cart_calculate_fees', function ($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    $premium_fields = wcdp_get_premium_fields();
    if (empty($premium_fields)) {
        return;
    }

    // Only add fees if a target product is in the cart.
    $has_target = false;
    foreach ($cart->get_cart() as $cart_item) {
        if (in_array((int) $cart_item['product_id'], wcdp_get_target_product_ids(), true)) {
            $has_target = true;
            break;
        }
    }
    if (!$has_target) {
        return;
    }

    $listing_id = wcdp_get_listing_post_id();
    if ($listing_id <= 0 || !function_exists('get_field')) {
        return;
    }

    foreach ($premium_fields as $pf) {
        $value = get_field($pf['field'], $listing_id);
        if (!empty($value)) {
            $cart->add_fee($pf['label'], (float) $pf['price']);
            wcdp_log("[premium_fee] Added fee '{$pf['label']}': {$pf['price']}€ (field '{$pf['field']}' is filled on listing {$listing_id})");
        }
    }
}, 9999, 1);

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
    if ($item && in_array((int) $item['product_id'], wcdp_get_target_product_ids(), true)) {
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
    $tiers = wcdp_get_pricing_tiers();
    $product_ids = wcdp_get_target_product_ids();
    ?>
    <h2><?php esc_html_e('Target Products', 'wc-dynamic-pricing'); ?></h2>
    <p><?php esc_html_e('WooCommerce product IDs that use dynamic pricing. Products must have the ACF field "hintapyyntö" on the associated listing.', 'wc-dynamic-pricing'); ?></p>
    <table class="wc_input_table widefat" id="wcdp-products-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Product ID', 'wc-dynamic-pricing'); ?></th>
                <th style="width:50px;">&nbsp;</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($product_ids as $pid) : ?>
            <tr>
                <td><input type="number" name="wcdp_product_ids[]" value="<?php echo esc_attr($pid); ?>" min="1" step="1" style="width:100%;" /></td>
                <td><a href="#" class="wcdp-remove-row" style="color:#a00;text-decoration:none;font-size:18px;" title="<?php esc_attr_e('Remove', 'wc-dynamic-pricing'); ?>">&times;</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">
                    <a href="#" id="wcdp-add-product" class="button"><?php esc_html_e('+ Add product', 'wc-dynamic-pricing'); ?></a>
                </td>
            </tr>
        </tfoot>
    </table>
    <h2><?php esc_html_e('Step-Based Pricing Tiers', 'wc-dynamic-pricing'); ?></h2>
    <p><?php esc_html_e('Set the price for each hintapyyntö threshold. Price applies when hintapyyntö is at or above the threshold.', 'wc-dynamic-pricing'); ?></p>
    <table class="wc_input_table widefat" id="wcdp-tiers-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Hintapyyntö from (€)', 'wc-dynamic-pricing'); ?></th>
                <th><?php esc_html_e('Price (€)', 'wc-dynamic-pricing'); ?></th>
                <th style="width:50px;">&nbsp;</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tiers as $i => [$threshold, $tier_price]) : ?>
            <tr>
                <td><input type="number" name="wcdp_tier_threshold[]" value="<?php echo esc_attr($threshold); ?>" min="0" step="1" style="width:100%;" /></td>
                <td><input type="number" name="wcdp_tier_price[]" value="<?php echo esc_attr($tier_price); ?>" min="0" step="1" style="width:100%;" /></td>
                <td><a href="#" class="wcdp-remove-row" style="color:#a00;text-decoration:none;font-size:18px;" title="<?php esc_attr_e('Remove', 'wc-dynamic-pricing'); ?>">&times;</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">
                    <a href="#" id="wcdp-add-tier" class="button"><?php esc_html_e('+ Add tier', 'wc-dynamic-pricing'); ?></a>
                </td>
            </tr>
        </tfoot>
    </table>
    <h2><?php esc_html_e('Premium Fields', 'wc-dynamic-pricing'); ?></h2>
    <p><?php esc_html_e('ACF fields that add an extra fee (separate line item) when filled on the listing.', 'wc-dynamic-pricing'); ?></p>
    <table class="wc_input_table widefat" id="wcdp-premium-table">
        <thead>
            <tr>
                <th><?php esc_html_e('ACF Field Name', 'wc-dynamic-pricing'); ?></th>
                <th><?php esc_html_e('Label', 'wc-dynamic-pricing'); ?></th>
                <th><?php esc_html_e('Price (€)', 'wc-dynamic-pricing'); ?></th>
                <th style="width:50px;">&nbsp;</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (wcdp_get_premium_fields() as $pf) : ?>
            <tr>
                <td><input type="text" name="wcdp_pf_field[]" value="<?php echo esc_attr($pf['field']); ?>" style="width:100%;" /></td>
                <td><input type="text" name="wcdp_pf_label[]" value="<?php echo esc_attr($pf['label']); ?>" style="width:100%;" /></td>
                <td><input type="number" name="wcdp_pf_price[]" value="<?php echo esc_attr($pf['price']); ?>" min="0" step="1" style="width:100%;" /></td>
                <td><a href="#" class="wcdp-remove-row" style="color:#a00;text-decoration:none;font-size:18px;" title="<?php esc_attr_e('Remove', 'wc-dynamic-pricing'); ?>">&times;</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">
                    <a href="#" id="wcdp-add-premium" class="button"><?php esc_html_e('+ Add field', 'wc-dynamic-pricing'); ?></a>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php wp_nonce_field('wcdp_save_settings', 'wcdp_settings_nonce'); ?>
    <script>
    jQuery(function($) {
        $('#wcdp-add-product').on('click', function(e) {
            e.preventDefault();
            var row = '<tr>' +
                '<td><input type="number" name="wcdp_product_ids[]" value="" min="1" step="1" style="width:100%;" /></td>' +
                '<td><a href="#" class="wcdp-remove-row" style="color:#a00;text-decoration:none;font-size:18px;" title="Remove">&times;</a></td>' +
                '</tr>';
            $('#wcdp-products-table tbody').append(row);
        });
        $('#wcdp-add-tier').on('click', function(e) {
            e.preventDefault();
            var row = '<tr>' +
                '<td><input type="number" name="wcdp_tier_threshold[]" value="0" min="0" step="1" style="width:100%;" /></td>' +
                '<td><input type="number" name="wcdp_tier_price[]" value="0" min="0" step="1" style="width:100%;" /></td>' +
                '<td><a href="#" class="wcdp-remove-row" style="color:#a00;text-decoration:none;font-size:18px;" title="Remove">&times;</a></td>' +
                '</tr>';
            $('#wcdp-tiers-table tbody').append(row);
        });
        $('#wcdp-add-premium').on('click', function(e) {
            e.preventDefault();
            var row = '<tr>' +
                '<td><input type="text" name="wcdp_pf_field[]" value="" style="width:100%;" /></td>' +
                '<td><input type="text" name="wcdp_pf_label[]" value="" style="width:100%;" /></td>' +
                '<td><input type="number" name="wcdp_pf_price[]" value="0" min="0" step="1" style="width:100%;" /></td>' +
                '<td><a href="#" class="wcdp-remove-row" style="color:#a00;text-decoration:none;font-size:18px;" title="Remove">&times;</a></td>' +
                '</tr>';
            $('#wcdp-premium-table tbody').append(row);
        });
        $(document).on('click', '.wcdp-remove-row', function(e) {
            e.preventDefault();
            $(this).closest('tr').remove();
        });
    });
    </script>
    <?php
});

/**
 * Save pricing tiers and premium fields when the Dynamic Pricing tab is saved.
 */
add_action('woocommerce_update_options_wcdp_settings', function () {
    if (!isset($_POST['wcdp_settings_nonce']) || !wp_verify_nonce($_POST['wcdp_settings_nonce'], 'wcdp_save_settings')) {
        return;
    }

    // Save target product IDs.
    $product_ids = isset($_POST['wcdp_product_ids']) ? array_map('intval', $_POST['wcdp_product_ids']) : [];
    $product_ids = array_values(array_filter($product_ids, fn($id) => $id > 0));
    update_option('wcdp_target_product_ids', $product_ids);

    // Save pricing tiers.
    $thresholds = isset($_POST['wcdp_tier_threshold']) ? array_map('intval', $_POST['wcdp_tier_threshold']) : [];
    $prices     = isset($_POST['wcdp_tier_price'])     ? array_map('intval', $_POST['wcdp_tier_price'])     : [];

    $tiers = [];
    foreach ($thresholds as $i => $threshold) {
        if (!isset($prices[$i])) {
            continue;
        }
        $tiers[] = [max(0, $threshold), max(0, $prices[$i])];
    }
    usort($tiers, fn($a, $b) => $a[0] <=> $b[0]);
    update_option('wcdp_pricing_tiers', $tiers);

    // Save premium fields.
    $fields = isset($_POST['wcdp_pf_field']) ? array_map('sanitize_text_field', $_POST['wcdp_pf_field']) : [];
    $labels = isset($_POST['wcdp_pf_label']) ? array_map('sanitize_text_field', $_POST['wcdp_pf_label']) : [];
    $pf_prices = isset($_POST['wcdp_pf_price']) ? array_map('intval', $_POST['wcdp_pf_price']) : [];

    $premium = [];
    foreach ($fields as $i => $field) {
        $field = trim($field);
        if ($field === '' || !isset($labels[$i]) || !isset($pf_prices[$i])) {
            continue;
        }
        $premium[] = [
            'field' => $field,
            'label' => trim($labels[$i]),
            'price' => max(0, $pf_prices[$i]),
        ];
    }
    update_option('wcdp_premium_fields', $premium);
});
