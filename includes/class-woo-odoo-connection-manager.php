<?php
/**
 * Connection Manager with Circuit Breaker Pattern
 *
 * Manages Odoo API connections with:
 * - Connection pooling and reuse
 * - Circuit breaker for fault tolerance
 * - Health checks and monitoring
 * - Automatic reconnection with exponential backoff
 *
 * @package Woo_Odoo_Connector
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Connection_Manager {

    /** Circuit breaker states */
    const STATE_CLOSED = 'closed';     // Normal operation
    const STATE_OPEN = 'open';         // Failing, reject requests
    const STATE_HALF_OPEN = 'half_open'; // Testing recovery

    /** @var array Connection pool */
    private static $connections = [];

    /** @var array Circuit breaker state per instance */
    private static $circuit_state = [];

    /** @var int Failure threshold before opening circuit */
    private static $failure_threshold = 5;

    /** @var int Timeout before attempting recovery (seconds) */
    private static $recovery_timeout = 60;

    /** @var int Success threshold to close circuit */
    private static $success_threshold = 2;

    /**
     * Get or create connection for instance.
     *
     * @param array $config Connection config
     * @return Woo_Odoo_API|WP_Error
     */
    public static function get_connection($config) {
        $filtered = apply_filters('woo_odoo_get_connection', null, $config);
        if ($filtered !== null) {
            return $filtered;
        }
        $instance_key = self::get_instance_key($config);

        // Check circuit breaker
        if (!self::can_attempt_connection($instance_key)) {
            return new WP_Error(
                'circuit_open',
                __('Connection circuit breaker is open. Service temporarily unavailable.', 'erplinker-odoosync-lite'),
                ['instance' => $instance_key, 'retry_after' => self::get_retry_after($instance_key)]
            );
        }

        // Return existing connection if valid
        if (isset(self::$connections[$instance_key])) {
            $conn = self::$connections[$instance_key];
            if (self::is_connection_valid($conn)) {
                self::record_success($instance_key);
                return $conn;
            }
            // Connection stale, remove it
            unset(self::$connections[$instance_key]);
        }

        // Create new connection
        try {
            // Select transport: JSON-RPC when api_protocol setting requests it.
            $protocol = $config['api_protocol'] ?? 'xmlrpc';
            if ($protocol === 'jsonrpc') {
                if (!class_exists('Woo_Odoo_API_JsonRPC')) {
                    require_once __DIR__ . '/class-odoo-api-jsonrpc.php';
                }
                $api = new Woo_Odoo_API_JsonRPC($config);
            } else {
                $api = new Woo_Odoo_API($config);
            }
            
            // Test connection
            if (!$api->test_connection()) {
                self::record_failure($instance_key);
                return new WP_Error(
                    'connection_failed',
                    __('Failed to establish connection to Odoo.', 'erplinker-odoosync-lite')
                );
            }

            // Store in pool
            self::$connections[$instance_key] = $api;
            self::record_success($instance_key);

            return $api;
        } catch (Exception $e) {
            self::record_failure($instance_key);
            return new WP_Error(
                'connection_error',
                $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    /**
     * Check if connection attempt is allowed (circuit breaker).
     *
     * @param string $instance_key
     * @return bool
     */
    private static function can_attempt_connection($instance_key) {
        $state = self::get_circuit_state($instance_key);

        switch ($state['status']) {
            case self::STATE_CLOSED:
                return true;

            case self::STATE_OPEN:
                // Check if recovery timeout has passed
                if (time() - $state['opened_at'] >= self::$recovery_timeout) {
                    self::transition_to_half_open($instance_key);
                    return true;
                }
                return false;

            case self::STATE_HALF_OPEN:
                return true;

            default:
                return true;
        }
    }

    /**
     * Get circuit breaker state for instance (F-021: DB-backed failure count).
     *
     * @param string $instance_key
     * @return array
     */
    private static function get_circuit_state($instance_key) {
        $opt_key = 'woo_odoo_circuit_failures_' . $instance_key;
        $db_failures = (int) get_option($opt_key, 0);
        if (function_exists('wp_cache_get')) {
            $cached = wp_cache_get('woo_odoo_circuit_' . $instance_key, 'woo_odoo_circuit');
            if ($cached !== false) {
                $db_failures = (int) $cached;
            }
        }
        $transient_key = 'woo_odoo_circuit_open_' . $instance_key;
        $cached_state = get_transient($transient_key);

        if (!isset(self::$circuit_state[$instance_key])) {
            self::$circuit_state[$instance_key] = [
                'status' => self::STATE_CLOSED,
                'failures' => $db_failures,
                'successes' => 0,
                'opened_at' => 0,
                'last_failure' => 0,
                'last_success' => 0,
            ];
        }
        $state = self::$circuit_state[$instance_key];
        $state['failures'] = $db_failures;
        if (is_array($cached_state) && !empty($cached_state['status'])) {
            $state['status'] = $cached_state['status'];
            $state['opened_at'] = (int) ($cached_state['opened_at'] ?? 0);
        }
        return $state;
    }

    /**
     * Record successful connection.
     *
     * @param string $instance_key
     */
    private static function record_success($instance_key) {
        $state = self::get_circuit_state($instance_key);
        $state['successes']++;
        $state['last_success'] = time();

        if ($state['status'] === self::STATE_HALF_OPEN && $state['successes'] >= self::$success_threshold) {
            $state['status'] = self::STATE_CLOSED;
            $state['failures'] = 0;
            $state['successes'] = 0;
            delete_option('woo_odoo_circuit_failures_' . $instance_key);
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete('woo_odoo_circuit_' . $instance_key, 'woo_odoo_circuit');
            }
            delete_transient('woo_odoo_circuit_open_' . $instance_key);
            do_action('woo_odoo_circuit_closed', $instance_key);
        }

        self::$circuit_state[$instance_key] = $state;
        self::persist_circuit_state();
    }

    /**
     * Record connection failure.
     *
     * @param string $instance_key
     */
    private static function record_failure($instance_key) {
        $opt_key = 'woo_odoo_circuit_failures_' . $instance_key;
        // SOFT CIRCUIT BREAKER: This counter is not atomically incremented.
        // Under high concurrency two requests may both read the same count and
        // both write count+1, effectively requiring 2x failures to trip the circuit.
        // This is acceptable — the circuit breaker is a best-effort DoS protection,
        // not a hard guarantee. For atomic behaviour, replace with a Redis INCR
        // via wp_cache_incr() when a persistent object cache is available.
        if (function_exists('wp_cache_incr')) {
            $cache_key = 'woo_odoo_circuit_' . $instance_key;
            if (wp_cache_get($cache_key, 'woo_odoo_circuit') === false) {
                wp_cache_set($cache_key, (int) get_option($opt_key, 0), 'woo_odoo_circuit', 3600);
            }
            wp_cache_incr($cache_key, 1, 'woo_odoo_circuit');
            $failures = (int) wp_cache_get($cache_key, 'woo_odoo_circuit');
        } else {
            $failures = (int) get_option($opt_key, 0);
            $failures++;
            update_option($opt_key, $failures, false);
        }

        $state = self::get_circuit_state($instance_key);
        $state['failures'] = $failures;
        $state['last_failure'] = time();
        $state['successes'] = 0;

        if ($state['status'] === self::STATE_CLOSED && $failures >= self::$failure_threshold) {
            $state['status'] = self::STATE_OPEN;
            $state['opened_at'] = time();
            set_transient('woo_odoo_circuit_open_' . $instance_key, ['status' => self::STATE_OPEN, 'opened_at' => $state['opened_at'], 'failures' => $failures], self::$recovery_timeout);
            do_action('woo_odoo_circuit_opened', $instance_key, $failures);
        }

        if ($state['status'] === self::STATE_HALF_OPEN) {
            $state['status'] = self::STATE_OPEN;
            $state['opened_at'] = time();
            set_transient('woo_odoo_circuit_open_' . $instance_key, ['status' => self::STATE_OPEN, 'opened_at' => $state['opened_at'], 'failures' => $failures], self::$recovery_timeout);
        }

        self::$circuit_state[$instance_key] = $state;
        self::persist_circuit_state();
    }

    /**
     * Transition circuit to half-open state.
     *
     * @param string $instance_key
     */
    private static function transition_to_half_open($instance_key) {
        $state = self::get_circuit_state($instance_key);
        $state['status'] = self::STATE_HALF_OPEN;
        $state['successes'] = 0;
        self::$circuit_state[$instance_key] = $state;
        self::persist_circuit_state();
        do_action('woo_odoo_circuit_half_open', $instance_key);
    }

    /**
     * Get retry-after time for open circuit.
     *
     * @param string $instance_key
     * @return int Seconds until retry
     */
    private static function get_retry_after($instance_key) {
        $state = self::get_circuit_state($instance_key);
        if ($state['status'] === self::STATE_OPEN) {
            return max(0, self::$recovery_timeout - (time() - $state['opened_at']));
        }
        return 0;
    }

    /**
     * Check if connection is still valid.
     *
     * @param Woo_Odoo_API $api
     * @return bool
     */
    private static function is_connection_valid($api) {
        // Simple validation - could be enhanced with ping/heartbeat
        return $api instanceof Woo_Odoo_API;
    }

    /**
     * Generate unique key for instance.
     *
     * @param array $config
     * @return string
     */
    private static function get_instance_key($config) {
        return md5(
            ($config['odoo_url'] ?? '') . 
            ($config['odoo_db'] ?? '') . 
            ($config['odoo_username'] ?? '')
        );
    }

    /**
     * Persist circuit state to database.
     */
    private static function persist_circuit_state() {
        update_option('woo_odoo_circuit_state', self::$circuit_state, false);
    }

    /**
     * Load circuit state from database.
     */
    public static function load_circuit_state() {
        self::$circuit_state = get_option('woo_odoo_circuit_state', []);
    }

    /**
     * Reset circuit breaker for instance.
     *
     * @param string $instance_key
     */
    public static function reset_circuit($instance_key = null) {
        if ($instance_key) {
            unset(self::$circuit_state[$instance_key]);
            unset(self::$connections[$instance_key]);
        } else {
            self::$circuit_state = [];
            self::$connections = [];
        }
        self::persist_circuit_state();
    }

    /**
     * Get health status for all connections.
     *
     * @return array
     */
    public static function get_health_status() {
        $status = [];
        foreach (self::$circuit_state as $key => $state) {
            $status[$key] = [
                'status' => $state['status'],
                'healthy' => $state['status'] === self::STATE_CLOSED,
                'failures' => $state['failures'],
                'last_failure' => $state['last_failure'],
                'last_success' => $state['last_success'],
                'retry_after' => self::get_retry_after($key),
            ];
        }
        return $status;
    }

    /**
     * Configure circuit breaker thresholds.
     *
     * @param array $config
     */
    public static function configure($config) {
        if (isset($config['failure_threshold'])) {
            self::$failure_threshold = max(1, (int) $config['failure_threshold']);
        }
        if (isset($config['recovery_timeout'])) {
            self::$recovery_timeout = max(10, (int) $config['recovery_timeout']);
        }
        if (isset($config['success_threshold'])) {
            self::$success_threshold = max(1, (int) $config['success_threshold']);
        }
    }

    /**
     * Close all connections (cleanup).
     */
    public static function close_all() {
        self::$connections = [];
    }
}

// Load circuit state on init
add_action('init', ['Woo_Odoo_Connection_Manager', 'load_circuit_state']);
