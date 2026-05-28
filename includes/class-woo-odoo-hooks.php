<?php
/**
 * WooCommerce & WP Cron hooks for real-time and scheduled sync.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Hooks {

    public function __construct() {
        $settings = get_option('woo_odoo_connector_settings', []);

        // Cron handlers — always register (scheduled sync must run regardless of real-time)
        add_action('woo_odoo_sync_products', [$this, 'cron_sync_products']);
        add_action('woo_odoo_sync_customers', [$this, 'cron_sync_customers']);
        add_action('woo_odoo_sync_orders', [$this, 'cron_sync_orders']);
        add_action('woo_odoo_sync_stock', [$this, 'cron_sync_stock']);
        add_action('woo_odoo_import_products', [$this, 'cron_import_products']);
        add_action('woo_odoo_process_retry_queue', ['Woo_Odoo_Retry_Queue', 'process']);

        // Real-time hooks — only when enabled
        if (empty($settings['enable_realtime_sync'])) {
            return;
        }

        add_action('woocommerce_new_product', [$this, 'on_product_save'], 20, 1);
        add_action('woocommerce_update_product', [$this, 'on_product_save'], 20, 1);
        add_action('woocommerce_new_order', [$this, 'on_order_created'], 20, 2);
        add_action('woocommerce_order_status_changed', [$this, 'on_order_status_changed'], 20, 4);
        add_action('user_register', [$this, 'on_customer_created'], 20, 1);
        add_action('profile_update', [$this, 'on_customer_updated'], 20, 1);
    }

    /**
     * Sync product on save.
     */
    public function on_product_save($product_id) {
        $sync = new Woo_Odoo_Product_Sync();
        $sync->sync_product($product_id);
    }

    /**
     * Sync order on creation.
     */
    public function on_order_created($order_id, $order = null) {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if ($order) {
            $sync = new Woo_Odoo_Order_Sync();
            $sync->sync_order($order_id);
        }
    }

    /**
     * Sync order on status change.
     */
    public function on_order_status_changed($order_id, $old_status, $new_status, $order) {
        $sync = new Woo_Odoo_Order_Sync();
        $sync->sync_order($order_id);
    }

    /**
     * Sync customer on registration.
     */
    public function on_customer_created($user_id) {
        $sync = new Woo_Odoo_Customer_Sync();
        $sync->sync_customer($user_id);
    }

    /**
     * Sync customer on profile update.
     */
    public function on_customer_updated($user_id) {
        $sync = new Woo_Odoo_Customer_Sync();
        $sync->sync_customer($user_id);
    }

    /**
     * Cron: sync products.
     */
    public function cron_sync_products() {
        $sync = new Woo_Odoo_Product_Sync();
        $sync->sync_all_products(50);
    }

    /**
     * Cron: sync customers.
     */
    public function cron_sync_customers() {
        $sync = new Woo_Odoo_Customer_Sync();
        $sync->sync_all_customers(50);
    }

    /**
     * Cron: sync orders (last 30 min).
     */
    public function cron_sync_orders() {
        $sync = new Woo_Odoo_Order_Sync();
        $sync->sync_orders_since(gmdate('Y-m-d H:i:s', strtotime('-30 minutes')), 50);
    }

    /**
     * Cron: sync stock from Odoo.
     */
    public function cron_sync_stock() {
        $sync = new Woo_Odoo_Stock_Sync();
        $sync->sync_stock_from_odoo(100);
    }

    /**
     * Cron: import products from Odoo (when enable_sync_price).
     */
    public function cron_import_products() {
        $settings = get_option('woo_odoo_connector_settings', []);
        if (empty($settings['enable_sync_price']) && empty($settings['enable_sync_stock'])) {
            return;
        }
        $import = new Woo_Odoo_Product_Import();
        $import->import_from_odoo(
            50,
            !empty($settings['enable_sync_price']),
            !empty($settings['enable_sync_stock']),
            !empty($settings['exclude_pos_products'])
        );
    }
}
