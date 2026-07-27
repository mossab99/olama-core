<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Archives and optionally purges explicitly classified historical data.
 * Deployment and preview are read-only; purge requires a verified archive.
 */
class Olama_Core_Year_Closeout_Service {
    private $calendar;
    private $context;
    private $archives_table;

    public function __construct(Olama_Core_Academic_Calendar_Service $calendar, Olama_Core_Academic_Context_Service $context) {
        global $wpdb;
        $this->calendar = $calendar;
        $this->context = $context;
        $this->archives_table = $wpdb->prefix . 'olama_core_year_archives';
    }

    public function preview($year_id) {
        $year = $this->eligible_year($year_id);
        if (is_wp_error($year)) {
            return $year;
        }

        $datasets = $this->datasets($year);
        $total = 0;
        $errors = array();
        foreach ($datasets as &$dataset) {
            $dataset['exists'] = $this->table_exists($dataset['table']);
            $count = $dataset['exists'] ? $this->count_dataset($dataset) : 0;
            if (is_wp_error($count)) {
                $dataset['count'] = 0;
                $dataset['error'] = $count->get_error_message();
                $errors[] = array('table' => $dataset['table'], 'column' => 'query', 'count' => 0, 'error' => $dataset['error']);
            } else {
                $dataset['count'] = $count;
            }
            if ($dataset['purge']) {
                $total += $dataset['count'];
            }
        }
        unset($dataset);

        return array(
            'year' => $year,
            'datasets' => $datasets,
            'purge_rows' => $total,
            'blockers' => array_merge($errors, $this->unclassified_scoped_tables($year, $datasets)),
            'preserved' => $this->preserved_data(),
        );
    }

    public function create_archive($year_id) {
        if (!$this->can_manage()) {
            return new WP_Error('year_closeout_forbidden', __('You cannot create year archives.', 'olama-core'));
        }
        $preview = $this->preview($year_id);
        if (is_wp_error($preview)) {
            return $preview;
        }
        if ($preview['blockers']) {
            return new WP_Error('year_closeout_unclassified', __('Archive blocked because unclassified year-scoped tables contain data.', 'olama-core'));
        }

        $root = $this->archive_root();
        if (!wp_mkdir_p($root) || !is_writable($root)) {
            return new WP_Error('year_archive_path', __('The private archive directory is not writable.', 'olama-core'));
        }

        $code = $this->year_code($preview['year']);
        $directory_name = 'year-' . sanitize_file_name($code) . '-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false);
        $directory = trailingslashit($root) . $directory_name;
        if (!wp_mkdir_p($directory)) {
            return new WP_Error('year_archive_create', __('The archive directory could not be created.', 'olama-core'));
        }

        $manifest = array(
            'format' => 'olama-year-archive-v1',
            'site_url' => home_url('/'),
            'academic_year_id' => (int) $preview['year']->id,
            'year_code' => $code,
            'created_at_utc' => gmdate('c'),
            'core_version' => OLAMA_CORE_VERSION,
            'preserved_data' => $preview['preserved'],
            'datasets' => array(),
            'total_rows' => 0,
        );

        @set_time_limit(0);
        foreach ($preview['datasets'] as $dataset) {
            if (!$dataset['purge'] || !$dataset['exists']) {
                continue;
            }
            $export = $this->export_dataset($directory, $dataset);
            if (is_wp_error($export)) {
                return $export;
            }
            $manifest['datasets'][] = $export;
            $manifest['total_rows'] += $export['rows'];
        }

        $manifest_path = trailingslashit($directory) . 'manifest.json';
        $written = file_put_contents($manifest_path, wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            return new WP_Error('year_archive_manifest', __('The archive manifest could not be written.', 'olama-core'));
        }
        $archive_hash = hash_file('sha256', $manifest_path);
        $zip_path = $this->create_zip($directory, $manifest);

        global $wpdb;
        $inserted = $wpdb->insert($this->archives_table, array(
            'academic_year_id' => (int) $preview['year']->id,
            'year_code' => $code,
            'status' => 'created',
            'archive_path' => $zip_path ?: $directory,
            'archive_hash' => $archive_hash,
            'manifest_json' => wp_json_encode($manifest),
            'total_rows' => (int) $manifest['total_rows'],
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ));
        if (!$inserted) {
            return new WP_Error('year_archive_record', $wpdb->last_error ?: __('The archive record could not be saved.', 'olama-core'));
        }

        Olama_Core_Logger::log('academic_year_archived', sprintf('Created archive #%d for academic year #%d with %d rows.', $wpdb->insert_id, $preview['year']->id, $manifest['total_rows']), 'core');
        return (int) $wpdb->insert_id;
    }

