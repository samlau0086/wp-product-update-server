<?php
/**
 * Plugin Name:       WP Product Update Server
 * Description:       Provides update metadata for WooCommerce downloadable products and validates customer access.
 * Version:           1.0.0
 * Author:            Your Name
 * Text Domain:       product-update-server
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-product-update-server.php';

\Product_Update_Server\Plugin::instance();
