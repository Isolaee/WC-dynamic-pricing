# WC Dynamic Pricing

A WordPress/WooCommerce plugin that dynamically calculates pricing for listing announcements based on ACF (Advanced Custom Fields) field values.

## Description

This plugin provides dynamic pricing functionality for a specific WooCommerce product (ID: 773) used for "Osaketori-ilmoitus" (share transfer announcements). The price is calculated as a percentage of the listing's asking price (`hintapyynto_ot` ACF field).

## Features

- **Dynamic Price Calculation**: Automatically calculates 5% of the asking price with a minimum of 99 EUR
- **Session-Based Pricing**: Uses WooCommerce sessions to track pending listings
- **Cart/Checkout Integration**: Prices are dynamically applied during cart totals calculation
- **Automatic Session Cleanup**: Clears session data after payment completion, order cancellation, or cart modifications
- **Debug Logging**: Comprehensive logging via WooCommerce logs (`wcdp-debug`)

## Requirements

- WordPress
- WooCommerce
- Advanced Custom Fields (ACF)

## How It Works

1. When a user submits a listing through the BV Listing Manager, the listing post ID is stored in the WooCommerce session as `bv_pending_post_id`
2. When the user proceeds to checkout with product 773, the plugin:
   - Retrieves the listing post ID from the session
   - Reads the `hintapyynto_ot` ACF field value from the listing
   - Calculates the dynamic price (5% of the value, minimum 99 EUR)
   - Overrides the cart item price accordingly
3. After payment or order cancellation, the session is automatically cleared

## Pricing Formula

```
price = max(99, hintapyynto_ot * 0.05)
```

- Minimum price: 99 EUR
- Percentage: 5% of the asking price

## Session Cleanup Triggers

The plugin clears the `bv_pending_post_id` session in the following scenarios:

- Payment completed
- Thank you page displayed
- Order cancelled
- Order failed
- Cart emptied
- Product 773 removed from cart
- Listing post already published (stale session)
- Listing post not found

## Debugging

Logs are written to WooCommerce logs under the source `wcdp-debug`. Access them via:

**WooCommerce > Status > Logs > wcdp-debug**

## Version

Current version: 2.2.0

## License

**PROPRIETARY LICENSE**

Copyright (c) 2024-2025. All rights reserved.

This software is proprietary and confidential. Unauthorized copying, modification, distribution, or use of this software, via any medium, is strictly prohibited without express written permission from the copyright holder.

This plugin is provided "as is" without warranty of any kind, express or implied.
