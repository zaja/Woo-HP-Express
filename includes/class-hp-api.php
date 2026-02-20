<?php
/**
 * HP Express API Class
 * Handles all communication with HP (Hrvatska Pošta) DXWebAPI
 */

if (!defined('ABSPATH')) {
    exit;
}

class HP_Express_API {
    
    private $test_mode = true;
    private $username = '';
    private $password = '';
    private $cecode = '';
    
    // API URLs
    private $auth_url_test = 'https://dxwebapit.posta.hr:9000/api/authentication/client_auth';
    private $auth_url_prod = 'https://dxwebapi.posta.hr:9000/api/authentication/client_auth';
    private $api_url_test = 'https://dxwebapit.posta.hr:9020/api';
    private $api_url_prod = 'https://dxwebapi.posta.hr:9020/api';
    
    // Token cache transient name
    private $token_transient = 'hp_express_auth_token';
    
    // Services
    public static $services = array(
        26 => 'Paket 24 D+1',
        29 => 'Paket 24 D+2',
        32 => 'Paket 24 D+3',
        38 => 'Paket 24 D+4',
        39 => 'EasyReturn D+3 (opcija 1)',
        40 => 'EasyReturn D+3 (opcija 2)',
        46 => 'Paletna pošiljka D+5',
    );
    
    // Delivery types
    public static $delivery_types = array(
        1 => 'Adresa',
        2 => 'Pošta',
        3 => 'Paketomat',
    );
    
    // Parcel sizes (for parcel locker)
    public static $parcel_sizes = array(
        'X' => 'XS (9x16x64 cm)',
        'S' => 'S (9x38x64 cm)',
        'M' => 'M (19x38x64 cm)',
        'L' => 'L (39x38x64 cm)',
    );
    
    // Additional services
    public static $additional_services = array(
        1 => 'Osobna dostava',
        3 => 'Dostava subotom',
        4 => 'Povratnica (AR)',
        9 => 'Otkupna pošiljka (COD)',
        11 => 'Prikup subotom',
        29 => 'Obavijest pošiljatelju',
        30 => 'Obavijest primatelju',
        31 => 'Email pošiljatelju',
        32 => 'Email primatelju',
        38 => 'Nestandardni format',
        45 => 'EasyReturn (opcija 3)',
        46 => 'Osjetljiv sadržaj',
        47 => 'Konsolidirana pošiljka',
        54 => 'Povrat prazne euro-palete',
    );
    
    public function __construct() {
        $this->load_settings();
    }
    
    /**
     * Load settings from options
     */
    private function load_settings() {
        $options = get_option('hp_express_settings', array());
        $this->test_mode = ($options['test_mode'] ?? '1') === '1';
        $this->username = $options['username'] ?? '';
        $this->password = !empty($options['password']) ? base64_decode($options['password']) : '';
        $this->cecode = $options['cecode'] ?? '';
    }
    
    /**
     * Get auth URL based on mode
     */
    private function get_auth_url() {
        return $this->test_mode ? $this->auth_url_test : $this->auth_url_prod;
    }
    
    /**
     * Get API URL based on mode
     */
    private function get_api_url() {
        return $this->test_mode ? $this->api_url_test : $this->api_url_prod;
    }
    
    /**
     * Ping API to check availability
     */
    public function ping() {
        $url = str_replace('/api', '', $this->get_api_url()) . '/api/ping';
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => !$this->test_mode,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        return $body;
    }
    
    /**
     * Get authentication token
     */
    public function get_token() {
        // Check cached token
        $cached = get_transient($this->token_transient);
        if ($cached) {
            return $cached;
        }
        
        if (empty($this->username) || empty($this->password)) {
            return new WP_Error('no_credentials', __('HP Express API credentials nisu konfigurirani.', 'woo-hp-express'));
        }
        
        $response = wp_remote_post($this->get_auth_url(), array(
            'timeout' => 30,
            'sslverify' => !$this->test_mode,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'username' => $this->username,
                'password' => $this->password,
            )),
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code !== 200 || empty($body['accessToken'])) {
            $error_msg = $body['message'] ?? __('Autentikacija nije uspjela.', 'woo-hp-express');
            return new WP_Error('auth_failed', $error_msg);
        }
        
        $token = $body['accessToken'];
        $expires = isset($body['expiresIn']) ? intval($body['expiresIn']) - 300 : 13800; // 4h - 5min buffer
        
        set_transient($this->token_transient, $token, $expires);
        
