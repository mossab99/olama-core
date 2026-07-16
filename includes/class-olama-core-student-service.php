<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Student_Service {
    private $repo;
    private $table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
        $this->table = $repo->table('olama_core_students');
    }

    public function get_by_uid($student_uid) {
        return $this->repo->get_row($this->table, array('student_uid' => sanitize_text_field($student_uid)));
    }

    public function get_by_oracle_keys($oracle_family_id, $oracle_student_id) {
        return $this->repo->get_row($this->table, array(
            'oracle_family_id' => sanitize_text_field($oracle_family_id),
            'oracle_student_id' => sanitize_text_field($oracle_student_id),
        ));
    }

    public function get_by_family_uid($family_uid) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->table) . '` WHERE family_uid = %s ORDER BY student_name ASC',
            sanitize_text_field($family_uid)
        ), ARRAY_A);
    }

    public function search($term, $args = array()) {
        return $this->repo->search($this->table, array('student_uid', 'family_uid', 'oracle_family_id', 'oracle_student_id', 'student_name', 'student_national_no', 'student_mobile', 'mother_mobile'), sanitize_text_field($term), $args);
    }

    public function upsert_from_source(array $data) {
        $partial = !empty($data['_partial']);
        $normalized = $this->normalize($data);
        $existing = $this->get_by_oracle_keys($normalized['oracle_family_id'], $normalized['oracle_student_id']);

        if ($partial && $existing) {
            $normalized = $this->preserve_missing_values($normalized, $existing);
        }

        if ($existing && hash_equals((string) $existing['source_hash'], $normalized['source_hash'])) {
            $now = current_time('mysql');
            $this->repo->update($this->table, array('last_synced_at' => $now), array('id' => (int) $existing['id']));
            return array('operation' => 'skipped', 'status' => 'success', 'id' => (int) $existing['id'], 'uid' => $existing['student_uid']);
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
        if ('' === $family_id || '' === $student_id) {
            throw new InvalidArgumentException('Missing Oracle family/student keys.');
        }

        $payload = array(
            'student_uid' => 'ORA-STU-' . $family_id . '-' . $student_id,
            'family_uid' => 'ORA-FAM-' . $family_id,
            'oracle_family_id' => $family_id,
            'oracle_student_id' => $student_id,
            'student_name' => $this->text_any($data, array('student_name', 'name', 'full_name'), ''),
            'student_national_no' => $this->text_any($data, array('student_national_no', 'national_no', 'national_number')),
            'student_gender' => $this->text_any($data, array('student_gender', 'gender')),
            'student_gender_name' => $this->text_any($data, array('student_gender_name', 'gender_name')),
            'student_mobile' => $this->text_any($data, array('student_mobile', 'mobile')),
            'mother_mobile' => $this->text_any($data, array('mother_mobile', 'sch_mother_mobile')),
            'mother_name' => $this->text_any($data, array('mother_name', 'sch_mother_full_name')),
            'email' => $this->email_any($data, array('email', 'student_email')),
            'birth_date' => $this->date_any($data, array('birth_date', 'student_birth_date')),
            'birth_place' => $this->text_any($data, array('birth_place', 'student_birth_place')),
            'nationality' => $this->text_any($data, array('nationality', 'nationality_name')),
            'registration_date' => $this->date_any($data, array('registration_date', 'date_registered')),
            'previous_school' => $this->text_any($data, array('from_school', 'previous_school', 'prev_school')),
            'previous_school_average' => $this->decimal_any($data, array('from_school_ave', 'previous_school_avg', 'previous_average')),
            'has_renew' => $this->bool_any($data, array('has_renew')),
            'renew_year' => $this->text_any($data, array('renew_year', 'renew_study_year')),
            'renew_date' => $this->date_any($data, array('renew_date', 'renewal_date')),
            'will_not_renew' => $this->bool_any($data, array('will_not_renew')),
            'will_not_renew_reason' => $this->textarea_any($data, array('no_renew_reason', 'will_not_renew_reason', 'not_renew_reason')),
            'student_health' => $this->textarea_any($data, array('student_health', 'health_status')),
            'social_case' => $this->text_any($data, array('social_case', 'social_status')),
            'refugee_emigrant' => $this->text_any($data, array('refugee_emigrant', 'refugee')),
            'black_list' => $this->bool_any($data, array('black_list')),
            'black_list_reason' => $this->textarea_any($data, array('black_list_reason')),
            'religion_id' => $this->text_any($data, array('religion_id', 'religion')),
            'pass_fail' => $this->text_any($data, array('pass_fail', 'student_result')),
            'monthly_income' => $this->decimal_any($data, array('monthly_income', 'student_monthly_income')),
            'student_status' => $this->text_any($data, array('student_status', 'status')),
            'student_status_name' => $this->text_any($data, array('student_status_name', 'status_name')),
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

    private function textarea_any($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_textarea_field((string) $data[$key]);
            }
        }
        return null;
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
                return in_array(strtolower(trim((string) $data[$key])), array('1', 'true', 'yes', 'active', 'enabled'), true) ? 1 : 0;
            }
        }
        return null;
    }

    private function date_any($data, $keys) {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                $timestamp = strtotime((string) $data[$key]);
                return $timestamp ? gmdate('Y-m-d', $timestamp) : null;
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

    private function decimal_any($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return is_numeric($data[$key]) ? round((float) $data[$key], 3) : null;
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
            if (in_array($key, array('student_uid', 'family_uid', 'oracle_family_id', 'oracle_student_id', 'source_system', 'source_hash'), true)) {
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
