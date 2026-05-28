<?php
/**
 * Order sync: WooCommerce → Odoo
 *
 * Maps: WC Order → Odoo sale.order
 * Uses order meta _odoo_order_id for mapping.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Order_Sync extends Woo_Odoo_Sync {

    const META_ODOO_ID = '_odoo_order_id';

    /**
     * Sync an order to Odoo.
     *
     * @param int $order_id WC Order ID
     * @return int|false Odoo sale.order ID or false
     */
    public function sync_order($order_id) {
        if (!$this->is_enabled('orders')) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Delta sync: skip orders that haven't changed since last successful sync.
        if (!$this->needs_sync_order($order)) {
            return (int) $order->get_meta(self::META_ODOO_ID) ?: false;
        }

        try {
            $odoo_id = $order->get_meta(self::META_ODOO_ID);
            if ($odoo_id) {
                $this->update_order($order, (int) $odoo_id);
                $this->stamp_synced_at_post($order->get_id(), 'order', (int) $odoo_id);
                return (int) $odoo_id;
            }

            $customer_sync = new Woo_Odoo_Customer_Sync();
            $partner_id = $customer_sync->sync_customer_from_order($order);
            if (!$partner_id) {
                throw new Exception(__('Could not create customer in Odoo.', 'erplinker-odoosync-lite'));
            }

            $order_vals = [
                'partner_id'       => $partner_id,
                'client_order_ref' => 'WC#' . $order->get_order_number(),
                'date_order'       => $order->get_date_created()->format('Y-m-d H:i:s'),
                'note'             => sprintf(
                    __('WooCommerce Order #%s | Payment: %s', 'erplinker-odoosync-lite'),
                    $order->get_order_number(),
                    $order->get_payment_method_title()
                ),
            ];
            $company_id = (int) ($this->settings['company_id'] ?? 0);
            if ($company_id > 0) {
                $order_vals['company_id'] = $company_id;
            }
            $fiscal_position_id = (int) ($this->settings['fiscal_position_id'] ?? 0);
            if ($fiscal_position_id > 0) {
                $order_vals['fiscal_position_id'] = $fiscal_position_id;
            }

            $order_lines = [];
            foreach ($order->get_items() as $item) {
                if (!$item->is_type('line_item')) {
                    continue;
                }
                $product = $item->get_product();
                $wc_product_id = $item->get_product_id();
                $wc_variation_id = $item->get_variation_id();
                $wc_id_to_sync = $wc_variation_id ? $wc_variation_id : $wc_product_id;
                if (!$wc_id_to_sync) {
                    continue;
                }
                $odoo_template_id = get_post_meta($wc_product_id, Woo_Odoo_Product_Sync::META_ODOO_ID, true);
                $odoo_variant_id = $wc_variation_id ? get_post_meta($wc_variation_id, Woo_Odoo_Product_Sync::META_ODOO_VARIANT_ID, true) : null;
                if (!$odoo_template_id && $product) {
                    $product_sync = new Woo_Odoo_Product_Sync();
                    $odoo_template_id = $product_sync->sync_product($wc_id_to_sync);
                }
                $odoo_product_id = $odoo_variant_id ?: ($odoo_template_id ? $this->api->get_product_variant_id($odoo_template_id) : 0);
                if ($odoo_product_id) {
                    $order_lines[] = [
                        0, 0,
                        [
                            'product_id' => $odoo_product_id,
                            'product_uom_qty' => $item->get_quantity(),
                            'price_unit' => (float) $item->get_subtotal() / max(1, $item->get_quantity()),
                        ],
                    ];
                }
            }

            foreach ($order->get_items('shipping') as $shipping) {
                $order_lines[] = $this->build_shipping_line($shipping);
            }

            $discount_line = $this->build_coupon_line($order);
            if ($discount_line) {
                $order_lines[] = $discount_line;
            }

            if (empty($order_lines)) {
                throw new Exception(__('No valid order lines to sync.', 'erplinker-odoosync-lite'));
            }

            $order_vals['order_line'] = $order_lines;
            $order_vals = apply_filters('woo_odoo_order_sync_vals', $order_vals, $order);
            do_action('woo_odoo_before_order_sync', $order_id, $order_vals);

            $ctx    = $this->get_lang_context();
            $new_id = $this->api->create('sale.order', $order_vals, $ctx);
            $order->update_meta_data(self::META_ODOO_ID, $new_id);
            $order->update_meta_data('_odoo_order_status', $order->get_status());
            $order->update_meta_data('_odoo_synced_at', current_time('mysql'));
            $order->save();

            $this->log(sprintf(__('Created order %d in Odoo (ID: %d)', 'erplinker-odoosync-lite'), $order_id, $new_id));
            do_action('woo_odoo_after_order_sync', $order_id, $new_id, $order_vals);

            $status_mapping = $this->get_order_status_mapping();
            $wc_status = $order->get_status();
            $odoo_action = $status_mapping[$wc_status] ?? $status_mapping['processing'];

            if ($odoo_action === 'sale' || $odoo_action === 'done') {
                $this->api->execute('sale.order', 'action_confirm', [[$new_id]]);
                if (!empty($this->settings['create_invoice_on_order'])) {
                    $this->create_invoice_for_order($new_id);
                }
            } elseif ($odoo_action === 'cancel') {
                $this->api->execute('sale.order', 'action_cancel', [[$new_id]]);
            }

            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            $this->add_to_retry('order', $order_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Build Odoo order line for shipping.
     *
     * @param WC_Order_Item_Shipping $shipping
     * @return array
     */
    protected function build_shipping_line($shipping) {
        $name = $shipping->get_name();
        $total = (float) $shipping->get_total();
        $shipping_product_id = $this->get_or_create_shipping_product();
        if (!$shipping_product_id) {
            return [0, 0, [
                'name'         => $name ?: __('Shipping', 'erplinker-odoosync-lite'),
                'product_uom_qty' => 1,
                'price_unit'   => $total,
            ]];
        }
        return [0, 0, [
            'product_id'      => $shipping_product_id,
            'product_uom_qty' => 1,
            'price_unit'      => $total,
        ]];
    }

    /**
     * Get or create generic shipping product in Odoo.
     *
     * @return int|false
     */
    protected function get_or_create_shipping_product() {
        $shipping_id = get_option('woo_odoo_shipping_product_id', 0);
        if ($shipping_id) {
            return (int) $shipping_id;
        }
        try {
            $ids = $this->api->search('product.template', [
                ['default_code', '=', 'WC_SHIPPING'],
            ], 1);
            if (!empty($ids)) {
                $shipping_id = $ids[0];
                update_option('woo_odoo_shipping_product_id', $shipping_id);
                return $shipping_id;
            }
            $new_id = $this->api->create('product.template', [
                'name'         => __('Shipping', 'erplinker-odoosync-lite'),
                'default_code' => 'WC_SHIPPING',
                'type'         => 'service',
                'list_price'   => 0,
                'sale_ok'      => true,
                'purchase_ok'  => false,
            ]);
            update_option('woo_odoo_shipping_product_id', $new_id);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Build discount line for coupons.
     *
     * @param WC_Order $order
     * @return array|null
     */
    protected function build_coupon_line($order) {
        $total_discount = (float) $order->get_total_discount();
        if ($total_discount <= 0) {
            return null;
        }
        $codes = $order->get_coupon_codes();
        $label = !empty($codes) ? sprintf(__('Discount: %s', 'erplinker-odoosync-lite'), implode(', ', $codes)) : __('Discount', 'erplinker-odoosync-lite');
        $discount_product_id = $this->get_or_create_discount_product();
        if ($discount_product_id) {
            return [0, 0, [
                'product_id'      => $discount_product_id,
                'product_uom_qty' => 1,
                'price_unit'      => -$total_discount,
            ]];
        }
        return [0, 0, [
            'name'            => $label,
            'product_uom_qty' => 1,
            'price_unit'      => -$total_discount,
        ]];
    }

    /**
     * Get or create generic discount product in Odoo.
     *
     * @return int|false
     */
    protected function get_or_create_discount_product() {
        $discount_id = get_option('woo_odoo_discount_product_id', 0);
        if ($discount_id) {
            return (int) $discount_id;
        }
        try {
            $ids = $this->api->search('product.template', [
                ['default_code', '=', 'WC_DISCOUNT'],
            ], 1);
            if (!empty($ids)) {
                $discount_id = $ids[0];
                update_option('woo_odoo_discount_product_id', $discount_id);
                return $discount_id;
            }
            $new_id = $this->api->create('product.template', [
                'name'         => __('Discount', 'erplinker-odoosync-lite'),
                'default_code' => 'WC_DISCOUNT',
                'type'         => 'service',
                'list_price'   => 0,
                'sale_ok'      => true,
                'purchase_ok'  => false,
            ]);
            update_option('woo_odoo_discount_product_id', $new_id);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Get order status mapping (WC status → Odoo action).
     *
     * @return array
     */
    protected function get_order_status_mapping() {
        $defaults = [
            'pending'   => 'draft',
            'processing'=> 'sale',
            'completed' => 'done',
            'cancelled' => 'cancel',
        ];
        $custom = $this->settings['order_status_mapping'] ?? [];
        return is_array($custom) && !empty($custom) ? array_merge($defaults, $custom) : $defaults;
    }

    /**
     * Create invoice in Odoo for confirmed sale order.
     *
     * @param int $odoo_order_id
     * @return int|false Invoice ID or false
     */
    protected function create_invoice_for_order($odoo_order_id) {
        try {
            $kwargs = [];
            $journal_id = (int) ($this->settings['invoice_journal_id'] ?? 0);
            if ($journal_id > 0) {
                $kwargs['context'] = ['default_journal_id' => $journal_id];
            }
            $result = $this->api->execute('sale.order', 'action_create_invoices', [[$odoo_order_id]], $kwargs);
            if (is_array($result) && !empty($result)) {
                $invoice_id = (int) $result[0];
                $this->log(sprintf(__('Created invoice %d for order %d', 'erplinker-odoosync-lite'), $invoice_id, $odoo_order_id));
                return $invoice_id;
            }
            return false;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Update existing Odoo order (e.g. status change).
     */
    protected function update_order($order, $odoo_id) {
        $status_mapping = $this->get_order_status_mapping();
        $status = $order->get_status();
        $odoo_action = $status_mapping[$status] ?? 'sale';

        if ($odoo_action === 'sale' || $odoo_action === 'done') {
            $this->api->execute('sale.order', 'action_confirm', [[$odoo_id]]);
            if (!empty($this->settings['create_invoice_on_order'])) {
                $this->create_invoice_for_order($odoo_id);
            }
        }
        if ($odoo_action === 'cancel') {
            $this->api->execute('sale.order', 'action_cancel', [[$odoo_id]]);
        }
    }

    /**
     * Sync orders modified since given date.
     *
     * @param string $since Date string (Y-m-d H:i:s)
     * @param int    $limit
     * @return array
     */
    public function sync_orders_since($since, $limit = 50) {
        $synced = 0;
        $failed = 0;

        $since_ts = strtotime($since);
        $orders = wc_get_orders([
            'limit'         => $limit,
            'date_modified' => '>' . $since_ts,
            'return'        => 'ids',
        ]);

        foreach ($orders as $order_id) {
            $result = $this->sync_order($order_id);
            if ($result !== false) {
                $synced++;
            } else {
                $failed++;
            }
            usleep(100000);
        }

        update_option('woo_odoo_last_sync_orders', gmdate('c'), false);
        return ['synced' => $synced, 'failed' => $failed];
    }
}
