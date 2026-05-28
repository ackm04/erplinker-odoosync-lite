<?php
declare(strict_types=1);
/**
 * Encryption Manager for Sensitive Data
 * Remediations: F-009 (per-credential salt), F-010 (random_bytes for IV)
 *
 * Handles encryption/decryption of sensitive data like passwords and API keys.
 * Uses per-credential random salt with PBKDF2 for key derivation.
 *
 * @package Woo_Odoo_Connector
 * @since 2.0.0
 */

defined('ABSPATH') || exit;

class Woo_Odoo_Encryption {

    /** @var string Encryption method */
    const METHOD = 'AES-256-CBC';

    /** @var string Encrypted value prefix (legacy) */
    const PREFIX = 'encrypted:';

    /** @var string PBKDF2 static-salt prefix (legacy) */
    const PREFIX_V2 = 'encrypted_v2:';

    /** @var string Per-credential salt prefix (current) */
    const PREFIX_V3 = 'encrypted_v3:';

    /** @var int Salt length in bytes */
    const SALT_LENGTH = 16;

    /** @var int PBKDF2 iterations */
    const PBKDF2_ITERATIONS = 100000;

    /**
     * Encrypt sensitive data with a unique salt per encryption.
     *
     * @param string $data Data to encrypt.
     * @return string Encrypted data with PREFIX_V3 prefix.
     * @throws \RuntimeException When openssl_encrypt returns false.
     */
    public static function encrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        if (self::is_encrypted($data)) {
            return $data;
        }

        $salt   = random_bytes(self::SALT_LENGTH);
        $key    = self::derive_key($salt);
        $iv_len = openssl_cipher_iv_length(self::METHOD);
        $iv     = random_bytes((int) $iv_len);

        $ciphertext = openssl_encrypt($data, self::METHOD, $key, 0, $iv);

        if (false === $ciphertext) {
            throw new \RuntimeException(
                'Woo_Odoo_Encryption::encrypt failed — openssl_encrypt returned false'
            );
        }

