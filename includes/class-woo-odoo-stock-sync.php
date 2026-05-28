<?php
/**
 * Stock sync: Odoo → WooCommerce
 *
 * Pulls stock from Odoo product.product (by SKU) and updates WC product stock.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Stock_Sync extends Woo_Odoo_Sync {

    /** Option key for the last time a bulk stock sync ran. */
    const LAST_STOCK_SYNC_OPTION = 'woo_odoo_last_stock_sync';

    /**
     * Sync stock from Odoo to WooCommerce.
     *
     * When multi-warehouse config exists: fetches stock.quant per enabled warehouse,
     * applies aggregation strategy (total/zone_based/primary), updates WC.
     * Otherwise uses single-location qty_available from product.product.
     *
     * @param int $limit
     * @return array ['updated' => int, 'skipped' => int, 'failed' => int]
     */
    public function sync_stock_from_odoo($limit = 200) {
        if (!$this->is_enabled('stock')) {
            return ['updated' => 0, 'skipped' => 0, 'failed' => 0];
        }

        Woo_Odoo_Sync::check_sku_collisions();

        $use_multi_warehouse = class_exists('Woo_Odoo_Warehouse_Manager')
            && !empty(Woo_Odoo_Warehouse_Manager::get_enabled_warehouses_ordered());

        if ($use_multi_warehouse) {
            return $this->sync_stock_multi_warehouse($limit);
        }

        $settings      = get_option('woo_odoo_connector_settings', []);
        $delta_enabled = !empty($settings['delta_sync_enabled']);
        $last_sync     = get_option(self::LAST_STOCK_SYNC_OPTION, '');

        // Capture the current run start time before any API calls.
        $run_start = gmdate('Y-m-d H:i:s');

        $updated = 0;
        $skipped = 0;
        $failed  = 0;

        if ($delta_enabled && $last_sync) {
            // --- Delta path: ask Odoo for products changed since last sync ---
            $sku_field = Woo_Odoo_Options::get_sku_field();
            try {
                $changed = $this->api->search_read(
                    'product.product',
                    [['write_date', '>', $last_sync]],
                    [$sku_field, 'qty_available', 'write_date'],
                    $limit
                );
            } catch (Exception $e) {
                $this->log('Stock delta query failed: ' . $e->getMessage(), 'error');
                return ['updated' => 0, 'skipped' => 0, 'failed' => 1];
            }

            if (empty($changed)) {
                update_option(self::LAST_STOCK_SYNC_OPTION, $run_start, false);
                return ['updated' => 0, 'skipped' => 0, 'failed' => 0];
            }

            $by_sku = [];
            foreach ($changed as $row) {
                $sku = $row[$sku_field] ?? '';
                if ($sku !== '') {
                    $by_sku[$sku][] = $row;
                }
            }
            foreach ($by_sku as $sku => $rows) {
                if (count($rows) > 1) {
                    if (class_exists('Woo_Odoo_Logger')) {
                        Woo_Odoo_Logger::error('Multiple Odoo records share same default_code; skipping all', ['sku' => $sku, 'count' => count($rows)]);
                    }
                    $skipped += count($rows);
                    continue;
                }
                $row = $rows[0];
                $sku = $row[$sku_field] ?? '';
                if (!$sku) {
                    $skipped++;
                    continue;
                }
                $wc_id = wc_get_product_id_by_sku($sku);
                if (!$wc_id) {
                    $skipped++;
                    continue;
                }
                $product = wc_get_product($wc_id);
                if (!$product) {
                    $skipped++;
                    continue;
                }
                try {
                    $product->set_manage_stock(true);
                    $product->set_stock_quantity((int) ($row['qty_available'] ?? 0));
                    $product->save();
                    $this->stamp_synced_at_post($wc_id, 'stock', 0);
                    $updated++;
                } catch (Exception $e) {
                    $failed++;
                    $this->log($e->getMessage(), 'error');
                    $this->add_to_retry('stock', $wc_id, $e->getMessage());
                }
                usleep(20000);
            }
        } else {
            // --- Full scan path (no delta or first run) ---
            $products = wc_get_products([
                'limit'  => $limit,
                'return' => 'ids',
                'status' => 'publish',
            ]);

            foreach ($products as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product || !$product->get_sku()) {
                    $skipped++;
                    continue;
                }

                try {
                    $odoo_qty = $this->get_odoo_stock_by_sku($product->get_sku());
                    if ($odoo_qty === false) {
                        $skipped++;
                        continue;
                    }

                    $product->set_manage_stock(true);
                    $product->set_stock_quantity($odoo_qty);
                    $product->save();
                    $this->stamp_synced_at_post($product_id, 'stock', 0);
                    $updated++;
                } catch (Exception $e) {
                    $failed++;
                    $this->log($e->getMessage(), 'error');
                    $this->add_to_retry('stock', $product_id, $e->getMessage());
                }
                usleep(50000);
            }
        }

        // Persist the timestamp only when no failures occurred so a failed run
        // triggers a full re-check next time.
        if ($failed === 0) {
            update_option(self::LAST_STOCK_SYNC_OPTION, $run_start, false);
            update_option('woo_odoo_last_sync_stock', gmdate('c'), false);
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Multi-warehouse stock sync: fetch stock.quant per warehouse, aggregate, update WC.
     *
     * @param int $limit
     * @return array ['updated' => int, 'skipped' => int, 'failed' => int]
     */
    protected function sync_stock_multi_warehouse($limit = 200) {
        $run_start = gmdate('Y-m-d H:i:s');
        $updated   = 0;
        $skipped   = 0;
        $failed    = 0;
        $sku_field = Woo_Odoo_Options::get_sku_field();

        $products = wc_get_products([
            'limit'  => $limit,
            'return' => 'ids',
            'status' => 'publish',
        ]);

        foreach ($products as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->get_sku()) {
                $skipped++;
                continue;
            }
            $sku = $product->get_sku();

            $odoo_ids = $this->api->search('product.product', [[$sku_field, '=', $sku]], 1);
            if (empty($odoo_ids)) {
                $skipped++;
                continue;
            }
            $odoo_id = (int) $odoo_ids[0];

            $per_warehouse = Woo_Odoo_Warehouse_Manager::get_stock_by_warehouse($this->api, $odoo_id);
            if (empty($per_warehouse)) {
                $skipped++;
                continue;
            }

            $mode    = Woo_Odoo_Warehouse_Manager::get_stock_display_mode();
            $zone_id = $mode === Woo_Odoo_Warehouse_Manager::MODE_ZONE
                ? Woo_Odoo_Warehouse_Manager::get_shipping_zone_for_customer()
                : null;
            $qty = Woo_Odoo_Warehouse_Manager::aggregate_stock($per_warehouse, $mode, $zone_id);

            if (!empty($this->settings['enable_logging'])) {
                $this->log('Stock sync ' . $sku . ': ' . json_encode(array_map(function ($w) {
                    return $w['available'];
                }, $per_warehouse)) . ' → ' . $qty, 'debug');
            }

            try {
                $product->set_manage_stock(true);
                $product->set_stock_quantity((int) $qty);
                $product->save();
                $this->stamp_synced_at_post($product_id, 'stock', 0);
                $updated++;
            } catch (Exception $e) {
                $failed++;
                $this->log($e->getMessage(), 'error');
                $this->add_to_retry('stock', $product_id, $e->getMessage());
            }
            usleep(50000);
        }

        if ($failed === 0) {
            update_option(self::LAST_STOCK_SYNC_OPTION, $run_start, false);
            update_option('woo_odoo_last_sync_stock', gmdate('c'), false);
        }

        return ['updated' => $updated, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Get per-warehouse stock breakdown for a WC product (admin / meta box).
     *
     * @param int $product_id WC product ID
     * @return array [ ['warehouse' => string, 'available' => float, 'reserved' => float, 'quantity' => float, 'last_updated' => string|null ], ... ]
     */
    public static function get_stock_breakdown($product_id) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->get_sku()) {
            return [];
        }
        if (!class_exists('Woo_Odoo_Warehouse_Manager')) {
            return [];
        }
        $warehouses = Woo_Odoo_Warehouse_Manager::get_enabled_warehouses_ordered();
        if (empty($warehouses)) {
            return [];
        }

        $settings = get_option('woo_odoo_connector_settings', []);
        $config   = class_exists('Woo_Odoo_Instances') ? array_merge($settings, Woo_Odoo_Instances::get_default_config()) : $settings;
        $api      = Woo_Odoo_Connection_Manager::get_connection($config);
        if (is_wp_error($api)) {
            return [];
        }

        $sku_field = Woo_Odoo_Options::get_sku_field();
        $ids       = $api->search('product.product', [[$sku_field, '=', $product->get_sku()]], 1);
        if (empty($ids)) {
            return [];
        }
        $odoo_id       = (int) $ids[0];
        $per_warehouse = Woo_Odoo_Warehouse_Manager::get_stock_by_warehouse($api, $odoo_id);
        $last_sync     = get_option('woo_odoo_last_sync_stock', null);

        $out = [];
        foreach ($per_warehouse as $name => $row) {
            $out[] = [
                'warehouse'     => $name,
                'available'    => (float) ($row['available'] ?? 0),
                'reserved'     => (float) ($row['reserved'] ?? 0),
                'quantity'     => (float) ($row['quantity'] ?? 0),
                'last_updated' => $last_sync,
            ];
        }
        return $out;
    }

    /**
     * Sync stock for specific product IDs (used by queue handler).
     *
     * @param array $product_ids
     * @return array ['updated' => int, 'failed' => int]
     */
    public function sync_stock_for_products($product_ids) {
        if (!$this->is_enabled('stock')) {
            return ['updated' => 0, 'failed' => 0];
        }
        $use_multi = class_exists('Woo_Odoo_Warehouse_Manager')
            && !empty(Woo_Odoo_Warehouse_Manager::get_enabled_warehouses_ordered());
        $sku_field = Woo_Odoo_Options::get_sku_field();
        $updated   = 0;
        $failed    = 0;

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || !$product->get_sku()) {
                continue;
            }
            try {
                if ($use_multi) {
                    $odoo_ids = $this->api->search('product.product', [[$sku_field, '=', $product->get_sku()]], 1);
                    if (empty($odoo_ids)) {
                        continue;
                    }
                    $per_warehouse = Woo_Odoo_Warehouse_Manager::get_stock_by_warehouse($this->api, (int) $odoo_ids[0]);
                    $mode   = Woo_Odoo_Warehouse_Manager::get_stock_display_mode();
                    $zone_id = $mode === Woo_Odoo_Warehouse_Manager::MODE_ZONE ? Woo_Odoo_Warehouse_Manager::get_shipping_zone_for_customer() : null;
                    $odoo_qty = (int) Woo_Odoo_Warehouse_Manager::aggregate_stock($per_warehouse, $mode, $zone_id);
                } else {
                    $odoo_qty = $this->get_odoo_stock_by_sku($product->get_sku());
                    if ($odoo_qty === false) {
                        continue;
                    }
                }
                $product->set_manage_stock(true);
                $product->set_stock_quantity($odoo_qty);
                $product->save();
                $updated++;
            } catch (Exception $e) {
                $failed++;
                $this->log($e->getMessage(), 'error');
                $this->add_to_retry('stock', $product_id, $e->getMessage());
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * Get stock quantity from Odoo by SKU (Internal Reference or Barcode).
     *
     * @param string $sku
     * @return int|false
     */
    private function get_odoo_stock_by_sku($sku) {
        $field = Woo_Odoo_Options::get_sku_field();
        $ids = $this->api->search('product.product', [
            [$field, '=', $sku],
        ], 1);

        if (empty($ids)) {
            return false;
        }

        $rows = $this->api->read('product.product', $ids, ['qty_available']);
        if (empty($rows)) {
            return false;
        }

        return (int) ($rows[0]['qty_available'] ?? 0);
    }
}
