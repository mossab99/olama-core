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
        return $this->repo->search($this->table, array('student_uid', 'family_uid', 'oracle_family_id', 'oracle_student_id', 'student_name', 'student_national_no', 'student_mobile'), sanitize_text_field($term), $args);
    }

    public function upsert_from_source(array $data) {
        $normalized = $this->normalize($data);
        $existing = $this->get_by_oracle_keys($normalized['oracle_family_id'], $normalized['oracle_student_id']);

        if ($existing && hash_equals((string) $existing['source_hash'], $normalized['source_hash'])) {
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
            'student_name' => isset($data['student_name']) ? sanitize_text_field($data['student_name']) : '',
            'student_national_no' => isset($data['student_national_no']) ? sanitize_text_field($data['student_national_no']) : null,
            'student_mobile' => isset($data['student_mobile']) ? sanitize_text_field($data['student_mobile']) : null,
            'student_status' => isset($data['student_status']) ? sanitize_text_field($data['student_status']) : null,
            'source_system' => 'oracle',
            'raw_json' => wp_json_encode(isset($data['raw']) ? $data['raw'] : $data),
        );
        $payload['source_hash'] = hash('sha256', wp_json_encode($payload));

        return $payload;
    }
}
