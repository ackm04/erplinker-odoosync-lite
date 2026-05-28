<?php
/**
 * Admin class for OdooSync Lite.
 *
 * @package Woo_Odoo_Connector_Lite
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Admin_Lite {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        add_action('wp_ajax_woo_odoo_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_woo_odoo_sync_products', [$this, 'ajax_sync_products']);
        add_action('wp_ajax_woo_odoo_sync_orders', [$this, 'ajax_sync_orders']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('OdooSync Lite', 'erplinker-odoosync-lite'),
            __('OdooSync Lite', 'erplinker-odoosync-lite'),
            'manage_woocommerce',
            'erplinker-odoosync-lite',
            [$this, 'render_dashboard'],
            'dashicons-update',
            58
        );

        add_submenu_page(
            'erplinker-odoosync-lite',
            __('Dashboard', 'erplinker-odoosync-lite'),
            __('Dashboard', 'erplinker-odoosync-lite'),
            'manage_woocommerce',
            'erplinker-odoosync-lite',
            [$this, 'render_dashboard']
        );

        add_submenu_page(
            'erplinker-odoosync-lite',
            __('Settings', 'erplinker-odoosync-lite'),
            __('Settings', 'erplinker-odoosync-lite'),
            'manage_woocommerce',
            'erplinker-odoosync-lite-settings',
            [$this, 'render_settings']
        );

        add_submenu_page(
            'erplinker-odoosync-lite',
            __('Upgrade to Pro', 'erplinker-odoosync-lite'),
            '<span style="color:#f39c12;">⭐ ' . __('Upgrade to Pro', 'erplinker-odoosync-lite') . '</span>',
            'manage_woocommerce',
            'erplinker-odoosync-upgrade',
            [$this, 'render_upgrade']
        );
    }

    public function register_settings() {
        register_setting('woo_odoo_lite_settings', 'woo_odoo_connector_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input) {
        $sanitized = [];

        $sanitized['odoo_url'] = isset($input['odoo_url']) ? esc_url_raw($input['odoo_url']) : '';
        $sanitized['odoo_db'] = isset($input['odoo_db']) ? sanitize_text_field($input['odoo_db']) : '';
        $sanitized['odoo_username'] = isset($input['odoo_username']) ? sanitize_text_field($input['odoo_username']) : '';
        
        if (!empty($input['odoo_password'])) {
            if (class_exists('Woo_Odoo_Encryption')) {
                $sanitized['odoo_password'] = Woo_Odoo_Encryption::encrypt($input['odoo_password']);
            } else {
                $sanitized['odoo_password'] = $input['odoo_password'];
            }
        } else {
            $old = get_option('woo_odoo_connector_settings', []);
            $sanitized['odoo_password'] = $old['odoo_password'] ?? '';
        }

        $sanitized['enable_sync_products'] = !empty($input['enable_sync_products']);
        $sanitized['enable_sync_customers'] = !empty($input['enable_sync_customers']);
        $sanitized['enable_sync_orders'] = !empty($input['enable_sync_orders']);
        $sanitized['enable_sync_stock'] = !empty($input['enable_sync_stock']);
        
        $sanitized['cron_products_freq'] = sanitize_key($input['cron_products_freq'] ?? 'hourly');
        $sanitized['cron_customers_freq'] = sanitize_key($input['cron_customers_freq'] ?? 'hourly');
        $sanitized['cron_orders_freq'] = sanitize_key($input['cron_orders_freq'] ?? 'hourly');
        $sanitized['cron_stock_freq'] = sanitize_key($input['cron_stock_freq'] ?? 'hourly');

        return $sanitized;
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'erplinker-odoosync-lite') === false) {
            return;
        }

        wp_enqueue_style(
            'woo-odoo-admin-lite',
            WOO_ODOO_LITE_PLUGIN_URL . 'admin/css/admin.css',
            [],
            WOO_ODOO_LITE_VERSION
        );

        wp_enqueue_script(
            'woo-odoo-admin-lite',
            WOO_ODOO_LITE_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            WOO_ODOO_LITE_VERSION,
            true
        );

        wp_localize_script('woo-odoo-admin-lite', 'wooOdooAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('woo_odoo_admin'),
            'strings' => [
                'testing'    => __('Testing connection...', 'erplinker-odoosync-lite'),
                'syncing'    => __('Syncing...', 'erplinker-odoosync-lite'),
                'success'    => __('Success!', 'erplinker-odoosync-lite'),
                'error'      => __('Error', 'erplinker-odoosync-lite'),
            ],
        ]);
    }

    public function render_dashboard() {
        $settings = get_option('woo_odoo_connector_settings', []);
        $is_connected = !empty($settings['odoo_url']) && !empty($settings['odoo_password']);
        ?>
        <div class="wrap woo-odoo-dashboard">
            <h1><?php esc_html_e('OdooSync Lite Dashboard', 'erplinker-odoosync-lite'); ?></h1>

            <div class="woo-odoo-lite-banner" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h2 style="margin: 0 0 10px 0; color: white;">⭐ <?php esc_html_e('Unlock Advanced Features with OdooSync Pro', 'erplinker-odoosync-lite'); ?></h2>
                <p style="margin: 0 0 15px 0;">
                    <?php esc_html_e('Get real-time sync, webhooks, field mapping, analytics, batch processing, and 20+ more features.', 'erplinker-odoosync-lite'); ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=erplinker-odoosync-upgrade')); ?>" 
                   class="button button-primary" style="background: white; color: #667eea; border: none;">
                    <?php esc_html_e('View Pro Features →', 'erplinker-odoosync-lite'); ?>
                </a>
            </div>

            <div class="woo-odoo-stats" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin: 20px 0;">
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0; color: #666; font-size: 14px;"><?php esc_html_e('Connection', 'erplinker-odoosync-lite'); ?></h3>
                    <p style="margin: 10px 0 0; font-size: 24px; font-weight: bold; color: <?php echo $is_connected ? '#48bb78' : '#e53e3e'; ?>">
                        <?php echo $is_connected ? esc_html__('Configured', 'erplinker-odoosync-lite') : esc_html__('Not Set', 'erplinker-odoosync-lite'); ?>
                    </p>
                </div>
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0; color: #666; font-size: 14px;"><?php esc_html_e('Products', 'erplinker-odoosync-lite'); ?></h3>
                    <p style="margin: 10px 0 0; font-size: 24px; font-weight: bold;">
                        <?php echo esc_html(wp_count_posts('product')->publish); ?>
                    </p>
                </div>
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0; color: #666; font-size: 14px;"><?php esc_html_e('Customers', 'erplinker-odoosync-lite'); ?></h3>
                    <p style="margin: 10px 0 0; font-size: 24px; font-weight: bold;">
                        <?php echo esc_html(count_users()['total_users']); ?>
                    </p>
                </div>
                <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h3 style="margin: 0; color: #666; font-size: 14px;"><?php esc_html_e('Orders', 'erplinker-odoosync-lite'); ?></h3>
                    <p style="margin: 10px 0 0; font-size: 24px; font-weight: bold;">
                        <?php echo esc_html(wc_orders_count('completed') + wc_orders_count('processing')); ?>
                    </p>
                </div>
            </div>

            <?php if ($is_connected): ?>
            <div class="woo-odoo-quick-actions" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin: 20px 0;">
                <h2><?php esc_html_e('Quick Actions', 'erplinker-odoosync-lite'); ?></h2>
                <p style="display: flex; gap: 10px;">
                    <button type="button" class="button button-primary" id="test-connection">
                        <?php esc_html_e('Test Connection', 'erplinker-odoosync-lite'); ?>
                    </button>
                    <button type="button" class="button" id="sync-products">
                        <?php esc_html_e('Sync Products', 'erplinker-odoosync-lite'); ?>
                    </button>
                    <button type="button" class="button" id="sync-orders">
                        <?php esc_html_e('Sync Orders', 'erplinker-odoosync-lite'); ?>
                    </button>
                </p>
                <div id="action-result" style="margin-top: 15px;"></div>
            </div>
            <?php else: ?>
            <div class="notice notice-warning" style="margin: 20px 0;">
                <p>
                    <?php 
                    printf(
                        esc_html__('Please %sconfigure your Odoo connection%s to start syncing.', 'erplinker-odoosync-lite'),
                        '<a href="' . esc_url(admin_url('admin.php?page=erplinker-odoosync-lite-settings')) . '">',
                        '</a>'
                    );
                    ?>
                </p>
            </div>
            <?php endif; ?>

            <div class="woo-odoo-lite-features" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2><?php esc_html_e('Lite Features', 'erplinker-odoosync-lite'); ?></h2>
                <ul style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                    <li>✅ <?php esc_html_e('Product sync (Odoo → WooCommerce)', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('Customer sync (WooCommerce → Odoo)', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('Order sync (WooCommerce → Odoo)', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('Stock sync (Odoo → WooCommerce)', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('Scheduled sync via WP-Cron', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('HPOS compatible', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('GDPR ready', 'erplinker-odoosync-lite'); ?></li>
                    <li>✅ <?php esc_html_e('Encrypted credentials', 'erplinker-odoosync-lite'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $settings = get_option('woo_odoo_connector_settings', []);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('OdooSync Lite Settings', 'erplinker-odoosync-lite'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('woo_odoo_lite_settings'); ?>
                
                <h2><?php esc_html_e('Odoo Connection', 'erplinker-odoosync-lite'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="odoo_url"><?php esc_html_e('Odoo URL', 'erplinker-odoosync-lite'); ?></label></th>
                        <td>
                            <input type="url" name="woo_odoo_connector_settings[odoo_url]" id="odoo_url" 
                                   value="<?php echo esc_attr($settings['odoo_url'] ?? ''); ?>" 
                                   class="regular-text" placeholder="https://your-odoo.com">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="odoo_db"><?php esc_html_e('Database Name', 'erplinker-odoosync-lite'); ?></label></th>
                        <td>
                            <input type="text" name="woo_odoo_connector_settings[odoo_db]" id="odoo_db" 
                                   value="<?php echo esc_attr($settings['odoo_db'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="odoo_username"><?php esc_html_e('Username', 'erplinker-odoosync-lite'); ?></label></th>
                        <td>
                            <input type="text" name="woo_odoo_connector_settings[odoo_username]" id="odoo_username" 
                                   value="<?php echo esc_attr($settings['odoo_username'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="odoo_password"><?php esc_html_e('Password / API Key', 'erplinker-odoosync-lite'); ?></label></th>
                        <td>
                            <input type="password" name="woo_odoo_connector_settings[odoo_password]" id="odoo_password" 
                                   value="" class="regular-text" placeholder="<?php echo !empty($settings['odoo_password']) ? '********' : ''; ?>">
                            <p class="description"><?php esc_html_e('Leave empty to keep existing password.', 'erplinker-odoosync-lite'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Sync Settings', 'erplinker-odoosync-lite'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Enable Sync', 'erplinker-odoosync-lite'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="woo_odoo_connector_settings[enable_sync_products]" value="1" 
                                       <?php checked(!empty($settings['enable_sync_products'])); ?>>
                                <?php esc_html_e('Products', 'erplinker-odoosync-lite'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="woo_odoo_connector_settings[enable_sync_customers]" value="1" 
                                       <?php checked(!empty($settings['enable_sync_customers'])); ?>>
                                <?php esc_html_e('Customers', 'erplinker-odoosync-lite'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="woo_odoo_connector_settings[enable_sync_orders]" value="1" 
                                       <?php checked(!empty($settings['enable_sync_orders'])); ?>>
                                <?php esc_html_e('Orders', 'erplinker-odoosync-lite'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="woo_odoo_connector_settings[enable_sync_stock]" value="1" 
                                       <?php checked(!empty($settings['enable_sync_stock'])); ?>>
                                <?php esc_html_e('Stock Levels', 'erplinker-odoosync-lite'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_upgrade() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Upgrade to OdooSync Pro', 'erplinker-odoosync-lite'); ?></h1>
            
            <div style="max-width: 800px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px; border-radius: 12px; margin: 20px 0;">
                    <h2 style="color: white; margin-top: 0;"><?php esc_html_e('Unlock the Full Power of OdooSync', 'erplinker-odoosync-lite'); ?></h2>
                    <p style="font-size: 18px; opacity: 0.9;">
                        <?php esc_html_e('Get enterprise-grade features trusted by hundreds of businesses worldwide.', 'erplinker-odoosync-lite'); ?>
                    </p>
                    <a href="https://erplinker.com/odoosync-pro" target="_blank" class="button button-primary" 
                       style="background: white; color: #667eea; border: none; font-size: 16px; padding: 12px 24px;">
                        <?php esc_html_e('Get OdooSync Pro', 'erplinker-odoosync-lite'); ?> →
                    </a>
                </div>

                <h2><?php esc_html_e('Pro Features', 'erplinker-odoosync-lite'); ?></h2>
                
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px;">
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>🔄 <?php esc_html_e('Real-time Sync', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('Instant bidirectional sync with webhooks and SSE support.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>📊 <?php esc_html_e('Analytics Dashboard', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('Detailed sync statistics, health scoring, and performance metrics.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>🗺️ <?php esc_html_e('Advanced Field Mapping', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('15+ transformations, conditional logic, and formula support.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>📦 <?php esc_html_e('Batch Processing', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('Process thousands of records with progress tracking.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>💳 <?php esc_html_e('Refunds & Payments', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('Sync refunds, credit notes, and payment methods.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>🏭 <?php esc_html_e('MRP & Manufacturing', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('Production orders, BOMs, and lot tracking support.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>🔌 <?php esc_html_e('9+ Integrations', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('ShipStation, Stripe, Mailchimp, QuickBooks, and more.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                    <div style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <h3>🛡️ <?php esc_html_e('Enterprise Security', 'erplinker-odoosync-lite'); ?></h3>
                        <p><?php esc_html_e('PBKDF2 encryption, HMAC webhooks, circuit breaker.', 'erplinker-odoosync-lite'); ?></p>
                    </div>
                </div>

                <div style="background: #f7fafc; padding: 20px; border-radius: 8px; margin-top: 30px; text-align: center;">
                    <p style="font-size: 18px; margin: 0 0 15px;">
                        <?php esc_html_e('Ready to upgrade?', 'erplinker-odoosync-lite'); ?>
                    </p>
                    <a href="https://erplinker.com/odoosync-pro" target="_blank" class="button button-primary button-hero">
                        <?php esc_html_e('Get OdooSync Pro Now', 'erplinker-odoosync-lite'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function ajax_test_connection() {
        check_ajax_referer('woo_odoo_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'erplinker-odoosync-lite')]);
        }

        try {
            $settings = get_option('woo_odoo_connector_settings', []);
            $api = Woo_Odoo_Connection_Manager::get_connection($settings);

            if (is_wp_error($api)) {
                wp_send_json_error(['message' => $api->get_error_message()]);
            }

            $version = $api->version();
            wp_send_json_success([
                'message' => sprintf(__('Connected to Odoo %s', 'erplinker-odoosync-lite'), $version),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_sync_products() {
        check_ajax_referer('woo_odoo_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'erplinker-odoosync-lite')]);
        }

        try {
            $sync = new Woo_Odoo_Product_Sync();
            $result = $sync->sync_all();
            wp_send_json_success(['message' => __('Product sync completed', 'erplinker-odoosync-lite')]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajax_sync_orders() {
        check_ajax_referer('woo_odoo_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Unauthorized', 'erplinker-odoosync-lite')]);
        }

        try {
            $sync = new Woo_Odoo_Order_Sync();
            $result = $sync->sync_all();
            wp_send_json_success(['message' => __('Order sync completed', 'erplinker-odoosync-lite')]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
