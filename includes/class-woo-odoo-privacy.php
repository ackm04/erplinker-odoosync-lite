<?php
/**
 * GDPR Privacy Compliance
 *
 * Handles data export and erasure for GDPR compliance.
 *
 * @package Woo_Odoo_Connector
 * @since 2.5.1
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

class Woo_Odoo_Privacy {
    
    /**
     * Initialize privacy hooks.
     */
    public static function init(): void {
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'register_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'register_erasers']);
        add_action('admin_init', [__CLASS__, 'add_privacy_policy']);
    }
    
    /**
     * Register data exporter.
     *
     * @param array $exporters Existing exporters
     * @return array Modified exporters
     */
    public static function register_exporters(array $exporters): array {
        $exporters['erplinker-odoosync'] = [
            'exporter_friendly_name' => __('OdooSync for WooCommerce', 'erplinker-odoosync-lite'),
            'callback' => [__CLASS__, 'export_personal_data'],
        ];
        return $exporters;
    }
    
    /**
     * Export personal data.
     *
     * @param string $email Email address
     * @param int $page Page number
     * @return array Export data
     */
    public static function export_personal_data(string $email, int $page = 1): array {
        $data_to_export = [];
        
        // Get user by email
        $user = get_user_by('email', $email);
        if (!$user) {
            return [
                'data' => [],
                'done' => true,
            ];
        }
        
        $billing = [];
        if (function_exists('WC')) {
            $customer = new WC_Customer($user->ID);
            $billing = [
                'first_name' => $customer->get_billing_first_name(),
                'last_name' => $customer->get_billing_last_name(),
                'company' => $customer->get_billing_company(),
                'address_1' => $customer->get_billing_address_1(),
                'address_2' => $customer->get_billing_address_2(),
                'city' => $customer->get_billing_city(),
                'state' => $customer->get_billing_state(),
                'postcode' => $customer->get_billing_postcode(),
                'country' => $customer->get_billing_country(),
            ];
        }

        $data_to_export[] = [
            'group_id' => 'woo-odoo-profile',
            'group_label' => __('Profile (WooCommerce)', 'erplinker-odoosync-lite'),
            'item_id' => 'profile-' . $user->ID,
            'data' => [
                ['name' => __('Name', 'erplinker-odoosync-lite'), 'value' => $user->display_name],
                ['name' => __('Email', 'erplinker-odoosync-lite'), 'value' => $user->user_email],
                ['name' => __('Phone', 'erplinker-odoosync-lite'), 'value' => get_user_meta($user->ID, 'billing_phone', true) ?: '—'],
                ['name' => __('Billing address', 'erplinker-odoosync-lite'), 'value' => implode(', ', array_filter($billing)) ?: '—'],
            ],
        ];

        $odoo_customer_id = get_user_meta($user->ID, '_odoo_customer_id', true);
        $last_sync = get_user_meta($user->ID, '_odoo_last_sync', true);
        $data_to_export[] = [
            'group_id' => 'woo-odoo-sync',
            'group_label' => __('Odoo Sync Data', 'erplinker-odoosync-lite'),
            'item_id' => 'odoo-customer-' . $user->ID,
            'data' => [
                ['name' => __('Odoo Customer ID', 'erplinker-odoosync-lite'), 'value' => $odoo_customer_id ?: '—'],
                ['name' => __('Last Synced', 'erplinker-odoosync-lite'), 'value' => $last_sync ? gmdate('Y-m-d H:i:s', (int) $last_sync) : __('Never', 'erplinker-odoosync-lite')],
            ],
        ];

        $orders = wc_get_orders([
            'customer_id' => $user->ID,
            'limit' => 100,
            'page' => $page,
        ]);

        foreach ($orders as $order) {
            $odoo_order_id = $order->get_meta('_odoo_order_id');
            $sync_date = $order->get_meta('_odoo_last_sync');
            $data_to_export[] = [
                'group_id' => 'woo-odoo-orders',
                'group_label' => __('Orders synced to Odoo', 'erplinker-odoosync-lite'),
                'item_id' => 'odoo-order-' . $order->get_id(),
                'data' => [
                    ['name' => __('Order ID', 'erplinker-odoosync-lite'), 'value' => $order->get_order_number()],
                    ['name' => __('Date', 'erplinker-odoosync-lite'), 'value' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i') : '—'],
                    ['name' => __('Total', 'erplinker-odoosync-lite'), 'value' => $order->get_total() . ' ' . $order->get_currency()],
                    ['name' => __('Odoo Order ID', 'erplinker-odoosync-lite'), 'value' => $odoo_order_id ?: '—'],
                    ['name' => __('Sync Date', 'erplinker-odoosync-lite'), 'value' => $sync_date ?: '—'],
                ],
            ];
        }

        $done = count($orders) < 100;
        
        return [
            'data' => $data_to_export,
            'done' => $done,
        ];
    }
    
    /**
     * Register data eraser.
     *
     * @param array $erasers Existing erasers
     * @return array Modified erasers
     */
    public static function register_erasers(array $erasers): array {
        $erasers['erplinker-odoosync'] = [
            'eraser_friendly_name' => __('OdooSync for WooCommerce', 'erplinker-odoosync-lite'),
            'callback' => [__CLASS__, 'erase_personal_data'],
        ];
        return $erasers;
    }
    
    /**
     * Erase personal data.
     *
     * @param string $email Email address
     * @param int $page Page number
     * @return array Erasure results
     */
    public static function erase_personal_data(string $email, int $page = 1): array {
        $items_removed = false;
        $items_retained = false;
        $messages = [];

        $user = get_user_by('email', $email);
        if (!$user) {
            return [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [],
                'done' => true,
            ];
        }

        $odoo_id = get_user_meta($user->ID, '_odoo_customer_id', true);

        if ($odoo_id) {
            $settings = get_option('woo_odoo_connector_settings', []);

            if (empty($settings['odoo_url'])) {
                // Odoo not configured — safe to remove local record immediately.
                delete_user_meta($user->ID, '_odoo_customer_id');
                delete_user_meta($user->ID, '_odoo_last_sync');
                $items_removed = true;
                $messages[] = __('Odoo sync data removed', 'erplinker-odoosync-lite');
                Woo_Odoo_Logger::purge_logs_for_email($email);
            } elseif (class_exists('Woo_Odoo_Connection_Manager')) {
                $odoo_anonymized = false;
                try {
                    $api = Woo_Odoo_Connection_Manager::get_connection($settings);
                    if (!is_wp_error($api)) {
                        $api->write('res.partner', [(int) $odoo_id], [
                            'name'   => 'ANONYMIZED',
                            'email'  => '',
                            'phone'  => '',
                            'street' => '',
                        ]);
                        $odoo_anonymized = true;
                    }
                } catch (Exception $e) {
                    // Odoo unreachable; defer deletion so the next run can retry.
                    $odoo_anonymized = false;
                }

                if ($odoo_anonymized) {
                    // Odoo success: remove all local Odoo identifiers and purge logs.
                    delete_user_meta($user->ID, '_odoo_customer_id');
                    delete_user_meta($user->ID, '_odoo_last_sync');
                    $items_removed = true;
                    $messages[] = __('Odoo sync data removed', 'erplinker-odoosync-lite');
                    Woo_Odoo_Logger::purge_logs_for_email($email);
                } else {
                    // Odoo failure: retain both meta keys so the eraser can retry.
                    $items_retained = true;
                    $messages[] = __('Odoo anonymization pending — retry when Odoo connection is restored.', 'erplinker-odoosync-lite');
                }
            }
        } else {
            // No Odoo record — _odoo_last_sync is safe to remove immediately.
            delete_user_meta($user->ID, '_odoo_last_sync');
            $items_removed = true;
            $messages[] = __('Odoo sync data removed', 'erplinker-odoosync-lite');
            Woo_Odoo_Logger::purge_logs_for_email($email);
        }

        // Erase Odoo order meta only when not retaining the user record.
        if (!$items_retained) {
            $orders = wc_get_orders([
                'customer_id' => $user->ID,
                'limit'       => 100,
                'page'        => $page,
            ]);

            foreach ($orders as $order) {
                $order->delete_meta_data('_odoo_order_id');
                $order->delete_meta_data('_odoo_last_sync');
                $order->delete_meta_data('_odoo_sync_error');
                $order->save();
                $items_removed = true;
            }

            $done = count($orders) < 100;
        } else {
            $orders = [];
            $done   = true;
        }

        if ($done && !$items_retained) {
            // Log by user_id only — never log the email address after erasure.
            Woo_Odoo_Logger::info('GDPR data erasure completed', ['user_id' => $user->ID]);
        }

        return [
            'items_removed' => $items_removed,
            'items_retained' => $items_retained,
            'messages'       => $messages,
            'done'           => $done,
        ];
    }
    
    /**
     * Add privacy policy content.
     */
    public static function add_privacy_policy(): void {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        
        $content = sprintf(
            '<h2>%s</h2><p>%s</p><h3>%s</h3><ul><li>%s</li><li>%s</li><li>%s</li></ul><h3>%s</h3><p>%s</p><h3>%s</h3><p>%s</p>',
            __('OdooSync for WooCommerce', 'erplinker-odoosync-lite'),
            __('This plugin synchronizes your store data with Odoo ERP system.', 'erplinker-odoosync-lite'),
            __('What data we collect', 'erplinker-odoosync-lite'),
            __('Customer information (name, email, address, phone)', 'erplinker-odoosync-lite'),
            __('Order details and purchase history', 'erplinker-odoosync-lite'),
            __('Product information and inventory data', 'erplinker-odoosync-lite'),
            __('Where we send your data', 'erplinker-odoosync-lite'),
            __('Your data is sent to your configured Odoo instance. We also connect to third-party services if you enable integrations (ShipStation, Mailchimp, etc.).', 'erplinker-odoosync-lite'),
            __('How long we retain your data', 'erplinker-odoosync-lite'),
            __('Sync data is retained as long as the plugin is active. You can request deletion via WordPress privacy tools.', 'erplinker-odoosync-lite')
        );
        
        wp_add_privacy_policy_content('OdooSync for WooCommerce', $content);
    }
}
