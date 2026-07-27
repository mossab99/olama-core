<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sole write owner for academic-year and semester definitions.
 */
class Olama_Core_Academic_Calendar_Service {
    private $years_table;
    private $semesters_table;
    private $source_mappings_table;

    public function __construct() {
        global $wpdb;
        $this->years_table = $wpdb->prefix . 'olama_academic_years';
        $this->semesters_table = $wpdb->prefix . 'olama_semesters';
        $this->source_mappings_table = $wpdb->prefix . 'olama_core_academic_year_source_mappings';
    }

    public function years() {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM `' . esc_sql($this->years_table) . '` ORDER BY start_date DESC, id DESC');
    }

    public function year($year_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->years_table) . '` WHERE id = %d LIMIT 1',
            absint($year_id)
        ));
    }

    public function semesters($year_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->semesters_table) . '` WHERE academic_year_id = %d ORDER BY start_date ASC, id ASC',
            absint($year_id)
        ));
    }

    public function semester($semester_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->semesters_table) . '` WHERE id = %d LIMIT 1',
            absint($semester_id)
        ));
    }

    public function normalize_year_code($value) {
        $value = trim((string) $value);
        if (preg_match('/(\d{4})\D*(\d{4})/', $value, $matches)) {
            return $matches[1] . '-' . $matches[2];
        }
        return sanitize_text_field($value);
    }

    public function resolve_year_code($value) {
        $canonical = $this->normalize_year_code($value);
        if ($canonical === '') {
            return null;
        }
        $matches = array();
        foreach ($this->years() as $year) {
            $candidate = !empty($year->code) ? $year->code : $year->year_name;
            if ($this->normalize_year_code($candidate) === $canonical) {
                $matches[] = $year;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    public function canonical_year_code($year_id) {
        $year = $this->year(absint($year_id));
        if (!$year) {
            return '';
        }
        return $this->normalize_year_code(!empty($year->code) ? $year->code : $year->year_name);
    }

    public function source_mapping($year_id, $source_system) {
        global $wpdb;
        $source_system = sanitize_key($source_system);
        if (!$year_id || $source_system === '' || !Olama_Core_Migrator::table_exists($this->source_mappings_table)) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM `' . esc_sql($this->source_mappings_table) . '` WHERE academic_year_id = %d AND source_system = %s LIMIT 1',
            absint($year_id),
            $source_system
        ));
    }

    public function external_year_code($year_id, $source_system) {
        $mapping = $this->source_mapping($year_id, $source_system);
        return $mapping ? (string) $mapping->external_code : $this->canonical_year_code($year_id);
    }

    public function resolve_external_year($source_system, $value) {
        global $wpdb;
        $source_system = sanitize_key($source_system);
        $value = trim(sanitize_text_field((string) $value));
        if ($source_system === '' || $value === '') {
            return null;
        }
        if (Olama_Core_Migrator::table_exists($this->source_mappings_table)) {
            $year_id = $wpdb->get_var($wpdb->prepare(
                'SELECT academic_year_id FROM `' . esc_sql($this->source_mappings_table) . '` WHERE source_system = %s AND external_code = %s LIMIT 1',
                $source_system,
                $value
            ));
            if ($year_id) {
                return $this->year((int) $year_id);
            }
        }
        return $this->resolve_year_code($value);
    }

    public function save_source_mapping($year_id, $source_system, $external_code) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage academic-year source mappings.', 'olama-core'));
        }
        $year_id = absint($year_id);
        $source_system = sanitize_key($source_system);
        $external_code = trim(sanitize_text_field((string) $external_code));
        $canonical = $this->canonical_year_code($year_id);
        if (!$year_id || $canonical === '') {
            return new WP_Error('academic_year_mapping_year', __('Select a valid academic year.', 'olama-core'));
        }
        if ($source_system === '') {
            return new WP_Error('academic_year_mapping_source', __('Provide a valid source system.', 'olama-core'));
        }
        if ($external_code === '') {
            global $wpdb;
            $deleted = $wpdb->delete($this->source_mappings_table, array('academic_year_id' => $year_id, 'source_system' => $source_system));
            if ($deleted === false) {
                return new WP_Error('academic_year_mapping_delete', $wpdb->last_error ?: __('The external year mapping could not be deleted.', 'olama-core'));
            }
            Olama_Core_Logger::log('academic_year_source_mapping_deleted', sprintf('Deleted %s mapping for academic year #%d.', $source_system, $year_id), 'core');
            return true;
        }
        if ($this->normalize_year_code($external_code) !== $canonical) {
            return new WP_Error('academic_year_mapping_mismatch', __('The external year code must represent the same start and end years as the Core canonical code.', 'olama-core'));
        }

        global $wpdb;
        $existing = $this->source_mapping($year_id, $source_system);
        $now = current_time('mysql');
        $data = array(
            'academic_year_id' => $year_id,
            'source_system' => $source_system,
            'external_code' => $external_code,
            'updated_by' => get_current_user_id(),
            'updated_at' => $now,
        );
        if ($existing) {
            $result = $wpdb->update($this->source_mappings_table, $data, array('id' => (int) $existing->id));
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = $now;
            $result = $wpdb->insert($this->source_mappings_table, $data);
        }
        if ($result === false) {
            return new WP_Error('academic_year_mapping_save', $wpdb->last_error ?: __('The external year mapping could not be saved.', 'olama-core'));
        }
        Olama_Core_Logger::log('academic_year_source_mapping_saved', sprintf('Saved %s mapping for academic year #%d as %s.', $source_system, $year_id, $external_code), 'core');
        return true;
    }

    public function create_year(array $data) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage the academic calendar.', 'olama-core'));
        }
        $validated = $this->validate_year($data);
        if (is_wp_error($validated)) {
            return $validated;
        }
        global $wpdb;
        $now = current_time('mysql');
        $inserted = $wpdb->insert($this->years_table, array(
            'code' => $validated['code'],
            'year_name' => $validated['year_name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'lifecycle_status' => 'draft',
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if (!$inserted) {
            return new WP_Error('academic_year_create_failed', $wpdb->last_error ?: __('Academic year could not be created.', 'olama-core'));
        }
        Olama_Core_Logger::log('academic_year_created', sprintf('Created academic year #%d (%s).', $wpdb->insert_id, $validated['code']), 'core');
        return (int) $wpdb->insert_id;
    }

    public function update_year($year_id, array $data) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage the academic calendar.', 'olama-core'));
        }
        $year_id = absint($year_id);
        if (!$this->year($year_id)) {
            return new WP_Error('academic_year_missing', __('Academic year was not found.', 'olama-core'));
        }
        $validated = $this->validate_year($data, $year_id);
        if (is_wp_error($validated)) {
            return $validated;
        }
        global $wpdb;
        $updated = $wpdb->update($this->years_table, array(
            'code' => $validated['code'],
            'year_name' => $validated['year_name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'updated_at' => current_time('mysql'),
        ), array('id' => $year_id));
        if ($updated === false) {
            return new WP_Error('academic_year_update_failed', $wpdb->last_error ?: __('Academic year could not be updated.', 'olama-core'));
        }
        Olama_Core_Logger::log('academic_year_updated', sprintf('Updated academic year #%d (%s).', $year_id, $validated['code']), 'core');
        return true;
    }

    public function delete_year($year_id) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage the academic calendar.', 'olama-core'));
        }
        $year_id = absint($year_id);
        if (!$this->year($year_id)) {
            return new WP_Error('academic_year_missing', __('Academic year was not found.', 'olama-core'));
        }
        if ($this->semesters($year_id)) {
            return new WP_Error('academic_year_has_semesters', __('Delete or move the year semesters before deleting this year.', 'olama-core'));
        }
        $current = olama_core()->academic_context()->current();
        if ($current && (int) $current->academic_year_id === $year_id) {
            return new WP_Error('academic_year_is_current', __('The current academic year cannot be deleted.', 'olama-core'));
        }
        $dependencies = $this->dependencies_for_column('academic_year_id', $year_id, array(
            $this->years_table,
            $this->semesters_table,
            $this->context_table(),
        ));
        if ($dependencies) {
            return new WP_Error('academic_year_has_dependencies', sprintf(
                __('Academic year cannot be deleted because it has related records: %s.', 'olama-core'),
                implode(', ', $dependencies)
            ));
        }
        global $wpdb;
        $deleted = $wpdb->delete($this->years_table, array('id' => $year_id));
        if (!$deleted) {
            return new WP_Error('academic_year_delete_failed', $wpdb->last_error ?: __('Academic year could not be deleted.', 'olama-core'));
        }
        Olama_Core_Logger::log('academic_year_deleted', sprintf('Deleted unused academic year #%d.', $year_id), 'core');
        return true;
    }

    public function create_semester(array $data) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage the academic calendar.', 'olama-core'));
        }
        $validated = $this->validate_semester($data);
        if (is_wp_error($validated)) {
            return $validated;
        }
        global $wpdb;
        $now = current_time('mysql');
        $inserted = $wpdb->insert($this->semesters_table, array(
            'academic_year_id' => $validated['academic_year_id'],
            'semester_name' => $validated['semester_name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'is_active' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ));
        if (!$inserted) {
            return new WP_Error('semester_create_failed', $wpdb->last_error ?: __('Semester could not be created.', 'olama-core'));
        }
        Olama_Core_Logger::log('semester_created', sprintf('Created semester #%d for year #%d.', $wpdb->insert_id, $validated['academic_year_id']), 'core');
        return (int) $wpdb->insert_id;
    }

    public function update_semester($semester_id, array $data) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage the academic calendar.', 'olama-core'));
        }
        $semester_id = absint($semester_id);
        $semester = $this->semester($semester_id);
        if (!$semester) {
            return new WP_Error('semester_missing', __('Semester was not found.', 'olama-core'));
        }
        $data['academic_year_id'] = (int) $semester->academic_year_id;
        $validated = $this->validate_semester($data, $semester_id);
        if (is_wp_error($validated)) {
            return $validated;
        }
        global $wpdb;
        $updated = $wpdb->update($this->semesters_table, array(
            'semester_name' => $validated['semester_name'],
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'updated_at' => current_time('mysql'),
        ), array('id' => $semester_id));
        if ($updated === false) {
            return new WP_Error('semester_update_failed', $wpdb->last_error ?: __('Semester could not be updated.', 'olama-core'));
        }
        Olama_Core_Logger::log('semester_updated', sprintf('Updated semester #%d.', $semester_id), 'core');
        return true;
    }

    public function delete_semester($semester_id) {
        if (!$this->can_manage()) {
            return new WP_Error('academic_calendar_forbidden', __('You cannot manage the academic calendar.', 'olama-core'));
        }
        $semester_id = absint($semester_id);
        $semester = $this->semester($semester_id);
        if (!$semester) {
            return new WP_Error('semester_missing', __('Semester was not found.', 'olama-core'));
        }
        $current = olama_core()->academic_context()->current();
        if ($current && (int) $current->semester_id === $semester_id) {
            return new WP_Error('semester_is_current', __('The current semester cannot be deleted.', 'olama-core'));
        }
        $dependencies = $this->dependencies_for_column('semester_id', $semester_id, array(
            $this->semesters_table,
            $this->context_table(),
        ));
        if ($dependencies) {
            return new WP_Error('semester_has_dependencies', sprintf(
                __('Semester cannot be deleted because it has related records: %s.', 'olama-core'),
                implode(', ', $dependencies)
            ));
        }
        global $wpdb;
        $deleted = $wpdb->delete($this->semesters_table, array('id' => $semester_id));
        if (!$deleted) {
            return new WP_Error('semester_delete_failed', $wpdb->last_error ?: __('Semester could not be deleted.', 'olama-core'));
        }
        Olama_Core_Logger::log('semester_deleted', sprintf('Deleted unused semester #%d.', $semester_id), 'core');
        return true;
    }

    private function validate_year(array $data, $exclude_id = 0) {
        $code = $this->normalize_year_code($data['code'] ?? $data['year_name'] ?? '');
        $name = sanitize_text_field((string) ($data['year_name'] ?? $code));
        $start = $this->valid_date($data['start_date'] ?? '');
        $end = $this->valid_date($data['end_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{4}$/', $code)) {
            return new WP_Error('academic_year_code_invalid', __('Use an academic-year code such as 2026-2027.', 'olama-core'));
        }
        if ($name === '' || !$start || !$end || $start > $end) {
            return new WP_Error('academic_year_dates_invalid', __('Provide a name and a valid academic-year date range.', 'olama-core'));
        }
        foreach ($this->years() as $year) {
            if ((int) $year->id === (int) $exclude_id) {
                continue;
            }
            $candidate = !empty($year->code) ? $year->code : $year->year_name;
            if ($this->normalize_year_code($candidate) === $code) {
                return new WP_Error('academic_year_duplicate', __('An equivalent academic year already exists.', 'olama-core'));
            }
        }
        return array('code' => $code, 'year_name' => $name, 'start_date' => $start, 'end_date' => $end);
    }

    private function validate_semester(array $data, $exclude_id = 0) {
        $year_id = absint($data['academic_year_id'] ?? 0);
        $year = $this->year($year_id);
        $name = sanitize_text_field((string) ($data['semester_name'] ?? ''));
        $start = $this->valid_date($data['start_date'] ?? '');
        $end = $this->valid_date($data['end_date'] ?? '');
        if (!$year) {
            return new WP_Error('semester_year_invalid', __('Select a valid academic year.', 'olama-core'));
        }
        if ($name === '' || !$start || !$end || $start > $end) {
            return new WP_Error('semester_dates_invalid', __('Provide a name and a valid semester date range.', 'olama-core'));
        }
        if ($start < $year->start_date || $end > $year->end_date) {
            return new WP_Error('semester_outside_year', __('Semester dates must be inside the academic-year dates.', 'olama-core'));
        }
        foreach ($this->semesters($year_id) as $semester) {
            if ((int) $semester->id === (int) $exclude_id) {
                continue;
            }
            if (strcasecmp((string) $semester->semester_name, $name) === 0) {
                return new WP_Error('semester_duplicate', __('A semester with this name already exists in the year.', 'olama-core'));
            }
            if ($start <= $semester->end_date && $end >= $semester->start_date) {
                return new WP_Error('semester_overlap', __('Semester dates overlap another semester in this year.', 'olama-core'));
            }
        }
        return array('academic_year_id' => $year_id, 'semester_name' => $name, 'start_date' => $start, 'end_date' => $end);
    }

    private function dependencies_for_column($column, $value, array $excluded_tables = array()) {
        global $wpdb;
        $column = sanitize_key($column);
        if (!in_array($column, array('academic_year_id', 'semester_id'), true)) {
            return array();
        }

        $found = array();
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        foreach ($tables as $table) {
            if (in_array($table, $excluded_tables, true)) {
                continue;
            }
            $has_column = $wpdb->get_var($wpdb->prepare(
                'SHOW COLUMNS FROM `' . esc_sql($table) . '` LIKE %s',
                $column
            ));
            if (!$has_column) {
                continue;
            }
            $count = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM `' . esc_sql($table) . '` WHERE `' . esc_sql($column) . '` = %d',
                absint($value)
            ));
            if ($count > 0) {
                $label = strpos($table, $wpdb->prefix) === 0 ? substr($table, strlen($wpdb->prefix)) : $table;
                $found[] = sprintf('%s (%d)', $label, $count);
            }
        }
        return $found;
    }

    private function context_table() {
        global $wpdb;
        return $wpdb->prefix . 'olama_core_academic_context';
    }

    private function valid_date($value) {
        $value = sanitize_text_field((string) $value);
        $date = DateTime::createFromFormat('Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : '';
    }

    private function can_manage() {
        return current_user_can('manage_olama_academic_context') || current_user_can('manage_options');
    }
}
