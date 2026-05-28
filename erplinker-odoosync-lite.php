<?php
declare(strict_types=1);
/**
 * Plugin Name:       ERP Linker OdooSync Lite
 * Plugin URI:        https://erplinker.com/odoosync-lite
 * Description:       The essential bridge between WooCommerce and Odoo 18/19. Secure, reliable product, customer, order, and stock sync. HPOS-compatible. GDPR-ready. Upgrade to Pro for advanced features.
 * Version:           3.2.0
 * Author:            ERP Linker
 * Author URI:        https://erplinker.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       erplinker-odoosync-lite
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Tested up to:      6.9
 * Requires PHP:      8.0
 * WC requires at least: 6.0
 * WC tested up to:   10.6
 * Requires Plugins:  woocommerce
 */

defined('ABSPATH') || exit;

add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks', __FILE__, true
        );
    }
});

if (!defined('WOO_ODOO_LITE_VERSION')) {
    define('WOO_ODOO_LITE_VERSION', '3.2.0');
}
if (!defined('WOO_ODOO_LITE_PLUGIN_DIR')) {
    define('WOO_ODOO_LITE_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WOO_ODOO_LITE_PLUGIN_URL')) {
    define('WOO_ODOO_LITE_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('WOO_ODOO_CONNECTOR_VERSION')) {
    define('WOO_ODOO_CONNECTOR_VERSION', '3.2.0');
}
if (!defined('WOO_ODOO_CONNECTOR_PLUGIN_DIR')) {
    define('WOO_ODOO_CONNECTOR_PLUGIN_DIR', WOO_ODOO_LITE_PLUGIN_DIR);
}

function woo_odoo_lite_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('OdooSync Lite requires WooCommerce to be installed and active.', 'erplinker-odoosync-lite');
            echo '</p></div>';
        });
        return false;
    }
    return true;
}

function woo_odoo_lite_init() {
    load_plugin_textdomain('erplinker-odoosync-lite', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Bail if the Pro version is active — they share class names and cannot coexist.
    // Woo_Odoo_License is a Pro-only class; if it's defined, Pro is already loaded.
    if (class_exists('Woo_Odoo_License')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            esc_html_e('ERP Linker OdooSync Lite is inactive — the Pro version is already active and provides all lite features. You can safely deactivate the Lite version.', 'erplinker-odoosync-lite');
            echo '</p></div>';
        });
        return;
    }

    if (!woo_odoo_lite_check_dependencies()) {
        return;
    }

    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/exceptions/class-woo-odoo-exception.php';

    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-options.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-logger.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-encryption.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-connection-manager.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-odoo-api.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-privacy.php';

    Woo_Odoo_Privacy::init();

    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-sync.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-product-sync.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-customer-sync.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-order-sync.php';
    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-stock-sync.php';

    require_once WOO_ODOO_LITE_PLUGIN_DIR . 'includes/class-woo-odoo-hooks.php';
    new Woo_Odoo_Hooks();

    if (is_admin()) {
        require_once WOO_ODOO_LITE_PLUGIN_DIR . 'admin/class-woo-odoo-admin-lite.php';
        new Woo_Odoo_Admin_Lite();
    }

    add_action('woo_odoo_cleanup_logs', 'woo_odoo_lite_run_log_cleanup');
}
add_action('plugins_loaded', 'woo_odoo_lite_init');

function woo_odoo_lite_run_log_cleanup() {
    $log = get_option('woo_odoo_sync_log', []);
    if (!is_array($log)) {
        return;
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $log = array_filter($log, function ($entry) use ($cutoff) {
        $time = $entry['time'] ?? '';
        return $time && $time >= $cutoff;
    });
    $log = array_values(array_slice($log, -500));
    update_option('woo_odoo_sync_log', $log, false);
}

function woo_odoo_lite_activate() {
    if (!woo_odoo_lite_check_dependencies()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html__('OdooSync Lite requires WooCommerce.', 'erplinker-odoosync-lite'));
    }

    woo_odoo_lite_schedule_cron();

    if (!wp_next_scheduled('woo_odoo_cleanup_logs')) {
        wp_schedule_event(time(), 'daily', 'woo_odoo_cleanup_logs');
    }
}
register_activation_hook(__FILE__, 'woo_odoo_lite_activate');

function woo_odoo_lite_deactivate() {
    wp_clear_scheduled_hook('woo_odoo_sync_products');
    wp_clear_scheduled_hook('woo_odoo_sync_customers');
    wp_clear_scheduled_hook('woo_odoo_sync_orders');
    wp_clear_scheduled_hook('woo_odoo_sync_stock');
    wp_clear_scheduled_hook('woo_odoo_cleanup_logs');
}
register_deactivation_hook(__FILE__, 'woo_odoo_lite_deactivate');

function woo_odoo_lite_schedule_cron() {
    $opts = get_option('woo_odoo_connector_settings', []);
    $schedules = array_keys(wp_get_schedules());
    $products_freq = in_array($opts['cron_products_freq'] ?? '', $schedules, true) ? $opts['cron_products_freq'] : 'hourly';
    $customers_freq = in_array($opts['cron_customers_freq'] ?? '', $schedules, true) ? $opts['cron_customers_freq'] : 'hourly';
    $orders_freq = in_array($opts['cron_orders_freq'] ?? '', $schedules, true) ? $opts['cron_orders_freq'] : 'hourly';
    $stock_freq = in_array($opts['cron_stock_freq'] ?? '', $schedules, true) ? $opts['cron_stock_freq'] : 'hourly';

    foreach (['woo_odoo_sync_products', 'woo_odoo_sync_customers', 'woo_odoo_sync_orders', 'woo_odoo_sync_stock'] as $hook) {
        wp_clear_scheduled_hook($hook);
    }

    wp_schedule_event(time(), $products_freq, 'woo_odoo_sync_products');
    wp_schedule_event(time(), $customers_freq, 'woo_odoo_sync_customers');
    wp_schedule_event(time(), $orders_freq, 'woo_odoo_sync_orders');
    wp_schedule_event(time(), $stock_freq, 'woo_odoo_sync_stock');
}

function woo_odoo_lite_cron_intervals($schedules) {
    $schedules['fifteen_minutes'] = [
        'interval' => 900,
        'display'  => __('Every 15 Minutes', 'erplinker-odoosync-lite'),
    ];
    $schedules['thirty_minutes'] = [
        'interval' => 1800,
        'display'  => __('Every 30 Minutes', 'erplinker-odoosync-lite'),
    ];
    return $schedules;
}
add_filter('cron_schedules', 'woo_odoo_lite_cron_intervals');
