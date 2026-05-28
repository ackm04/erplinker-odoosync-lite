<?php
/**
 * Sync class contract
 *
 * All entity sync classes (product, order, customer, stock) must implement
 * this interface. It ensures a stable, predictable API surface for third-party
 * code, WP-CLI commands, and unit tests.
 *
 * @package Woo_Odoo_Connector
 * @since   3.0.0
 */

defined('ABSPATH') || exit;

interface Woo_Odoo_Sync_Interface {

    /**
     * Sync a single entity (identified by its WP/WC ID) to Odoo.
     *
     * @param int $id WC/WP entity ID (product post ID, order ID, user ID, …).
     * @return int|false Odoo record ID, or false on failure.
     */
    public function sync( $id );

    /**
     * Sync all entities of this type (paginated, respects batch limits).
     *
     * @param int $limit Maximum records to process in this call.
     * @return array ['synced' => int, 'failed' => int]
     */
    public function sync_all( $limit = 100 );

    /**
     * Return a sync status summary for a given WC/WP entity.
     *
     * Implementations should return at minimum:
     *   [ 'odoo_id' => int|null, 'synced_at' => string|null, 'status' => 'synced'|'pending'|'failed' ]
     *
     * @param int $id WC/WP entity ID.
     * @return array
     */
    public function get_sync_status( $id );
}
