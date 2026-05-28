<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Woo_Odoo_Connector_Lite
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

function woo_odoo_lite_uninstall_blog() {
    $options_to_delete = [
        'woo_odoo_connector_settings',
        'woo_odoo_sync_log',
    ];

    foreach ($options_to_delete as $option) {
        delete_option($option);
    }

    $cron_hooks = [
        'woo_odoo_sync_products',
        'woo_odoo_sync_customers',
        'woo_odoo_sync_orders',
        'woo_odoo_sync_stock',
        'woo_odoo_cleanup_logs',
    ];

    foreach ($cron_hooks as $hook) {
        wp_clear_scheduled_hook($hook);
    }
}

if (!is_multisite()) {
    woo_odoo_lite_uninstall_blog();
} else {
    $blog_ids = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($blog_ids as $blog_id) {
        switch_to_blog((int) $blog_id);
        woo_odoo_lite_uninstall_blog();
        restore_current_blog();
    }
}
