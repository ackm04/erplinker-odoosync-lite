<?php
/**
 * Advanced Logger with Structured Logging
 *
 * Features:
 * - PSR-3 compatible log levels
 * - Structured JSON logging
 * - PII masking
 * - Log rotation
 * - Context enrichment
 * - Performance tracking
 *
 * @package Woo_Odoo_Connector
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Logger {

    /** Log levels (PSR-3) */
    const EMERGENCY = 'emergency';
    const ALERT = 'alert';
    const CRITICAL = 'critical';
    const ERROR = 'error';
    const WARNING = 'warning';
    const NOTICE = 'notice';
    const INFO = 'info';
    const DEBUG = 'debug';

    /** @var array Log level priorities */
    private static $levels = [
        self::EMERGENCY => 800,
        self::ALERT => 700,
        self::CRITICAL => 600,
        self::ERROR => 500,
        self::WARNING => 400,
        self::NOTICE => 300,
        self::INFO => 200,
        self::DEBUG => 100,
    ];

    /** @var string Current log level threshold */
    private static $threshold = self::INFO;

    /** @var array PII patterns to mask */
    private static $pii_patterns = [
        'email' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/',
        'phone' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
        'credit_card' => '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/',
        'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
        'password' => '/(password|passwd|pwd)[\s:=]+\S+/i',
        'api_key' => '/(api[_-]?key|token|secret)[\s:=]+\S+/i',
    ];

    /** @var int Max log entries to keep */
    private static $max_entries = 1000;

    /** @var bool Enable performance tracking */
    private static $track_performance = true;

    /** @var array Email hashes collected during current log() for GDPR purge (F-013) */
    private static $email_hashes = [];

    /**
     * Log a message.
     *
     * @param string $level Log level
     * @param string $message Message
     * @param array $context Additional context
     */
    public static function log($level, $message, array $context = []) {
        if (!self::should_log($level)) {
            return;
        }

        $entry = self::build_log_entry($level, $message, $context);
        self::write_log($entry);
        
        // Trigger action for external handlers
        do_action('woo_odoo_log', $level, $message, $context, $entry);
    }

    /**
     * Emergency: system is unusable.
     */
    public static function emergency($message, array $context = []) {
        self::log(self::EMERGENCY, $message, $context);
    }

    /**
     * Alert: action must be taken immediately.
     */
    public static function alert($message, array $context = []) {
        self::log(self::ALERT, $message, $context);
    }

    /**
     * Critical: critical conditions.
     */
    public static function critical($message, array $context = []) {
        self::log(self::CRITICAL, $message, $context);
    }

    /**
     * Error: error conditions.
     */
    public static function error($message, array $context = []) {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * Warning: warning conditions.
     */
    public static function warning($message, array $context = []) {
        self::log(self::WARNING, $message, $context);
    }

    /**
     * Notice: normal but significant condition.
     */
    public static function notice($message, array $context = []) {
        self::log(self::NOTICE, $message, $context);
    }

    /**
     * Info: informational messages.
     */
    public static function info($message, array $context = []) {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Debug: debug-level messages.
     */
    public static function debug($message, array $context = []) {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Log exception with full context.
     *
     * @param Throwable $exception
     * @param string $level
     * @param array $context
     */
    public static function exception(Throwable $exception, $level = self::ERROR, array $context = []) {
        $context = array_merge($context, [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        if ($exception instanceof Woo_Odoo_Exception) {
            $context = array_merge($context, [
                'category' => $exception->getCategory(),
                'retryable' => $exception->isRetryable(),
                'exception_context' => $exception->getContext(),
            ]);
        }

        self::log($level, 'Exception: ' . $exception->getMessage(), $context);
    }

    /**
     * Build structured log entry.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return array
     */
    private static function build_log_entry($level, $message, array $context) {
        // Mask PII in message and context
        $message = self::mask_pii($message);
        $context = self::mask_pii_recursive($context);

        $entry = [
            'timestamp' => current_time('mysql'),
            'timestamp_unix' => time(),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
        $hashes = self::get_and_clear_email_hashes();
        if (!empty($hashes)) {
            $entry['_email_hashes'] = $hashes;
        }

        // Add system context
        $entry['system'] = [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'unknown',
            'plugin_version' => WOO_ODOO_CONNECTOR_VERSION,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];

        // Add request context if available
        $raw_ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (!empty($raw_uri)) {
            // Strip non-printable / control characters before storing.
            $req_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $req_host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $entry['request'] = [
                'method'     => $req_method,
                'uri'        => esc_url_raw(wp_parse_url(home_url('/'), PHP_URL_SCHEME) . '://' . $req_host . $raw_uri),
                'user_agent' => mb_substr($raw_ua, 0, 255),
                'ip'         => self::get_client_ip(),
            ];
        }

        // Add user context
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $entry['user'] = [
                'id' => $user->ID,
                'login' => $user->user_login,
                'roles' => $user->roles,
            ];
        }

        // Add trace ID for distributed tracing
        $entry['trace_id'] = self::get_trace_id();

        return $entry;
    }

    /**
     * Write log entry to storage.
     *
     * @param array $entry
     */
    private static function write_log(array $entry) {
        $logs = get_option('woo_odoo_structured_log', []);
        
        // Add entry
        $logs[] = $entry;
        
        // Rotate if needed
        if (count($logs) > self::$max_entries) {
            $logs = array_slice($logs, -self::$max_entries);
        }
        
        update_option('woo_odoo_structured_log', $logs, false);

        if (apply_filters('woo_odoo_json_logging', defined('WOO_ODOO_JSON_LOG') && WOO_ODOO_JSON_LOG)) {
            $masked_context = $entry['context'] ?? [];
            if (isset($entry['_email_hashes'])) {
                unset($masked_context['_email_hashes']);
            }
            error_log( wp_json_encode( [ // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,QITStandard.PHP.DebugCode.DebugFunctionFound -- Optional structured JSON logging.
                'timestamp' => gmdate('c'),
                'level' => $entry['level'],
                'message' => $entry['message'],
                'context' => $masked_context,
                'plugin' => 'erplinker-odoosync',
            ] ) );
        }

        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && !defined('WOO_ODOO_JSON_LOG')) {
            error_log( '[Woo-Odoo] ' . $entry['level'] . ': ' . $entry['message'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log,QITStandard.PHP.DebugCode.DebugFunctionFound -- Mirrors WP_DEBUG_LOG.
        }
    }

    /**
     * Remove log entries that contain the given email (GDPR erasure). F-013.
     *
     * @param string $email
     */
    public static function purge_logs_for_email($email) {
        if ($email === '') {
            return;
        }
        $target = hash('sha256', strtolower($email));
        $logs = get_option('woo_odoo_structured_log', []);
        if (!is_array($logs)) {
            return;
        }
        $logs = array_filter($logs, function ($entry) use ($target) {
            $hashes = $entry['_email_hashes'] ?? [];
            return !in_array($target, $hashes, true);
        });
        $logs = array_values($logs);
        update_option('woo_odoo_structured_log', $logs, false);
    }

    /**
     * Check if level should be logged.
     *
     * @param string $level
     * @return bool
     */
    private static function should_log($level) {
        $settings = get_option('woo_odoo_connector_settings', []);
        if (empty($settings['enable_logging'])) {
            return false;
        }

        $level_priority = self::$levels[$level] ?? 0;
        $threshold_priority = self::$levels[self::$threshold] ?? 0;

        return $level_priority >= $threshold_priority;
    }

    /**
     * Mask PII in string with enhanced patterns.
     *
     * @param string $text
     * @return string
     */
    private static function mask_pii($text) {
        if (!is_string($text)) {
            return $text;
        }

        foreach (self::$pii_patterns as $type => $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) use ($type) {
                $val = $matches[0] ?? '';
                if ($type === 'email' && $val !== '') {
                    self::$email_hashes[] = hash('sha256', strtolower($val));
                }
                return '[' . strtoupper($type) . '_MASKED]';
            }, $text);
        }

        return $text;
    }

    /**
     * Return and clear email hashes collected during this log call (F-013).
     *
     * @return array
     */
    private static function get_and_clear_email_hashes() {
        $h = array_unique(self::$email_hashes);
        self::$email_hashes = [];
        return array_values($h);
    }

    /**
     * Recursively mask PII in arrays with sensitive key detection.
     *
     * @param mixed $data
     * @return mixed
     */
    private static function mask_pii_recursive($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                // Check if key name indicates sensitive data
                $key_lower = strtolower($key);
                $sensitive_keys = [
                    'password', 'passwd', 'pwd',
                    'api_key', 'apikey', 'api_secret',
                    'token', 'access_token', 'refresh_token',
                    'secret', 'private_key',
                    'credit_card', 'cc_number', 'cvv',
                    'ssn', 'social_security',
                ];
                
                $is_sensitive = false;
                foreach ($sensitive_keys as $sensitive) {
                    if (strpos($key_lower, $sensitive) !== false) {
                        $data[$key] = '***REDACTED***';
                        $is_sensitive = true;
                        break;
                    }
                }
                
                if (!$is_sensitive) {
                    $data[$key] = self::mask_pii_recursive($value);
                }
            }
        } elseif (is_string($data)) {
            $data = self::mask_pii($data);
        }
        return $data;
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if (!empty($_SERVER[$key])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$key])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        
        return 'unknown';
    }

    /**
     * Get or generate trace ID for request.
     *
     * @return string
     */
    private static function get_trace_id() {
        static $trace_id = null;
        
        if ($trace_id === null) {
            $trace_id = wp_generate_uuid4();
        }
        
        return $trace_id;
    }

    /**
     * Get logs with filters.
     *
     * @param array $filters
     * @return array
     */
    public static function get_logs(array $filters = []) {
        $logs = get_option('woo_odoo_structured_log', []);
        
        // Apply filters
        if (!empty($filters['level'])) {
            $logs = array_filter($logs, function($entry) use ($filters) {
                return $entry['level'] === $filters['level'];
            });
        }
        
        if (!empty($filters['since'])) {
            $since = strtotime($filters['since']);
            $logs = array_filter($logs, function($entry) use ($since) {
                return $entry['timestamp_unix'] >= $since;
            });
        }
        
        if (!empty($filters['search'])) {
            $search = strtolower($filters['search']);
            $logs = array_filter($logs, function($entry) use ($search) {
                return strpos(strtolower($entry['message']), $search) !== false;
            });
        }
        
        // Sort by timestamp descending
        usort($logs, function($a, $b) {
            return $b['timestamp_unix'] - $a['timestamp_unix'];
        });
        
        return array_values($logs);
    }

    /**
     * Clear all logs.
     */
    public static function clear_logs() {
        delete_option('woo_odoo_structured_log');
    }

    /**
     * Export logs to JSON file with path validation.
     *
     * @param string $filepath
     * @return bool
     */
    public static function export_logs($filepath) {
        // Validate and sanitize filepath
        $filepath = sanitize_file_name(basename($filepath));
        
        // Ensure it's within allowed directory (WordPress uploads)
        $upload_dir = wp_upload_dir();
        $allowed_base = $upload_dir['basedir'] . '/woo-odoo-logs';
        
        // Create directory if it doesn't exist
        if (!file_exists($allowed_base)) {
            wp_mkdir_p($allowed_base);
        }
        
        $full_path = $allowed_base . '/' . $filepath;
        
        // Validate filename extension
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(json|csv)$/', $filepath)) {
            Woo_Odoo_Logger::error('Invalid filename for log export', ['filename' => $filepath]);
            return false;
        }
        
        // Ensure resolved path is still within allowed directory
        $real_path = realpath(dirname($full_path));
        $real_allowed = realpath($allowed_base);
        
        if ($real_path !== $real_allowed) {
            Woo_Odoo_Logger::error('Path traversal attempt detected', ['path' => $filepath]);
            return false;
        }
        
        $logs = get_option('woo_odoo_structured_log', []);
        return file_put_contents($full_path, json_encode($logs, JSON_PRETTY_PRINT)) !== false;
    }

    /**
     * Get log statistics.
     *
     * @return array
     */
    public static function get_stats() {
        $logs = get_option('woo_odoo_structured_log', []);
        
        $stats = [
            'total' => count($logs),
            'by_level' => [],
            'by_category' => [],
            'errors_last_hour' => 0,
            'errors_last_day' => 0,
        ];
        
        $hour_ago = time() - 3600;
        $day_ago = time() - 86400;
        
        foreach ($logs as $entry) {
            // Count by level
            $level = $entry['level'] ?? 'unknown';
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
            
            // Count by category
            $category = $entry['context']['category'] ?? 'unknown';
            $stats['by_category'][$category] = ($stats['by_category'][$category] ?? 0) + 1;
            
            // Count recent errors
            if (in_array($level, [self::ERROR, self::CRITICAL, self::ALERT, self::EMERGENCY])) {
                if ($entry['timestamp_unix'] >= $hour_ago) {
                    $stats['errors_last_hour']++;
                }
                if ($entry['timestamp_unix'] >= $day_ago) {
                    $stats['errors_last_day']++;
                }
            }
        }
        
        return $stats;
    }

    /**
     * Configure logger.
     *
     * @param array $config
     */
    public static function configure(array $config) {
        if (isset($config['threshold'])) {
            self::$threshold = $config['threshold'];
        }
        if (isset($config['max_entries'])) {
            self::$max_entries = max(100, (int) $config['max_entries']);
        }
        if (isset($config['track_performance'])) {
            self::$track_performance = (bool) $config['track_performance'];
        }
    }
}
