<?php
/**
 * Base sync functionality.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

abstract class Woo_Odoo_Sync {

    /** @var Woo_Odoo_API|Woo_Odoo_API_Enhanced */
    protected $api;

    /** @var array Settings */
    protected $settings;

    public function __construct() {
        $this->settings = get_option('woo_odoo_connector_settings', []);
        $config         = $this->get_api_config();

        // Attempt to get a connection through the Connection Manager (honours the circuit breaker).
        if (class_exists('Woo_Odoo_Connection_Manager')) {
            $conn = Woo_Odoo_Connection_Manager::get_connection($config);
            if (!is_wp_error($conn)) {
                $this->api = $conn;
                return;
            }
            // Circuit is open or connection failed — log and fall through to direct instantiation.
            if (class_exists('Woo_Odoo_Logger')) {
                Woo_Odoo_Logger::warning('Connection Manager unavailable, falling back to direct API: ' . $conn->get_error_message());
            }
        }

        // Direct instantiation fallback (no circuit-breaker protection).
        if (class_exists('Woo_Odoo_API_Enhanced')) {
            $this->api = new Woo_Odoo_API_Enhanced($config);
        } else {
            $this->api = new Woo_Odoo_API($config);
        }
    }

    /**
     * Get API config (connection from instance or main settings).
     *
     * @return array
     */
    protected function get_api_config() {
        if (class_exists('Woo_Odoo_Instances')) {
            $instance_config = Woo_Odoo_Instances::get_default_config();
            return array_merge($this->settings, $instance_config);
        }
        return $this->settings;
    }

    /**
     * Get Odoo API context with language (for multi-language sync).
     *
     * @return array
     */
    protected function get_lang_context() {
        $lang = Woo_Odoo_Options::get_odoo_lang();
        return $lang && $lang !== 'en_US' ? ['lang' => $lang] : [];
    }

    /**
     * Check for duplicate SKUs in WooCommerce (F-044).
     * Logs WARNING for each duplicate; safe to call before sync.
     */
    public static function check_sku_collisions() {
        global $wpdb;
        $table = $wpdb->postmeta;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $dupes = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value AS sku, COUNT(*) AS c FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != %s GROUP BY meta_value HAVING c > %d",
                '_sku',
                '',
                1
            ),
            ARRAY_A
        );
        foreach ($dupes as $row) {
            if (class_exists('Woo_Odoo_Logger')) {
                Woo_Odoo_Logger::warning('Duplicate SKU in WooCommerce', ['sku' => $row['sku'], 'count' => (int) $row['c']]);
            }
        }
    }

    /**
     * Check if sync is enabled (connection + entity toggle).
     *
     * @param string $entity Optional: 'products', 'customers', 'orders', 'stock'
     * @return bool
     */
    protected function is_enabled($entity = '') {
        if (empty($this->settings['odoo_url']) || empty($this->settings['odoo_username'])) {
            return false;
        }
        if (empty($entity)) {
            return true;
        }
        $key = 'enable_sync_' . $entity;
        return !isset($this->settings[$key]) || !empty($this->settings[$key]);
    }

    /**
     * Log sync activity.
     *
     * Routes all messages through Woo_Odoo_Logger when available.
     * Falls back to the legacy wp_options log only when the structured logger
     * has not been loaded (e.g. very early bootstrap or test context).
     *
     * @param string $message
     * @param string $level   'info'|'error'|'warning'|'debug'
     * @param array  $context Optional structured context array
     */
    protected function log($message, $level = 'info', $context = []) {
        if (empty($this->settings['enable_logging'])) {
            return;
        }

        if (class_exists('Woo_Odoo_Logger')) {
            $context['sync_class'] = static::class;
            switch ($level) {
                case 'error':
                    Woo_Odoo_Logger::error($message, $context);
                    break;
                case 'warning':
                    Woo_Odoo_Logger::warning($message, $context);
                    break;
                case 'debug':
                    Woo_Odoo_Logger::debug($message, $context);
                    break;
                default:
                    Woo_Odoo_Logger::info($message, $context);
            }
            return;
        }

        // Legacy fallback — kept only for environments where the structured
        // logger class is unavailable. Not used during normal plugin operation.
        $log   = get_option('woo_odoo_sync_log', []);
        $log[] = [
            'time'    => current_time('mysql'),
            'level'   => $level,
            'message' => $message,
        ];
        $log = array_slice($log, -500);
        update_option('woo_odoo_sync_log', $log, false);
    }

    /**
     * Add failed job to retry queue.
     *
     * @param string $entity    'product'|'customer'|'order'|'stock'
     * @param int    $entity_id WC entity ID
     * @param string $error     Error message
     */
    protected function add_to_retry($entity, $entity_id, $error = '') {
        if (class_exists('Woo_Odoo_Retry_Queue')) {
            Woo_Odoo_Retry_Queue::add($entity, $entity_id, $error);
        }
    }

    // -------------------------------------------------------------------------
    // Delta sync helpers
    // -------------------------------------------------------------------------

    /**
     * Record successful sync timestamp for a post-based entity.
     *
     * @param int    $post_id  WC post ID (product, order).
     * @param string $entity   Entity type label (for action hook).
     * @param int    $odoo_id  Odoo record ID.
     */
    protected function stamp_synced_at_post($post_id, $entity, $odoo_id, $odoo_data = null) {
        $now = current_time('mysql');
        update_post_meta($post_id, '_odoo_synced_at', $now);
        if ($entity === 'product' && $odoo_data !== null) {
            do_action("woo_odoo_after_{$entity}_sync", $post_id, $odoo_id, $odoo_data);
        } else {
            do_action("woo_odoo_after_{$entity}_sync", $post_id, $odoo_id);
        }
    }

    /**
     * Record successful sync timestamp for a user-based entity.
     *
     * @param int    $user_id
     * @param string $entity
     * @param int    $odoo_id
     */
    protected function stamp_synced_at_user($user_id, $entity, $odoo_id) {
        update_user_meta($user_id, '_odoo_synced_at', current_time('mysql'));
        do_action("woo_odoo_after_{$entity}_sync", $user_id, $odoo_id);
    }

    /**
     * Check whether a WC post-based entity needs re-syncing (delta sync).
     *
     * Returns true (needs sync) when:
     *  a) delta sync is disabled in settings, OR
     *  b) the entity has never been synced, OR
     *  c) the WC post was modified after _odoo_synced_at.
     *
     * @param int    $post_id
     * @param string $odoo_meta_key  e.g. '_odoo_product_id'
     * @return bool
     */
    protected function needs_sync_post($post_id, $odoo_meta_key = '') {
        if (empty($this->settings['delta_sync_enabled'])) {
            return true;
        }
        $synced_at = get_post_meta($post_id, '_odoo_synced_at', true);
        if (!$synced_at) {
            return true;
        }
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        return strtotime($post->post_modified_gmt) > strtotime(get_gmt_from_date($synced_at));
    }

    /**
     * Check whether a WC order (HPOS-aware) needs re-syncing.
     *
     * @param WC_Order $order
     * @return bool
     */
    protected function needs_sync_order($order) {
        if (empty($this->settings['delta_sync_enabled'])) {
            return true;
        }
        $synced_at = $order->get_meta('_odoo_synced_at', true);
        if (!$synced_at) {
            return true;
        }
        $modified = $order->get_date_modified();
        if (!$modified) {
            return true;
        }
        return $modified->getTimestamp() > strtotime($synced_at);
    }

    /**
     * Check whether a WP user entity needs re-syncing.
     *
     * @param int $user_id
     * @return bool
     */
    protected function needs_sync_user($user_id) {
        if (empty($this->settings['delta_sync_enabled'])) {
            return true;
        }
        $synced_at = get_user_meta($user_id, '_odoo_synced_at', true);
        if (!$synced_at) {
            return true;
        }
        // WP user has no reliable modification timestamp without a plugin;
        // fall back to "always sync if odoo_id already set" — i.e. skip only
        // when already synced and no meta change indicator is available.
        return false;
    }

    /**
     * Log audit trail (before/after) when enabled.
     *
     * @param string $entity   'product'|'customer'|'order'|'stock'
     * @param int    $entity_id WC entity ID
     * @param string $direction 'wc_to_odoo'|'odoo_to_wc'
     * @param mixed  $before   State before (serializable)
     * @param mixed  $after    State after (serializable)
     */
    protected function audit($entity, $entity_id, $direction, $before = null, $after = null) {
        if (empty($this->settings['enable_audit_trail'])) {
            return;
        }
        $log = get_option('woo_odoo_audit_log', []);
        $log[] = [
            'time'     => current_time('mysql'),
            'entity'   => $entity,
            'entity_id'=> (int) $entity_id,
            'direction'=> $direction,
            'before'   => $before,
            'after'    => $after,
        ];
        $log = array_slice($log, -200); // Keep last 200
        update_option('woo_odoo_audit_log', $log, false);
    }
}
