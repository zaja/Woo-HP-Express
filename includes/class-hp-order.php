<?php
/**
 * HP Express Order Handler
 * Handles shipment creation, cancellation, labels and tracking for orders
 */

if (!defined('ABSPATH')) {
    exit;
}

class HP_Express_Order {
    
    private $api;
    
    public function __construct() {
        $this->api = WooHPExpress()->api();
        
        // Add metabox to order page
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        
        // Order list columns
        add_filter('manage_edit-shop_order_columns', array($this, 'add_order_column'));
        add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_order_column'));
        add_action('manage_shop_order_posts_custom_column', array($this, 'render_order_column'), 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', array($this, 'render_order_column_hpos'), 10, 2);
        
        // Email tracking info
        add_action('woocommerce_email_order_meta', array($this, 'add_tracking_to_email'), 20, 4);
        
        // Tracking in customer account order view
        add_action('woocommerce_order_details_after_order_table', array($this, 'add_tracking_to_order_view'), 10, 1);
    }
    
    /**
     * Add metabox to order edit page
     */
    public function add_order_metabox() {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') 
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';
        
        add_meta_box(
            'hp_express_shipment',
            __('HP Express Pošiljka', 'woo-hp-express'),
            array($this, 'render_order_metabox'),
            $screen,
            'side',
            'high'
        );
    }
    
    /**
     * Render order metabox
     */
    public function render_order_metabox($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) {
            return;
        }
        
        $order_id = $order->get_id();
        $shipment_data = $this->get_shipment_data($order);
        $has_shipment = !empty($shipment_data['barcode']);
        
        // Get shipping method settings if available
        $shipping_methods = $order->get_shipping_methods();
        $hp_settings = array();
        foreach ($shipping_methods as $method) {
            if (strpos($method->get_method_id(), 'hp_express') !== false) {
                $hp_settings = array(
                    'service_type' => $method->get_meta('hp_service_type') ?: '38',
                    'delivery_type' => $method->get_meta('hp_delivery_type') ?: '1',
                    'parcel_size' => $method->get_meta('hp_parcel_size') ?: 'S',
                    'default_weight' => $method->get_meta('hp_default_weight') ?: '1',
                );
                break;
            }
        }
        
        // Calculate order weight
        $total_weight = 0;
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_weight()) {
                $total_weight += floatval($product->get_weight()) * $item->get_quantity();
            }
        }
        if ($total_weight <= 0) {
            $total_weight = floatval($hp_settings['default_weight'] ?? 1);
        }
        
        // Check if COD
        $is_cod = $order->get_payment_method() === 'cod';
        ?>
        <div class="hp-express-metabox" data-order-id="<?php echo esc_attr($order_id); ?>">
            <?php if ($has_shipment): ?>
                <!-- Shipment exists -->
                <div class="hp-shipment-info">
                    <p><strong><?php _e('Broj pošiljke:', 'woo-hp-express'); ?></strong><br>
                    <code style="font-size: 14px;"><?php echo esc_html($shipment_data['barcode']); ?></code></p>
                    
                    <p><strong><?php _e('Referenca:', 'woo-hp-express'); ?></strong><br>
                    <?php echo esc_html($shipment_data['reference']); ?></p>
                    
                    <p><strong><?php _e('Status:', 'woo-hp-express'); ?></strong><br>
                    <span class="hp-status" id="hp-status-display"><?php echo esc_html($shipment_data['status'] ?? __('Nepoznat', 'woo-hp-express')); ?></span>
                    <button type="button" class="button button-small hp-refresh-status" title="<?php _e('Osvježi status', 'woo-hp-express'); ?>">↻</button>
                    </p>
                    
                    <p><strong><?php _e('Kreirana:', 'woo-hp-express'); ?></strong><br>
                    <?php echo esc_html($shipment_data['created_at']); ?></p>
                    
                    <div class="hp-actions">
                        <button type="button" class="button hp-get-label" data-format="1">
                            <?php _e('PDF Naljepnica', 'woo-hp-express'); ?>
                        </button>
                        <button type="button" class="button hp-get-label" data-format="3">
                            <?php _e('PDF (CODE128)', 'woo-hp-express'); ?>
                        </button>
                        <button type="button" class="button hp-cancel-shipment" style="color: #a00;">
                            <?php _e('Otkaži', 'woo-hp-express'); ?>
                        </button>
                    </div>
                    
                    <div class="hp-tracking-info" id="hp-tracking-info" style="display: none; margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                    </div>
                </div>
            <?php else: ?>
                <!-- Create new shipment -->
                <div class="hp-create-shipment">
                    <p>
                        <label><strong><?php _e('Usluga:', 'woo-hp-express'); ?></strong></label>
                        <select name="hp_service" id="hp_service" class="widefat">
                            <?php foreach (HP_Express_API::$services as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($hp_settings['service_type'] ?? '38', $id); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    
                    <p>
                        <label><strong><?php _e('Tip dostave:', 'woo-hp-express'); ?></strong></label>
                        <select name="hp_delivery_type" id="hp_delivery_type" class="widefat">
                            <?php foreach (HP_Express_API::$delivery_types as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($hp_settings['delivery_type'] ?? '1', $id); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    
                    <p id="hp_parcel_size_row" style="<?php echo ($hp_settings['delivery_type'] ?? '1') != '3' ? 'display:none;' : ''; ?>">
                        <label><strong><?php _e('Veličina pretinca:', 'woo-hp-express'); ?></strong></label>
                        <select name="hp_parcel_size" id="hp_parcel_size" class="widefat">
                            <?php foreach (HP_Express_API::$parcel_sizes as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($hp_settings['parcel_size'] ?? 'S', $id); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    
                    <p>
                        <label><strong><?php _e('Težina (kg):', 'woo-hp-express'); ?></strong></label>
                        <input type="number" name="hp_weight" id="hp_weight" value="<?php echo esc_attr($total_weight); ?>" step="0.001" min="0.001" class="widefat">
                    </p>
                    
                    <?php if ($is_cod): ?>
                    <p>
                        <label>
                            <input type="checkbox" name="hp_cod_enabled" id="hp_cod_enabled" value="1" checked>
                            <strong><?php _e('Otkupnina (COD)', 'woo-hp-express'); ?></strong>
                        </label>
                        <br>
                        <span class="description"><?php printf(__('Iznos: %s', 'woo-hp-express'), $order->get_formatted_order_total()); ?></span>
                    </p>
                    <?php endif; ?>
                    
                    <p>
                        <button type="button" class="button button-primary hp-create-shipment-btn widefat">
                            <?php _e('Kreiraj pošiljku', 'woo-hp-express'); ?>
                        </button>
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="hp-message" id="hp-message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
        </div>
        
        <style>
            .hp-express-metabox .hp-actions { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 10px; }
            .hp-express-metabox .hp-actions .button { flex: 1; min-width: 45%; text-align: center; }
            .hp-express-metabox .hp-message.success { background: #d4edda; color: #155724; }
            .hp-express-metabox .hp-message.error { background: #f8d7da; color: #721c24; }
            .hp-express-metabox .hp-refresh-status { padding: 0 5px !important; min-height: 20px !important; line-height: 18px !important; }
        </style>
        <?php
    }
    
    /**
     * Get shipment data from order meta
     */
    public function get_shipment_data($order) {
        return array(
            'barcode' => $order->get_meta('_hp_express_barcode'),
            'reference' => $order->get_meta('_hp_express_reference'),
            'status' => $order->get_meta('_hp_express_status'),
            'status_description' => $order->get_meta('_hp_express_status_description'),
            'created_at' => $order->get_meta('_hp_express_created_at'),
            'label_pdf' => $order->get_meta('_hp_express_label_pdf'),
        );
    }
    
    /**
     * Create shipment for order
     */
    public function create_shipment($order, $options = array()) {
        $settings = get_option('hp_express_settings', array());
        
        // Validate sender info
        if (empty($settings['sender_name']) || empty($settings['sender_phone'])) {
            return new WP_Error('missing_sender', __('Podaci pošiljatelja nisu konfigurirani. Provjerite HP Express postavke.', 'woo-hp-express'));
        }
        
        $order_id = $order->get_id();
        $service = intval($options['service'] ?? 38);
        $delivery_type = intval($options['delivery_type'] ?? 1);
        $parcel_size = sanitize_text_field($options['parcel_size'] ?? 'S');
        $weight = floatval($options['weight'] ?? 1);
        $cod_enabled = !empty($options['cod_enabled']);
        
        // Parse recipient address
        $address = HP_Express_API::parse_address($order->get_shipping_address_1() ?: $order->get_billing_address_1());
        
        // Build shipment data
        $client_reference = 'WC-' . $order_id . '-' . time();
        
        $parcel_data = array(
            'client_reference_number' => $client_reference,
            'service' => (string) $service,
            'payed_by' => 1, // Sender pays
            'delivery_type' => $delivery_type,
            'pickup_type' => 1, // Address pickup
            'sender' => array(
                'sender_name' => mb_substr($settings['sender_name'], 0, 50),
                'sender_phone' => mb_substr($settings['sender_phone'], 0, 25),
                'sender_email' => mb_substr($settings['sender_email'] ?? '', 0, 100),
                'sender_street' => mb_substr($settings['sender_street'], 0, 50),
                'sender_hnum' => $settings['sender_hnum'] ?: '.',
                'sender_hnum_suffix' => '',
                'sender_zip' => $settings['sender_zip'],
                'sender_city' => mb_substr($settings['sender_city'], 0, 25),
            ),
            'recipient' => array(
                'recipient_name' => mb_substr(
                    ($order->get_shipping_first_name() ?: $order->get_billing_first_name()) . ' ' . 
                    ($order->get_shipping_last_name() ?: $order->get_billing_last_name()),
                    0, 50
                ),
                'recipient_phone' => HP_Express_API::format_phone($order->get_billing_phone()),
                'recipient_email' => mb_substr($order->get_billing_email(), 0, 100),
                'recipient_street' => $address['street'],
                'recipient_hnum' => $address['hnum'],
                'recipient_hnum_suffix' => $address['hnum_suffix'],
                'recipient_zip' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
                'recipient_city' => mb_substr($order->get_shipping_city() ?: $order->get_billing_city(), 0, 25),
            ),
            'additional_services' => array(),
            'packages' => array(
                array(
                    'barcode' => '',
                    'barcode_type' => 1, // Get barcode from HP
                    'barcode_client' => (string) $order_id,
                    'weight' => $weight,
                ),
            ),
        );
        
        // Post office delivery - requires mobile phone for SMS notification
        if ($delivery_type == 2) {
            if (!HP_Express_API::is_valid_mobile($order->get_billing_phone())) {
                return new WP_Error('invalid_phone', __('Za dostavu na poštu potreban je ispravan hrvatski mobilni broj.', 'woo-hp-express'));
            }
        }
        
        // Parcel locker delivery
        if ($delivery_type == 3) {
            $parcel_data['parcel_size'] = $parcel_size;
            
            // Get selected paketomat from order meta
            $paketomat_code = $order->get_meta('_hp_paketomat_code');
            if (!empty($paketomat_code)) {
                // HP API - paketomat code goes in recipient object as string
                $parcel_data['recipient']['recipient_delivery_center'] = strval($paketomat_code);
            }
            
            // Validate mobile phone for parcel locker
            if (!HP_Express_API::is_valid_mobile($order->get_billing_phone())) {
                return new WP_Error('invalid_phone', __('Za dostavu u paketomat potreban je ispravan hrvatski mobilni broj.', 'woo-hp-express'));
            }
        }
        
        // COD
        if ($cod_enabled && $order->get_payment_method() === 'cod') {
            $parcel_data['payment_value'] = floatval($order->get_total());
            $parcel_data['additional_services'][] = array('additional_service_id' => 9);
        }
        
        // Send email to recipient
        $parcel_data['additional_services'][] = array('additional_service_id' => 32);
        
        // Make API call
        $result = $this->api->create_shipment($parcel_data, true);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Extract data from response
        $shipment_order = $result['ShipmentOrdersList'][0] ?? null;
        if (!$shipment_order) {
            return new WP_Error('no_response', __('Neispravan odgovor od HP API-a.', 'woo-hp-express'));
        }
        
        $barcode = $shipment_order['Packages'][0]['barcode'] ?? '';
        if (empty($barcode)) {
            return new WP_Error('no_barcode', __('HP API nije vratio broj pošiljke.', 'woo-hp-express'));
        }
        
        // Save to order meta
        $order->update_meta_data('_hp_express_barcode', $barcode);
        $order->update_meta_data('_hp_express_reference', $client_reference);
        $order->update_meta_data('_hp_express_status', 'NOV');
        $order->update_meta_data('_hp_express_status_description', 'Kreirana pošiljka');
        $order->update_meta_data('_hp_express_created_at', current_time('mysql'));
        $order->update_meta_data('_hp_express_service', $service);
        $order->update_meta_data('_hp_express_delivery_type', $delivery_type);
        
        // Save label if returned
        if (!empty($result['ShipmentsLabel'])) {
            $order->update_meta_data('_hp_express_label_pdf', $result['ShipmentsLabel']);
        }
        
        $order->save();
        
        // Add order note
        $order->add_order_note(sprintf(
            __('HP Express pošiljka kreirana. Broj: %s', 'woo-hp-express'),
            $barcode
        ));
        
        return array(
            'success' => true,
            'barcode' => $barcode,
            'reference' => $client_reference,
            'label' => $result['ShipmentsLabel'] ?? null,
        );
    }
    
    /**
     * Cancel shipment
     */
    public function cancel_shipment($order) {
        $reference = $order->get_meta('_hp_express_reference');
        if (empty($reference)) {
            return new WP_Error('no_shipment', __('Nema pošiljke za otkazivanje.', 'woo-hp-express'));
        }
        
        $result = $this->api->cancel_shipment($reference);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Clear shipment data
        $barcode = $order->get_meta('_hp_express_barcode');
        $order->delete_meta_data('_hp_express_barcode');
        $order->delete_meta_data('_hp_express_reference');
        $order->delete_meta_data('_hp_express_status');
        $order->delete_meta_data('_hp_express_status_description');
        $order->delete_meta_data('_hp_express_created_at');
        $order->delete_meta_data('_hp_express_label_pdf');
        $order->delete_meta_data('_hp_express_service');
        $order->delete_meta_data('_hp_express_delivery_type');
        $order->save();
        
        // Add order note
        $order->add_order_note(sprintf(
            __('HP Express pošiljka otkazana. Broj: %s', 'woo-hp-express'),
            $barcode
        ));
        
        return array('success' => true);
    }
    
    /**
     * Get shipping label
     */
    public function get_label($order, $format = 1) {
        $barcode = $order->get_meta('_hp_express_barcode');
        if (empty($barcode)) {
            return new WP_Error('no_shipment', __('Nema pošiljke.', 'woo-hp-express'));
        }
        
        // Check if we have cached label
        if ($format == 1) {
            $cached = $order->get_meta('_hp_express_label_pdf');
            if (!empty($cached)) {
                return array(
                    'success' => true,
                    'label' => $cached,
                    'format' => 'pdf',
                );
            }
        }
        
        $result = $this->api->get_shipping_labels(array($barcode), '', $format, false);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (empty($result['PackageLabel'])) {
            return new WP_Error('no_label', __('HP API nije vratio naljepnicu.', 'woo-hp-express'));
        }
        
        return array(
            'success' => true,
            'label' => $result['PackageLabel'],
            'format' => $format == 2 ? 'zpl' : 'pdf',
        );
    }
    
    /**
     * Get shipment status
     */
    public function get_status($order) {
        $barcode = $order->get_meta('_hp_express_barcode');
        if (empty($barcode)) {
            return new WP_Error('no_shipment', __('Nema pošiljke.', 'woo-hp-express'));
        }
        
        $result = $this->api->get_shipment_status($barcode);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        if (empty($result[0])) {
            return new WP_Error('no_status', __('HP API nije vratio status.', 'woo-hp-express'));
        }
        
        $status_data = $result[0];
        $scans = $status_data['PackageScansList'] ?? array();
        
        // Get latest status
        $latest_scan = end($scans);
        if ($latest_scan) {
            $order->update_meta_data('_hp_express_status', $latest_scan['Scan']);
            $order->update_meta_data('_hp_express_status_description', $latest_scan['ScanDescription']);
            $order->save();
        }
        
        return array(
            'success' => true,
            'barcode' => $barcode,
            'status' => $latest_scan['Scan'] ?? '',
            'status_description' => $latest_scan['ScanDescription'] ?? '',
            'scans' => $scans,
        );
    }
    
    /**
     * Add HP Express column to orders list
     */
    public function add_order_column($columns) {
        $new_columns = array();
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'order_status') {
                $new_columns['hp_express'] = __('HP Express', 'woo-hp-express');
            }
        }
        return $new_columns;
    }
    
    /**
     * Render HP Express column (legacy)
     */
    public function render_order_column($column, $post_id) {
        if ($column !== 'hp_express') {
            return;
        }
        $order = wc_get_order($post_id);
        $this->output_column_content($order);
    }
    
    /**
     * Render HP Express column (HPOS)
     */
    public function render_order_column_hpos($column, $order) {
        if ($column !== 'hp_express') {
            return;
        }
        $this->output_column_content($order);
    }
    
    /**
     * Output column content
     */
    private function output_column_content($order) {
        if (!$order) {
            echo '—';
            return;
        }
        
        $barcode = $order->get_meta('_hp_express_barcode');
        if ($barcode) {
            $status = $order->get_meta('_hp_express_status');
            echo '<span title="' . esc_attr($status) . '" style="color: green;">✓</span> ';
            echo '<code style="font-size: 11px;">' . esc_html($barcode) . '</code>';
        } else {
            echo '<span style="color: #999;">—</span>';
        }
    }
    
    /**
     * Add tracking info to customer emails
     */
    public function add_tracking_to_email($order, $sent_to_admin, $plain_text, $email) {
        // Only for customer emails (not admin)
        if ($sent_to_admin) {
            return;
        }
        
        // Only for specific email types - completed, shipped
        $allowed_emails = array('customer_completed_order', 'customer_invoice', 'customer_note');
        if (!in_array($email->id, $allowed_emails)) {
            return;
        }
        
        $barcode = $order->get_meta('_hp_express_barcode');
        if (empty($barcode)) {
            return;
        }
        
        $tracking_url = self::get_tracking_url($barcode);
        
        if ($plain_text) {
            echo "\n\n";
            echo "==========\n";
            echo __('Praćenje pošiljke', 'woo-hp-express') . "\n";
            echo "==========\n\n";
            echo __('Broj pošiljke:', 'woo-hp-express') . ' ' . $barcode . "\n";
            echo __('Pratite svoju pošiljku:', 'woo-hp-express') . ' ' . $tracking_url . "\n";
        } else {
            ?>
            <div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9;">
                <h3 style="margin: 0 0 10px 0; color: #333;"><?php _e('Praćenje pošiljke', 'woo-hp-express'); ?></h3>
                <p style="margin: 0 0 10px 0;">
                    <strong><?php _e('Broj pošiljke:', 'woo-hp-express'); ?></strong><br>
                    <code style="font-size: 16px; background: #fff; padding: 5px 10px; border-radius: 3px;"><?php echo esc_html($barcode); ?></code>
                </p>
                <p style="margin: 0;">
                    <a href="<?php echo esc_url($tracking_url); ?>" 
                       style="display: inline-block; padding: 10px 20px; background: #ffc107; color: #000; text-decoration: none; border-radius: 4px; font-weight: bold;">
                        <?php _e('Pratite svoju pošiljku →', 'woo-hp-express'); ?>
                    </a>
                </p>
            </div>
            <?php
        }
    }
    
    /**
     * Add tracking info to customer account order view
     */
    public function add_tracking_to_order_view($order) {
        $barcode = $order->get_meta('_hp_express_barcode');
        if (empty($barcode)) {
            return;
        }
        
        $tracking_url = self::get_tracking_url($barcode);
        ?>
        <section class="woocommerce-hp-tracking" style="margin: 20px 0;">
            <h2><?php _e('Praćenje pošiljke', 'woo-hp-express'); ?></h2>
            <table class="woocommerce-table shop_table hp-tracking-table">
                <tbody>
                    <tr>
                        <th><?php _e('Broj pošiljke:', 'woo-hp-express'); ?></th>
                        <td><code style="font-size: 14px;"><?php echo esc_html($barcode); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php _e('Dostavljač:', 'woo-hp-express'); ?></th>
                        <td>HP Express (Hrvatska Pošta)</td>
                    </tr>
                    <tr>
                        <th><?php _e('Praćenje:', 'woo-hp-express'); ?></th>
                        <td>
                            <a href="<?php echo esc_url($tracking_url); ?>" target="_blank" class="button" style="display: inline-block;">
                                <?php _e('Prati pošiljku →', 'woo-hp-express'); ?>
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
        <?php
    }
    
    /**
     * Get tracking URL for barcode
     */
    public static function get_tracking_url($barcode) {
        return 'https://posiljka.posta.hr/tragom-posiljke/tracking/trackingdata?barcode=' . urlencode($barcode);
    }
}

// Initialize
add_action('init', function() {
    new HP_Express_Order();
});
