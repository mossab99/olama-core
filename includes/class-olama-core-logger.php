<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Logger {
    public static function log($action, $details = '', $source = 'core', $user_id = 0) {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'olama_logs', array(
            'user_id' => $user_id ? absint($user_id) : get_current_user_id(),
            'source' => sanitize_key($source),
            'action' => sanitize_text_field($action),
            'details' => sanitize_textarea_field($details),
            'ip_address' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'created_at' => current_time('mysql'),
        ));

        self::maybe_notify($action, $details, $source, $user_id ? absint($user_id) : get_current_user_id());
    }

    private static function maybe_notify($action, $details, $source, $user_id) {
        if ('yes' !== get_option('olama_enable_notifs', 'yes')) {
            return;
        }
        $user = get_userdata($user_id);
        $message = sprintf(
            "Source: %s\nAction: %s\nUser: %s\nDetails: %s\nTime: %s",
            sanitize_key($source),
            sanitize_text_field($action),
            $user ? $user->display_name : __('System', 'olama-core'),
            sanitize_textarea_field($details),
            current_time('mysql')
        );
        wp_mail(
            get_option('olama_admin_email', get_option('admin_email')),
            sprintf('[Olama] Activity Alert: %s', strtoupper(sanitize_text_field($action))),
            $message
        );
    }
}
