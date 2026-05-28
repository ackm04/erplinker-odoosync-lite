<?php
/**
 * Base Exception for Woo Odoo Connector
 *
 * @package Woo_Odoo_Connector
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Exception extends Exception {
    
    /** @var array Additional context data */
    protected $context = [];
    
    /** @var bool Whether this error is retryable */
    protected $retryable = false;
    
    /** @var string Error category */
    protected $category = 'general';

    /**
     * Constructor.
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param array $context Additional context
     * @param Throwable|null $previous Previous exception
     */
    public function __construct($message = '', $code = 0, array $context = [], ?Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get context data.
     *
     * @return array
     */
    public function getContext() {
        return $this->context;
    }

    /**
     * Check if error is retryable.
     *
     * @return bool
     */
    public function isRetryable() {
        return $this->retryable;
    }

    /**
     * Get error category.
     *
     * @return string
     */
    public function getCategory() {
        return $this->category;
    }

    /**
     * Convert to array for logging.
     *
     * @return array
     */
    public function toArray() {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'category' => $this->category,
            'retryable' => $this->retryable,
            'context' => $this->context,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString(),
        ];
    }
}

/**
 * Connection related exceptions
 */
class Woo_Odoo_Connection_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'connection';
}

/**
 * Authentication failures
 */
class Woo_Odoo_Auth_Exception extends Woo_Odoo_Exception {
    protected $retryable = false;
    protected $category = 'authentication';
}

/**
 * API call failures
 */
class Woo_Odoo_API_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'api';
}

/**
 * Rate limiting errors
 */
class Woo_Odoo_Rate_Limit_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'rate_limit';
}

/**
 * Data validation errors
 */
class Woo_Odoo_Validation_Exception extends Woo_Odoo_Exception {
    protected $retryable = false;
    protected $category = 'validation';
}

/**
 * Sync operation failures
 */
class Woo_Odoo_Sync_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'sync';
}

/**
 * Configuration errors
 */
class Woo_Odoo_Config_Exception extends Woo_Odoo_Exception {
    protected $retryable = false;
    protected $category = 'configuration';
}

/**
 * Timeout errors
 */
class Woo_Odoo_Timeout_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'timeout';
}

/**
 * Data mapping errors
 */
class Woo_Odoo_Mapping_Exception extends Woo_Odoo_Exception {
    protected $retryable = false;
    protected $category = 'mapping';
}

/**
 * Circuit breaker open
 */
class Woo_Odoo_Circuit_Open_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'circuit_breaker';
}

/**
 * Odoo record not found (fault code 1)
 */
class Woo_Odoo_Not_Found_Exception extends Woo_Odoo_Exception {
    protected $retryable = false;
    protected $category = 'not_found';
}

/**
 * Odoo user/access error (fault code 3)
 */
class Woo_Odoo_User_Exception extends Woo_Odoo_Exception {
    protected $retryable = false;
    protected $category = 'user_error';
}

/**
 * Odoo server error (fault code 100+) — retry with backoff
 */
class Woo_Odoo_Server_Exception extends Woo_Odoo_Exception {
    protected $retryable = true;
    protected $category = 'server';
}
