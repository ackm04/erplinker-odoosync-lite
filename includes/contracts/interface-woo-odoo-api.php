<?php
/**
 * Odoo API Contract
 *
 * Both Woo_Odoo_API (XML-RPC) and Woo_Odoo_API_JsonRPC must implement this
 * interface. Third-party code can depend on this interface without coupling to
 * a concrete transport layer.
 *
 * @package Woo_Odoo_Connector
 * @since   3.0.0
 */

defined('ABSPATH') || exit;

interface Woo_Odoo_API_Interface {

    /**
     * Test connection to Odoo.
     *
     * @return bool
     */
    public function test_connection();

    /**
     * Authenticate and return the Odoo uid.
     *
     * @return int Authenticated user ID.
     * @throws Exception On authentication failure.
     */
    public function authenticate();

    /**
     * Execute any Odoo model method.
     *
     * @param string $model  e.g. 'product.template'
     * @param string $method e.g. 'search_read'
     * @param array  $args   Positional arguments.
     * @param array  $kwargs Keyword arguments.
     * @return mixed
     * @throws Exception On API failure.
     */
    public function execute( $model, $method, $args = [], $kwargs = [] );

    /**
     * Search and read records.
     *
     * @param string $model
     * @param array  $domain
     * @param array  $fields
     * @param int    $limit
     * @param int    $offset
     * @param array  $context
     * @return array
     */
    public function search_read( $model, $domain = [], $fields = [], $limit = 0, $offset = 0, $context = [] );

    /**
     * Create a record and return its ID.
     *
     * @param string $model
     * @param array  $vals
     * @param array  $context
     * @return int
     */
    public function create( $model, $vals, $context = [] );

    /**
     * Write values to one or more records.
     *
     * @param string    $model
     * @param int|array $ids
     * @param array     $vals
     * @param array     $context
     * @return bool
     */
    public function write( $model, $ids, $vals, $context = [] );

    /**
     * Return IDs matching domain.
     *
     * @param string $model
     * @param array  $domain
     * @param int    $limit
     * @return array
     */
    public function search( $model, $domain = [], $limit = 0 );

    /**
     * Read specific fields for given IDs.
     *
     * @param string    $model
     * @param int|array $ids
     * @param array     $fields
     * @return array
     */
    public function read( $model, $ids, $fields = [] );

    /**
     * Get product.product ID from product.template ID.
     *
     * @param int $template_id
     * @return int
     */
    public function get_product_variant_id( $template_id );

    /**
     * Return installed Odoo languages.
     *
     * @return array [['code' => 'en_US', 'name' => 'English'], …]
     */
    public function get_languages();
}