    public function verify_archive($archive_id) {
        if (!$this->can_manage()) {
            return new WP_Error('year_closeout_forbidden', __('You cannot verify year archives.', 'olama-core'));
        }
        $archive = $this->archive($archive_id);
        if (!$archive) {
            return new WP_Error('year_archive_missing', __('Archive record was not found.', 'olama-core'));
        }
        if (!in_array((string) $archive->status, array('created', 'verified'), true)) {
            return new WP_Error('year_archive_verify_status', __('Only a newly created or verified archive can be verified from this action.', 'olama-core'));
        }
        $verified = $this->verify_archive_files($archive);
        if (is_wp_error($verified)) {
            return $verified;
        }

        global $wpdb;
        $wpdb->update($this->archives_table, array('status' => 'verified', 'verified_at' => current_time('mysql')), array('id' => (int) $archive->id));
        Olama_Core_Logger::log('academic_year_archive_verified', sprintf('Verified archive #%d.', $archive->id), 'core');
        return true;
    }

    public function purge_verified_archive($archive_id, $confirmation) {
        if (!$this->can_manage()) {
            return new WP_Error('year_closeout_forbidden', __('You cannot purge historical data.', 'olama-core'));
        }
        $archive = $this->archive($archive_id);
        if (!$archive || !in_array((string) $archive->status, array('verified', 'restored'), true)) {
            return new WP_Error('year_archive_not_verified', __('A verified archive is required before purge.', 'olama-core'));
        }
        $year = $this->eligible_year((int) $archive->academic_year_id);
        if (is_wp_error($year)) {
            return $year;
        }
        if ($this->calendar->normalize_year_code($confirmation) !== $this->year_code($year)) {
            return new WP_Error('year_purge_confirmation', __('Type the exact academic-year code to confirm purge.', 'olama-core'));
        }
        $verified = $this->verify_archive_files($archive);
        if (is_wp_error($verified)) {
            return $verified;
        }
        $preview = $this->preview($year->id);
        if (is_wp_error($preview) || $preview['blockers']) {
            return is_wp_error($preview) ? $preview : new WP_Error('year_closeout_unclassified', __('Purge blocked by unclassified scoped data.', 'olama-core'));
        }
        $manifest = json_decode((string) $archive->manifest_json, true);
        $archived_counts = array();
        foreach ((array) $manifest['datasets'] as $dataset) {
            $archived_counts[$dataset['key']] = (int) $dataset['rows'];
        }
        foreach ($preview['datasets'] as $dataset) {
            if (!$dataset['purge']) {
                continue;
            }
            $archived_count = (int) ($archived_counts[$dataset['key']] ?? -1);
            if ((!$dataset['exists'] && $archived_count > 0) || ($dataset['exists'] && (int) $dataset['count'] !== $archived_count)) {
                return new WP_Error('year_archive_drift', sprintf(__('Data changed after archive creation in %s. Create a new archive.', 'olama-core'), $dataset['label']));
            }
        }

        global $wpdb;
        @set_time_limit(0);
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($preview['datasets'] as $dataset) {
                if (!$dataset['purge'] || !$dataset['exists'] || !$dataset['count']) {
                    continue;
                }
                $sql = 'DELETE FROM `' . esc_sql($dataset['table']) . '` WHERE ' . $dataset['where'];
                $result = $wpdb->query($this->prepare($sql, $dataset['params']));
                if ($result === false) {
                    throw new RuntimeException($wpdb->last_error ?: 'delete failed');
                }
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException($wpdb->last_error ?: 'commit failed');
            }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('year_purge_failed', $error->getMessage());
        }

