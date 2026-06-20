<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Family_Service {
    private $repo;
    private $table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
        $this->table = $repo->table('olama_core_families');
    }

    public function get_by_uid($family_uid) {
        return $this->repo->get_row($this->table, array('family_uid' => sanitize_text_field($family_uid)));
    }

    public function get_by_oracle_id($oracle_family_id) {
        return $this->repo->get_row($this->table, array('oracle_family_id' => sanitize_text_field($oracle_family_id)));
    }

    public function search($term, $args = array()) {
        return $this->repo->search($this->table, array('family_uid', 'oracle_family_id', 'sponsor_full_name', 'father_name', 'father_mobile', 'mother_mobile', 'primary_mobile', 'family_address', 'address', 'trans_region_name'), sanitize_text_field($term), $args);
    }

    public function get_students($family_uid) {
        return olama_core()->students()->get_by_family_uid($family_uid);
    }

    public function upsert_from_source(array $data) {
        $normalized = $this->normalize($data);
        $existing = $this->get_by_oracle_id($normalized['oracle_family_id']);

        if ($existing && hash_equals((string) $existing['source_hash'], $normalized['source_hash'])) {
            return array('operation' => 'skipped', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => $existing['family_uid']);
        }

        $now = current_time('mysql');
        $normalized['updated_at'] = $now;
        $normalized['last_synced_at'] = $now;

        if ($existing) {
            $this->repo->update($this->table, $normalized, array('id' => (int) $existing['id']));
            return array('operation' => 'updated', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => $normalized['family_uid']);
        }

        $normalized['created_at'] = $now;
        $id = $this->repo->insert($this->table, $normalized);

        return array('operation' => 'created', 'status' => 'success', 'id' => (int) $id, 'uid' => $normalized['family_uid']);
    }

    public function count($args = array()) {
        return $this->repo->count($this->table, $args);
    }

    private function normalize(array $data) {
        $oracle_id = isset($data['oracle_family_id']) ? $data['oracle_family_id'] : (isset($data['family_id']) ? $data['family_id'] : '');
        $oracle_id = sanitize_text_field((string) $oracle_id);
        if ('' === $oracle_id) {
            throw new InvalidArgumentException('Missing oracle_family_id.');
        }

        $payload = array(
            'family_uid' => 'ORA-FAM-' . $oracle_id,
            'oracle_family_id' => $oracle_id,
            'sponsor_full_name' => $this->text($data, 'sponsor_full_name'),
            'father_name' => $this->text($data, 'father_name'),
            'mother_name' => $this->text($data, 'mother_name'),
            'father_mobile' => $this->text($data, 'father_mobile'),
            'mother_mobile' => $this->text($data, 'mother_mobile'),
            'primary_mobile' => $this->text($data, 'primary_mobile', $this->text($data, 'father_mobile')),
            'email' => isset($data['email']) ? sanitize_email($data['email']) : null,
            'address' => $this->textarea($data, array('address', 'family_address')),
            'family_address' => $this->textarea($data, array('family_address', 'address')),
            'trans_region_id' => $this->text_any($data, array('trans_region_id', 'transportation_region_id', 'region_id')),
            'trans_region_name' => $this->text_any($data, array('trans_region_name', 'transportation_region_name', 'region_name', 'area_name')),
            'family_status' => $this->text_any($data, array('family_status', 'status')),
            'family_status_name' => $this->text_any($data, array('family_status_name', 'status_name')),
            'students_count' => $this->int_any($data, array('students_count', 'student_count', 'children_count')),
            'source_system' => 'oracle',
            'raw_json' => $this->raw_json($data),
        );
        $payload['source_hash'] = hash('sha256', wp_json_encode($payload));

        return $payload;
    }

    private function text($data, $key, $default = null) {
        return isset($data[$key]) && $data[$key] !== '' ? sanitize_text_field((string) $data[$key]) : $default;
    }

    private function text_any($data, $keys, $default = null) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_text_field((string) $data[$key]);
            }
        }

        return $default;
    }

    private function textarea($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_textarea_field((string) $data[$key]);
            }
        }

        return null;
    }

    private function int_any($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return absint($data[$key]);
            }
        }

        return null;
    }

    private function raw_json($data) {
        if (class_exists('Olama_Oracle_Settings') && Olama_Oracle_Settings::get('store_raw_payloads') !== 'yes') {
            return null;
        }

        return wp_json_encode(isset($data['raw']) ? $data['raw'] : $data);
    }
}
