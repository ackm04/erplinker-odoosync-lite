<?php
declare(strict_types=1);
/**
 * Odoo XML-RPC API Client
 *
 * Connects to Odoo 18/19 via XML-RPC using WordPress built-in IXR client.
 * No external dependencies.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

// Use our bundled XML-RPC client (WP_HTTP_IXR_Client was removed in WP 6.4).
require_once __DIR__ . '/class-woo-odoo-xmlrpc-client.php';
require_once __DIR__ . '/contracts/interface-woo-odoo-api.php';

class Woo_Odoo_API implements Woo_Odoo_API_Interface {

    /** @var string Odoo URL */
    private $url;

    /** @var string Database name */
    private $db;

    /** @var string Username */
    private $username;

    /** @var string Password */
    private $password;

    /** @var int Authenticated user ID */
    private $uid;

    /** @var bool Connection verified */
    private $verified = false;

    /**
     * Authentication method: 'password' (default) or 'api_key'.
     * When 'api_key' is used the common.authenticate call is skipped;
     * the API key is passed directly as the password in execute_kw and
     * uid defaults to 1 (overridable via 'odoo_api_uid').
     *
     * @var string
     */
    private $auth_method = 'password';

    /**
     * Constructor.
     *
     * @param array|null $config Accepts: odoo_url, odoo_db, odoo_username,
     *                           odoo_password, auth_method ('password'|'api_key'),
     *                           odoo_api_key, odoo_api_uid.
     */
    public function __construct($config = null) {
        if ($config === null) {
            $config = get_option('woo_odoo_connector_settings', []);
        }
        $this->url         = rtrim($config['odoo_url'] ?? '', '/');
        $this->db          = $config['odoo_db'] ?? '';
        $this->username    = $config['odoo_username'] ?? '';
        $this->auth_method = $config['auth_method'] ?? 'password';

        if ($this->auth_method === 'api_key') {
            // Use the API key as the "password" for execute_kw calls.
            $api_key        = $config['odoo_api_key'] ?? '';
            if (class_exists('Woo_Odoo_Encryption') && Woo_Odoo_Encryption::is_encrypted($api_key)) {
                $api_key = Woo_Odoo_Encryption::decrypt($api_key);
            }
            $this->password = $api_key;
            // The uid used for execute_kw when skipping authenticate.
            $this->uid      = (int) ($config['odoo_api_uid'] ?? 1);
            $this->verified = !empty($api_key); // Skip auth call; key is static.
        } else {
            $this->password = $config['odoo_password'] ?? '';
            if (class_exists('Woo_Odoo_Encryption') && Woo_Odoo_Encryption::is_encrypted($this->password)) {
                $this->password = Woo_Odoo_Encryption::decrypt($this->password);
            }
        }
    }

    /**
     * Test connection.
     *
     * @return bool
     */
    public function test_connection() {
        try {
            $this->authenticate();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Authenticate with Odoo.
     *
     * When auth_method is 'api_key' this is a no-op because the API key is
     * passed directly to execute_kw; no session token is required.
     *
     * @return int User ID
     * @throws Exception On auth failure.
     */
    public function authenticate() {
        // API-key mode: the key is passed as the password to every execute_kw
        // call.  No common.authenticate round-trip is needed.
        if ($this->auth_method === 'api_key') {
            if (empty($this->password)) {
                throw new Exception(__('Odoo API key is not configured.', 'erplinker-odoosync-lite'));
            }
            $this->verified = true;
            return $this->uid;
        }

        if (empty($this->url) || empty($this->db) || empty($this->username) || empty($this->password)) {
            throw new Exception(__('Odoo connection settings are incomplete.', 'erplinker-odoosync-lite'));
        }

        $client = new Woo_Odoo_XMLRPC_Client($this->url . '/xmlrpc/2/common', false, false, 30);
        $client->query('authenticate', $this->db, $this->username, $this->password, []);

        if ($client->isError()) {
            self::throw_odoo_fault($client->getErrorCode(), $client->getErrorMessage(), 'authenticate');
        }

        $this->uid = $client->getResponse();
        if (!$this->uid) {
            throw new Exception(__('Odoo authentication failed. Check credentials.', 'erplinker-odoosync-lite'));
        }

        $this->verified = true;
        return $this->uid;
    }

    /**
     * Ensure authenticated.
     */
    private function ensure_authenticated() {
        if (!$this->verified || !$this->uid) {
            $this->authenticate();
        }
    }

    /**
     * Execute Odoo model method.
     *
     * @param string $model  Odoo model
     * @param string $method Method name
     * @param array  $args   Positional args
     * @param array  $kwargs Keyword args
     * @return mixed
     */
    public function execute($model, $method, $args = [], $kwargs = []) {
        $this->ensure_authenticated();

        // Check rate limit
        if (class_exists('Woo_Odoo_Rate_Limiter') && !Woo_Odoo_Rate_Limiter::is_allowed('api_call')) {
            $info = Woo_Odoo_Rate_Limiter::get_info('api_call');
            throw new Woo_Odoo_Rate_Limit_Exception(
                sprintf(__('Rate limit exceeded. Try again in %d seconds.', 'erplinker-odoosync-lite'), $info['reset_in']),
                429,
                ['rate_limit' => $info]
            );
        }

        $start_time = microtime(true);

        $client = new Woo_Odoo_XMLRPC_Client($this->url . '/xmlrpc/2/object', false, false, 30);
        $client->query('execute_kw', $this->db, $this->uid, $this->password, $model, $method, $args, $kwargs);

        $duration = microtime(true) - $start_time;

        if ($client->isError()) {
            if (class_exists('Woo_Odoo_Metrics')) {
                Woo_Odoo_Metrics::record_api_call("{$model}.{$method}", false, $duration);
            }
            self::throw_odoo_fault($client->getErrorCode(), $client->getErrorMessage(), "{$model}.{$method}");
        }

        // Record successful API call
        if (class_exists('Woo_Odoo_Metrics')) {
            Woo_Odoo_Metrics::record_api_call("{$model}.{$method}", true, $duration);
        }

        return $client->getResponse();
    }

    /**
     * Search and read.
     *
     * @param array $context Optional e.g. ['lang' => 'fr_FR']
     */
    public function search_read($model, $domain = [], $fields = [], $limit = 0, $offset = 0, $context = []) {
        $kwargs = [];
        if (!empty($fields)) {
            $kwargs['fields'] = $fields;
        }
        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }
        if ($offset > 0) {
            $kwargs['offset'] = $offset;
        }
        if (!empty($context)) {
            $kwargs['context'] = $context;
        }
        return $this->execute($model, 'search_read', [$domain], $kwargs);
    }

    /**
     * Create record.
     *
     * @param string $model
     * @param array  $vals
     * @param array  $context Optional e.g. ['lang' => 'fr_FR']
     * @return int Created ID
     */
    public function create($model, $vals, $context = []) {
        $kwargs = !empty($context) ? ['context' => $context] : [];
        $result = $this->execute($model, 'create', [$vals], $kwargs);
        return is_array($result) ? (int) $result[0] : (int) $result;
    }

    /**
     * Update records.
     *
     * @param array $context Optional e.g. ['lang' => 'fr_FR']
     */
    public function write($model, $ids, $vals, $context = []) {
        $ids = is_array($ids) ? $ids : [(int) $ids];
        $kwargs = !empty($context) ? ['context' => $context] : [];
        return $this->execute($model, 'write', [$ids, $vals], $kwargs);
    }

    /**
     * Search IDs.
     *
     * @return array
     */
    public function search($model, $domain = [], $limit = 0) {
        $kwargs = $limit > 0 ? ['limit' => $limit] : [];
        $result = $this->execute($model, 'search', [$domain], $kwargs);
        return is_array($result) ? $result : [$result];
    }

    /**
     * Read records.
     */
    public function read($model, $ids, $fields = []) {
        $ids = is_array($ids) ? $ids : [(int) $ids];
        $kwargs = !empty($fields) ? ['fields' => $fields] : [];
        return $this->execute($model, 'read', [$ids], $kwargs);
    }

    /**
     * Get product.product ID from product.template ID (for sale order lines).
     *
     * @param int $template_id
     * @return int
     */
    public function get_product_variant_id($template_id) {
        $ids = $this->search('product.product', [['product_tmpl_id', '=', (int) $template_id]], 1);
        return !empty($ids) ? (int) $ids[0] : (int) $template_id;
    }

    /**
     * Get companies for accounting settings.
     *
     * @return array [['id' => 1, 'name' => 'My Company'], ...]
     */
    public function get_companies() {
        $rows = $this->search_read('res.company', [], ['id', 'name'], 100);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get sale journals (for invoices).
     *
     * @param int $company_id Optional company filter
     * @return array
     */
    public function get_invoice_journals($company_id = 0) {
        $domain = [['type', '=', 'sale']];
        if ($company_id > 0) {
            $domain[] = ['company_id', '=', $company_id];
        }
        $rows = $this->search_read('account.journal', $domain, ['id', 'name', 'code'], 50);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get fiscal positions.
     *
     * @param int $company_id Optional company filter
     * @return array
     */
    public function get_fiscal_positions($company_id = 0) {
        $domain = [];
        if ($company_id > 0) {
            $domain[] = ['company_id', '=', $company_id];
        }
        $rows = $this->search_read('account.fiscal.position', $domain, ['id', 'name'], 100);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get receivable (debtors) accounts. Uses account_type (Odoo 14+).
     *
     * @param int $company_id Optional company filter
     * @return array
     */
    public function get_receivable_accounts($company_id = 0) {
        $domain = [
            ['account_type', 'in', ['asset_receivable', 'asset_current']],
            ['deprecated', '=', false],
        ];
        if ($company_id > 0) {
            $domain[] = ['company_id', '=', $company_id];
        }
        $rows = $this->search_read('account.account', $domain, ['id', 'name', 'code'], 100);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get sale taxes.
     *
     * @param int $company_id Optional company filter
     * @return array
     */
    public function get_sale_taxes($company_id = 0) {
        $domain = [['type_tax_use', '=', 'sale']];
        if ($company_id > 0) {
            $domain[] = ['company_id', '=', $company_id];
        }
        $rows = $this->search_read('account.tax', $domain, ['id', 'name', 'amount'], 100);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Get installed Odoo languages.
     *
     * @return array [['code' => 'en_US', 'name' => 'English'], ...]
     */
    public function get_languages() {
        $rows = $this->search_read('res.lang', [['active', '=', true]], ['code', 'name'], 100);
        return is_array($rows) ? $rows : [];
    }

    /**
     * Map Odoo XML-RPC fault codes to typed exceptions (F-043).
     *
     * @param int $code Fault code
     * @param string $message Fault message
     * @param string $context Operation name
     * @throws Woo_Odoo_Not_Found_Exception
     * @throws Woo_Odoo_Auth_Exception
     * @throws Woo_Odoo_User_Exception
     * @throws Woo_Odoo_Server_Exception
     * @throws Exception
     */
    private static function throw_odoo_fault($code, $message, $context = '') {
        $code = (int) $code;
        if ($code === 1) {
            throw new Woo_Odoo_Not_Found_Exception($message, $code, ['context' => $context]);
        }
        if ($code === 2) {
            update_option('woo_odoo_auth_failed', time(), false);
            throw new Woo_Odoo_Auth_Exception($message, $code, ['context' => $context]);
        }
        if ($code === 3) {
            throw new Woo_Odoo_User_Exception($message, $code, ['context' => $context]);
        }
        if ($code >= 100) {
            throw new Woo_Odoo_Server_Exception($message, $code, ['context' => $context]);
        }
        throw new Exception(sprintf(__('Odoo API error: %s', 'erplinker-odoosync-lite'), $message), $code);
    }
}
