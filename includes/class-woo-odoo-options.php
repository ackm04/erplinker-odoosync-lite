<?php
/**
 * Plugin options and defaults.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Options {

    /**
     * Default settings.
     *
     * @return array
     */
    public static function get_defaults() {
        return [
            'odoo_url'                => '',
            'odoo_db'                 => '',
            'odoo_username'            => '',
            'odoo_password'            => '',
            'odoo_version'             => '18',
            'enable_realtime_sync'    => false,
            'enable_sync_products'    => true,
            'enable_sync_customers'   => true,
            'enable_sync_orders'      => true,
            'enable_sync_stock'       => true,
            'enable_sync_categories'  => true,
            'enable_sync_images'      => true,
            'enable_sync_variants'    => false,
            'enable_sync_attributes'  => false,
            'enable_sync_price'        => false,
            'enable_logging'           => false,
            'enable_audit_trail'       => false,
            'webhook_secret'           => '',
            'sku_mapping'              => 'default_code', // 'default_code' | 'barcode'
            'create_invoice_on_order'  => false,
            'exclude_pos_products'     => false,
            'cron_products_freq'       => 'hourly',
            'cron_customers_freq'      => 'hourly',
            'cron_orders_freq'         => 'fifteen_minutes',
            'cron_stock_freq'          => 'thirty_minutes',
            'company_id'               => 0,
            'invoice_journal_id'       => 0,
            'fiscal_position_id'       => 0,
            'account_type_id'          => 0,
            'debtors_account_id'        => 0,
            'tax_id'                   => 0,
            'order_status_mapping'     => [
                'pending'   => 'draft',
                'processing'=> 'sale',
                'completed' => 'done',
                'cancelled' => 'cancel',
            ],
            'odoo_lang'                 => 'en_US',
            // Advanced feature flags
            'feature_trust_proxy'       => false,
            'feature_graphql'           => false,
            'feature_multi_currency'    => false,
            'feature_b2b'               => false,
            'feature_analytics'         => false,
            'feature_ai_optimizer'      => false,
            'feature_realtime'          => false,
            // API authentication
            'auth_method'               => 'password', // 'password'|'api_key'
            'odoo_api_key'              => '',
            'odoo_api_uid'              => 1,
            // Protocol
            'api_protocol'              => 'xmlrpc',   // 'xmlrpc'|'jsonrpc'
            'verify_ssl'                => true,       // SSL peer verification for Odoo (F-042)
            // Notifications
            'alert_email'               => '',
            'slack_webhook_url'         => '',
            'alert_stale_hours'         => 2,
            // Delta sync
            'delta_sync_enabled'        => true,
            // v3.0: AI enrichment (legacy single-key field; superseded by per-provider keys below)
            'ai_enrich_descriptions'    => false,
            'ai_enrich_min_words'       => 30,
            'ai_llm_api_key'            => '',
            'anomaly_alert_threshold'   => 5,
            // v3.1: AI provider configuration
            'ai_provider'              => 'openai',
            'ai_model'                 => '',
            'ai_temperature'           => 0.3,
            'ai_max_tokens'            => 800,
            'ai_auto_apply'            => false,
            'ai_openai_api_key'        => '',
            'ai_gemini_api_key'        => '',
            'ai_openrouter_api_key'    => '',
            // v3.0: log shipping
            'log_ship_endpoint'         => '',
            'log_ship_api_key'          => '',
            'log_ship_batch_size'       => 100,
            // v3.0: lot tracking customer display
            'show_lots_to_customers'    => false,
        ];
    }

    /**
     * Get Odoo language code for API context.
     *
     * @return string e.g. en_US, fr_FR
     */
    public static function get_odoo_lang() {
        $opts = get_option('woo_odoo_connector_settings', []);
        return !empty($opts['odoo_lang']) ? sanitize_text_field($opts['odoo_lang']) : 'en_US';
    }

    /**
     * Get SKU field for Odoo (default_code = Internal Reference, barcode = Barcode).
     *
     * @return string
     */
    public static function get_sku_field() {
        $opts = get_option('woo_odoo_connector_settings', []);
        return ($opts['sku_mapping'] ?? 'default_code') === 'barcode' ? 'barcode' : 'default_code';
    }

    /**
     * Cron frequency options.
     *
     * @return array
     */
    public static function get_cron_frequencies() {
        return [
            'hourly'          => __('Every Hour', 'erplinker-odoosync-lite'),
            'twicedaily'      => __('Twice Daily', 'erplinker-odoosync-lite'),
            'daily'           => __('Once Daily', 'erplinker-odoosync-lite'),
            'fifteen_minutes' => __('Every 15 Minutes', 'erplinker-odoosync-lite'),
            'thirty_minutes'  => __('Every 30 Minutes', 'erplinker-odoosync-lite'),
        ];
    }

    /**
     * Odoo version options.
     *
     * @return array
     */
    public static function get_odoo_versions() {
        return [
            '13' => 'Odoo 13',
            '14' => 'Odoo 14',
            '15' => 'Odoo 15',
            '16' => 'Odoo 16',
            '17' => 'Odoo 17',
            '18' => 'Odoo 18',
            '19' => 'Odoo 19',
        ];
    }
}
