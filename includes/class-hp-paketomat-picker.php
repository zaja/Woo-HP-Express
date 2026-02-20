<?php
/**
 * HP Express Paketomat Picker
 * Handles parcel locker selection on checkout with Leaflet map
 */

if (!defined('ABSPATH')) {
    exit;
}

class HP_Express_Paketomat_Picker {
    
    private static $instance = null;
    private $paketomati_cache_key = 'hp_express_paketomati';
    private $cache_expiration = DAY_IN_SECONDS;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_review_order_before_payment', array($this, 'render_picker'));
        add_action('woocommerce_after_shipping_rate', array($this, 'render_picker_after_shipping'), 20, 2);
        add_action('woocommerce_checkout_update_order_review', array($this, 'update_order_review'));
        
        // Add data attributes to shipping methods
        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'add_paketomat_data_to_label'), 10, 2);
        add_action('woocommerce_after_shipping_rate', array($this, 'add_paketomat_script_data'), 10, 2);
        
        // Save paketomat to order
        add_action('woocommerce_checkout_create_order', array($this, 'save_paketomat_to_order'), 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_hp_get_paketomati', array($this, 'ajax_get_paketomati'));
        add_action('wp_ajax_nopriv_hp_get_paketomati', array($this, 'ajax_get_paketomati'));
        add_action('wp_ajax_hp_save_paketomat', array($this, 'ajax_save_paketomat'));
        add_action('wp_ajax_nopriv_hp_save_paketomat', array($this, 'ajax_save_paketomat'));
        
        // WooCommerce Blocks checkout support
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'save_paketomat_blocks'), 10, 2);
        add_action('woocommerce_checkout_order_created', array($this, 'save_paketomat_from_session'), 10, 1);
        
        // Validate paketomat selection
        add_action('woocommerce_checkout_process', array($this, 'validate_paketomat_selection'));
        
        // Display paketomat in order details
        add_action('woocommerce_admin_order_data_after_shipping_address', array($this, 'display_paketomat_admin'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_paketomat_customer'), 5);
    }
    
    /**
     * Enqueue scripts and styles for checkout
     */
    public function enqueue_scripts() {
        // Check for checkout page (classic or block)
        if (!is_checkout() && !has_block('woocommerce/checkout')) {
            return;
        }
        
        // Leaflet CSS
        wp_enqueue_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );
        
        // Leaflet JS
        wp_enqueue_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );
        
        // Paketomat picker CSS
        wp_enqueue_style(
            'hp-paketomat-picker',
            HP_EXPRESS_PLUGIN_URL . 'assets/css/paketomat-picker.css',
            array('leaflet'),
            HP_EXPRESS_VERSION
        );
        
        // Paketomat picker JS
        wp_enqueue_script(
            'hp-paketomat-picker',
            HP_EXPRESS_PLUGIN_URL . 'assets/js/paketomat-picker.js',
            array('jquery', 'leaflet'),
            HP_EXPRESS_VERSION,
            true
        );
        
        wp_localize_script('hp-paketomat-picker', 'hpPaketomatPicker', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hp_paketomat_nonce'),
            'strings' => array(
                'loading' => __('Učitavanje paketomata...', 'woo-hp-express'),
                'select' => __('Odaberi', 'woo-hp-express'),
                'selected' => __('Odabrano', 'woo-hp-express'),
                'search_placeholder' => __('Pretraži po gradu ili adresi...', 'woo-hp-express'),
                'no_results' => __('Nema pronađenih paketomata', 'woo-hp-express'),
                'error' => __('Greška pri učitavanju paketomata', 'woo-hp-express'),
            ),
            'defaultCenter' => array(45.815, 15.982), // Zagreb
            'defaultZoom' => 8,
        ));
    }
    
    /**
     * Render picker after paketomat shipping rate (only once)
     */
    public function render_picker_after_shipping($method, $index) {
        static $rendered = false;
        
        if ($rendered) {
            return;
        }
        
        // Only render after HP Express paketomat method
        if (strpos($method->get_id(), 'hp_express') !== false) {
            $meta = $method->get_meta_data();
            if (isset($meta['hp_is_paketomat']) && $meta['hp_is_paketomat'] === 'yes') {
                $rendered = true;
                $this->render_picker();
            }
        }
    }
    
    /**
     * Render the paketomat picker on checkout
     */
    public function render_picker() {
        static $picker_rendered = false;
        if ($picker_rendered) {
            return;
        }
        $picker_rendered = true;
        ?>
        <div id="hp-paketomat-picker-wrapper" style="display: none;">
            <h3><?php _e('Odaberi paketomat', 'woo-hp-express'); ?></h3>
            
            <div class="hp-paketomat-search">
                <input type="text" id="hp-paketomat-search" placeholder="<?php _e('Pretraži po gradu ili adresi...', 'woo-hp-express'); ?>">
            </div>
            
            <div class="hp-paketomat-container">
                <div id="hp-paketomat-map"></div>
                <div id="hp-paketomat-list"></div>
            </div>
            
            <div id="hp-paketomat-selected" style="display: none;">
                <strong><?php _e('Odabrani paketomat:', 'woo-hp-express'); ?></strong>
                <span id="hp-paketomat-selected-name"></span>
                <button type="button" id="hp-paketomat-change" class="button-link"><?php _e('Promijeni', 'woo-hp-express'); ?></button>
            </div>
            
            <input type="hidden" name="hp_paketomat_code" id="hp_paketomat_code" value="">
            <input type="hidden" name="hp_paketomat_name" id="hp_paketomat_name" value="">
            <input type="hidden" name="hp_paketomat_address" id="hp_paketomat_address" value="">
        </div>
        <?php
    }
    
    /**
     * Update order review - check if paketomat shipping is selected
     */
    public function update_order_review($posted_data) {
        // This is handled by JavaScript
    }
    
    /**
     * Add paketomat indicator to shipping label
     */
    public function add_paketomat_data_to_label($label, $method) {
        if (strpos($method->get_id(), 'hp_express') !== false) {
            $meta = $method->get_meta_data();
            if (isset($meta['hp_is_paketomat']) && $meta['hp_is_paketomat'] === 'yes') {
                $label .= ' <span class="hp-paketomat-indicator" style="display:none;" data-paketomat="1"></span>';
            }
        }
        return $label;
    }
    
    /**
     * Add script to mark paketomat methods
     */
    public function add_paketomat_script_data($method, $index) {
        if (strpos($method->get_id(), 'hp_express') !== false) {
            $meta = $method->get_meta_data();
            if (isset($meta['hp_is_paketomat']) && $meta['hp_is_paketomat'] === 'yes') {
                ?>
                <script>
                    (function() {
                        var input = document.querySelector('input[value="<?php echo esc_js($method->get_id()); ?>"]');
                        if (input) {
                            var li = input.closest('li');
                            if (li) {
                                li.classList.add('hp-paketomat-delivery');
                                li.setAttribute('data-delivery-type', '3');
                            }
                        }
                    })();
                </script>
                <?php
            }
        }
    }
    
    /**
     * AJAX: Get paketomati list
     */
    public function ajax_get_paketomati() {
        check_ajax_referer('hp_paketomat_nonce', 'nonce');
        
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        
        // Try to get from cache first
        $paketomati = $this->get_cached_paketomati();
        
        if ($paketomati === false) {
            // Fetch from API
            $api = WooHPExpress()->api();
            $result = $api->get_delivery_points('PAK', '', 0);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            $paketomati = $result['paketomatInfoList'] ?? array();
            
            // Cache the results
            set_transient($this->paketomati_cache_key, $paketomati, $this->cache_expiration);
        }
        
        // Filter by search if provided
        if (!empty($search)) {
            $search_lower = mb_strtolower($search);
            $paketomati = array_filter($paketomati, function($p) use ($search_lower) {
                return mb_strpos(mb_strtolower($p['name'] ?? ''), $search_lower) !== false ||
                       mb_strpos(mb_strtolower($p['city'] ?? ''), $search_lower) !== false ||
                       mb_strpos(mb_strtolower($p['address'] ?? ''), $search_lower) !== false ||
                       mb_strpos($p['zip'] ?? '', $search_lower) !== false;
            });
        }
        
        // Format for frontend
        $formatted = array_map(function($p) {
            return array(
                'code' => $p['code'] ?? '',
                'name' => $p['name'] ?? '',
                'address' => $p['address'] ?? '',
                'city' => $p['city'] ?? '',
                'zip' => $p['zip'] ?? '',
                'lat' => floatval($p['geoLat'] ?? 0),
                'lng' => floatval($p['getLng'] ?? 0),
            );
        }, array_values($paketomati));
        
        wp_send_json_success(array('paketomati' => $formatted));
    }
    
    /**
     * Get cached paketomati
     */
    private function get_cached_paketomati() {
        return get_transient($this->paketomati_cache_key);
    }
    
    /**
     * Validate paketomat selection on checkout
     */
    public function validate_paketomat_selection() {
        if (!$this->is_paketomat_shipping_selected()) {
            return;
        }
        
        if (empty($_POST['hp_paketomat_code'])) {
            wc_add_notice(__('Molimo odaberite paketomat za dostavu.', 'woo-hp-express'), 'error');
        }
    }
    
    /**
     * Check if paketomat shipping method is selected
     */
    private function is_paketomat_shipping_selected() {
        $chosen_methods = WC()->session->get('chosen_shipping_methods');
        if (empty($chosen_methods)) {
            return false;
        }
        
        foreach ($chosen_methods as $method) {
            if (strpos($method, 'hp_express') !== false) {
                // Get instance settings
                $parts = explode(':', $method);
                $instance_id = isset($parts[1]) ? intval($parts[1]) : 0;
                
                if ($instance_id > 0) {
                    $shipping_method = new WC_HP_Express_Shipping_Method($instance_id);
                    $delivery_type = $shipping_method->get_option('delivery_type', '1');
                    
                    if ($delivery_type === '3') { // Paketomat
                        return true;
                    }
                }
            }
        }
        
        return false;
    }
    
    /**
     * AJAX: Save paketomat to session
     */
    public function ajax_save_paketomat() {
        check_ajax_referer('hp_paketomat_nonce', 'nonce');
        
        $code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $address = isset($_POST['address']) ? sanitize_text_field($_POST['address']) : '';
        
        if (empty($code)) {
            wp_send_json_error(array('message' => 'No code provided'));
        }
        
        // Save to WC session
        if (WC()->session) {
            WC()->session->set('hp_paketomat_code', $code);
            WC()->session->set('hp_paketomat_name', $name);
            WC()->session->set('hp_paketomat_address', $address);
        }
        
        wp_send_json_success(array('saved' => true));
    }
    
    /**
     * Save paketomat to order (classic checkout)
     */
    public function save_paketomat_to_order($order, $data) {
        // Try POST data first (classic checkout)
        if (!empty($_POST['hp_paketomat_code'])) {
            $order->update_meta_data('_hp_paketomat_code', sanitize_text_field($_POST['hp_paketomat_code']));
            $order->update_meta_data('_hp_paketomat_name', sanitize_text_field($_POST['hp_paketomat_name']));
            $order->update_meta_data('_hp_paketomat_address', sanitize_text_field($_POST['hp_paketomat_address']));
        }
    }
    
    /**
     * Save paketomat from session (for Blocks checkout)
     */
    public function save_paketomat_from_session($order) {
        // Skip if already saved
        if ($order->get_meta('_hp_paketomat_code')) {
            return;
        }
        
        // Get from session
        if (WC()->session) {
            $code = WC()->session->get('hp_paketomat_code');
            $name = WC()->session->get('hp_paketomat_name');
            $address = WC()->session->get('hp_paketomat_address');
            
            if (!empty($code)) {
                $order->update_meta_data('_hp_paketomat_code', $code);
                $order->update_meta_data('_hp_paketomat_name', $name);
                $order->update_meta_data('_hp_paketomat_address', $address);
                $order->save();
                
                // Clear session
                WC()->session->set('hp_paketomat_code', null);
                WC()->session->set('hp_paketomat_name', null);
                WC()->session->set('hp_paketomat_address', null);
            }
        }
    }
    
    /**
     * Save paketomat for Blocks checkout via Store API
     */
    public function save_paketomat_blocks($order, $request) {
        // Get from session
        $this->save_paketomat_from_session($order);
    }
    
    /**
     * Display paketomat in admin order
     */
    public function display_paketomat_admin($order) {
        $paketomat_code = $order->get_meta('_hp_paketomat_code');
        if (empty($paketomat_code)) {
            return;
        }
        
        $paketomat_name = $order->get_meta('_hp_paketomat_name');
        $paketomat_address = $order->get_meta('_hp_paketomat_address');
        ?>
        <div class="address" style="margin-top: 15px; padding: 10px; background: #fff8e5; border-left: 3px solid #ffba00;">
            <p><strong><?php _e('Paketomat:', 'woo-hp-express'); ?></strong></p>
            <p>
                <?php echo esc_html($paketomat_name); ?><br>
                <?php echo esc_html($paketomat_address); ?><br>
                <code><?php echo esc_html($paketomat_code); ?></code>
            </p>
        </div>
        <?php
    }
    
    /**
     * Display paketomat in customer order view
     */
    public function display_paketomat_customer($order) {
        $paketomat_code = $order->get_meta('_hp_paketomat_code');
        if (empty($paketomat_code)) {
            return;
        }
        
        $paketomat_name = $order->get_meta('_hp_paketomat_name');
        $paketomat_address = $order->get_meta('_hp_paketomat_address');
        ?>
        <section class="woocommerce-hp-paketomat" style="margin: 20px 0;">
            <h2><?php _e('Paketomat za preuzimanje', 'woo-hp-express'); ?></h2>
            <table class="woocommerce-table shop_table">
                <tbody>
                    <tr>
                        <th><?php _e('Naziv:', 'woo-hp-express'); ?></th>
                        <td><?php echo esc_html($paketomat_name); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('Adresa:', 'woo-hp-express'); ?></th>
                        <td><?php echo esc_html($paketomat_address); ?></td>
                    </tr>
                </tbody>
            </table>
        </section>
        <?php
    }
    
    /**
     * Get selected paketomat code for order
     */
    public static function get_paketomat_code($order) {
        return $order->get_meta('_hp_paketomat_code');
    }
}

// Initialize
add_action('init', function() {
    HP_Express_Paketomat_Picker::instance();
});
