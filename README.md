# WP Product Update Server

This WordPress plugin provides a lightweight update server for WooCommerce downloadable products. It exposes REST API endpoints that your external update managers can query to determine if a customer is eligible to download the latest version of a plugin or theme purchased from your store.

## Installation

1. Copy the `product-update-server` directory into your WordPress site's `wp-content/plugins/` folder.
2. Activate **WP Product Update Server** from the WordPress admin Plugins screen.
3. Ensure WooCommerce is active. Optional: enable WooCommerce Memberships if you plan to validate membership access.

## Configuring Products

1. Edit a downloadable product in WooCommerce.
2. In the **General** tab, fill in the following custom fields:
   - **Plugin or Theme Slug** (`_plugin_name`): Unique identifier used by the update endpoint to match requests.
   - **Product Version** (`_version`): Current version number delivered to customers.
3. Add or update the product's downloadable files. The update server will use the most recently added file as the download package provided in the API response.

## REST API

All endpoints are prefixed with `/wp-json/product-update-server/v1`.

- `GET /products`
  - Returns the cached index of all downloadable products including `plugin_name`, `version`, `product_id`, and `last_updated`.
- `GET /products/{plugin_name}?customer_email=...&customer_id=...&membership_plan=...`
  - Returns the download payload for a specific product if the provided customer has access.
  - Provide at least one of the following query parameters:
    - `customer_email`
    - `customer_id`
    - `membership_plan` (requires WooCommerce Memberships and a logged customer ID)
  - Successful responses include the download URL, file name, version, product ID, and timestamp.

Use these endpoints within your custom update manager to verify access and fetch download information before delivering updates to clients.

## Caching & Index Refresh

The plugin caches the product index using WordPress transients. Adjust behavior under **WooCommerce → Product Updates**:

- **Cache Lifetime**: Configure how long the product index is cached (default 1 hour).
- **Automatic Index Refresh**: Enable an hourly cron job to rebuild the cache.
- **Rebuild Index Now**: Manually refresh the cache when product data changes.

## Access Validation

When a client requests an update, the plugin checks whether the user has purchased the product or has an active membership plan (if provided). Access is granted if any of these conditions are met. Ensure your external update client supplies the required identifiers for validation.

## Development Notes

- Activation builds the index and optionally schedules the cron job.
- Deactivation clears the scheduled event and transient cache.