        return $token;
    }
    
    /**
     * Clear cached token
     */
    public function clear_token() {
        delete_transient($this->token_transient);
    }
    
    /**
     * Make authenticated API request
     */
    private function request($endpoint, $data = array(), $method = 'POST') {
        $token = $this->get_token();
        if (is_wp_error($token)) {
            return $token;
        }
        
        $url = $this->get_api_url() . $endpoint;
        
        $args = array(
            'timeout' => 60,
            'sslverify' => !$this->test_mode,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ),
        );
        
        if ($method === 'POST') {
            $args['body'] = json_encode($data);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        // Token expired - retry once
        if ($code === 401) {
            $this->clear_token();
            $token = $this->get_token();
            if (is_wp_error($token)) {
                return $token;
            }
            
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            if ($method === 'POST') {
                $response = wp_remote_post($url, $args);
            } else {
                $response = wp_remote_get($url, $args);
            }
            
            if (is_wp_error($response)) {
                return $response;
            }
            
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);
        }
        
        if ($code !== 200) {
            $error_msg = $result['message'] ?? sprintf(__('API greška (HTTP %d)', 'woo-hp-express'), $code);
            return new WP_Error('api_error', $error_msg);
        }
        
        return $result;
    }
    
    /**
     * Create shipment orders
     */
    public function create_shipment($parcel_data, $return_label = true) {
        $data = array(
            'parcels' => array($parcel_data),
            'return_address_label' => $return_label,
        );
        
        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('HP Express API Request: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        $result = $this->request('/shipment/create_shipment_orders', $data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Check for API-level errors
        if (!empty($result['ShipmentOrdersList'])) {
            $order_result = $result['ShipmentOrdersList'][0];
            if ($order_result['ResponseStatus'] === 1) {
                $error_msg = $order_result['ErrorMessage'] ?? __('Greška pri kreiranju pošiljke.', 'woo-hp-express');
                return new WP_Error('shipment_error', $error_msg, $order_result);
            }
        }
        
        return $result;
    }
    
    /**
     * Cancel shipment orders
     */
    public function cancel_shipment($client_reference_number) {
        $data = array(
            'parcels' => array(
                array('client_reference_number' => $client_reference_number),
            ),
        );
        
        $result = $this->request('/shipment/cancel_shipment_orders', $data);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Check result
        if (is_array($result) && !empty($result[0])) {
            if ($result[0]['ResponseStatus'] === 1) {
                $error_msg = $result[0]['ErrorMessage'] ?? __('Greška pri otkazivanju pošiljke.', 'woo-hp-express');
                return new WP_Error('cancel_error', $error_msg);
            }
        }
        
        return $result;
    }
    
    /**
     * Get shipment status
     */
    public function get_shipment_status($barcodes) {
        if (!is_array($barcodes)) {
            $barcodes = array($barcodes);
        }
        
        $data = array(
            'barcodes' => array_map(function($barcode) {
                return array('barcode' => $barcode);
            }, $barcodes),
        );
        
        return $this->request('/shipment/fetch_shipment_status', $data);
    }
    
    /**
     * Get shipping labels
     * @param array $barcodes List of barcodes
     * @param string $client_reference_number Optional client reference
     * @param int $format 1=PDF CODE39, 2=ZPL, 3=PDF CODE128
     * @param bool $a4 A4 format (4 labels per page)
     */
    public function get_shipping_labels($barcodes = array(), $client_reference_number = '', $format = 1, $a4 = false) {
        $data = array(
            'A4' => $a4,
            'format' => $format,
        );
        
        if (!empty($client_reference_number)) {
            $data['client_reference_number'] = $client_reference_number;
        }
        
        if (!empty($barcodes)) {
            $data['barcodes'] = array_map(function($barcode) {
                return array('barcode' => $barcode);
            }, $barcodes);
        }
        
        return $this->request('/shipment/fetch_shipping_labels', $data);
    }
    
    /**
     * Get parcel delivery points (post offices and parcel lockers)
     * @param string $facility_type ALL, PU (post offices), PAK (parcel lockers)
     * @param string $search_text Filter by name, location, postal code
     * @param int $next_week 0=current week, 1=current and next week
     */
    public function get_delivery_points($facility_type = 'ALL', $search_text = '', $next_week = 0) {
        $data = array(
            'facilityType' => $facility_type,
            'nextWeek' => $next_week,
            'searchText' => $search_text,
        );
        
        return $this->request('/delivery_point/fetch_parcel_delivery_point', $data);
    }
    
    /**
     * Get CECODE
     */
    public function get_cecode() {
        return $this->cecode;
    }
    
    /**
     * Check if test mode
     */
    public function is_test_mode() {
        return $this->test_mode;
    }
    
    /**
     * Parse address into street and house number
     */
    public static function parse_address($address) {
        $street = $address;
        $hnum = '.';
        $hnum_suffix = '';
        
        // Try to extract house number from end of address
        // Patterns: "Ulica 123", "Ulica 123/A", "Ulica 123A", "Ulica 123 A"
        if (preg_match('/^(.+?)\s+(\d+)\s*([a-zA-Z\/].*)?$/u', trim($address), $matches)) {
            $street = trim($matches[1]);
            $hnum = $matches[2];
            $hnum_suffix = isset($matches[3]) ? trim($matches[3]) : '';
        }
        
        return array(
            'street' => mb_substr($street, 0, 75),
            'hnum' => mb_substr($hnum, 0, 10),
            'hnum_suffix' => mb_substr($hnum_suffix, 0, 10),
        );
    }
    
    /**
     * Format Croatian phone number for parcel locker delivery
     */
    public static function format_phone($phone) {
        // Remove all non-digits except +
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        // Remove country code if present
        $phone = preg_replace('/^(\+385|00385|385)/', '0', $phone);
        
        // Ensure it starts with 0
        if (substr($phone, 0, 1) !== '0') {
            $phone = '0' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Validate Croatian mobile phone
     */
    public static function is_valid_mobile($phone) {
        $phone = self::format_phone($phone);
        // Croatian mobile prefixes: 091, 092, 095, 097, 098, 099
        return preg_match('/^09[125789]\d{6,7}$/', $phone);
    }
}
