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
        $normalized = $this->normalize($data);
        $existing = $this->repo->get_row($this->table, array(
            'student_uid' => $normalized['student_uid'],
            'study_year' => $normalized['study_year'],
        ));

        if ($existing && hash_equals((string) $existing['source_hash'], $normalized['source_hash'])) {
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
            'class_id' => isset($data['class_id']) ? sanitize_text_field($data['class_id']) : null,
            'class_name' => isset($data['class_name']) ? sanitize_text_field($data['class_name']) : null,
            'section_id' => isset($data['section_id']) ? sanitize_text_field($data['section_id']) : null,
            'section_name' => isset($data['section_name']) ? sanitize_text_field($data['section_name']) : null,
            'student_year_status' => isset($data['student_year_status']) ? sanitize_text_field($data['student_year_status']) : (isset($data['student_status']) ? sanitize_text_field($data['student_status']) : null),
            'source_system' => 'oracle',
            'raw_json' => wp_json_encode(isset($data['raw']) ? $data['raw'] : $data),
        );
        $payload['source_hash'] = hash('sha256', wp_json_encode($payload));

        return $payload;
    }
}
