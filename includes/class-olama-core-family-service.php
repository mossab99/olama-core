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
        $partial = !empty($data['_partial']);
        $normalized = $this->normalize($data);
        $existing = $this->get_by_oracle_id($normalized['oracle_family_id']);

        if ($partial && $existing) {
            $normalized = $this->preserve_missing_values($normalized, $existing);
        }

        if ($existing && hash_equals((string) $existing['source_hash'], $normalized['source_hash'])) {
            $now = current_time('mysql');
            $this->repo->update($this->table, array('last_synced_at' => $now), array('id' => (int) $existing['id']));
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
            'father_national_no' => $this->text_any($data, array('father_national_no', 'father_national_number')),
            'father_nation' => $this->text_any($data, array('father_nation', 'father_nationality')),
            'father_email' => $this->email_any($data, array('father_email', 'father_mail')),
            'father_job' => $this->text_any($data, array('father_job', 'father_occupation')),
            'father_work_place' => $this->text_any($data, array('father_work_place', 'father_workplace')),
            'father_work_phone' => $this->text_any($data, array('father_work_phone', 'father_work_mobile')),
            'father_is_employee' => $this->bool_any($data, array('father_is_employee')),
            'mother_national_no' => $this->text_any($data, array('mother_national_no', 'mother_national_number')),
            'mother_nation' => $this->text_any($data, array('mother_nation', 'mother_nationality')),
            'mother_email' => $this->email_any($data, array('mother_email', 'mother_mail')),
            'mother_job' => $this->text_any($data, array('mother_job', 'mother_occupation')),
            'mother_work_place' => $this->text_any($data, array('mother_work_place', 'mother_workplace')),
            'mother_work_phone' => $this->text_any($data, array('mother_work_phone', 'mother_work_mobile')),
            'mother_is_employee' => $this->bool_any($data, array('mother_is_employee')),
            'father_mobile' => $this->text($data, 'father_mobile'),
            'mother_mobile' => $this->text($data, 'mother_mobile'),
            'primary_mobile' => $this->text($data, 'primary_mobile', $this->text($data, 'father_mobile')),
            'email' => $this->email_any($data, array('email', 'father_email', 'mother_email')),
            'address' => $this->textarea($data, array('address', 'family_address')),
            'family_address' => $this->textarea($data, array('family_address', 'address')),
            'family_home_phone' => $this->text_any($data, array('family_home_phone', 'home_phone', 'family_phone')),
            'building_no' => $this->text_any($data, array('building_no', 'building_number')),
            'home_no' => $this->text_any($data, array('home_no', 'house_no', 'home_number')),
            'trans_region_id' => $this->text_any($data, array('trans_region_id', 'transportation_region_id', 'region_id')),
            'trans_region_name' => $this->text_any($data, array('trans_region_name', 'transportation_region_name', 'region_name', 'area_name')),
            'family_class_id' => $this->text_any($data, array('family_class_id', 'fam_class_id')),
            'family_class_name' => $this->text_any($data, array('family_class_name')),
            'is_active' => $this->bool_any($data, array('is_active', 'active')),
            'family_status' => $this->text_any($data, array('family_status', 'status', 'is_active')),
            'family_status_name' => $this->text_any($data, array('family_status_name', 'status_name', 'is_active_name')),
            'students_count' => $this->int_any($data, array('students_count', 'student_count', 'children_count')),
            'notes' => $this->textarea($data, array('notes', 'family_notes')),
            'oracle_created_at' => $this->datetime_any($data, array('date_created', 'oracle_created_at')),
            'oracle_modified_at' => $this->datetime_any($data, array('date_modified', 'oracle_modified_at')),
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

    private function email_any($data, $keys) {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                $email = sanitize_email($data[$key]);
                return $email !== '' ? $email : null;
            }
        }

        return null;
    }

    private function bool_any($data, $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== '' && $data[$key] !== null) {
                $value = strtolower(trim((string) $data[$key]));
                return in_array($value, array('1', 'true', 'yes', 'active', 'enabled'), true) ? 1 : 0;
            }
        }

        return null;
    }

    private function datetime_any($data, $keys) {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                $timestamp = strtotime((string) $data[$key]);
                return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
            }
        }

        return null;
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

    private function preserve_missing_values($normalized, $existing) {
        foreach ($normalized as $key => $value) {
            if (in_array($key, array('family_uid', 'oracle_family_id', 'source_system', 'source_hash'), true)) {
                continue;
            }
            if ($value === null && array_key_exists($key, $existing)) {
                $normalized[$key] = $existing[$key];
            }
        }
        $hash_payload = $normalized;
        unset($hash_payload['source_hash']);
        $normalized['source_hash'] = hash('sha256', wp_json_encode($hash_payload));
        return $normalized;
    }
}
