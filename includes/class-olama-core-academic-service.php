<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Academic_Service {
    private $repo;
    private $grades;
    private $sections;
    private $grade_sections;
    private $students;
    private $grade_subjects;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
        $this->grades = $repo->table('olama_core_academic_grades');
        $this->sections = $repo->table('olama_core_academic_sections');
        $this->grade_sections = $repo->table('olama_core_academic_grade_sections');
        $this->students = $repo->table('olama_core_academic_students');
        $this->grade_subjects = $repo->table('olama_core_academic_grade_subjects');
    }

    public function import_snapshot(array $snapshot) {
        global $wpdb;

        if (!Olama_Core_Migrator::schema_is_current()) {
            Olama_Core_Migrator::create_tables();
            if (!Olama_Core_Migrator::schema_is_current()) {
                throw new RuntimeException('Olama Core academic database schema could not be installed. Verify database CREATE and ALTER permissions.');
            }
            update_option('olama_core_db_version', OLAMA_CORE_VERSION);
        }

        $study_year = $this->text($snapshot, 'study_year');
        if ('' === $study_year) {
            throw new InvalidArgumentException('Academic snapshot study year is required.');
        }

        foreach (array('grades', 'sections', 'grade_sections', 'students', 'grade_subjects') as $key) {
            if (!isset($snapshot[$key]) || !is_array($snapshot[$key])) {
                throw new InvalidArgumentException('Academic snapshot is missing ' . $key . '.');
            }
        }
        if (!$snapshot['grades'] || !$snapshot['sections']) {
            throw new InvalidArgumentException('Academic snapshot must contain grade and section master data.');
        }
        $snapshot['grade_subjects'] = $this->deduplicate_grade_subjects($snapshot['grade_subjects']);

        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        try {
            $this->assert_query($wpdb->query('DELETE FROM `' . esc_sql($this->grades) . '`'), 'grade master');
            $this->assert_query($wpdb->query('DELETE FROM `' . esc_sql($this->sections) . '`'), 'section master');
            foreach ($snapshot['grades'] as $row) {
                $grade_id = $this->text($row, 'grade_id');
                if ('' === $grade_id) {
                    continue;
                }
                $result = $wpdb->replace($this->grades, array(
                    'grade_id' => $grade_id,
                    'grade_name' => $this->nullable_text($row, 'grade_name'),
                    'raw_json' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                ));
                $this->assert_written($result, 'grade');
            }

            foreach ($snapshot['sections'] as $row) {
                $section_id = $this->text($row, 'section_id');
                if ('' === $section_id) {
                    continue;
                }
                $result = $wpdb->replace($this->sections, array(
                    'section_id' => $section_id,
                    'section_name' => $this->nullable_text($row, 'section_name'),
                    'raw_json' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                ));
                $this->assert_written($result, 'section');
            }

            foreach (array($this->grade_sections, $this->students, $this->grade_subjects) as $table) {
                $this->assert_query($wpdb->delete($table, array('study_year' => $study_year)), 'study-year snapshot');
            }

            foreach ($snapshot['grade_sections'] as $row) {
                $this->insert_grade_section($row, $study_year, $now);
            }
            foreach ($snapshot['students'] as $row) {
                $this->insert_student($row, $study_year, $now);
            }
            foreach ($snapshot['grade_subjects'] as $row) {
                $this->insert_grade_subject($row, $study_year, $now);
            }

            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }

        return array(
            'study_year' => $study_year,
            'grades' => count($snapshot['grades']),
            'sections' => count($snapshot['sections']),
            'grade_sections' => count($snapshot['grade_sections']),
            'students' => count($snapshot['students']),
            'grade_subjects' => count($snapshot['grade_subjects']),
        );
    }

    public function grades() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM `' . esc_sql($this->grades) . '` ORDER BY CAST(grade_id AS UNSIGNED), grade_id', ARRAY_A);
    }

    public function sections() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM `' . esc_sql($this->sections) . '` ORDER BY CAST(section_id AS UNSIGNED), section_id', ARRAY_A);
    }

    public function grade_sections($study_year) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->grade_sections) . '` WHERE study_year=%s ORDER BY CAST(grade_id AS UNSIGNED), grade_id, CAST(section_id AS UNSIGNED), section_id',
            sanitize_text_field((string) $study_year)
        ), ARRAY_A);
    }

    public function students($study_year, $grade_id = '', $section_id = '') {
        global $wpdb;
        $where = array('study_year=%s');
        $values = array(sanitize_text_field((string) $study_year));
        if ('' !== (string) $grade_id) {
            $where[] = 'grade_id=%s';
            $values[] = sanitize_text_field((string) $grade_id);
        }
        if ('' !== (string) $section_id) {
            $where[] = 'section_id=%s';
            $values[] = sanitize_text_field((string) $section_id);
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->students) . '` WHERE ' . implode(' AND ', $where) . ' ORDER BY student_name, family_id, student_id',
            $values
        ), ARRAY_A);
    }

    public function grade_subjects($study_year, $grade_id = '') {
        global $wpdb;
        $sql = 'SELECT * FROM `' . esc_sql($this->grade_subjects) . '` WHERE study_year=%s';
        $values = array(sanitize_text_field((string) $study_year));
        if ('' !== (string) $grade_id) {
            $sql .= ' AND grade_id=%s';
            $values[] = sanitize_text_field((string) $grade_id);
        }
        $sql .= ' ORDER BY CAST(grade_id AS UNSIGNED), grade_id, is_active DESC, CAST(subject_id AS UNSIGNED), subject_id';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
        foreach ($rows as &$row) {
            $row['subject_status_name'] = !empty($row['is_active']) ? 'فعال' : 'غير فعال';
        }
        unset($row);
        return $rows;
    }

    public function latest_study_year() {
        global $wpdb;
        return (string) $wpdb->get_var('SELECT study_year FROM `' . esc_sql($this->grade_sections) . '` ORDER BY last_synced_at DESC, study_year DESC LIMIT 1');
    }

    private function insert_grade_section(array $row, $study_year, $now) {
        $grade_id = $this->text($row, 'grade_id');
        $section_id = $this->text($row, 'section_id');
        if ('' === $grade_id || '' === $section_id) {
            return;
        }
        $id = $this->repo->insert($this->grade_sections, array(
            'study_year' => $study_year,
            'law_id' => $this->nullable_text($row, 'law_id'),
            'grade_id' => $grade_id,
            'grade_name' => $this->nullable_text($row, 'grade_name'),
            'section_id' => $section_id,
            'section_name' => $this->nullable_text($row, 'section_name'),
            'raw_json' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_synced_at' => $now,
            'created_at' => $now,
        ));
        $this->assert_written($id, 'grade-section relationship');
    }

    private function insert_student(array $row, $study_year, $now) {
        $family_id = $this->text($row, 'family_id');
        $student_id = $this->text($row, 'student_id');
        $grade_id = $this->text($row, 'grade_id');
        if ('' === $family_id || '' === $student_id || '' === $grade_id) {
            return;
        }
        $id = $this->repo->insert($this->students, array(
            'study_year' => $study_year,
            'family_id' => $family_id,
            'student_id' => $student_id,
            'student_name' => $this->nullable_text($row, 'student_name'),
            'grade_id' => $grade_id,
            'grade_name' => $this->nullable_text($row, 'grade_name'),
            'section_id' => $this->nullable_text($row, 'section_id'),
            'section_name' => $this->nullable_text($row, 'section_name'),
            'student_status' => $this->nullable_text($row, 'student_status'),
            'raw_json' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_synced_at' => $now,
            'created_at' => $now,
        ));
        $this->assert_written($id, 'academic student');
    }

    private function insert_grade_subject(array $row, $study_year, $now) {
        $grade_id = $this->text($row, 'grade_id');
        $subject_id = $this->text($row, 'subject_id');
        if ('' === $grade_id || '' === $subject_id) {
            return;
        }
        $id = $this->repo->insert($this->grade_subjects, array(
            'study_year' => $study_year,
            'law_id' => $this->nullable_text($row, 'law_id'),
            'grade_id' => $grade_id,
            'grade_name' => $this->nullable_text($row, 'grade_name'),
            'subject_id' => $subject_id,
            'subject_name' => $this->nullable_text($row, 'subject_name'),
            'is_active' => $this->bool_value($row, 'is_active', 1),
            'raw_json' => wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_synced_at' => $now,
            'created_at' => $now,
        ));
        $this->assert_written($id, 'grade subject');
    }

    private function text(array $row, $key) {
        return isset($row[$key]) && null !== $row[$key] ? sanitize_text_field((string) $row[$key]) : '';
    }

    private function nullable_text(array $row, $key) {
        $value = $this->text($row, $key);
        return '' === $value ? null : $value;
    }

    private function bool_value(array $row, $key, $default = 0) {
        if (!array_key_exists($key, $row) || null === $row[$key] || '' === $row[$key]) {
            return (int) $default;
        }
        $value = strtolower(trim((string) $row[$key]));
        return in_array($value, array('1', 'true', 'yes', 'active', 'فعال'), true) ? 1 : 0;
    }

    private function deduplicate_grade_subjects(array $rows) {
        $unique = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $grade_id = $this->text($row, 'grade_id');
            $subject_id = $this->text($row, 'subject_id');
            if ('' === $grade_id || '' === $subject_id) {
                continue;
            }
            $key = $grade_id . "\0" . $subject_id;
            if (!isset($unique[$key])) {
                $unique[$key] = $row;
                continue;
            }
            if ($this->bool_value($row, 'is_active', 1) > $this->bool_value($unique[$key], 'is_active', 1)) {
                foreach (array('grade_name', 'subject_name') as $name_key) {
                    if (empty($row[$name_key]) && !empty($unique[$key][$name_key])) {
                        $row[$name_key] = $unique[$key][$name_key];
                    }
                }
                $unique[$key] = $row;
            } else {
                foreach (array('grade_name', 'subject_name') as $name_key) {
                    if (empty($unique[$key][$name_key]) && !empty($row[$name_key])) {
                        $unique[$key][$name_key] = $row[$name_key];
                    }
                }
            }
        }
        return array_values($unique);
    }

    private function assert_written($result, $entity) {
        global $wpdb;
        if (false === $result || 0 === (int) $result) {
            throw new RuntimeException('Could not save academic ' . $entity . ': ' . $wpdb->last_error);
        }
    }

    private function assert_query($result, $entity) {
        global $wpdb;
        if (false === $result) {
            throw new RuntimeException('Could not replace academic ' . $entity . ': ' . $wpdb->last_error);
        }
    }
}