        $wpdb->update($this->archives_table, array('status' => 'purged', 'purged_at' => current_time('mysql')), array('id' => (int) $archive->id));
        Olama_Core_Logger::log('academic_year_data_purged', sprintf('Purged %d archived rows for academic year #%d using archive #%d.', $preview['purge_rows'], $year->id, $archive->id), 'core');
        do_action('olama_core_academic_year_purged', $year, $archive, $preview);
        return true;
    }

    public function restore_purged_archive($archive_id, $confirmation) {
        if (!$this->can_manage()) {
            return new WP_Error('year_closeout_forbidden', __('You cannot restore historical data.', 'olama-core'));
        }
        $archive = $this->archive($archive_id);
        if (!$archive || 'purged' !== (string) $archive->status) {
            return new WP_Error('year_archive_not_purged', __('Only an archive with purged status can be restored.', 'olama-core'));
        }
        $year = $this->eligible_year((int) $archive->academic_year_id);
        if (is_wp_error($year)) {
            return $year;
        }
        if ($this->calendar->normalize_year_code($confirmation) !== $this->year_code($year)) {
            return new WP_Error('year_restore_confirmation', __('Type the exact academic-year code to confirm restore.', 'olama-core'));
        }

        $manifest = $this->verify_archive_files($archive);
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        if ((int) ($manifest['academic_year_id'] ?? 0) !== (int) $year->id || $this->calendar->normalize_year_code($manifest['year_code'] ?? '') !== $this->year_code($year)) {
            return new WP_Error('year_restore_manifest_scope', __('The archive manifest does not match the selected academic year.', 'olama-core'));
        }

        $before = $this->preview($year->id);
        if (is_wp_error($before)) {
            return $before;
        }
        if ($before['blockers']) {
            return new WP_Error('year_restore_unclassified', __('Restore is blocked by unclassified scoped data.', 'olama-core'));
        }
        if ((int) $before['purge_rows'] !== 0) {
            return new WP_Error('year_restore_existing_data', __('Historical rows already exist for this year. Restore requires an empty classified year scope.', 'olama-core'));
        }

        global $wpdb;
        @set_time_limit(0);
        $wpdb->query('START TRANSACTION');
        try {
            foreach (array_reverse((array) $manifest['datasets']) as $dataset) {
                $result = $this->restore_dataset($this->archive_directory($archive->archive_path), $dataset);
                if (is_wp_error($result)) {
                    throw new RuntimeException($result->get_error_message());
                }
            }

            $after = $this->preview($year->id);
            if (is_wp_error($after) || $after['blockers']) {
                throw new RuntimeException(is_wp_error($after) ? $after->get_error_message() : __('Post-restore validation found unclassified scoped data.', 'olama-core'));
            }
            $archived_counts = array();
            foreach ((array) $manifest['datasets'] as $dataset) {
                $archived_counts[$dataset['key']] = (int) $dataset['rows'];
            }
            foreach ($after['datasets'] as $dataset) {
                if (!$dataset['purge']) {
                    continue;
                }
                if ((int) $dataset['count'] !== (int) ($archived_counts[$dataset['key']] ?? 0)) {
                    throw new RuntimeException(sprintf(__('Restored row count mismatch in %s.', 'olama-core'), $dataset['label']));
                }
            }
            if ((int) $after['purge_rows'] !== (int) $manifest['total_rows']) {
                throw new RuntimeException(__('The total restored row count does not match the archive manifest.', 'olama-core'));
            }

            $updated = $wpdb->update(
                $this->archives_table,
                array('status' => 'restored', 'restored_at' => current_time('mysql'), 'restored_by' => get_current_user_id()),
                array('id' => (int) $archive->id)
            );
            if ($updated === false) {
                throw new RuntimeException($wpdb->last_error ?: __('The restored archive status could not be saved.', 'olama-core'));
            }
            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException($wpdb->last_error ?: 'commit failed');
            }
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('year_restore_failed', $error->getMessage());
        }

        Olama_Core_Logger::log('academic_year_data_restored', sprintf('Restored %d rows for academic year #%d from archive #%d.', $manifest['total_rows'], $year->id, $archive->id), 'core');
        do_action('olama_core_academic_year_restored', $year, $archive, $manifest);
        return true;
    }

    public function archives($year_id = 0) {
        global $wpdb;
        if ($year_id) {
            return $wpdb->get_results($wpdb->prepare('SELECT * FROM `' . esc_sql($this->archives_table) . '` WHERE academic_year_id = %d ORDER BY id DESC', absint($year_id)));
        }
        return $wpdb->get_results('SELECT * FROM `' . esc_sql($this->archives_table) . '` ORDER BY id DESC');
    }

    private function archive($archive_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . esc_sql($this->archives_table) . '` WHERE id = %d LIMIT 1', absint($archive_id)));
    }

    private function eligible_year($year_id) {
        $year = $this->calendar->year(absint($year_id));
        if (!$year) {
            return new WP_Error('year_closeout_missing', __('Academic year was not found.', 'olama-core'));
        }
        $current = $this->context->current();
        if ($current && (int) $current->academic_year_id === (int) $year->id) {
            return new WP_Error('year_closeout_current', __('The active academic year cannot be archived or purged.', 'olama-core'));
        }
        if ('open' === (string) $year->lifecycle_status || !empty($year->is_active)) {
            return new WP_Error('year_closeout_open', __('Close the academic year by activating a different year before closeout.', 'olama-core'));
        }
        return $year;
    }

    private function datasets($year) {
        global $wpdb;
        $p = $wpdb->prefix;
        $year_id = (int) $year->id;
        $code = $this->year_code($year);
        $rows = array();
        $add = static function($key, $label, $table, $where, $params, $purge = true) use (&$rows) {
            $rows[] = compact('key', 'label', 'table', 'where', 'params', 'purge');
        };
        $direct = static function($key, $label, $suffix, $column = 'academic_year_id', $purge = true) use ($add, $p, $year_id) {
            $add($key, $label, $p . $suffix, '`' . $column . '` = %d', array($year_id), $purge);
        };
        $study = static function($key, $label, $suffix, $purge = true) use ($add, $p, $code) {
            $add($key, $label, $p . $suffix, "REPLACE(REPLACE(`study_year`, '/', '-'), '_', '-') = %s", array($code), $purge);
        };
        $semester_scope = "`semester_id` IN (SELECT id FROM `{$p}olama_semesters` WHERE academic_year_id = %d)";

        // Child rows precede their parents so deletion cannot leave orphans.
        $add('school_plan_questions', 'School plan questions', $p . 'olama_plan_questions', "plan_id IN (SELECT id FROM `{$p}olama_plans` WHERE academic_year_id = %d)", array($year_id));
        $add('evaluation_scores', 'Evaluation scores', $p . 'olama_ev_scores', "evaluation_id IN (SELECT id FROM `{$p}olama_ev_records` WHERE academic_year_id = %d)", array($year_id));
        $add('supervisor_visits', 'Supervision visits', $p . 'olama_supervisor_visits', "schedule_id IN (SELECT id FROM `{$p}olama_schedule` WHERE {$semester_scope})", array($year_id));
        $add('exam_hall_notes', 'Exam hall notes', $p . 'olama_exam_hall_notes', $semester_scope, array($year_id));
        $add('exam_essay_grades', 'Exam essay grades/results', $p . 'olama_exam_essay_grades', "attempt_id IN (SELECT a.id FROM `{$p}olama_exam_attempts` a INNER JOIN `{$p}olama_exam_exams` e ON e.id = a.exam_id WHERE e.academic_year_id = %d)", array($year_id));
        $add('exam_placement_info', 'Exam placement result details', $p . 'olama_exam_placement_info', "attempt_id IN (SELECT a.id FROM `{$p}olama_exam_attempts` a INNER JOIN `{$p}olama_exam_exams` e ON e.id = a.exam_id WHERE e.academic_year_id = %d)", array($year_id));
        $add('exam_attempts', 'Student exam attempts and results', $p . 'olama_exam_attempts', "exam_id IN (SELECT id FROM `{$p}olama_exam_exams` WHERE academic_year_id = %d)", array($year_id));
        $add('store_assignment_returns', 'Store assignment returns', $p . 'os_assignment_returns', "assignment_id IN (SELECT id FROM `{$p}os_assignments` WHERE academic_year_id = %d)", array($year_id));
        $add('store_inventory_lines', 'Store inventory count lines', $p . 'os_inventory_count_lines', "count_id IN (SELECT id FROM `{$p}os_inventory_counts` WHERE academic_year_id = %d)", array($year_id));
        $add('invoice_allocations', 'Invoice payment allocations', $p . 'olama_payment_allocations', "invoice_id IN (SELECT id FROM `{$p}olama_invoices` WHERE academic_year_id = %d)", array($year_id));
        $add('invoice_adjustments', 'Invoice adjustments', $p . 'olama_invoice_adjustments', "invoice_id IN (SELECT id FROM `{$p}olama_invoices` WHERE academic_year_id = %d)", array($year_id));
        $add('invoice_payments', 'Invoice payments', $p . 'olama_payments', "invoice_id IN (SELECT id FROM `{$p}olama_invoices` WHERE academic_year_id = %d)", array($year_id));
        $add('invoice_installments', 'Invoice installments', $p . 'olama_invoice_installments', "invoice_id IN (SELECT id FROM `{$p}olama_invoices` WHERE academic_year_id = %d)", array($year_id));
        $add('invoice_items', 'Invoice items', $p . 'olama_invoice_items', "invoice_id IN (SELECT id FROM `{$p}olama_invoices` WHERE academic_year_id = %d)", array($year_id));
        $add('agreement_amendment_lines', 'Agreement amendment lines', $p . 'olama_agreement_amendment_lines', "agreement_id IN (SELECT id FROM `{$p}olama_agreements` WHERE academic_year_id = %d)", array($year_id));
        $add('agreement_amendments', 'Agreement amendments', $p . 'olama_agreement_amendments', "agreement_id IN (SELECT id FROM `{$p}olama_agreements` WHERE academic_year_id = %d)", array($year_id));
        $add('agreement_fees', 'Agreement fees', $p . 'olama_agreement_fees', "agreement_id IN (SELECT id FROM `{$p}olama_agreements` WHERE academic_year_id = %d)", array($year_id));
        $add('agreement_clauses', 'Agreement clauses', $p . 'olama_agreement_clauses', "agreement_id IN (SELECT id FROM `{$p}olama_agreements` WHERE academic_year_id = %d)", array($year_id));
        $add('agreement_participants', 'Agreement participants', $p . 'olama_agreement_participants', "agreement_id IN (SELECT id FROM `{$p}olama_agreements` WHERE academic_year_id = %d)", array($year_id));
        $add('message_token_views', 'Message token views', $p . 'olama_msg_token_views', "token_id IN (SELECT id FROM `{$p}olama_msg_tokens` WHERE REPLACE(REPLACE(study_year, '/', '-'), '_', '-') = %s)", array($code));
        $add('message_short_links', 'Message short links', $p . 'olama_msg_short_links', "REPLACE(REPLACE(study_year, '/', '-'), '_', '-') = %s", array($code));
        $add('message_queue', 'Message sending queue', $p . 'olama_msg_queue', "campaign_id IN (SELECT id FROM `{$p}olama_msg_campaigns` WHERE REPLACE(REPLACE(study_year, '/', '-'), '_', '-') = %s)", array($code));
        $add('message_recipients', 'Message campaign recipients', $p . 'olama_msg_campaign_recipients', "campaign_id IN (SELECT id FROM `{$p}olama_msg_campaigns` WHERE REPLACE(REPLACE(study_year, '/', '-'), '_', '-') = %s)", array($code));
        $add('transport_route_stops', 'Transportation route stops', $p . 'olama_transport_route_stops', "route_version_id IN (SELECT id FROM `{$p}olama_transport_route_versions` WHERE academic_year_id = %d)", array($year_id));
        $add('transport_optimization_runs', 'Transportation optimization runs', $p . 'olama_transport_optimization_runs', "route_version_id IN (SELECT id FROM `{$p}olama_transport_route_versions` WHERE academic_year_id = %d)", array($year_id));
        $add('transport_planning_families', 'Transportation planning group families', $p . 'olama_transport_planning_group_families', "group_id IN (SELECT id FROM `{$p}olama_transport_planning_groups` WHERE academic_year_id = %d)", array($year_id));
        $add('employee_shift_assignments', 'Employee shift assignments', $p . 'olama_shifts_assignments', "shift_id IN (SELECT s.id FROM `{$p}olama_shifts` s INNER JOIN `{$p}olama_shifts_periods` p ON p.id = s.period_id WHERE p.academic_year_id = %d)", array($year_id));
        $add('employee_shifts', 'Employee shifts', $p . 'olama_shifts', "period_id IN (SELECT id FROM `{$p}olama_shifts_periods` WHERE academic_year_id = %d)", array($year_id));
        $add('school_schedule', 'School timetable rows', $p . 'olama_schedule', $semester_scope, array($year_id));

        foreach (array(
            array('school_sections', 'School sections', 'olama_sections'),
            array('school_plans', 'School weekly plans', 'olama_plans'),
            array('school_events', 'School academic events', 'olama_academic_events'),
            array('school_teacher_assignments', 'School teacher assignments', 'olama_teacher_assignments'),
            array('school_office_hours', 'School teacher office hours', 'olama_teacher_office_hours'),
            array('school_stationary', 'School stationary plans', 'olama_stationary'),
            array('supervision_lesson_plans', 'Supervision lesson plans', 'olama_lesson_plans'),
            array('supervision_assignments', 'Supervision assignments', 'olama_supervisor_assignments'),
            array('evaluation_records', 'Student evaluation records/results', 'olama_ev_records'),
            array('attendance', 'Student attendance records', 'olama_attendance'),
            array('attendance_sheets', 'Attendance sheets', 'olama_attendance_sheets'),
            array('exam_hall_assignments', 'Exam hall student assignments', 'olama_exam_hall_assignments'),
            array('exam_hall_attendance', 'Exam hall attendance/results', 'olama_exam_hall_attendance'),
            array('exam_hall_invigilators', 'Exam hall invigilator assignments', 'olama_exam_hall_invigilators'),
            array('exam_halls', 'Exam halls for the year', 'olama_exam_halls'),
            array('kg_photo', 'KG photo-session student records', 'olama_kg_photo_session'),
            array('kg_graduation', 'KG graduation student records', 'olama_kg_graduation_session'),
            array('store_movements', 'Store stock movements', 'os_stock_movements'),
            array('store_counts', 'Store inventory counts', 'os_inventory_counts'),
            array('store_assignments', 'Store student/employee assignments', 'os_assignments'),
            array('store_approvals', 'Store custom withdrawal approvals', 'os_custom_withdrawal_approvals'),
            array('store_uniform_sizes', 'Store student uniform sizes', 'os_student_uniform_sizes'),
            array('store_entitlements', 'Store grade entitlements', 'os_entitlements'),
            array('invoices', 'Invoices', 'olama_invoices'),
            array('agreements', 'Agreements', 'olama_agreements'),
            array('family_entitlements', 'Family financial entitlements', 'olama_family_entitlements'),
            array('family_snapshots', 'Family financial snapshots', 'olama_family_financial_snapshots'),
            array('transport_enrollments', 'Transportation enrollments', 'olama_transport_enrollments'),
            array('transport_area_buses', 'Transportation area/bus assignments', 'olama_transport_area_bus_assignments'),
            array('transport_routes', 'Transportation route versions', 'olama_transport_route_versions'),
            array('transport_planning_groups', 'Transportation planning groups', 'olama_transport_planning_groups'),
            array('employee_shift_schedule', 'Employee shift schedules', 'olama_shifts_schedule'),
            array('employee_shift_periods', 'Employee shift periods', 'olama_shifts_periods'),
            array('employee_cleaning_logs', 'Employee cleaning operational logs', 'olama_cleaning_logs'),
        ) as $definition) {
            $direct($definition[0], $definition[1], $definition[2]);
        }

        foreach (array(
            array('core_student_years', 'Core student-year records', 'olama_core_student_years'),
            array('core_family_financial_years', 'Core family financial years', 'olama_core_family_financial_years'),
            array('core_financial_dues', 'Core financial dues', 'olama_core_family_financial_dues'),
            array('core_financial_transactions', 'Core financial transactions', 'olama_core_financial_transactions'),
            array('core_student_transport', 'Core student transportation records', 'olama_core_student_transportation'),
            array('core_grade_sections', 'Core academic grade/section mappings', 'olama_core_academic_grade_sections'),
            array('core_academic_students', 'Core academic student placements', 'olama_core_academic_students'),
            array('core_grade_subjects', 'Core academic grade/subject mappings', 'olama_core_academic_grade_subjects'),
            array('message_tokens', 'Message payment tokens', 'olama_msg_tokens'),
            array('message_campaigns', 'Message campaigns', 'olama_msg_campaigns'),
            array('oracle_financial_import_skips', 'Oracle financial import skips', 'olama_oracle_financial_import_skips'),
            array('oracle_financial_ledger', 'Oracle financial ledger imports', 'olama_oracle_financial_ledger'),
            array('oracle_financial_receipts', 'Oracle financial receipt imports', 'olama_oracle_financial_receipts'),
            array('oracle_financial_reconciliation', 'Oracle financial reconciliation records', 'olama_oracle_financial_reconciliation'),
            array('oracle_financial_import_runs', 'Oracle financial import runs', 'olama_oracle_financial_import_runs'),
        ) as $definition) {
            $study($definition[0], $definition[1], $definition[2]);
        }

        // Explicitly classified preservation tables that still contain a scope column.
        $direct('preserve_semesters', 'Academic semester definitions', 'olama_semesters', 'academic_year_id', false);
        $direct('preserve_year_source_mappings', 'External academic-year source mappings', 'olama_core_academic_year_source_mappings', 'academic_year_id', false);
        $direct('preserve_exam_engine', 'Reusable exam definitions', 'olama_exam_exams', 'academic_year_id', false);
        $direct('preserve_exam_management', 'Exam definitions and attachments', 'olama_exams', 'academic_year_id', false);
        $direct('preserve_evaluation_templates', 'Reusable evaluation templates', 'olama_ev_templates', 'academic_year_id', false);
        $direct('preserve_store_items', 'Store item catalogue', 'os_items', 'academic_year_id', false);
        $direct('preserve_legacy_grade_subjects', 'Legacy academic grade/subject mappings', 'olama_academic_grade_subjects', 'academic_year_id', false);
        $direct('preserve_legacy_sections', 'Legacy academic section definitions', 'olama_academic_sections', 'academic_year_id', false);
        $direct('preserve_legacy_semesters', 'Legacy academic semester definitions', 'olama_academic_semesters', 'academic_year_id', false);
        $direct('preserve_legacy_teacher_assignments', 'Legacy academic teacher/curriculum mappings', 'olama_academic_teacher_assignments', 'academic_year_id', false);

        return apply_filters('olama_core_year_closeout_datasets', $rows, $year);
    }

    private function preserved_data() {
        return array(
            'Academic-year and semester definitions and numeric IDs',
            'Curriculum units, lessons, questions, dates, and video URLs',
            'Uploaded media, Drive mappings, and lesson/media links',
            'Reusable exam question banks, exam definitions, and attachments',
            'Reusable evaluation templates, domains, categories, and indicators',
            'Master families, students, employees, grades, subjects, buses, stops, and Store catalogue data',
        );
    }

    private function unclassified_scoped_tables($year, array $datasets) {
        global $wpdb;
        $classified = array_fill_keys(array_map(static function($dataset) { return $dataset['table']; }, $datasets), true);
        $ignore = array($wpdb->prefix . 'olama_core_academic_context', $wpdb->prefix . 'olama_core_year_archives');
        $blockers = array();
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
        foreach ($tables as $table) {
            if (isset($classified[$table]) || in_array($table, $ignore, true)) {
                continue;
            }
            foreach (array('academic_year_id' => (int) $year->id, 'study_year' => $this->year_code($year)) as $column => $value) {
                if (!$this->has_column($table, $column)) {
                    continue;
                }
                $where = $column === 'study_year'
                    ? "REPLACE(REPLACE(`study_year`, '/', '-'), '_', '-') = %s"
                    : '`academic_year_id` = %d';
                $count = (int) $wpdb->get_var($this->prepare('SELECT COUNT(*) FROM `' . esc_sql($table) . '` WHERE ' . $where, array($value)));
                if ($count) {
                    $blockers[] = array('table' => $table, 'column' => $column, 'count' => $count);
                    break;
                }
            }
        }
        return $blockers;
    }

    private function export_dataset($directory, array $dataset) {
        global $wpdb;
        $file = sanitize_file_name($dataset['key']) . '.jsonl';
        $path = trailingslashit($directory) . $file;
        $handle = fopen($path, 'wb');
        if (!$handle) {
            return new WP_Error('year_archive_file', sprintf(__('Could not write archive dataset %s.', 'olama-core'), $dataset['label']));
        }
        $offset = 0;
        $rows_written = 0;
        do {
            $sql = 'SELECT * FROM `' . esc_sql($dataset['table']) . '` WHERE ' . $dataset['where'] . ' LIMIT 500 OFFSET ' . (int) $offset;
            $wpdb->last_error = '';
            $rows = $wpdb->get_results($this->prepare($sql, $dataset['params']), ARRAY_A);
            if ($wpdb->last_error) {
                fclose($handle);
                return new WP_Error('year_archive_query', $wpdb->last_error);
            }
            foreach ($rows as $row) {
                if (fwrite($handle, wp_json_encode($row, JSON_UNESCAPED_UNICODE) . "\n") === false) {
                    fclose($handle);
                    return new WP_Error('year_archive_write', sprintf(__('Could not complete archive dataset %s.', 'olama-core'), $dataset['label']));
                }
                $rows_written++;
            }
            $offset += count($rows);
        } while (count($rows) === 500);
        fclose($handle);

        $schema = $wpdb->get_row('SHOW CREATE TABLE `' . esc_sql($dataset['table']) . '`', ARRAY_N);
        return array(
            'key' => $dataset['key'],
            'label' => $dataset['label'],
            'table' => $dataset['table'],
            'file' => $file,
            'rows' => $rows_written,
            'sha256' => hash_file('sha256', $path),
            'create_table' => isset($schema[1]) ? $schema[1] : '',
        );
    }

    private function create_zip($directory, array $manifest) {
        if (!class_exists('ZipArchive')) {
            return '';
        }
        $zip_path = $directory . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return '';
        }
        $zip->addFile(trailingslashit($directory) . 'manifest.json', 'manifest.json');
        foreach ((array) $manifest['datasets'] as $dataset) {
            $zip->addFile(trailingslashit($directory) . $dataset['file'], $dataset['file']);
        }
        $zip->close();
        return is_file($zip_path) ? $zip_path : '';
    }

    private function archive_root() {
        return apply_filters('olama_core_year_archive_root', dirname(ABSPATH) . DIRECTORY_SEPARATOR . 'olama-private-archives');
    }

    private function archive_directory($archive_path) {
        return substr((string) $archive_path, -4) === '.zip' ? substr((string) $archive_path, 0, -4) : (string) $archive_path;
    }

    private function count_dataset(array $dataset) {
        global $wpdb;
        $wpdb->flush();
        $wpdb->last_error = '';
        $sql = 'SELECT COUNT(*) FROM `' . esc_sql($dataset['table']) . '` WHERE ' . $dataset['where'];
        $count = $wpdb->get_var($this->prepare($sql, $dataset['params']));
        if ($wpdb->last_error) {
            return new WP_Error('year_closeout_query', $wpdb->last_error);
        }
        return (int) $count;
    }

    private function verify_archive_files($archive) {
        $stored_manifest = json_decode((string) $archive->manifest_json, true);
        if (!is_array($stored_manifest) || ($stored_manifest['format'] ?? '') !== 'olama-year-archive-v1') {
            return new WP_Error('year_archive_manifest_invalid', __('Archive manifest is invalid.', 'olama-core'));
        }
        $directory = $this->archive_directory($archive->archive_path);
        $manifest_path = trailingslashit($directory) . 'manifest.json';
        if (!is_file($manifest_path) || !hash_equals((string) $archive->archive_hash, (string) hash_file('sha256', $manifest_path))) {
            return new WP_Error('year_archive_hash', __('Archive manifest checksum failed.', 'olama-core'));
        }
        $manifest = json_decode((string) file_get_contents($manifest_path), true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'olama-year-archive-v1' || !isset($manifest['datasets'], $manifest['total_rows'])) {
            return new WP_Error('year_archive_manifest_file_invalid', __('The archive manifest file is invalid.', 'olama-core'));
        }
        if (wp_json_encode($manifest) !== wp_json_encode($stored_manifest)) {
            return new WP_Error('year_archive_manifest_mismatch', __('The archive manifest file does not match its database record.', 'olama-core'));
        }
        $total_rows = 0;
        foreach ((array) $manifest['datasets'] as $dataset) {
            if (empty($dataset['key']) || empty($dataset['table']) || empty($dataset['file']) || !isset($dataset['sha256'], $dataset['rows'])) {
                return new WP_Error('year_archive_dataset_manifest', __('An archive dataset definition is incomplete.', 'olama-core'));
            }
            $path = trailingslashit($directory) . basename($dataset['file']);
            if (!is_file($path) || !hash_equals((string) $dataset['sha256'], (string) hash_file('sha256', $path))) {
                return new WP_Error('year_archive_dataset_hash', sprintf(__('Checksum failed for %s.', 'olama-core'), $dataset['key']));
            }
            if ($this->line_count($path) !== (int) $dataset['rows']) {
                return new WP_Error('year_archive_dataset_rows', sprintf(__('Row verification failed for %s.', 'olama-core'), $dataset['key']));
            }
            $restorable = $this->validate_restore_compatibility($path, $dataset['table']);
            if (is_wp_error($restorable)) {
                return $restorable;
            }
            $total_rows += (int) $dataset['rows'];
        }
        if ($total_rows !== (int) $manifest['total_rows'] || $total_rows !== (int) $archive->total_rows) {
            return new WP_Error('year_archive_total_rows', __('Archive total row verification failed.', 'olama-core'));
        }
        return $manifest;
    }

    private function restore_dataset($directory, array $dataset) {
        global $wpdb;
        $table = (string) ($dataset['table'] ?? '');
        if (strpos($table, $wpdb->prefix) !== 0 || !$this->table_exists($table)) {
            return new WP_Error('year_restore_table', sprintf(__('Restore table is unavailable: %s.', 'olama-core'), $table));
        }
        $path = trailingslashit($directory) . basename((string) ($dataset['file'] ?? ''));
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return new WP_Error('year_restore_file', sprintf(__('Could not open restore dataset %s.', 'olama-core'), $dataset['key'] ?? ''));
        }
        $batch = array();
        $line_number = 0;
        $restored = 0;
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $row = json_decode($line, true);
            if (!is_array($row)) {
                fclose($handle);
                return new WP_Error('year_restore_json', sprintf(__('Invalid JSON in %1$s at row %2$d.', 'olama-core'), $dataset['key'], $line_number));
            }
            $batch[] = $row;
            if (count($batch) >= 50) {
                $result = $this->restore_batch($table, $batch);
                if (is_wp_error($result)) {
                    fclose($handle);
                    return $result;
                }
                $restored += $result;
                $batch = array();
            }
        }
        fclose($handle);
        if ($batch) {
            $result = $this->restore_batch($table, $batch);
            if (is_wp_error($result)) {
                return $result;
            }
            $restored += $result;
        }
        if ($restored !== (int) ($dataset['rows'] ?? -1)) {
            return new WP_Error('year_restore_dataset_count', sprintf(__('Restore count mismatch for %s.', 'olama-core'), $dataset['key'] ?? $table));
        }
        return $restored;
    }

    private function restore_batch($table, array $rows) {
        global $wpdb;
        if (!$rows) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        if (!$columns) {
            return new WP_Error('year_restore_columns', sprintf(__('Restore dataset has no columns for %s.', 'olama-core'), $table));
        }
        $column_sql = implode(', ', array_map(static function($column) {
            return '`' . esc_sql($column) . '`';
        }, $columns));
        $value_groups = array();
        $params = array();
        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                return new WP_Error('year_restore_columns', sprintf(__('Restore columns are inconsistent for %s.', 'olama-core'), $table));
            }
            $placeholders = array();
            foreach ($columns as $column) {
                if ($row[$column] === null) {
                    $placeholders[] = 'NULL';
                } else {
                    $placeholders[] = '%s';
                    $params[] = (string) $row[$column];
                }
            }
            $value_groups[] = '(' . implode(', ', $placeholders) . ')';
        }
        $sql = 'INSERT INTO `' . esc_sql($table) . '` (' . $column_sql . ') VALUES ' . implode(', ', $value_groups);
        $wpdb->last_error = '';
        $result = $wpdb->query($this->prepare($sql, $params));
        if ($result === false || (int) $result !== count($rows)) {
            return new WP_Error('year_restore_insert', sprintf(__('Restore insert failed for %1$s: %2$s', 'olama-core'), $table, $wpdb->last_error ?: __('unexpected inserted-row count', 'olama-core')));
        }
        return (int) $result;
    }

    private function validate_restore_compatibility($path, $table) {
        global $wpdb;
        $columns = $wpdb->get_col('SHOW COLUMNS FROM `' . esc_sql($table) . '`');
        if (!$columns) {
            return new WP_Error('year_archive_restore_schema', sprintf(__('Restore validation could not read schema for %s.', 'olama-core'), $table));
        }
        $allowed = array_fill_keys($columns, true);
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return new WP_Error('year_archive_restore_file', sprintf(__('Restore validation could not read %s.', 'olama-core'), basename($path)));
        }
        $line_number = 0;
        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $row = json_decode($line, true);
            if (!is_array($row) || array_diff_key($row, $allowed)) {
                fclose($handle);
                return new WP_Error('year_archive_restore_row', sprintf(__('Restore validation failed for %1$s at row %2$d.', 'olama-core'), $table, $line_number));
            }
        }
        fclose($handle);
        return true;
    }

    private function line_count($path) {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return -1;
        }
        $count = 0;
        while (fgets($handle) !== false) {
            $count++;
        }
        fclose($handle);
        return $count;
    }

    private function prepare($sql, array $params) {
        global $wpdb;
        return $params ? $wpdb->prepare($sql, $params) : $sql;
    }

    private function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function has_column($table, $column) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM `' . esc_sql($table) . '` LIKE %s', $column));
    }

    private function year_code($year) {
        return $this->calendar->normalize_year_code(!empty($year->code) ? $year->code : $year->year_name);
    }

    private function can_manage() {
        return current_user_can('manage_olama_academic_context') || current_user_can('manage_options');
    }
}
