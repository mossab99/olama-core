<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authoritative, atomic active academic-year and semester context.
 */
class Olama_Core_Academic_Context_Service {
    private $calendar;
    private $context_table;
    private $years_table;
    private $semesters_table;
    private $cached = false;

    public function __construct(Olama_Core_Academic_Calendar_Service $calendar) {
        global $wpdb;
        $this->calendar = $calendar;
        $this->context_table = $wpdb->prefix . 'olama_core_academic_context';
        $this->years_table = $wpdb->prefix . 'olama_academic_years';
        $this->semesters_table = $wpdb->prefix . 'olama_semesters';
    }

    public function current() {
        if ($this->cached !== false) {
            return $this->cached;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            'SELECT c.academic_year_id, c.semester_id, c.revision, c.updated_by, c.updated_at,
                    y.code AS study_year, y.year_name, y.start_date AS year_start_date,
                    y.end_date AS year_end_date, y.lifecycle_status,
                    s.semester_name, s.start_date AS semester_start_date, s.end_date AS semester_end_date
             FROM `' . esc_sql($this->context_table) . '` c
             LEFT JOIN `' . esc_sql($this->years_table) . '` y ON y.id = c.academic_year_id
             LEFT JOIN `' . esc_sql($this->semesters_table) . '` s ON s.id = c.semester_id
             WHERE c.id = 1 LIMIT 1'
        );
        if (!$row || !(int) $row->academic_year_id || !(int) $row->semester_id || empty($row->year_name) || empty($row->semester_name)) {
            $this->cached = null;
            return null;
        }
        $this->cached = $row;
        return $row;
    }

    public function current_year() {
        $current = $this->current();
        return $current ? $this->calendar->year((int) $current->academic_year_id) : null;
    }

    public function current_semester() {
        $current = $this->current();
        return $current ? $this->calendar->semester((int) $current->semester_id) : null;
    }

    public function set_context($year_id, $semester_id) {
        if (!current_user_can('manage_olama_academic_context') && !current_user_can('manage_options')) {
            return new WP_Error('academic_context_forbidden', __('You cannot change the academic context.', 'olama-core'));
        }
        $year_id = absint($year_id);
        $semester_id = absint($semester_id);
        $year = $this->calendar->year($year_id);
        $semester = $this->calendar->semester($semester_id);
        if (!$year) {
            return new WP_Error('academic_context_year_missing', __('Academic year was not found.', 'olama-core'));
        }
        if (!$semester) {
            return new WP_Error('academic_context_semester_missing', __('Semester was not found.', 'olama-core'));
        }
        if ((int) $semester->academic_year_id !== $year_id) {
            return new WP_Error('academic_context_mismatch', __('The selected semester does not belong to the selected academic year.', 'olama-core'));
        }

        global $wpdb;
        $previous = $this->current();
        $now = current_time('mysql');
        $wpdb->query('START TRANSACTION');
        try {
            $locked = $wpdb->get_row('SELECT * FROM `' . esc_sql($this->context_table) . '` WHERE id = 1 FOR UPDATE');
            if (!$locked) {
                throw new RuntimeException('Academic context row is missing.');
            }
            $this->assert_query($wpdb->query(
                "UPDATE `" . esc_sql($this->years_table) . "`
                 SET is_active = 0,
                     lifecycle_status = CASE WHEN lifecycle_status = 'open' THEN 'closed' ELSE lifecycle_status END
                 WHERE is_active <> 0 OR lifecycle_status = 'open'"
            ), 'deactivate academic years');
            $this->assert_query($wpdb->query('UPDATE `' . esc_sql($this->semesters_table) . '` SET is_active = 0 WHERE is_active <> 0'), 'deactivate semesters');
            $this->assert_query($wpdb->update($this->years_table, array(
                'is_active' => 1,
                'lifecycle_status' => 'open',
                'updated_at' => $now,
            ), array('id' => $year_id)), 'activate academic year');
            $this->assert_query($wpdb->update($this->semesters_table, array(
                'is_active' => 1,
                'updated_at' => $now,
            ), array('id' => $semester_id)), 'activate semester');
            $this->assert_query($wpdb->query($wpdb->prepare(
                'UPDATE `' . esc_sql($this->context_table) . '`
                 SET academic_year_id = %d, semester_id = %d, revision = revision + 1,
                     updated_by = %d, updated_at = %s WHERE id = 1',
                $year_id,
                $semester_id,
                get_current_user_id(),
                $now
            )), 'update academic context');
            $this->assert_query($wpdb->query('COMMIT'), 'commit academic context');
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('academic_context_update_failed', $error->getMessage());
        }

        $this->cached = false;
        $new = $this->current();
        Olama_Core_Logger::log('academic_context_changed', sprintf(
            'Academic context changed from year #%d / semester #%d to year #%d / semester #%d.',
            $previous ? (int) $previous->academic_year_id : 0,
            $previous ? (int) $previous->semester_id : 0,
            $year_id,
            $semester_id
        ), 'core');
        do_action('olama_core_academic_context_changed', $new, $previous);
        return $new;
    }

    public function assert_writable_year($year_id) {
        $current = $this->current();
        if (!$current || (int) $current->academic_year_id !== absint($year_id) || 'open' !== (string) $current->lifecycle_status) {
            return new WP_Error('academic_year_closed', __('Transactions can only be written to the open academic year.', 'olama-core'));
        }
        return true;
    }

    private function assert_query($result, $operation) {
        global $wpdb;
        if ($result === false) {
            throw new RuntimeException(sprintf('%s failed: %s', $operation, $wpdb->last_error ?: 'database error'));
        }
    }
}
