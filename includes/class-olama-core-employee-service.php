<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Employee_Service {
    private $repo;
    private $table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
        $this->table = $repo->table('olama_core_employees');
    }

    public function get_by_employee_id($employee_id) {
        return $this->repo->get_row($this->table, array('employee_id' => sanitize_text_field((string) $employee_id)));
    }

    public function active($args = array()) {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(1000, absint($args['limit']))) : 1000;
        $offset = isset($args['offset']) ? max(0, absint($args['offset'])) : 0;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->table) . '` WHERE employee_status=%s ORDER BY CAST(employee_id AS UNSIGNED), employee_id LIMIT %d OFFSET %d',
            'مستمر',
            $limit,
            $offset
        ), ARRAY_A);
    }

    public function count($status = '') {
        return $status ? $this->repo->count($this->table, array('employee_status' => $status)) : $this->repo->count($this->table);
    }

    public function upsert_from_source(array $data) {
        $payload = $this->normalize($data);
        $existing = $this->get_by_employee_id($payload['employee_id']);
        $now = current_time('mysql');

        if ($existing && (string) $existing['employee_status'] === (string) $payload['employee_status'] && hash_equals((string) $existing['source_hash'], $payload['source_hash'])) {
            $this->repo->update($this->table, array('last_synced_at' => $now), array('id' => (int) $existing['id']));
            return array('operation' => 'skipped', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => 'ORA-EMP-' . $payload['employee_id']);
        }

        $payload['last_synced_at'] = $now;
        $payload['updated_at'] = $now;
        if ($existing) {
            $this->repo->update($this->table, $payload, array('id' => (int) $existing['id']));
            return array('operation' => 'updated', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => 'ORA-EMP-' . $payload['employee_id']);
        }

        $payload['created_at'] = $now;
        $id = $this->repo->insert($this->table, $payload);
        if (!$id) {
            throw new RuntimeException('Employee record could not be inserted into Olama Core.');
        }
        return array('operation' => 'created', 'status' => 'success', 'id' => (int) $id, 'uid' => 'ORA-EMP-' . $payload['employee_id']);
    }

    public function mark_missing_inactive(array $active_employee_ids) {
        global $wpdb;
        $active_employee_ids = array_values(array_unique(array_filter(array_map('strval', $active_employee_ids), 'strlen')));
        if (!$active_employee_ids) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($active_employee_ids), '%s'));
        $values = array_merge(array('inactive', current_time('mysql'), 'مستمر'), $active_employee_ids);
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE `' . esc_sql($this->table) . '` SET employee_status=%s, updated_at=%s WHERE employee_status=%s AND employee_id NOT IN (' . $placeholders . ')',
            $values
        ));
    }

    private function normalize(array $data) {
        $employee_id = isset($data['employee_id']) ? sanitize_text_field((string) $data['employee_id']) : '';
        $full_name = isset($data['full_name']) ? sanitize_text_field((string) $data['full_name']) : '';
        if ('' === $employee_id || '' === $full_name) {
            throw new InvalidArgumentException('Employee ID and full name are required.');
        }
        $payload = array(
            'employee_id' => $employee_id,
            'full_name' => $full_name,
            'national_number' => $this->text($data, 'national_number'),
            'birth_date' => $this->date($data, 'birth_date'),
            'gender' => $this->text($data, 'gender'),
            'job_title' => $this->text($data, 'job_title'),
            'appointment_date' => $this->date($data, 'appointment_date'),
            'address' => isset($data['address']) && '' !== $data['address'] ? sanitize_textarea_field((string) $data['address']) : null,
            'phones' => $this->text($data, 'phones'),
            'certificate_grade' => $this->text($data, 'certificate_grade'),
            'certificate_type' => $this->text($data, 'certificate_type'),
            'certificate_date' => $this->date($data, 'certificate_date'),
            'certificate_average' => isset($data['certificate_average']) && is_numeric($data['certificate_average']) ? (float) $data['certificate_average'] : null,
            'employee_status' => $this->text($data, 'employee_status', ''),
            'source_system' => 'oracle',
            'raw_json' => class_exists('Olama_Oracle_Settings') && 'yes' === Olama_Oracle_Settings::get('store_raw_payloads') ? wp_json_encode($data) : null,
        );
        $hash_payload = $payload;
        unset($hash_payload['raw_json']);
        $payload['source_hash'] = hash('sha256', wp_json_encode($hash_payload));
        return $payload;
    }

    private function text(array $data, $key, $default = null) {
        return isset($data[$key]) && '' !== (string) $data[$key] ? sanitize_text_field((string) $data[$key]) : $default;
    }

    private function date(array $data, $key) {
        if (empty($data[$key])) {
            return null;
        }
        $timestamp = strtotime((string) $data[$key]);
        return $timestamp ? gmdate('Y-m-d', $timestamp) : null;
    }
}