        return self::PREFIX_V3 . base64_encode($salt . $iv . $ciphertext);
    }

    /**
     * Decrypt sensitive data. Supports PREFIX, PREFIX_V2, and PREFIX_V3 formats.
     *
     * @param string $encrypted Encrypted data with prefix.
     * @return string Decrypted data, or empty string when input is empty.
     */
    public static function decrypt(string $encrypted): string {
        if (empty($encrypted)) {
            return '';
        }

        if (!self::is_encrypted($encrypted)) {
            return $encrypted;
        }

        try {
            $is_v3 = strpos($encrypted, self::PREFIX_V3) === 0;
            $is_v2 = strpos($encrypted, self::PREFIX_V2) === 0;
            $prefix = $is_v3 ? self::PREFIX_V3 : ($is_v2 ? self::PREFIX_V2 : self::PREFIX);

            $encrypted = substr($encrypted, strlen($prefix));
            $data = base64_decode($encrypted, true);

            if ($data === false) {
                throw new Exception('Invalid encrypted data');
            }

            $iv_length = openssl_cipher_iv_length(self::METHOD);

            if ($is_v3) {
                $salt = substr($data, 0, self::SALT_LENGTH);
                $iv = substr($data, self::SALT_LENGTH, $iv_length);
                $encrypted_data = substr($data, self::SALT_LENGTH + $iv_length);
                $key = self::derive_key($salt);
            } else {
                $key = $is_v2 ? self::get_legacy_pbkdf2_key() : self::get_legacy_encryption_key();
                $iv = substr($data, 0, $iv_length);
                $encrypted_data = substr($data, $iv_length);
            }

            $decrypted = openssl_decrypt($encrypted_data, self::METHOD, $key, 0, $iv);

            if ($decrypted === false) {
                throw new Exception('Decryption failed');
            }

            return $decrypted;
        } catch (Exception $e) {
            Woo_Odoo_Logger::error('Decryption failed', [
                'error' => $e->getMessage(),
            ]);
            return '';
        }
    }

    /**
     * Derive encryption key from WordPress secrets and per-credential salt.
     *
     * @param string $salt 16-byte salt
     * @return string 32-byte key
     */
    private static function derive_key($salt) {
        $password = AUTH_KEY . SECURE_AUTH_KEY;
        return hash_pbkdf2('sha256', $password, $salt, self::PBKDF2_ITERATIONS, 32, true);
    }

    /**
     * Legacy PBKDF2 key (static NONCE_SALT) for decrypting PREFIX_V2 only.
     *
     * @return string
     */
    private static function get_legacy_pbkdf2_key() {
        $password = AUTH_KEY . SECURE_AUTH_KEY;
        $salt = NONCE_SALT;
        return hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    }

    /**
     * Check if data is already encrypted (has a known prefix).
     *
     * @param string $data Value to check.
     * @return bool True when $data starts with a known encryption prefix.
     */
    public static function is_encrypted(string $data): bool {
        return (
            strpos($data, self::PREFIX) === 0 ||
            strpos($data, self::PREFIX_V2) === 0 ||
            strpos($data, self::PREFIX_V3) === 0
        );
    }

    /**
     * Get legacy encryption key (for PREFIX only).
     *
     * @return string
     */
    private static function get_legacy_encryption_key() {
        $salt = wp_salt('auth') . wp_salt('secure_auth') . wp_salt('logged_in') . wp_salt('nonce');
        return hash('sha256', $salt, true);
    }

    /**
     * Migrate all stored credentials to per-credential salt (PREFIX_V3).
     * Re-encrypts main settings and instance passwords. Safe to run multiple times.
     * Wired to WP-CLI and admin migration flow.
     *
     * @return array{main_settings: array, instances: int, errors: array}
     */
    public static function migrate_legacy_credentials(): array {
        $results = [
            'main_settings' => [],
            'instances' => 0,
            'errors' => [],
        ];

        try {
            $settings = get_option('woo_odoo_connector_settings', []);
            $fields = ['odoo_password', 'odoo_api_key', 'webhook_secret'];

            foreach ($fields as $field) {
                if (empty($settings[$field]) || !self::is_encrypted($settings[$field])) {
                    continue;
                }
                if (strpos($settings[$field], self::PREFIX_V3) === 0) {
                    continue;
                }
                try {
                    $decrypted = self::decrypt($settings[$field]);
                    $settings[$field] = self::encrypt($decrypted);
                    $results['main_settings'][] = $field;
                } catch (Exception $e) {
                    $results['errors'][] = $field . ': ' . $e->getMessage();
                }
            }

            if (!empty($results['main_settings'])) {
                update_option('woo_odoo_connector_settings', $settings);
            }

            $instances = get_option('woo_odoo_instances', []);
            foreach ($instances as $key => &$instance) {
                if (empty($instance['password']) || !self::is_encrypted($instance['password'])) {
                    continue;
                }
                if (strpos($instance['password'], self::PREFIX_V3) === 0) {
                    continue;
                }
                try {
                    $decrypted = self::decrypt($instance['password']);
                    $instance['password'] = self::encrypt($decrypted);
                    $results['instances']++;
                } catch (Exception $e) {
                    $results['errors'][] = 'Instance ' . $key . ': ' . $e->getMessage();
                }
            }

            if ($results['instances'] > 0) {
                update_option('woo_odoo_instances', $instances);
            }

            if (empty($results['errors'])) {
                update_option('woo_odoo_encryption_v3_migrated', true);
            }
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Migrate existing passwords to encrypted format (plaintext → encrypted).
     *
     * @return array Migration results
     */
    public static function migrate_passwords(): array {
        $results = [
            'main_settings' => false,
            'instances' => 0,
            'errors' => [],
        ];

        try {
            $settings = get_option('woo_odoo_connector_settings', []);
            if (!empty($settings['odoo_password']) && !self::is_encrypted($settings['odoo_password'])) {
                $settings['odoo_password'] = self::encrypt($settings['odoo_password']);
                update_option('woo_odoo_connector_settings', $settings);
                $results['main_settings'] = true;
            }

            $instances = get_option('woo_odoo_instances', []);
            foreach ($instances as $key => &$instance) {
                if (!empty($instance['password']) && !self::is_encrypted($instance['password'])) {
                    $instance['password'] = self::encrypt($instance['password']);
                    $results['instances']++;
                }
            }

            if ($results['instances'] > 0) {
                update_option('woo_odoo_instances', $instances);
            }

            update_option('woo_odoo_encryption_migrated', true);
            self::migrate_legacy_credentials();
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Migrate existing encrypted data from legacy to PBKDF2 then to per-salt (V3).
     *
     * @return array Migration results
     */
    public static function migrate_to_pbkdf2() {
        $results = [
            'main_settings' => [],
            'instances' => 0,
            'errors' => [],
        ];

        try {
            $settings = get_option('woo_odoo_connector_settings', []);
            $fields_to_migrate = ['odoo_password', 'odoo_api_key', 'webhook_secret'];

            foreach ($fields_to_migrate as $field) {
                if (empty($settings[$field])) {
                    continue;
                }
                if (strpos($settings[$field], self::PREFIX) === 0 && strpos($settings[$field], self::PREFIX_V2) !== 0 && strpos($settings[$field], self::PREFIX_V3) !== 0) {
                    try {
                        $decrypted = self::decrypt($settings[$field]);
                        $settings[$field] = self::encrypt($decrypted);
                        $results['main_settings'][] = $field;
                    } catch (Exception $e) {
                        $results['errors'][] = $field . ': ' . $e->getMessage();
                    }
                }
            }

            if (!empty($results['main_settings'])) {
                update_option('woo_odoo_connector_settings', $settings);
            }

            $instances = get_option('woo_odoo_instances', []);
            foreach ($instances as $key => &$instance) {
                if (empty($instance['password'])) {
                    continue;
                }
                if (strpos($instance['password'], self::PREFIX) === 0 && strpos($instance['password'], self::PREFIX_V2) !== 0 && strpos($instance['password'], self::PREFIX_V3) !== 0) {
                    try {
                        $decrypted = self::decrypt($instance['password']);
                        $instance['password'] = self::encrypt($decrypted);
                        $results['instances']++;
                    } catch (Exception $e) {
                        $results['errors'][] = 'Instance ' . $key . ': ' . $e->getMessage();
                    }
                }
            }

            if ($results['instances'] > 0) {
                update_option('woo_odoo_instances', $instances);
            }

            if (empty($results['errors'])) {
                update_option('woo_odoo_encryption_v2_migrated', true);
            }
            self::migrate_legacy_credentials();
        } catch (Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    /**
     * Check if PBKDF2 migration is needed.
     *
     * @return bool
     */
    public static function needs_pbkdf2_migration() {
        if (get_option('woo_odoo_encryption_v2_migrated')) {
            return false;
        }
        $settings = get_option('woo_odoo_connector_settings', []);
        $fields_to_check = ['odoo_password', 'odoo_api_key', 'webhook_secret'];
        foreach ($fields_to_check as $field) {
            if (!empty($settings[$field]) && strpos($settings[$field], self::PREFIX) === 0 && strpos($settings[$field], self::PREFIX_V2) !== 0 && strpos($settings[$field], self::PREFIX_V3) !== 0) {
                return true;
            }
        }
        $instances = get_option('woo_odoo_instances', []);
        foreach ($instances as $instance) {
            if (!empty($instance['password']) && strpos($instance['password'], self::PREFIX) === 0 && strpos($instance['password'], self::PREFIX_V2) !== 0 && strpos($instance['password'], self::PREFIX_V3) !== 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if migration is needed (plaintext passwords).
     *
     * @return bool
     */
    public static function needs_migration() {
        if (get_option('woo_odoo_encryption_migrated')) {
            return false;
        }
        $settings = get_option('woo_odoo_connector_settings', []);
        if (!empty($settings['odoo_password']) && !self::is_encrypted($settings['odoo_password'])) {
            return true;
        }
        $instances = get_option('woo_odoo_instances', []);
        foreach ($instances as $instance) {
            if (!empty($instance['password']) && !self::is_encrypted($instance['password'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Encrypt field in array.
     *
     * @param array $data
     * @param string $field
     * @return array
     */
    public static function encrypt_field(&$data, $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = self::encrypt($data[$field]);
        }
        return $data;
    }

    /**
     * Decrypt field in array.
     *
     * @param array $data
     * @param string $field
     * @return array
     */
    public static function decrypt_field(&$data, $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $data[$field] = self::decrypt($data[$field]);
        }
        return $data;
    }

    /**
     * Test encryption/decryption.
     *
     * @return bool
     */
    public static function test() {
        $test_data = 'test_password_' . wp_generate_password(20);
        $encrypted = self::encrypt($test_data);
        $decrypted = self::decrypt($encrypted);
        return $test_data === $decrypted;
    }
}
