<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Student_Year_Service {
    private $repo;
    private $table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
        $this->table = $repo->table('olama_core_student_years');
    }

    public function get_current_year($student_uid, $study_year = null) {
        global $wpdb;

        $student_uid = sanitize_text_field($student_uid);
        if ($study_year) {
            return $this->repo->get_row($this->table, array('student_uid' => $student_uid, 'study_year' => sanitize_text_field($study_year)));
        }

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->table) . '` WHERE student_uid = %s ORDER BY study_year DESC LIMIT 1',
            $student_uid
        ), ARRAY_A);
    }

    public function get_by_student($student_uid) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->table) . '` WHERE student_uid = %s ORDER BY study_year DESC',
            sanitize_text_field($student_uid)
        ), ARRAY_A);
    }

    public function get_by_family($family_uid, $study_year = null) {
        global $wpdb;

        if ($study_year) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM `' . esc_sql($this->table) . '` WHERE family_uid = %s AND study_year = %s ORDER BY student_uid ASC',
                sanitize_text_field($family_uid),
                sanitize_text_field($study_year)
            ), ARRAY_A);
        }

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->table) . '` WHERE family_uid = %s ORDER BY study_year DESC, student_uid ASC',
            sanitize_text_field($family_uid)
        ), ARRAY_A);
    }

    public function upsert_from_source(array $data) {
        $partial = !empty($data['_partial']);
        $normalized = $this->normalize($data);
        $existing = $this->repo->get_row($this->table, array(
            'student_uid' => $normalized['student_uid'],
            'study_year' => $normalized['study_year'],
        ));

        if ($partial && $existing) {
            $normalized = $this->preserve_missing_values($normalized, $existing);
        }

        if ($existing && hash_equals((string) $existing['source_hash'], $normalized['source_hash'])) {
            $now = current_time('mysql');
            $this->repo->update($this->table, array('last_synced_at' => $now), array('id' => (int) $existing['id']));
            return array('operation' => 'skipped', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => $normalized['student_uid']);
        }

        $now = current_time('mysql');
        $normalized['updated_at'] = $now;
        $normalized['last_synced_at'] = $now;

        if ($existing) {
            $this->repo->update($this->table, $normalized, array('id' => (int) $existing['id']));
            return array('operation' => 'updated', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => $normalized['student_uid']);
        }

        $normalized['created_at'] = $now;
        $id = $this->repo->insert($this->table, $normalized);

        return array('operation' => 'created', 'status' => 'success', 'id' => (int) $id, 'uid' => $normalized['student_uid']);
    }

    public function count($args = array()) {
        return $this->repo->count($this->table, $args);
    }

    private function normalize(array $data) {
        $family_id = isset($data['oracle_family_id']) ? $data['oracle_family_id'] : (isset($data['family_id']) ? $data['family_id'] : '');
        $student_id = isset($data['oracle_student_id']) ? $data['oracle_student_id'] : (isset($data['student_id']) ? $data['student_id'] : '');
        $family_id = sanitize_text_field((string) $family_id);
        $student_id = sanitize_text_field((string) $student_id);
        $study_year = isset($data['study_year']) ? sanitize_text_field($data['study_year']) : '';
        if ('' === $family_id || '' === $student_id || '' === $study_year) {
            throw new InvalidArgumentException('Missing student year keys.');
        }

        $payload = array(
            'student_uid' => 'ORA-STU-' . $family_id . '-' . $student_id,
            'family_uid' => 'ORA-FAM-' . $family_id,
            'oracle_family_id' => $family_id,
            'oracle_student_id' => $student_id,
            'study_year' => $study_year,
            'school_id' => $this->text_any($data, array('school_id')),
            'school_name' => $this->text_any($data, array('school_name')),
            'class_id' => $this->text_any($data, array('class_id')),
            'class_name' => $this->text_any($data, array('class_name')),
            'branch_id' => $this->text_any($data, array('branch_id')),
            'branch_name' => $this->text_any($data, array('branch_name')),
            'section_id' => $this->text_any($data, array('section_id')),
            'section_name' => $this->text_any($data, array('section_name')),
            'student_status' => $this->text_any($data, array('student_status', 'student_year_status', 'status')),
            'student_status_name' => $this->text_any($data, array('student_status_name', 'student_year_status_name', 'status_name')),
            'student_year_status' => $this->text_any($data, array('student_year_status', 'student_status', 'status')),
            'registration_date' => $this->date_any($data, array('registration_date', 'register_date', 'date_registered')),
            'withdraw_date' => $this->date_any($data, array('withdraw_date', 'withdrawal_date')),
            'renew_student' => $this->text_any($data, array('renew_student', 'is_renewed')),
            'system_respect' => $this->text_any($data, array('system_respect', 'commitment_to_system')),
            'no_absent' => $this->int_any($data, array('no_absent', 'absence_count')),
            'final_mark_result' => $this->text_any($data, array('final_mrk_result', 'final_mark_result', 'final_result')),
            'notes' => $this->textarea_any($data, array('notes', 'academic_notes')),
            'oracle_created_at' => $this->datetime_any($data, array('date_created', 'oracle_created_at')),
            'oracle_modified_at' => $this->datetime_any($data, array('date_modified', 'oracle_modified_at')),
            'source_system' => 'oracle',
            'raw_json' => $this->raw_json($data),
        );
        $payload['source_hash'] = hash('sha256', wp_json_encode($payload));

        return $payload;
    }

    private function text_any($data, $keys, $default = null) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_text_field((string) $data[$key]);
            }
        }

        return $default;
    }

    private function date_any($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                $timestamp = strtotime((string) $data[$key]);
                return $timestamp ? gmdate('Y-m-d', $timestamp) : sanitize_text_field((string) $data[$key]);
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

    private function int_any($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return (int) $data[$key];
            }
        }
        return null;
    }

    private function textarea_any($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_textarea_field((string) $data[$key]);
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
            if (in_array($key, array('student_uid', 'family_uid', 'oracle_family_id', 'oracle_student_id', 'study_year', 'source_system', 'source_hash'), true)) {
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
