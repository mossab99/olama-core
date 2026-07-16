<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public, read-only audience queries for Olama consumer plugins.
 */
class Olama_Core_Audience_Service {
    private $repo;

    public function __construct(Olama_Core_Repository $repo) {
        $this->repo = $repo;
    }

    public function is_ready() {
        foreach (array('olama_core_families', 'olama_core_students', 'olama_core_student_years') as $name) {
            if (!Olama_Core_Migrator::table_exists($this->repo->table($name))) {
                return false;
            }
        }
        return true;
    }

    public function get_study_years() {
        global $wpdb;
        $table = $this->repo->table('olama_core_student_years');
        return $wpdb->get_col("SELECT DISTINCT study_year FROM `{$table}` WHERE study_year IS NOT NULL AND study_year <> '' ORDER BY study_year DESC");
    }

    public function get_class_names($study_year = '') {
        return $this->distinct_year_values('class_name', $study_year);
    }

    public function get_section_names($study_year = '') {
        return $this->distinct_year_values('section_name', $study_year);
    }

    public function query(array $filters = array()) {
        $target_type = sanitize_key(isset($filters['target_type']) ? $filters['target_type'] : 'general');
        if ($target_type === 'transportation') {
            return $this->query_transportation($filters);
        }
        if ($target_type === 'collection' || $target_type === 'financial') {
            return $this->query_financial($filters);
        }
        return $this->query_general($filters);
    }

    private function query_general(array $filters) {
        global $wpdb;
        $families = $this->repo->table('olama_core_families');
        $years = $this->repo->table('olama_core_student_years');
        $where = array();
        $values = array();
        $study_year = sanitize_text_field(isset($filters['study_year']) ? $filters['study_year'] : '');

        if (!empty($filters['family_id'])) {
            $where[] = 'f.oracle_family_id = %s';
            $values[] = sanitize_text_field((string) $filters['family_id']);
        }
        if (!empty($filters['search'])) {
            $search = sanitize_text_field((string) $filters['search']);
            $like = '%' . $wpdb->esc_like($search) . '%';
            if (is_numeric($search)) {
                $where[] = '(f.oracle_family_id = %s OR f.father_mobile LIKE %s OR f.mother_mobile LIKE %s)';
                array_push($values, $search, $like, $like);
            } else {
                $where[] = '(f.sponsor_full_name LIKE %s OR f.father_name LIKE %s OR f.mother_name LIKE %s)';
                array_push($values, $like, $like, $like);
            }
        }

        $year_conditions = array('sy.family_uid = f.family_uid');
        $year_values = array();
        if ($study_year !== '') {
            $year_conditions[] = 'sy.study_year = %s';
            $year_values[] = $study_year;
        }
        foreach (array('class_id', 'class_name', 'section_id', 'section_name') as $field) {
            if (!empty($filters[$field])) {
                $year_conditions[] = "sy.{$field} = %s";
                $year_values[] = sanitize_text_field((string) $filters[$field]);
            }
        }
        if ($study_year !== '' || count($year_conditions) > 1) {
            $where[] = 'EXISTS (SELECT 1 FROM `' . esc_sql($years) . '` sy WHERE ' . implode(' AND ', $year_conditions) . ')';
            $values = array_merge($values, $year_values);
        }

        $where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $limit = min(200, max(1, absint(isset($filters['limit']) ? $filters['limit'] : 50)));
        $offset = max(0, absint(isset($filters['offset']) ? $filters['offset'] : 0));
        $base = " FROM `{$families}` f{$where_sql}";
        $count_sql = 'SELECT COUNT(*)' . $base;
        $rows_sql = 'SELECT f.*' . $base . ' ORDER BY CAST(f.oracle_family_id AS UNSIGNED), f.oracle_family_id LIMIT %d OFFSET %d';
        $total = (int) ($values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql));
        $query_values = array_merge($values, array($limit, $offset));
        $families_rows = $wpdb->get_results($wpdb->prepare($rows_sql, $query_values), ARRAY_A);

