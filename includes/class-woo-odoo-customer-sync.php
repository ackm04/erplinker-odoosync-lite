<?php
/**
 * Customer sync: WooCommerce → Odoo
 *
 * Maps: WC Customer → Odoo res.partner
 * Uses user meta _odoo_partner_id for mapping.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Customer_Sync extends Woo_Odoo_Sync {

    const META_ODOO_ID = '_odoo_partner_id';

    /** @var array In-memory cache for country ID lookups (code → Odoo ID). */
    private $country_id_cache = [];

    /** @var array In-memory cache for state ID lookups ("CC:SC" → Odoo ID). */
    private $state_id_cache = [];

    /**
     * Sync a customer to Odoo.
     *
     * @param int $user_id WP User ID
     * @return int|false Odoo res.partner ID or false
     */
    public function sync_customer($user_id) {
        if (!$this->is_enabled('customers')) {
            return false;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return false;
        }

        // Delta sync: skip if the user has already been synced and no change detected.
        if (!$this->needs_sync_user($user_id)) {
            return (int) get_user_meta($user_id, self::META_ODOO_ID, true) ?: false;
        }

        try {
            $odoo_id = get_user_meta($user_id, self::META_ODOO_ID, true);
            $vals    = apply_filters('woo_odoo_customer_sync_vals', $this->build_partner_vals($user), $user);

            do_action('woo_odoo_before_customer_sync', $user_id, $vals);

            if ($odoo_id) {
                $this->api->write('res.partner', [(int) $odoo_id], $vals);
                $this->log(sprintf(__('Updated customer %d in Odoo (ID: %s)', 'erplinker-odoosync-lite'), $user_id, $odoo_id));
                $this->stamp_synced_at_user($user_id, 'customer', (int) $odoo_id);
                return (int) $odoo_id;
            }

            $vals['customer_rank'] = 1;
            $new_id = $this->api->create('res.partner', $vals);
            update_user_meta($user_id, self::META_ODOO_ID, $new_id);
            $this->log(sprintf(__('Created customer %d in Odoo (ID: %d)', 'erplinker-odoosync-lite'), $user_id, $new_id));
            $this->stamp_synced_at_user($user_id, 'customer', $new_id);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            $this->add_to_retry('customer', $user_id, $e->getMessage());
            return false;
        }
    }

    /**
     * Sync customer from order billing data (guest checkout).
     *
     * @param WC_Order $order
     * @return int|false Odoo partner ID
     */
    public function sync_customer_from_order($order) {
        $email = $order->get_billing_email();
        if (!$email) {
            return false;
        }

        $user = get_user_by('email', $email);
        if ($user) {
            return $this->sync_customer($user->ID);
        }

        // Guest: create partner by email, store mapping in options.
        // The map is capped at 5000 entries (LRU-style) to prevent unbounded wp_options growth.
        $guest_map = get_option('woo_odoo_guest_partner_map', []);
        if (isset($guest_map[$email])) {
            return (int) $guest_map[$email];
        }

        try {
            $vals = [
                'name'        => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) ?: $email,
                'email'       => $email,
                'phone'       => $order->get_billing_phone(),
                'street'      => $order->get_billing_address_1(),
                'street2'     => $order->get_billing_address_2(),
                'city'        => $order->get_billing_city(),
                'zip'         => $order->get_billing_postcode(),
                'country_id'  => $this->get_country_id($order->get_billing_country()),
                'state_id'    => $this->get_state_id($order->get_billing_country(), $order->get_billing_state()),
                'customer_rank' => 1,
            ];

            $new_id = $this->api->create('res.partner', $vals);

            $guest_map[$email] = $new_id;

            // Prune to the most recent 5000 entries if the map grows too large.
            if (count($guest_map) > 5000) {
                $guest_map = array_slice($guest_map, -5000, null, true);
            }

            update_option('woo_odoo_guest_partner_map', $guest_map, false);
            return $new_id;
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Build res.partner vals from WP_User.
     */
    protected function build_partner_vals($user) {
        $billing = [
            'first_name' => get_user_meta($user->ID, 'billing_first_name', true),
            'last_name'  => get_user_meta($user->ID, 'billing_last_name', true),
            'email'      => $user->user_email,
            'phone'      => get_user_meta($user->ID, 'billing_phone', true),
            'address_1'  => get_user_meta($user->ID, 'billing_address_1', true),
            'address_2'  => get_user_meta($user->ID, 'billing_address_2', true),
            'city'       => get_user_meta($user->ID, 'billing_city', true),
            'postcode'   => get_user_meta($user->ID, 'billing_postcode', true),
            'country'    => get_user_meta($user->ID, 'billing_country', true),
            'state'      => get_user_meta($user->ID, 'billing_state', true),
        ];

        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
        if (!$name) {
            $name = $user->display_name ?: $user->user_login;
        }

        return [
            'name'       => $name,
            'email'      => $user->user_email,
            'phone'      => $billing['phone'] ?: '',
            'street'     => $billing['address_1'] ?: '',
            'street2'    => $billing['address_2'] ?: '',
            'city'       => $billing['city'] ?: '',
            'zip'        => $billing['postcode'] ?: '',
            'country_id' => $this->get_country_id($billing['country']),
            'state_id'   => $this->get_state_id($billing['country'], $billing['state']),
        ];
    }

    /**
     * Get Odoo country ID by ISO code (cached per request to avoid N+1 calls).
     */
    protected function get_country_id($code) {
        if (!$code) {
            return false;
        }
        $key = strtoupper($code);
        if (!array_key_exists($key, $this->country_id_cache)) {
            $ids = $this->api->search('res.country', [['code', '=', $key]], 1);
            $this->country_id_cache[$key] = !empty($ids) ? $ids[0] : false;
        }
        return $this->country_id_cache[$key];
    }

    /**
     * Get Odoo state ID by country + state code (cached per request).
     */
    protected function get_state_id($country_code, $state_code) {
        if (!$country_code || !$state_code) {
            return false;
        }
        $country_id = $this->get_country_id($country_code);
        if (!$country_id) {
            return false;
        }
        $cache_key = strtoupper($country_code) . ':' . strtoupper($state_code);
        if (!array_key_exists($cache_key, $this->state_id_cache)) {
            $ids = $this->api->search('res.country.state', [
                ['country_id', '=', $country_id],
                ['code', '=', strtoupper($state_code)],
            ], 1);
            $this->state_id_cache[$cache_key] = !empty($ids) ? $ids[0] : false;
        }
        return $this->state_id_cache[$cache_key];
    }

    /**
     * Sync all customers (WooCommerce customer role only).
     */
    public function sync_all_customers($limit = 100) {
        $synced = 0;
        $failed = 0;

        $users = get_users([
            'role__in' => ['customer', 'subscriber'],
            'number'   => $limit,
            'orderby'  => 'registered',
            'order'    => 'DESC',
        ]);

        foreach ($users as $user) {
            $result = $this->sync_customer($user->ID);
            if ($result !== false) {
                $synced++;
            } else {
                $failed++;
            }
            usleep(100000);
        }

        update_option('woo_odoo_last_sync_customers', gmdate('c'), false);
        return ['synced' => $synced, 'failed' => $failed];
    }
}
