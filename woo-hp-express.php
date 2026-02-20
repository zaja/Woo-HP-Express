<?php
/**
 * Plugin Name: WooCommerce HP Express Shipping
 * Plugin URI: https://svejedobro.hr
 * Description: Integracija s HP Express (Hrvatska Pošta) dostavom - podrška za višestruke shipping metode, kreiranje pošiljki, ispis naljepnica i praćenje.
 * Version: 1.0.0
 * Author: Goran Zajec
 * Author URI: https://svejedobro.hr
 * Text Domain: woo-hp-express
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('HP_EXPRESS_VERSION', '1.0.0');
define('HP_EXPRESS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HP_EXPRESS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HP_EXPRESS_PLUGIN_BASENAME', plugin_basename(__FILE__));

// HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Main plugin class
 */
class WooHPExpress {
    
    private static $instance = null;
    private $api = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->includes();
        $this->init_hooks();
    }
    
    private function includes() {
        require_once HP_EXPRESS_PLUGIN_DIR . 'includes/class-hp-api.php';
        require_once HP_EXPRESS_PLUGIN_DIR . 'includes/class-hp-shipping-method.php';
        require_once HP_EXPRESS_PLUGIN_DIR . 'includes/class-hp-order.php';
        require_once HP_EXPRESS_PLUGIN_DIR . 'includes/class-hp-settings.php';
        require_once HP_EXPRESS_PLUGIN_DIR . 'includes/class-hp-paketomat-picker.php';
    }
    
    private function init_hooks() {
        add_action('init', array($this, 'load_textdomain'));
        add_filter('woocommerce_shipping_methods', array($this, 'register_shipping_method'));
        add_filter('plugin_action_links_' . HP_EXPRESS_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
        
        // Admin assets
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_hp_express_create_shipment', array($this, 'ajax_create_shipment'));
        add_action('wp_ajax_hp_express_cancel_shipment', array($this, 'ajax_cancel_shipment'));
        add_action('wp_ajax_hp_express_get_label', array($this, 'ajax_get_label'));
        add_action('wp_ajax_hp_express_get_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_hp_express_test_connection', array($this, 'ajax_test_connection'));
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('woo-hp-express', false, dirname(HP_EXPRESS_PLUGIN_BASENAME) . '/languages');
    }
    
    public function register_shipping_method($methods) {
        $methods['hp_express'] = 'WC_HP_Express_Shipping_Method';
        return $methods;
    }
    
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping&section=hp_express') . '">' . __('Postavke', 'woo-hp-express') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    public function admin_scripts($hook) {
        global $post;
        
        // Only on order pages
        if ($hook === 'post.php' || $hook === 'woocommerce_page_wc-orders') {
            wp_enqueue_style('hp-express-admin', HP_EXPRESS_PLUGIN_URL . 'assets/css/admin.css', array(), HP_EXPRESS_VERSION);
            wp_enqueue_script('hp-express-admin', HP_EXPRESS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), HP_EXPRESS_VERSION, true);
            wp_localize_script('hp-express-admin', 'hpExpressAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hp_express_nonce'),
                'strings' => array(
                    'confirm_cancel' => __('Jeste li sigurni da želite otkazati pošiljku?', 'woo-hp-express'),
                    'creating' => __('Kreiranje pošiljke...', 'woo-hp-express'),
                    'canceling' => __('Otkazivanje...', 'woo-hp-express'),
                    'loading' => __('Učitavanje...', 'woo-hp-express'),
                    'error' => __('Greška', 'woo-hp-express'),
                    'success' => __('Uspješno', 'woo-hp-express'),
                )
            ));
        }
        
        // Settings page
        if ($hook === 'woocommerce_page_wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'shipping') {
            wp_enqueue_script('hp-express-settings', HP_EXPRESS_PLUGIN_URL . 'assets/js/settings.js', array('jquery'), HP_EXPRESS_VERSION, true);
            wp_localize_script('hp-express-settings', 'hpExpressSettings', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('hp_express_nonce'),
            ));
        }
    }
    
    /**
     * Get API instance
     */
    public function api() {
        if (is_null($this->api)) {
            $this->api = new HP_Express_API();
        }
        return $this->api;
    }
    
    /**
     * AJAX: Create shipment
     */
    public function ajax_create_shipment() {
        check_ajax_referer('hp_express_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'woo-hp-express')));
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $service = isset($_POST['service']) ? intval($_POST['service']) : 38;
        $delivery_type = isset($_POST['delivery_type']) ? intval($_POST['delivery_type']) : 1;
        $parcel_size = isset($_POST['parcel_size']) ? sanitize_text_field($_POST['parcel_size']) : '';
        $weight = isset($_POST['weight']) ? floatval($_POST['weight']) : 1;
        $cod_enabled = isset($_POST['cod_enabled']) && $_POST['cod_enabled'] === '1';
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Neispravan ID narudžbe.', 'woo-hp-express')));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Narudžba nije pronađena.', 'woo-hp-express')));
        }
        
        $hp_order = new HP_Express_Order();
        $result = $hp_order->create_shipment($order, array(
            'service' => $service,
            'delivery_type' => $delivery_type,
            'parcel_size' => $parcel_size,
            'weight' => $weight,
            'cod_enabled' => $cod_enabled,
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Cancel shipment
     */
    public function ajax_cancel_shipment() {
        check_ajax_referer('hp_express_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'woo-hp-express')));
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Neispravan ID narudžbe.', 'woo-hp-express')));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Narudžba nije pronađena.', 'woo-hp-express')));
        }
        
        $hp_order = new HP_Express_Order();
        $result = $hp_order->cancel_shipment($order);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get shipping label
     */
    public function ajax_get_label() {
        check_ajax_referer('hp_express_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'woo-hp-express')));
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $format = isset($_POST['format']) ? intval($_POST['format']) : 1;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Neispravan ID narudžbe.', 'woo-hp-express')));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Narudžba nije pronađena.', 'woo-hp-express')));
        }
        
        $hp_order = new HP_Express_Order();
        $result = $hp_order->get_label($order, $format);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Get shipment status
     */
    public function ajax_get_status() {
        check_ajax_referer('hp_express_nonce', 'nonce');
        
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'woo-hp-express')));
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(array('message' => __('Neispravan ID narudžbe.', 'woo-hp-express')));
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => __('Narudžba nije pronađena.', 'woo-hp-express')));
        }
        
        $hp_order = new HP_Express_Order();
        $result = $hp_order->get_status($order);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success($result);
    }
    
    /**
     * AJAX: Test API connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('hp_express_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Nemate dozvolu za ovu akciju.', 'woo-hp-express')));
        }
        
        $result = $this->api()->ping();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => $result));
    }
}

/**
 * Main instance
 */
function WooHPExpress() {
    return WooHPExpress::instance();
}

// Check for WooCommerce
add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . __('WooCommerce HP Express zahtijeva WooCommerce plugin.', 'woo-hp-express') . '</p></div>';
        });
        return;
    }
    WooHPExpress();
});
