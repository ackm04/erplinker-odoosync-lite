<?php
/**
 * Product sync: WooCommerce → Odoo
 *
 * Maps: WC Product → Odoo product.template
 * Uses post meta _odoo_product_id for mapping.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Product_Sync extends Woo_Odoo_Sync {

    const META_ODOO_ID = '_odoo_product_id';
    const META_ODOO_CATEGORY_ID = '_odoo_category_id';
    const META_ODOO_VARIANT_ID = '_odoo_variant_id';

    /**
     * Sync a single product to Odoo.
     *
     * @param int $product_id WC_Product ID or product_id (variation ID for variable products)
     * @return int|false Odoo product.template ID or product.product ID for variations
     */
    public function sync_product($product_id) {
        if (!$this->is_enabled('products')) {
            return false;
        }

        Woo_Odoo_Sync::check_sku_collisions();

        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        if ($product->is_type('variation') && !empty($this->settings['enable_sync_variants'])) {
            return $this->sync_variation($product);
        }

        if ($product->is_type('variation')) {
            $product_id = $product->get_parent_id();
            $product    = wc_get_product($product_id);
        }

        // Delta sync: skip if product has not changed since the last successful sync.
        if (!$this->needs_sync_post($product_id, self::META_ODOO_ID)) {
            return (int) get_post_meta($product_id, self::META_ODOO_ID, true) ?: false;
        }

        try {
            $odoo_id = get_post_meta($product_id, self::META_ODOO_ID, true);
            $vals    = apply_filters('woo_odoo_product_sync_vals', $this->build_product_vals($product), $product);

            do_action('woo_odoo_before_product_sync', $product_id, $vals);

            $ctx = $this->get_lang_context();
            if ($odoo_id) {
                $this->api->write('product.template', [(int) $odoo_id], $vals, $ctx);
                $this->audit('product', $product_id, 'wc_to_odoo', ['odoo_id' => $odoo_id], $vals);
                $this->log(sprintf(__('Updated product %d in Odoo (ID: %s)', 'erplinker-odoosync-lite'), $product_id, $odoo_id));
                $odoo_rows = $this->api->read('product.template', [(int) $odoo_id], ['name', 'list_price', 'qty_available']);
                $odoo_data = isset($odoo_rows[0]) ? $odoo_rows[0] : [];
                $this->stamp_synced_at_post($product_id, 'product', (int) $odoo_id, $odoo_data);
                return (int) $odoo_id;
            }

            $vals['sale_ok']     = true;
            $vals['purchase_ok'] = false;
            if (isset($vals['barcode']) && $vals['barcode'] === false) {
                unset($vals['barcode']);
            }
            $new_id = $this->api->create('product.template', $vals, $ctx);
            update_post_meta($product_id, self::META_ODOO_ID, $new_id);
            $this->audit('product', $product_id, 'wc_to_odoo', null, array_merge($vals, ['odoo_id' => $new_id]));
            $this->log(sprintf(__('Created product %d in Odoo (ID: %d)', 'erplinker-odoosync-lite'), $product_id, $new_id));
            $odoo_rows = $this->api->read('product.template', [$new_id], ['name', 'list_price', 'qty_available']);
            $odoo_data = isset($odoo_rows[0]) ? $odoo_rows[0] : [];
            $this->stamp_synced_at_post($product_id, 'product', $new_id, $odoo_data);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            $this->add_to_retry('product', $product_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Sync product variation to Odoo product.product.
     *
     * @param WC_Product_Variation $product
     * @return int|false Odoo product.product ID
     */
    protected function sync_variation($product) {
        $variation_id = $product->get_id();
        $parent_id = $product->get_parent_id();
        $template_id = get_post_meta($parent_id, self::META_ODOO_ID, true);
        if (!$template_id) {
            $this->sync_product($parent_id);
            $template_id = get_post_meta($parent_id, self::META_ODOO_ID, true);
        }
        if (!$template_id) {
            return false;
        }

        $sku = $product->get_sku();
        $sku_field = Woo_Odoo_Options::get_sku_field();
        $vals = [
            'product_tmpl_id' => (int) $template_id,
            'list_price'      => (float) $product->get_price(),
        ];
        if ($sku_field === 'default_code') {
            $vals['default_code'] = $sku ?: 'VAR-' . $variation_id;
        } else {
            $vals['barcode'] = $sku ?: 'VAR-' . $variation_id;
        }

        try {
            $ctx = $this->get_lang_context();
            $ids = $this->api->search('product.product', [
                ['product_tmpl_id', '=', (int) $template_id],
                [$sku_field, '=', $vals[$sku_field === 'default_code' ? 'default_code' : 'barcode']],
            ], 1);
            if (!empty($ids)) {
                $this->api->write('product.product', $ids, $vals, $ctx);
                update_post_meta($variation_id, self::META_ODOO_VARIANT_ID, (int) $ids[0]);
                return (int) $ids[0];
            }
            $new_id = $this->api->create('product.product', $vals, $ctx);
            update_post_meta($variation_id, self::META_ODOO_VARIANT_ID, $new_id);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return $this->api->get_product_variant_id($template_id);
        }
    }

    /**
     * Build Odoo product.template values from WC product.
     *
     * @param WC_Product $product
     * @return array
     */
    public function build_product_vals($product) {
        $name = $product->get_name();
        $description = $product->get_description();
        $short_desc = $product->get_short_description();
        $price = (float) $product->get_regular_price();
        $sku = $product->get_sku();
        $weight = $product->get_weight();
        $manage_stock = $product->managing_stock();
        $sku_field = Woo_Odoo_Options::get_sku_field();
        $vals = [
            'name'        => $name ?: 'Product ' . $product->get_id(),
            'list_price'  => $price,
            'default_code'=> $sku_field === 'default_code' ? ($sku ?: '') : '',
            'barcode'     => $sku_field === 'barcode' ? ($sku ?: '') : false,
            'weight'      => $weight ? (float) $weight : 0,
            'type'        => $manage_stock ? 'product' : 'consu', // Storable if WC manages stock
            'active'      => $product->get_status() === 'publish',
        ];

        if ($description) {
            $vals['description_sale'] = $description;
        }
        if ($short_desc) {
            $vals['description_sale'] = ($vals['description_sale'] ?? '') . "\n\n" . $short_desc;
        }

        if ($this->is_enabled('categories')) {
            $categ_id = $this->sync_product_category($product->get_id());
            if ($categ_id) {
                $vals['categ_id'] = $categ_id;
            }
        }

        if ($this->is_enabled('images')) {
            $image_b64 = $this->get_product_image_base64($product);
            if ($image_b64) {
                $vals['image_1920'] = $image_b64;
            }
        }

        if ($this->is_enabled('variants') && $product->is_type('variable')) {
            $attr_lines = $this->build_attribute_line_ids($product);
            if (!empty($attr_lines)) {
                $vals['attribute_line_ids'] = array_merge([[5, 0, 0]], $attr_lines);
            }
        }

        return $vals;
    }

    /**
     * Build Odoo attribute_line_ids from WC variable product attributes.
     *
     * @param WC_Product_Variable $product
     * @return array
     */
    protected function build_attribute_line_ids($product) {
        $lines = [];
        $attributes = $product->get_attributes();
        if (empty($attributes)) {
            return $lines;
        }
        foreach ($attributes as $attr_name => $attr) {
            if (!$attr->get_variation() || !$attr->get_options()) {
                continue;
            }
            $value_ids = [];
            $attr_id = 0;
            foreach ($attr->get_options() as $option) {
                $option_name = (string) $option;
                if (is_numeric($option)) {
                    $taxonomy = strpos($attr_name, 'pa_') === 0 ? $attr_name : 'pa_' . $attr_name;
                    $term = get_term($option, $taxonomy);
                    $option_name = $term && !is_wp_error($term) ? $term->name : $option_name;
                }
                list($aid, $vid) = $this->get_or_create_attribute_value($attr_name, $option_name);
                if ($aid && $vid) {
                    $attr_id = $aid;
                    $value_ids[] = $vid;
                }
            }
            if ($attr_id && !empty($value_ids)) {
                $lines[] = [0, 0, ['attribute_id' => $attr_id, 'value_ids' => [[6, 0, $value_ids]]]];
            }
        }
        return $lines;
    }

    /**
     * Get or create product.attribute and product.attribute.value in Odoo.
     *
     * @param string $attr_name   WC attribute name (e.g. pa_color)
     * @param string $value_name  Attribute value (e.g. Red)
     * @return array [attribute_id, value_id]
     */
    protected function get_or_create_attribute_value($attr_name, $value_name) {
        $attr_label = str_replace('pa_', '', $attr_name);
        $attr_label = ucfirst(str_replace(['-', '_'], ' ', $attr_label));
        $cache_key = 'woo_odoo_attr_' . md5($attr_label . '_' . $value_name);
        $cached = wp_cache_get($cache_key, 'woo_odoo');
        if ($cached !== false) {
            return $cached;
        }
        try {
            $ids = $this->api->search('product.attribute', [['name', '=', $attr_label]], 1);
            if (empty($ids)) {
                $attr_id = $this->api->create('product.attribute', ['name' => $attr_label]);
            } else {
                $attr_id = (int) $ids[0];
            }
            $val_ids = $this->api->search('product.attribute.value', [
                ['attribute_id', '=', $attr_id],
                ['name', '=', $value_name],
            ], 1);
            if (empty($val_ids)) {
                $val_id = $this->api->create('product.attribute.value', [
                    'attribute_id' => $attr_id,
                    'name' => $value_name,
                ]);
            } else {
                $val_id = (int) $val_ids[0];
            }
            wp_cache_set($cache_key, [$attr_id, $val_id], 'woo_odoo', 60);
            return [$attr_id, $val_id];
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return [0, 0];
        }
    }

    /**
     * Sync WC product category to Odoo product.category.
     *
     * @param int $product_id WC product ID
     * @return int|false Odoo product.category ID
     */
    protected function sync_product_category($product_id) {
        $terms = get_the_terms($product_id, 'product_cat');
        if (!$terms || is_wp_error($terms)) {
            return false;
        }
        $term = reset($terms);
        if (!$term) {
            return false;
        }

        $odoo_id = get_term_meta($term->term_id, self::META_ODOO_CATEGORY_ID, true);
        if ($odoo_id) {
            return (int) $odoo_id;
        }

        try {
            $parent_id = false;
            if ($term->parent) {
                $parent_term = get_term($term->parent, 'product_cat');
                if ($parent_term && !is_wp_error($parent_term)) {
                    $parent_id = $this->sync_category_by_term($parent_term);
                }
            }

            $vals = [
                'name' => $term->name,
            ];
            if ($parent_id) {
                $vals['parent_id'] = $parent_id;
            }

            $new_id = $this->api->create('product.category', $vals);
            update_term_meta($term->term_id, self::META_ODOO_CATEGORY_ID, $new_id);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Sync a single category term to Odoo.
     */
    protected function sync_category_by_term($term) {
        $odoo_id = get_term_meta($term->term_id, self::META_ODOO_CATEGORY_ID, true);
        if ($odoo_id) {
            return (int) $odoo_id;
        }
        $parent_id = false;
        if ($term->parent) {
            $parent_term = get_term($term->parent, 'product_cat');
            if ($parent_term && !is_wp_error($parent_term)) {
                $parent_id = $this->sync_category_by_term($parent_term);
            }
        }
        $vals = ['name' => $term->name];
        if ($parent_id) {
            $vals['parent_id'] = $parent_id;
        }
        $new_id = $this->api->create('product.category', $vals);
        update_term_meta($term->term_id, self::META_ODOO_CATEGORY_ID, $new_id);
        return $new_id;
    }

    /**
     * Get product main image as base64 for Odoo.
     *
     * @param WC_Product $product
     * @return string|false
     */
    protected function get_product_image_base64($product) {
        $image_id = $product->get_image_id();
        if (!$image_id) {
            return false;
        }
        $path = get_attached_file($image_id);
        if (!$path || !file_exists($path)) {
            return false;
        }
        $data = file_get_contents($path);
        if ($data === false) {
            return false;
        }
        return base64_encode($data);
    }

    /**
     * Sync all products (for admin manual sync).
     *
     * @param int $limit
     * @return array ['synced' => int, 'failed' => int]
     */
    public function sync_all_products($limit = 100) {
        $synced = 0;
        $failed = 0;

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'fields'         => 'ids',
        ];

        $query = new WP_Query($args);
        foreach ($query->posts as $post_id) {
            $result = $this->sync_product($post_id);
            if ($result !== false) {
                $synced++;
            } else {
                $failed++;
            }
            usleep(100000); // 0.1s delay to avoid rate limits
        }

        update_option('woo_odoo_last_sync_products', gmdate('c'), false);
        return ['synced' => $synced, 'failed' => $failed];
    }
}
