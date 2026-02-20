<?php
/**
 * HP Express Shipping Method
 * Supports multiple instances - each zone can have different HP Express configurations
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register shipping method after WooCommerce is loaded
 */
add_action('woocommerce_shipping_init', 'hp_express_shipping_method_init');

function hp_express_shipping_method_init() {
    
    class WC_HP_Express_Shipping_Method extends WC_Shipping_Method {
        
        /**
         * Constructor
         */
        public function __construct($instance_id = 0) {
            $this->id = 'hp_express';
            $this->instance_id = absint($instance_id);
            $this->method_title = __('HP Express', 'woo-hp-express');
            $this->method_description = __('Dostava putem HP Express (Hrvatska Pošta). Možete kreirati više instanci s različitim postavkama.', 'woo-hp-express');
            $this->supports = array(
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            );
            
            $this->init();
        }
        
        /**
         * Initialize settings
         */
        public function init() {
            $this->init_form_fields();
            $this->init_settings();
            
            // Instance settings
            $this->title = $this->get_option('title', __('HP Express', 'woo-hp-express'));
            $this->tax_status = $this->get_option('tax_status', 'taxable');
            $this->cost = $this->get_option('cost', '');
            $this->free_shipping_threshold = $this->get_option('free_shipping_threshold', '');
            $this->service_type = $this->get_option('service_type', '38');
            $this->delivery_type = $this->get_option('delivery_type', '1');
            $this->default_weight = $this->get_option('default_weight', '1');
            
            add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        }
        
        /**
         * Define settings fields for each instance
         */
        public function init_form_fields() {
            $this->instance_form_fields = array(
                'title' => array(
                    'title' => __('Naziv metode', 'woo-hp-express'),
                    'type' => 'text',
                    'description' => __('Naziv koji će kupac vidjeti na checkoutu.', 'woo-hp-express'),
                    'default' => __('HP Express', 'woo-hp-express'),
                    'desc_tip' => true,
                ),
                'tax_status' => array(
                    'title' => __('Status poreza', 'woo-hp-express'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => 'taxable',
                    'options' => array(
                        'taxable' => __('Oporezivo', 'woo-hp-express'),
                        'none' => __('Nije oporezivo', 'woo-hp-express'),
                    ),
                ),
                'cost' => array(
                    'title' => __('Cijena dostave', 'woo-hp-express'),
                    'type' => 'text',
                    'placeholder' => '0.00',
                    'description' => __('Cijena dostave u EUR. Ostavite prazno za besplatnu dostavu.', 'woo-hp-express'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'free_shipping_threshold' => array(
                    'title' => __('Prag za besplatnu dostavu', 'woo-hp-express'),
                    'type' => 'text',
                    'placeholder' => '',
                    'description' => __('Minimalni iznos narudžbe za besplatnu dostavu. Ostavite prazno da onemogućite.', 'woo-hp-express'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'service_type' => array(
                    'title' => __('Zadana usluga', 'woo-hp-express'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'description' => __('Zadana HP usluga za ovu shipping metodu. Može se promijeniti pri kreiranju pošiljke.', 'woo-hp-express'),
                    'default' => '38',
                    'options' => HP_Express_API::$services,
                    'desc_tip' => true,
                ),
                'delivery_type' => array(
                    'title' => __('Tip dostave', 'woo-hp-express'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'description' => __('Tip dostave za ovu shipping metodu.', 'woo-hp-express'),
                    'default' => '1',
                    'options' => HP_Express_API::$delivery_types,
                    'desc_tip' => true,
                ),
                'parcel_size' => array(
                    'title' => __('Veličina pretinca', 'woo-hp-express'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'description' => __('Zadana veličina pretinca za paketomat dostavu.', 'woo-hp-express'),
                    'default' => 'S',
                    'options' => HP_Express_API::$parcel_sizes,
                    'desc_tip' => true,
                ),
                'default_weight' => array(
                    'title' => __('Zadana težina (kg)', 'woo-hp-express'),
                    'type' => 'text',
                    'placeholder' => '1',
                    'description' => __('Zadana težina paketa ako proizvodi nemaju definiranu težinu.', 'woo-hp-express'),
                    'default' => '1',
                    'desc_tip' => true,
                ),
                'cod_enabled' => array(
                    'title' => __('COD dostupan', 'woo-hp-express'),
                    'type' => 'checkbox',
                    'label' => __('Omogući pouzeće za ovu metodu dostave', 'woo-hp-express'),
                    'default' => 'yes',
                    'description' => __('Ako je uključeno, pošiljke s plaćanjem pouzećem će automatski imati COD opciju.', 'woo-hp-express'),
                ),
            );
            
            // Global settings (shown in Shipping > HP Express section)
            $this->form_fields = array(
                'api_settings' => array(
                    'title' => __('API Postavke', 'woo-hp-express'),
                    'type' => 'title',
                    'description' => sprintf(
                        __('Konfigurirajte HP Express API pristupne podatke. <a href="%s">Globalne postavke</a>', 'woo-hp-express'),
                        admin_url('admin.php?page=hp-express-settings')
                    ),
                ),
            );
        }
        
        /**
         * Calculate shipping cost
         */
        public function calculate_shipping($package = array()) {
            $cost = $this->cost;
            
            // Check free shipping threshold
            if (!empty($this->free_shipping_threshold)) {
                $cart_total = WC()->cart->get_displayed_subtotal();
                if ($cart_total >= floatval($this->free_shipping_threshold)) {
                    $cost = 0;
                }
            }
            
            $delivery_type = $this->get_option('delivery_type', '1');
            
            $rate = array(
                'id' => $this->get_rate_id(),
                'label' => $this->title,
                'cost' => $cost,
                'package' => $package,
                'meta_data' => array(
                    'hp_service_type' => $this->service_type,
                    'hp_delivery_type' => $delivery_type,
                    'hp_parcel_size' => $this->get_option('parcel_size', 'S'),
                    'hp_default_weight' => $this->default_weight,
                    'hp_is_paketomat' => ($delivery_type === '3') ? 'yes' : 'no',
                ),
            );
            
            $this->add_rate($rate);
        }
        
        /**
         * Check if this is paketomat delivery
         */
        public function is_paketomat_delivery() {
            return $this->get_option('delivery_type', '1') === '3';
        }
        
        /**
         * Check if method is available
         */
        public function is_available($package) {
            $is_available = parent::is_available($package);
            
            // Additional checks can be added here
            // For example, check if API credentials are configured
            $options = get_option('hp_express_settings', array());
            if (empty($options['username']) || empty($options['password'])) {
                $is_available = false;
            }
            
            return $is_available;
        }
        
        /**
         * Get instance settings for order
         */
        public function get_instance_settings() {
            return array(
                'service_type' => $this->service_type,
                'delivery_type' => $this->delivery_type,
                'parcel_size' => $this->get_option('parcel_size', 'S'),
                'default_weight' => $this->default_weight,
                'cod_enabled' => $this->get_option('cod_enabled', 'yes'),
            );
        }
    }
}

/**
 * Add global settings page
 */
add_action('admin_menu', 'hp_express_add_settings_page');

function hp_express_add_settings_page() {
    add_submenu_page(
        'woocommerce',
        __('HP Express Postavke', 'woo-hp-express'),
        __('HP Express', 'woo-hp-express'),
        'manage_woocommerce',
        'hp-express-settings',
        'hp_express_settings_page'
    );
}

function hp_express_settings_page() {
    // Save settings
    if (isset($_POST['hp_express_save_settings']) && wp_verify_nonce($_POST['hp_express_nonce'], 'hp_express_settings')) {
        $options = array(
            'test_mode' => isset($_POST['test_mode']) ? '1' : '0',
            'username' => sanitize_text_field($_POST['username'] ?? ''),
            'cecode' => sanitize_text_field($_POST['cecode'] ?? ''),
            // Sender info
            'sender_name' => sanitize_text_field($_POST['sender_name'] ?? ''),
            'sender_phone' => sanitize_text_field($_POST['sender_phone'] ?? ''),
            'sender_email' => sanitize_email($_POST['sender_email'] ?? ''),
            'sender_street' => sanitize_text_field($_POST['sender_street'] ?? ''),
            'sender_hnum' => sanitize_text_field($_POST['sender_hnum'] ?? ''),
            'sender_zip' => sanitize_text_field($_POST['sender_zip'] ?? ''),
            'sender_city' => sanitize_text_field($_POST['sender_city'] ?? ''),
        );
        
        // Handle password
        $existing = get_option('hp_express_settings', array());
        if (!empty($_POST['password'])) {
            $options['password'] = base64_encode($_POST['password']);
        } elseif (!empty($existing['password'])) {
            $options['password'] = $existing['password'];
        }
        
        update_option('hp_express_settings', $options);
        
        // Clear token cache when credentials change
        delete_transient('hp_express_auth_token');
        
        echo '<div class="notice notice-success"><p>' . __('Postavke spremljene.', 'woo-hp-express') . '</p></div>';
    }
    
    $options = get_option('hp_express_settings', array());
    ?>
    <div class="wrap">
        <h1><?php _e('HP Express Postavke', 'woo-hp-express'); ?></h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('hp_express_settings', 'hp_express_nonce'); ?>
            
            <h2><?php _e('API Pristupni podaci', 'woo-hp-express'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Test način rada', 'woo-hp-express'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="test_mode" value="1" <?php checked($options['test_mode'] ?? '1', '1'); ?>>
                            <?php _e('Koristi test API (dxwebapit.posta.hr)', 'woo-hp-express'); ?>
                        </label>
                        <p class="description"><?php _e('Uključite za testiranje. Isključite za produkciju.', 'woo-hp-express'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Korisničko ime', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="username" value="<?php echo esc_attr($options['username'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Lozinka', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="password" name="password" value="" class="regular-text" placeholder="<?php echo !empty($options['password']) ? '••••••••••••' : ''; ?>">
                        <?php if (!empty($options['password'])): ?>
                            <p class="description" style="color: green;"><?php _e('✓ Lozinka je spremljena', 'woo-hp-express'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('CECODE', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="cecode" value="<?php echo esc_attr($options['cecode'] ?? ''); ?>" class="regular-text">
                        <p class="description"><?php _e('Vaš korisnički kod (Customer ID) kod HP-a. Za test: 111111', 'woo-hp-express'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Test veze', 'woo-hp-express'); ?></th>
                    <td>
                        <button type="button" id="hp-test-connection" class="button"><?php _e('Testiraj vezu', 'woo-hp-express'); ?></button>
                        <span id="hp-test-result"></span>
                    </td>
                </tr>
            </table>
            
            <h2><?php _e('Podaci pošiljatelja', 'woo-hp-express'); ?></h2>
            <p class="description"><?php _e('Ovi podaci se koriste kao podaci pošiljatelja na svim pošiljkama.', 'woo-hp-express'); ?></p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Naziv', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="sender_name" value="<?php echo esc_attr($options['sender_name'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Telefon', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="sender_phone" value="<?php echo esc_attr($options['sender_phone'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Email', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="email" name="sender_email" value="<?php echo esc_attr($options['sender_email'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Ulica', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="sender_street" value="<?php echo esc_attr($options['sender_street'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Kućni broj', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="sender_hnum" value="<?php echo esc_attr($options['sender_hnum'] ?? ''); ?>" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Poštanski broj', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="sender_zip" value="<?php echo esc_attr($options['sender_zip'] ?? ''); ?>" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Grad', 'woo-hp-express'); ?></th>
                    <td>
                        <input type="text" name="sender_city" value="<?php echo esc_attr($options['sender_city'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="hp_express_save_settings" class="button-primary" value="<?php _e('Spremi postavke', 'woo-hp-express'); ?>">
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            $('#hp-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#hp-test-result');
                
                $btn.prop('disabled', true);
                $result.html('<span style="color: #666;"><?php _e('Testiranje...', 'woo-hp-express'); ?></span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'hp_express_test_connection',
                        nonce: '<?php echo wp_create_nonce('hp_express_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                        } else {
                            $result.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                        }
                    },
                    error: function() {
                        $result.html('<span style="color: red;">✗ <?php _e('Greška pri testiranju veze', 'woo-hp-express'); ?></span>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
    </div>
    <?php
}
