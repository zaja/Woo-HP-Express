<?php
/**
 * HP Express Settings Helper
 * Additional settings utilities
 */

if (!defined('ABSPATH')) {
    exit;
}

class HP_Express_Settings {
    
    /**
     * Get all settings
     */
    public static function get_all() {
        return get_option('hp_express_settings', array());
    }
    
    /**
     * Get single setting
     */
    public static function get($key, $default = '') {
        $options = self::get_all();
        return $options[$key] ?? $default;
    }
    
    /**
     * Update setting
     */
    public static function update($key, $value) {
        $options = self::get_all();
        $options[$key] = $value;
        update_option('hp_express_settings', $options);
    }
    
    /**
     * Get sender data
     */
    public static function get_sender() {
        $options = self::get_all();
        return array(
            'name' => $options['sender_name'] ?? '',
            'phone' => $options['sender_phone'] ?? '',
            'email' => $options['sender_email'] ?? '',
            'street' => $options['sender_street'] ?? '',
            'hnum' => $options['sender_hnum'] ?? '',
            'zip' => $options['sender_zip'] ?? '',
            'city' => $options['sender_city'] ?? '',
        );
    }
    
    /**
     * Check if properly configured
     */
    public static function is_configured() {
        $options = self::get_all();
        return !empty($options['username']) 
            && !empty($options['password']) 
            && !empty($options['sender_name'])
            && !empty($options['sender_phone'])
            && !empty($options['sender_zip']);
    }
}
