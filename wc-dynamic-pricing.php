<?php
/**
 * Plugin Name: WC Dynamic Pricing
 * Description: Dynamic pricing for Osaketori-ilmoitus based on ACF field "hintaluokka".
 * Version: 4.0.0
 * Requires Plugins: woocommerce, advanced-custom-fields
 */

defined('ABSPATH') || exit;

/**
 * Plugin constants — defaults used as fallback if no DB option exists.
 */
define('WCDP_DEFAULT_PRODUCT_IDS', [773, 2834]);
define('WCDP_HINTALUOKKA_FIELD', 'hintaluokka');

/**
 * Get the hintaluokka price map from the database.
 * Returns associative array: ['<100 k€' => 0, '100-300k€' => 29, ...].
 */
function wcdp_get_hintaluokka_prices(): array {
    $saved = get_option('wcdp_hintaluokka_prices');
    if (!is_array($saved) || empty($saved)) {
        return [];
    }
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
 * Get all choices defined for the ACF field "hintaluokka".
 * Returns array of choice values, e.g. ['<100 k€', '100-300k€', ...].
 */
function wcdp_get_hintaluokka_choices(): array {
    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
        return [];
    }

    $groups = acf_get_field_groups();
    foreach ($groups as $group) {
        $fields = acf_get_fields($group['key']);
        if (!is_array($fields)) {
            continue;
        }
        foreach ($fields as $field) {
            if ($field['name'] === WCDP_HINTALUOKKA_FIELD && !empty($field['choices'])) {
                // ACF choices can be ['value' => 'label'] — return the values (keys).
                return array_keys($field['choices']);
            }
        }
    }

    return [];
}

/**
 * Calculate dynamic price based on hintaluokka value.
 */
function wcdp_calculate_price(string $hintaluokka): float {
    $prices = wcdp_get_hintaluokka_prices();
    if (isset($prices[$hintaluokka])) {
        return (float) $prices[$hintaluokka];
    }
    return 0.0;
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
 * Read hintaluokka from the listing post.
 * Returns empty string if not found.
 */
function wcdp_get_hintaluokka(int $listing_post_id): string {
    if ($listing_post_id <= 0) {
        return '';
    }
    if (!function_exists('get_field')) {
        return '';
    }
    $value = get_field(WCDP_HINTALUOKKA_FIELD, $listing_post_id);
    return is_string($value) ? trim($value) : '';
}

/* =============================================================================
   CART / CHECKOUT PRICE OVERRIDE
   The product page always shows the original WC product price.
   Dynamic pricing only applies inside the cart and checkout.
============================================================================= */

/**
 * Override the cart item price for target products during cart totals calculation.
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

    $hintaluokka = wcdp_get_hintaluokka($listing_id);
    if ($should_log) {
        wcdp_log("[cart_totals] Listing {$listing_id}, hintaluokka: {$hintaluokka}");
    }

    if ($hintaluokka === '') {
        if ($should_log) {
            wcdp_log("[cart_totals] hintaluokka is empty, skipping.");
        }
        return;
    }

    $calculated = wcdp_calculate_price($hintaluokka);

    foreach ($cart_object->get_cart() as $cart_item) {
        if (!in_array((int) $cart_item['product_id'], wcdp_get_target_product_ids(), true)) {
            continue;
        }
        $cart_item['data']->set_price($calculated);
        if ($should_log) {
            wcdp_log("[cart_totals] Set cart price to {$calculated} for product {$cart_item['product_id']} (listing {$listing_id}, hintaluokka: {$hintaluokka})");
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
        wcdp_log("[remove_cart_item] Target product removed from cart — clearing session.");
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
    $product_ids = wcdp_get_target_product_ids();
    $choices = wcdp_get_hintaluokka_choices();
    $prices = wcdp_get_hintaluokka_prices();
    ?>
    <h2><?php esc_html_e('Target Products', 'wc-dynamic-pricing'); ?></h2>
    <p><?php esc_html_e('WooCommerce product IDs that use dynamic pricing.', 'wc-dynamic-pricing'); ?></p>
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

    <h2><?php esc_html_e('Hintaluokka Pricing', 'wc-dynamic-pricing'); ?></h2>
    <?php if (empty($choices)) : ?>
        <p style="color:#a00;">
            <?php esc_html_e('Could not find ACF field "hintaluokka" or it has no choices defined. Please create the field first.', 'wc-dynamic-pricing'); ?>
        </p>
    <?php else : ?>
        <p><?php esc_html_e('Set the price for each hintaluokka choice. Choices are fetched automatically from the ACF field definition.', 'wc-dynamic-pricing'); ?></p>
        <table class="wc_input_table widefat" id="wcdp-hintaluokka-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Hintaluokka', 'wc-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Price (€)', 'wc-dynamic-pricing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($choices as $choice) : ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($choice); ?></strong>
                        <input type="hidden" name="wcdp_hl_choice[]" value="<?php echo esc_attr($choice); ?>" />
                    </td>
                    <td>
                        <input type="number" name="wcdp_hl_price[]" value="<?php echo esc_attr($prices[$choice] ?? 0); ?>" min="0" step="1" style="width:100%;" />
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

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
 * Save settings when the Dynamic Pricing tab is saved.
 */
add_action('woocommerce_update_options_wcdp_settings', function () {
    if (!isset($_POST['wcdp_settings_nonce']) || !wp_verify_nonce($_POST['wcdp_settings_nonce'], 'wcdp_save_settings')) {
        return;
    }

    // Save target product IDs.
    $product_ids = isset($_POST['wcdp_product_ids']) ? array_map('intval', $_POST['wcdp_product_ids']) : [];
    $product_ids = array_values(array_filter($product_ids, fn($id) => $id > 0));
    update_option('wcdp_target_product_ids', $product_ids);

    // Save hintaluokka prices.
    $hl_choices = isset($_POST['wcdp_hl_choice']) ? array_map('sanitize_text_field', $_POST['wcdp_hl_choice']) : [];
    $hl_prices  = isset($_POST['wcdp_hl_price'])  ? array_map('intval', $_POST['wcdp_hl_price'])              : [];

    $hintaluokka_prices = [];
    foreach ($hl_choices as $i => $choice) {
        $choice = trim($choice);
        if ($choice === '' || !isset($hl_prices[$i])) {
            continue;
        }
        $hintaluokka_prices[$choice] = max(0, $hl_prices[$i]);
    }
    update_option('wcdp_hintaluokka_prices', $hintaluokka_prices);

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
