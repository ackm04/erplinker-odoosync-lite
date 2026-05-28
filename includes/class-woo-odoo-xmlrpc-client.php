<?php
/**
 * Bundled XML-RPC client using WordPress HTTP API.
 *
 * WordPress 6.4+ removed WP_HTTP_IXR_Client and the entire Incutio IXR library
 * from core. This drop-in replacement provides the same query/isError/
 * getResponse interface using wp_remote_post() + PHP's SimpleXML, so the
 * plugin works on any WordPress version ≥ 5.8 with no external dependencies.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

class Woo_Odoo_XMLRPC_Client {

    /** @var string Full XML-RPC endpoint URL */
    private string $url;

    /** @var int Request timeout in seconds */
    private int $timeout;

    /** @var mixed Last decoded response value */
    private $response = null;

    /** @var bool Whether the last query resulted in an error */
    private bool $is_error = false;

    /** @var string Error message from last failed query */
    private string $error_message = '';

    /** @var int Fault code from last XML-RPC fault */
    private int $error_code = 0;

    /**
     * Constructor — matches WP_HTTP_IXR_Client signature so it is a drop-in.
     *
     * @param string $url     Full endpoint URL (e.g. https://odoo.example.com/xmlrpc/2/common).
     * @param mixed  $unused1 Ignored (was $credentials in WP_HTTP_IXR_Client).
     * @param mixed  $unused2 Ignored (was $port).
     * @param int    $timeout HTTP timeout in seconds.
     */
    public function __construct( string $url, $unused1 = false, $unused2 = false, int $timeout = 30 ) {
        $this->url     = $url;
        $this->timeout = $timeout;
    }

    /**
     * Execute an XML-RPC method call.
     *
     * @param string $method XML-RPC method name.
     * @param mixed  ...$args Arguments to pass.
     * @return bool True on success, false on error (check isError / getErrorMessage).
     */
    public function query( string $method, ...$args ): bool {
        $this->is_error      = false;
        $this->response      = null;
        $this->error_message = '';
        $this->error_code    = 0;

        $body = $this->build_request( $method, $args );

        $http_response = wp_remote_post( $this->url, [
            'timeout'    => $this->timeout,
            'headers'    => [ 'Content-Type' => 'text/xml; charset=utf-8' ],
            'body'       => $body,
            'sslverify'  => apply_filters( 'woo_odoo_xmlrpc_sslverify', true ),
        ] );

        if ( is_wp_error( $http_response ) ) {
            $this->is_error      = true;
            $this->error_message = $http_response->get_error_message();
            return false;
        }

        $status = wp_remote_retrieve_response_code( $http_response );
        $xml    = wp_remote_retrieve_body( $http_response );

        if ( 200 !== $status ) {
            $this->is_error      = true;
            $this->error_message = 'HTTP ' . $status;
            return false;
        }

        return $this->parse_response( $xml );
    }

    // -------------------------------------------------------------------------
    // WP_HTTP_IXR_Client-compatible getters
    // -------------------------------------------------------------------------

    /** @return bool */
    public function isError(): bool { return $this->is_error; }

    /** @return string */
    public function getErrorMessage(): string { return $this->error_message; }

    /** @return int */
    public function getErrorCode(): int { return $this->error_code; }

    /** @return mixed */
    public function getResponse() { return $this->response; }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the XML-RPC <methodCall> envelope.
     */
    private function build_request( string $method, array $params ): string {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<methodCall>';
        $xml .= '<methodName>' . htmlspecialchars( $method, ENT_XML1, 'UTF-8' ) . '</methodName>';
        $xml .= '<params>';
        foreach ( $params as $param ) {
            $xml .= '<param>' . $this->encode_value( $param ) . '</param>';
        }
        $xml .= '</params></methodCall>';
        return $xml;
    }

    /**
     * Recursively encode a PHP value as an XML-RPC <value> element.
     */
    private function encode_value( $value ): string {
        if ( null === $value ) {
            return '<value><nil/></value>';
        }

        if ( is_bool( $value ) ) {
            return '<value><boolean>' . ( $value ? '1' : '0' ) . '</boolean></value>';
        }

        if ( is_int( $value ) ) {
            return '<value><int>' . $value . '</int></value>';
        }

        if ( is_float( $value ) ) {
            return '<value><double>' . $value . '</double></value>';
        }

        if ( is_array( $value ) ) {
            $keys    = array_keys( $value );
            $is_list = empty( $value ) || ( $keys === range( 0, count( $value ) - 1 ) );

            if ( $is_list ) {
                $xml = '<value><array><data>';
                foreach ( $value as $v ) {
                    $xml .= $this->encode_value( $v );
                }
                return $xml . '</data></array></value>';
            }

            // Associative array → XML-RPC struct
            $xml = '<value><struct>';
            foreach ( $value as $k => $v ) {
                $xml .= '<member>'
                      . '<name>' . htmlspecialchars( (string) $k, ENT_XML1, 'UTF-8' ) . '</name>'
                      . $this->encode_value( $v )
                      . '</member>';
            }
            return $xml . '</struct></value>';
        }

        // Default: string
        return '<value><string>' . htmlspecialchars( (string) $value, ENT_XML1, 'UTF-8' ) . '</string></value>';
    }

    /**
     * Parse an XML-RPC <methodResponse> envelope.
     */
    private function parse_response( string $xml ): bool {
        $prev   = libxml_use_internal_errors( true );
        $sxe    = simplexml_load_string( $xml );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        if ( false === $sxe || ! empty( $errors ) ) {
            $this->is_error      = true;
            $this->error_message = 'Malformed XML-RPC response';
            return false;
        }

        // Fault response
        if ( isset( $sxe->fault ) ) {
            $fault               = $this->decode_value( $sxe->fault->value );
            $this->is_error      = true;
            $this->error_code    = (int)    ( $fault['faultCode']   ?? -1 );
            $this->error_message = (string) ( $fault['faultString'] ?? 'Unknown XML-RPC fault' );
            return false;
        }

        $this->response = $this->decode_value( $sxe->params->param->value );
        return true;
    }

    /**
     * Recursively decode a SimpleXMLElement <value> node into a PHP value.
     */
    private function decode_value( \SimpleXMLElement $value ) {
        $children = $value->children();

        // Implicit string — no type wrapper tag
        if ( 0 === $children->count() ) {
            return (string) $value;
        }

        $type_node = $children[0];
        $type      = $type_node->getName();

        switch ( $type ) {
            case 'int':
            case 'i4':
            case 'i8':
                return (int) (string) $type_node;

            case 'double':
                return (float) (string) $type_node;

            case 'boolean':
                return (bool) (int) (string) $type_node;

            case 'nil':
                return null;

            case 'base64':
                return base64_decode( (string) $type_node );

            case 'string':
                return (string) $type_node;

            case 'array':
                $result = [];
                if ( isset( $type_node->data ) ) {
                    foreach ( $type_node->data->value as $v ) {
                        $result[] = $this->decode_value( $v );
                    }
                }
                return $result;

            case 'struct':
                $result = [];
                foreach ( $type_node->member as $member ) {
                    $result[ (string) $member->name ] = $this->decode_value( $member->value );
                }
                return $result;

            default:
                return (string) $type_node;
        }
    }
}