        $items = array();
        foreach ($families_rows as $family) {
            $student_rows = $this->student_rows($family['family_uid'], $study_year, $filters);
            $summary = $study_year !== '' ? olama_core()->financial()->get_summary($family['oracle_family_id'], $study_year) : null;
            $dues = $summary ? olama_core()->financial()->get_dues($family['oracle_family_id'], $study_year) : array();
            $items[] = $this->recipient_item($family, $student_rows, $summary, $dues);
        }

        return $this->response($items, $total, $limit, $offset, 'general');
    }

    private function query_financial(array $filters) {
        $study_year = $this->study_year($filters);
        if ($study_year === '') {
            return $this->response(array(), 0, 50, 0, 'financial');
        }
        $result = olama_core()->financial()->query_recipients($study_year, $filters);
        $items = array();
        foreach ((array) ($result['recipients'] ?? array()) as $row) {
            $student_rows = array_values((array) ($row['students'] ?? array()));
            $items[] = array(
                'family_id' => absint($row['family_id'] ?? 0),
                'oracle_family_id' => (string) ($row['family_id'] ?? ''),
                'sponsor_name' => (string) ($row['sponsor_full_name'] ?? ''),
                'father_name' => (string) ($row['father_name'] ?? ''),
                'father_mobile' => (string) ($row['father_mobile'] ?? ''),
                'mother_name' => (string) ($row['mother_name'] ?? ''),
                'mother_mobile' => (string) ($row['mother_mobile'] ?? ''),
                'students' => array_values(array_filter(wp_list_pluck($student_rows, 'student_name'))),
                'student_rows' => $student_rows,
                'class_names' => array_values(array_unique(array_filter(wp_list_pluck($student_rows, 'class_name')))),
                'section_names' => array_values(array_unique(array_filter(wp_list_pluck($student_rows, 'section_name')))),
                'balance' => isset($row['balance']) ? (float) $row['balance'] : null,
                'monthly_due' => isset($row['monthly_due']) ? $row['monthly_due'] : null,
                'monthly_due_source' => isset($row['monthly_due']) && $row['monthly_due'] !== null ? 'due_allocation' : 'unavailable',
                'currency' => (string) ($row['currency'] ?? 'JOD'),
                'financial_available' => true,
            );
        }
        return $this->response($items, absint($result['count'] ?? count($items)), absint($result['limit'] ?? 50), absint($result['offset'] ?? 0), 'financial');
    }

    private function query_transportation(array $filters) {
        $study_year = $this->study_year($filters);
        if ($study_year === '') {
            return $this->response(array(), 0, 50, 0, 'transportation');
        }
        $result = olama_core()->transportation()->query_recipients($study_year, $filters);
        $items = array();
        foreach ((array) ($result['recipients'] ?? array()) as $row) {
            $student_rows = array_values((array) ($row['matching_students'] ?? array()));
            $items[] = array(
                'family_id' => absint($row['family_id'] ?? 0),
                'oracle_family_id' => (string) ($row['family_id'] ?? ''),
                'sponsor_name' => (string) ($row['sponsor_full_name'] ?? ''),
                'father_name' => (string) ($row['father_name'] ?? ''),
                'father_mobile' => (string) ($row['father_mobile'] ?? ''),
                'mother_name' => (string) ($row['mother_name'] ?? ''),
                'mother_mobile' => (string) ($row['mother_mobile'] ?? ''),
                'students' => array_values(array_filter(wp_list_pluck($student_rows, 'student_name'))),
                'student_rows' => $student_rows,
                'class_names' => array_values(array_unique(array_filter(wp_list_pluck($student_rows, 'class_name')))),
                'section_names' => array_values(array_unique(array_filter(wp_list_pluck($student_rows, 'section_name')))),
                'balance' => null,
                'monthly_due' => null,
                'monthly_due_source' => 'unavailable',
                'currency' => 'JOD',
                'financial_available' => false,
            );
        }
        return $this->response($items, absint($result['count'] ?? count($items)), absint($result['limit'] ?? 50), absint($result['offset'] ?? 0), 'transportation');
    }

    private function student_rows($family_uid, $study_year, array $filters) {
        $years = olama_core()->student_years()->get_by_family($family_uid, $study_year !== '' ? $study_year : null);
        $seen = array();
        $rows = array();
        foreach ($years as $year) {
            if (isset($seen[$year['student_uid']])) {
                continue;
            }
            $include = true;
            foreach (array('class_id', 'class_name', 'section_id', 'section_name') as $field) {
                if (!empty($filters[$field]) && (string) ($year[$field] ?? '') !== (string) $filters[$field]) {
                    $include = false;
                }
            }
            if (!$include) {
                continue;
            }
            $student = olama_core()->students()->get_by_uid($year['student_uid']);
            $rows[] = array(
                'student_id' => $student ? $student['oracle_student_id'] : $year['oracle_student_id'],
                'student_name' => $student ? $student['student_name'] : '',
                'class_id' => $year['class_id'],
                'class_name' => $year['class_name'],
                'section_id' => $year['section_id'],
                'section_name' => $year['section_name'],
                'study_year' => $year['study_year'],
            );
            $seen[$year['student_uid']] = true;
        }
        if (!$rows && $study_year === '' && empty(array_filter(array_intersect_key($filters, array_flip(array('class_id', 'class_name', 'section_id', 'section_name')))))) {
            foreach (olama_core()->students()->get_by_family_uid($family_uid) as $student) {
                $rows[] = array('student_id' => $student['oracle_student_id'], 'student_name' => $student['student_name'], 'class_id' => '', 'class_name' => '', 'section_id' => '', 'section_name' => '', 'study_year' => '');
            }
        }
        return $rows;
    }

    private function recipient_item(array $family, array $student_rows, $summary, array $dues) {
        return array(
            'family_id' => absint($family['oracle_family_id']),
            'oracle_family_id' => (string) $family['oracle_family_id'],
            'sponsor_name' => (string) ($family['sponsor_full_name'] ?? ''),
            'father_name' => (string) ($family['father_name'] ?? ''),
            'father_mobile' => (string) ($family['father_mobile'] ?? ''),
            'mother_name' => (string) ($family['mother_name'] ?? ''),
            'mother_mobile' => (string) ($family['mother_mobile'] ?? ''),
            'students' => array_values(array_filter(wp_list_pluck($student_rows, 'student_name'))),
            'student_rows' => $student_rows,
            'class_names' => array_values(array_unique(array_filter(wp_list_pluck($student_rows, 'class_name')))),
            'section_names' => array_values(array_unique(array_filter(wp_list_pluck($student_rows, 'section_name')))),
            'balance' => $summary ? (float) $summary['balance'] : null,
            'monthly_due' => $dues ? (float) $dues[0]['due_amount'] : null,
            'monthly_due_source' => $dues ? 'due_allocation' : 'unavailable',
            'currency' => $summary ? (string) $summary['currency'] : 'JOD',
            'financial_available' => (bool) $summary,
        );
    }

    private function response(array $items, $total, $limit, $offset, $audience) {
        return array('success' => true, 'data_source' => 'olama_core', 'audience' => $audience, 'items' => $items, 'total' => (int) $total, 'limit' => (int) $limit, 'offset' => (int) $offset);
    }

    private function study_year(array $filters) {
        $study_year = sanitize_text_field(isset($filters['study_year']) ? $filters['study_year'] : '');
        if ($study_year === '') {
            $years = $this->get_study_years();
            $study_year = $years ? (string) $years[0] : '';
        }
        return $study_year;
    }

    private function distinct_year_values($column, $study_year) {
        global $wpdb;
        $allowed = array('class_name', 'section_name');
        if (!in_array($column, $allowed, true)) {
            return array();
        }
        $table = $this->repo->table('olama_core_student_years');
        $study_year = sanitize_text_field((string) $study_year);
        if ($study_year !== '') {
            return $wpdb->get_col($wpdb->prepare("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE study_year = %s AND `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`", $study_year));
        }
        return $wpdb->get_col("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`");
    }
}
