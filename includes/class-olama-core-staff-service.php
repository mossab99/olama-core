<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Staff_Service {
    private $repo;
    private $table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
        $this->table = $repo->table('olama_core_staff_profiles');
    }

    public function get($user_id) {
        return $this->repo->get_row($this->table, array('user_id' => absint($user_id)));
    }

    public function save($user_id, array $data) {
        global $wpdb;

        $user_id = absint($user_id);
        if (!$user_id || !get_userdata($user_id)) {
            return new WP_Error('invalid_user', __('The selected user does not exist.', 'olama-core'));
        }

        $now = current_time('mysql');
        $payload = array(
            'employee_id' => sanitize_text_field(isset($data['employee_id']) ? $data['employee_id'] : ''),
            'phone_number' => sanitize_text_field(isset($data['phone_number']) ? $data['phone_number'] : ''),
            'updated_at' => $now,
        );
        $existing = $this->get($user_id);
        if ($existing) {
            $result = $this->repo->update($this->table, $payload, array('user_id' => $user_id));
        } else {
            $payload['user_id'] = $user_id;
            $payload['created_at'] = $now;
            $result = $wpdb->insert($this->table, $payload);
        }

        if (false === $result) {
            return new WP_Error('staff_profile_save_failed', __('The staff profile could not be saved.', 'olama-core'));
        }

        // Keep the School compatibility table synchronized while it exists.
        $legacy_table = $wpdb->prefix . 'olama_teachers';
        if (Olama_Core_Migrator::table_exists($legacy_table)) {
            $wpdb->replace($legacy_table, array(
                'id' => $user_id,
                'employee_id' => $payload['employee_id'],
                'phone_number' => $payload['phone_number'],
            ));
        }

        return true;
    }
}
