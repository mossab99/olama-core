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

    /**
     * Return active families for the operator phone book.
     *
     * A family belongs to a year when it has at least one active student-year
     * record. Table knowledge remains owned by Olama Core.
     */
    public function query_phone_book($study_year, array $args = array()) {
        global $wpdb;

        $study_year = $this->canonical_study_year($study_year);
        if ($study_year === '') {
            $years = $this->get_study_years();
            $study_year = $years ? (string) $years[0] : '';
        }
        if ($study_year === '') {
            return array('items' => array(), 'total' => 0, 'limit' => 50, 'offset' => 0, 'study_year' => '');
        }

        $families = $this->repo->table('olama_core_families');
        $student_years = $this->repo->table('olama_core_student_years');
        $active_student_year = $this->active_student_year_condition('sy');
        $where = array(
            'f.is_active = 1',
            "EXISTS (
                SELECT 1 FROM `{$student_years}` sy
                WHERE sy.family_uid = f.family_uid
                  AND sy.study_year = %s
                  AND {$active_student_year}
            )",
        );
        $values = array($study_year);
        $summary_where_sql = implode(' AND ', $where);
        $summary = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS families,
                        SUM(TRIM(COALESCE(f.father_mobile, '')) <> '') AS father_phones,
                        SUM(TRIM(COALESCE(f.mother_mobile, '')) <> '') AS mother_phones,
                        SUM(TRIM(COALESCE(f.father_mobile, '')) = '' AND TRIM(COALESCE(f.mother_mobile, '')) = '') AS missing_both,
                        MAX(f.last_synced_at) AS last_synced_at
                 FROM `{$families}` f
                 WHERE {$summary_where_sql}",
                $values
            ),
            ARRAY_A
        );
        $search = sanitize_text_field((string) ($args['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(f.oracle_family_id = %s OR f.sponsor_full_name LIKE %s OR f.father_name LIKE %s OR f.mother_name LIKE %s OR f.father_mobile LIKE %s OR f.mother_mobile LIKE %s OR f.family_address LIKE %s OR f.address LIKE %s)';
            array_push($values, $search, $like, $like, $like, $like, $like, $like, $like);
        }

        $limit = min(100, max(10, absint($args['limit'] ?? 50)));
        $offset = max(0, absint($args['offset'] ?? 0));
        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$families}` f WHERE {$where_sql}", $values));
        $query_values = array_merge($values, array($limit, $offset));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.oracle_family_id, f.sponsor_full_name, f.father_name, f.father_mobile,
                        f.mother_name, f.mother_mobile, f.primary_mobile, f.family_address,
                        f.address, f.family_home_phone, f.last_synced_at
                 FROM `{$families}` f
                 WHERE {$where_sql}
                 ORDER BY CAST(f.oracle_family_id AS UNSIGNED), f.oracle_family_id
                 LIMIT %d OFFSET %d",
                $query_values
            ),
            ARRAY_A
        );

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = array(
                'family_id' => (string) $row['oracle_family_id'],
                'sponsor_name' => (string) ($row['sponsor_full_name'] ?? ''),
                'father_name' => (string) ($row['father_name'] ?? ''),
                'father_mobile' => (string) ($row['father_mobile'] ?? ''),
                'mother_name' => (string) ($row['mother_name'] ?? ''),
                'mother_mobile' => (string) ($row['mother_mobile'] ?? ''),
                'primary_mobile' => (string) ($row['primary_mobile'] ?? ''),
                'address' => (string) (($row['family_address'] ?? '') ?: ($row['address'] ?? '')),
                'home_phone' => (string) ($row['family_home_phone'] ?? ''),
                'last_synced_at' => $row['last_synced_at'] ?? null,
            );
        }

        return array(
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'study_year' => $study_year,
            'data_source' => 'olama_core',
            'summary' => array(
                'families' => (int) ($summary['families'] ?? 0),
                'father_phones' => (int) ($summary['father_phones'] ?? 0),
                'mother_phones' => (int) ($summary['mother_phones'] ?? 0),
                'missing_both' => (int) ($summary['missing_both'] ?? 0),
                'last_synced_at' => $summary['last_synced_at'] ?? null,
            ),
        );
    }

    /**
     * Return target-specific synchronization health for consumer plugins.
     *
     * This keeps table knowledge inside Olama Core while allowing consumers to
     * show operators whether an audience is safe to prepare.
     */
    public function get_sync_health($target_type = 'general', $study_year = '') {
        global $wpdb;

        $target_type = sanitize_key((string) $target_type);
        $study_year = $this->canonical_study_year($study_year);
        if (($target_type === 'finance_renewal_reminder' || $target_type === 'renewal_reminder')) {
            $current_year = $study_year;
            if ($current_year === '' && function_exists('olama_core') && method_exists(olama_core(), 'academic_context')) {
                $year = olama_core()->academic_context()->current_year();
                $current_year = $year ? (string) (!empty($year->code) ? $year->code : $year->year_name) : '';
                $current_year = $this->canonical_study_year($current_year);
            }
            $previous_year = $this->get_previous_study_year_code($current_year);
            $definitions = array(
                'families' => array('table' => 'olama_core_families', 'sync_column' => 'last_synced_at', 'year_scoped' => false, 'required' => true),
                'students' => array('table' => 'olama_core_students', 'sync_column' => 'last_synced_at', 'year_scoped' => false, 'required' => true),
                'student_years_previous' => array('table' => 'olama_core_student_years', 'sync_column' => 'last_synced_at', 'year_scoped' => true, 'study_year' => $previous_year, 'required' => true),
                'student_years_current' => array('table' => 'olama_core_student_years', 'sync_column' => 'last_synced_at', 'year_scoped' => true, 'study_year' => $current_year, 'required' => true),
                'transferred_students' => array('table' => 'olama_core_academic_transferred_students', 'sync_column' => 'last_synced_at', 'year_scoped' => true, 'study_year' => $previous_year, 'required' => true),
            );

            $sources = array();
            $ready = $current_year !== '' && $previous_year !== '';
            $latest_values = array();

            foreach ($definitions as $key => $definition) {
                $table = $this->repo->table($definition['table']);
                $exists = Olama_Core_Migrator::table_exists($table);
                $count = 0;
                $latest = null;

                if ($exists && ( empty($definition['year_scoped']) || !empty($definition['study_year']) )) {
                    $where = '';
                    $values = array();
                    if (!empty($definition['year_scoped'])) {
                        $where = ' WHERE study_year = %s';
                        $values[] = $definition['study_year'];
                    }

                    $count_sql = "SELECT COUNT(*) FROM `{$table}`{$where}";
                    $sync_sql = "SELECT MAX(`{$definition['sync_column']}`) FROM `{$table}`{$where}";
                    $count = (int) ($values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql));
                    $latest = $values ? $wpdb->get_var($wpdb->prepare($sync_sql, $values)) : $wpdb->get_var($sync_sql);
                }

                if (!empty($definition['required']) && (!$exists || $count === 0)) {
                    $ready = false;
                }

                if ($latest) {
                    $latest_values[] = $latest;
                }

                $sources[$key] = array(
                    'ready' => $exists && ($count > 0 || empty($definition['required'])),
                    'required' => !empty($definition['required']),
                    'row_count' => $count,
                    'last_synced_at' => $latest ?: null,
                );
            }

            sort($latest_values);

            return array(
                'ready' => $ready,
                'target_type' => $target_type,
                'study_year' => $current_year,
                'current_year' => $current_year,
                'previous_year' => $previous_year,
                'last_synced_at' => $latest_values ? reset($latest_values) : null,
                'checked_at' => current_time('mysql'),
                'sources' => $sources,
                'message' => $ready ? 'Olama Core renewal audience sources are ready.' : 'Olama Core renewal audience sources are incomplete. Synchronize families, students, student years, and transferred students before preparing this campaign.',
            );
        }

        $definitions = array(
            'families' => array('table' => 'olama_core_families', 'sync_column' => 'last_synced_at', 'year_scoped' => false),
            'students' => array('table' => 'olama_core_students', 'sync_column' => 'last_synced_at', 'year_scoped' => false),
            'student_years' => array('table' => 'olama_core_student_years', 'sync_column' => 'last_synced_at', 'year_scoped' => true),
        );

        if ($target_type === 'collection' || $target_type === 'financial') {
            $definitions['financial_years'] = array('table' => 'olama_core_family_financial_years', 'sync_column' => 'last_synced_at', 'year_scoped' => true);
            $definitions['financial_dues'] = array('table' => 'olama_core_family_financial_dues', 'sync_column' => 'last_synced_at', 'year_scoped' => true, 'optional' => true);
        } elseif ($target_type === 'transportation') {
            $definitions['transportation'] = array('table' => 'olama_core_student_transportation', 'sync_column' => 'last_synced_at', 'year_scoped' => true);
        } elseif ($target_type === 'finance_renewal_reminder' || $target_type === 'renewal_reminder') {
            // Renewal reminder needs student_years for the previous year as well.
            // The transferred-students table is optional (added later by sync).
            $definitions['transferred_students'] = array('table' => 'olama_core_academic_transferred_students', 'sync_column' => 'last_synced_at', 'year_scoped' => true, 'optional' => true);
        }

        $sources = array();
        $ready = true;
        $latest_values = array();

        foreach ($definitions as $key => $definition) {
            $table = $this->repo->table($definition['table']);
            $exists = Olama_Core_Migrator::table_exists($table);
            $count = 0;
            $latest = null;

            if ($exists) {
                $where = '';
                $values = array();
                if (!empty($definition['year_scoped']) && $study_year !== '') {
                    $where = ' WHERE study_year = %s';
                    $values[] = $study_year;
                }

                $count_sql = "SELECT COUNT(*) FROM `{$table}`{$where}";
                $sync_sql = "SELECT MAX(`{$definition['sync_column']}`) FROM `{$table}`{$where}";
                $count = (int) ($values ? $wpdb->get_var($wpdb->prepare($count_sql, $values)) : $wpdb->get_var($count_sql));
                $latest = $values ? $wpdb->get_var($wpdb->prepare($sync_sql, $values)) : $wpdb->get_var($sync_sql);
            }

            $required = empty($definition['optional']);
            if ($required && (!$exists || $count === 0)) {
                $ready = false;
            }
            if ($latest) {
                $latest_values[] = $latest;
            }

            $sources[$key] = array(
                'ready' => $exists && ($count > 0 || !$required),
                'required' => $required,
                'row_count' => $count,
                'last_synced_at' => $latest ?: null,
            );
        }

        sort($latest_values);

        return array(
            'ready' => $ready,
            'target_type' => $target_type,
            'study_year' => $study_year,
            'last_synced_at' => $latest_values ? reset($latest_values) : null,
            'checked_at' => current_time('mysql'),
            'sources' => $sources,
        );
    }

    public function query(array $filters = array()) {
        $target_type = sanitize_key(isset($filters['target_type']) ? $filters['target_type'] : 'general');
        if ($target_type === 'transportation') {
            return $this->query_transportation($filters);
        }
        if ($target_type === 'collection' || $target_type === 'financial') {
            return $this->query_financial($filters);
        }
        if ($target_type === 'finance_renewal_reminder' || $target_type === 'renewal_reminder') {
            return $this->query_renewal_candidates(isset($filters['study_year']) ? $filters['study_year'] : '', $filters);
        }
        return $this->query_general($filters);
    }

    public function query_renewal_candidates($current_study_year, array $args = array()) {
        global $wpdb;

        $current_year = $this->canonical_study_year($current_study_year);
        if ($current_year === '' && function_exists('olama_core') && method_exists(olama_core(), 'academic_context')) {
            $year = olama_core()->academic_context()->current_year();
            $current_year = $year ? (string) (!empty($year->code) ? $year->code : $year->year_name) : '';
            $current_year = $this->canonical_study_year($current_year);
        }
        $previous_year = $this->get_previous_study_year_code($current_year);
        if ($previous_year === '' || $current_year === '') {
            return $this->response(array(), 0, 50, 0, 'finance_renewal_reminder', array(
                'current_year'  => $current_year,
                'previous_year' => $previous_year,
            ));
        }

        // ── Stage 1: PREVIOUS year family IDs ─────────────────────────────
        // Distinct families that had at least one student-year record last year.
        // No is_active filter — a family that did not renew may have become
        // inactive, and that is precisely who we need to contact.
        $previous_family_ids = $this->get_family_ids_for_study_year($previous_year);

        // ── Stage 2: CURRENT year family IDs ──────────────────────────────
        // Families that already have at least one registered student this year.
        // Family-level rule: even one enrolled child excludes the whole family.
        $current_family_ids = $this->get_family_ids_for_study_year($current_year);

        // ── Stage 3: TRANSFERRED family IDs ───────────────────────────────
        // Families whose students transferred out of the previous year's
        // population. Uses the same source as the Core Transferred Students
        // screen. Throws if the source is unavailable — an unknown transfer
        // state is NOT equivalent to zero transfers.
        $transferred_family_ids = $this->get_transferred_family_ids($previous_year);

        // ── Stage 4: Set subtraction ───────────────────────────────────────
        //
        //   PREVIOUS YEAR FAMILIES
        //           -
        //   CURRENT YEAR FAMILIES
        //           -
        //   TRANSFERRED FAMILIES
        //           =
        //   RENEWAL REMINDER FAMILIES
        //
        $renewal_family_ids = array_values(array_diff(
            $previous_family_ids,
            $current_family_ids,
            $transferred_family_ids
        ));

        // Optional family_id debug filter — eligibility is still enforced.
        if (!empty($args['family_id'])) {
            $filter_id          = (int) sanitize_text_field((string) $args['family_id']);
            $renewal_family_ids = in_array($filter_id, $renewal_family_ids, true)
                ? array($filter_id)
                : array();
        }

        // ── Stage 5: Deterministic ordering → pagination → fetch details ───
        sort($renewal_family_ids);
        $total  = count($renewal_family_ids);
        $limit  = min(200, max(1, absint(isset($args['limit'])  ? $args['limit']  : 50)));
        $offset = max(0,   absint(isset($args['offset']) ? $args['offset'] : 0));
        $page_ids = array_slice($renewal_family_ids, $offset, $limit);

        if (!$page_ids) {
            return $this->response(array(), $total, $limit, $offset, 'finance_renewal_reminder', array(
                'current_year'  => $current_year,
                'previous_year' => $previous_year,
            ));
        }

        // Fetch family records only for the current page — no N+1 queries.
        $families_table = $this->repo->table('olama_core_families');
        $id_strings     = array_map('strval', $page_ids);
        $placeholders   = implode(',', array_fill(0, count($id_strings), '%s'));
        $where          = array("f.oracle_family_id IN ({$placeholders})");
        $values         = $id_strings;

        if (!empty($args['search'])) {
            $search = sanitize_text_field((string) $args['search']);
            $like   = '%' . $wpdb->esc_like($search) . '%';
            if (is_numeric($search)) {
                $where[] = '(f.oracle_family_id = %s OR f.father_mobile LIKE %s OR f.mother_mobile LIKE %s)';
                array_push($values, $search, $like, $like);
            } else {
                $where[] = '(f.sponsor_full_name LIKE %s OR f.father_name LIKE %s OR f.mother_name LIKE %s)';
                array_push($values, $like, $like, $like);
            }
        }

        $where_sql   = 'WHERE ' . implode(' AND ', $where);
        $family_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT f.* FROM `{$families_table}` f {$where_sql} ORDER BY CAST(f.oracle_family_id AS UNSIGNED), f.oracle_family_id",
                $values
            ),
            ARRAY_A
        );

        $student_rows_by_family = $this->student_rows_for_families($family_rows, $previous_year, $args, false);
        $items = array();
        foreach ($family_rows as $family) {
            $uid          = (string) ($family['family_uid'] ?? '');
            $student_rows = $student_rows_by_family[$uid] ?? array();
            $items[]      = $this->recipient_item($family, $student_rows, null, array());
        }

        return $this->response($items, $total, $limit, $offset, 'finance_renewal_reminder', array(
            'current_year'  => $current_year,
            'previous_year' => $previous_year,
        ));
    }

    /**
     * Return distinct oracle_family_id integers for all families that had at
     * least one student-year enrollment record in the given study year.
     *
     * No is_active filter is applied. Historical enrollment alone determines
     * membership in the previous-year set.
     *
     * @param  string $study_year Canonical study year code.
     * @return int[]              Unique oracle family IDs as integers.
     */
    public function get_family_ids_for_study_year($study_year) {
        global $wpdb;
        $student_years = $this->repo->table('olama_core_student_years');
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT oracle_family_id FROM `{$student_years}` WHERE study_year = %s AND oracle_family_id IS NOT NULL AND oracle_family_id <> ''",
            $study_year
        ));
        return array_values(array_unique(array_map('intval', array_filter((array) $rows, 'strlen'))));
    }

    /**
     * Return distinct oracle_family_id integers for families that have at least
     * one student recorded as transferred in the given study year.
     *
     * Uses the same authoritative source table as the Olama Core Transferred
     * Students screen (olama_core_academic_transferred_students).
     *
     * IMPORTANT: If the transferred-students table does not exist, this method
     * throws a RuntimeException. An unknown transfer state is NOT equivalent to
     * zero transfers — returning a silently empty set would produce a misleading
     * audience by including families who may have transferred.
     *
     * @param  string           $study_year Canonical previous study year code.
     * @return int[]                        Unique oracle family IDs as integers.
     * @throws RuntimeException             If the transferred-students table is absent.
     */
    public function get_transferred_family_ids($study_year) {
        $transferred = $this->repo->table('olama_core_academic_transferred_students');
        if (!Olama_Core_Migrator::table_exists($transferred)) {
            throw new RuntimeException(
                'Renewal Reminder audience cannot be calculated because transferred student data is unavailable.'
            );
        }
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT family_id FROM `{$transferred}` WHERE study_year = %s AND family_id IS NOT NULL AND family_id <> ''",
            $study_year
        ));
        return array_values(array_unique(array_map('intval', array_filter((array) $rows, 'strlen'))));
    }

    public function query_renewal_reminder(array $filters = array()) {
        return $this->query_renewal_candidates(isset($filters['study_year']) ? $filters['study_year'] : '', $filters);
    }

    public function get_previous_study_year_code($current_year) {
        $current_year = $this->canonical_study_year($current_year);
        if ($current_year === '') {
            return '';
        }

        $years = $this->get_study_years();
        if ($years) {
            $idx = array_search($current_year, $years, true);
            if ($idx !== false && isset($years[$idx + 1])) {
                return (string) $years[$idx + 1];
            }
        }

        if (preg_match('/^(\d{4})\D*(\d{4})$/', $current_year, $m)) {
            $prev_start = (int) $m[1] - 1;
            $prev_end   = (int) $m[2] - 1;
            $prev_code  = $prev_start . '-' . $prev_end;
            return $this->canonical_study_year($prev_code) ?: $prev_code;
        }

        return '';
    }

    private function query_general(array $filters) {
        global $wpdb;
        $families = $this->repo->table('olama_core_families');
        $years = $this->repo->table('olama_core_student_years');
        // Campaign audiences are based on active Core families only.
        $where = array('f.is_active = 1');
        $values = array();
        $study_year = $this->canonical_study_year(isset($filters['study_year']) ? $filters['study_year'] : '');

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

        $year_conditions = array(
            'sy.family_uid = f.family_uid',
            $this->active_student_year_condition('sy'),
        );
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

        $student_rows_by_family = $this->student_rows_for_families($families_rows, $study_year, $filters);
        $items = array();
        foreach ($families_rows as $family) {
            $student_rows = $student_rows_by_family[$family['family_uid']] ?? array();
            // General audiences do not require per-family financial queries.
            // Collection audiences use query_financial(), which returns its
            // synchronized financial snapshot in one query.
            $items[] = $this->recipient_item($family, $student_rows, null, array());
        }

        return $this->response($items, $total, $limit, $offset, 'general');
    }

    /**
     * Load students for a page of families in one query.
     *
     * This keeps campaign previews responsive and avoids hundreds of
     * per-family student and financial lookups.
     */
    private function student_rows_for_families(array $families, $study_year, array $filters, $require_active = true) {
        global $wpdb;
        $family_uids = array_values(array_unique(array_filter(wp_list_pluck($families, 'family_uid'))));
        if (!$family_uids) {
            return array();
        }

        $student_years = $this->repo->table('olama_core_student_years');
        $students = $this->repo->table('olama_core_students');
        $where = array('sy.family_uid IN (' . implode(',', array_fill(0, count($family_uids), '%s')) . ')');
        $values = $family_uids;
        if ($study_year !== '') {
            $where[] = 'sy.study_year = %s';
            $values[] = $study_year;
        }
        if ($require_active) {
            $where[] = $this->active_student_year_condition('sy');
        }
        foreach (array('class_id', 'class_name', 'section_id', 'section_name') as $field) {
            if (!empty($filters[$field])) {
                $where[] = "sy.{$field} = %s";
                $values[] = sanitize_text_field((string) $filters[$field]);
            }
        }

        $sql = "SELECT sy.family_uid, sy.student_uid, sy.oracle_student_id, sy.class_id, sy.class_name,
                       sy.section_id, sy.section_name, sy.study_year, s.student_name
                FROM `{$student_years}` sy
                LEFT JOIN `{$students}` s ON s.student_uid = sy.student_uid
                WHERE " . implode(' AND ', $where) . '
                ORDER BY sy.family_uid, sy.student_uid';
        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);
        $result = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            $family_uid = (string) $row['family_uid'];
            $student_uid = (string) $row['student_uid'];
            if (isset($seen[$family_uid][$student_uid])) {
                continue;
            }
            $result[$family_uid][] = array(
                'student_id' => $row['oracle_student_id'],
                'student_name' => (string) ($row['student_name'] ?? ''),
                'class_id' => $row['class_id'],
                'class_name' => $row['class_name'],
                'section_id' => $row['section_id'],
                'section_name' => $row['section_name'],
                'study_year' => $row['study_year'],
            );
            $seen[$family_uid][$student_uid] = true;
        }
        return $result;
    }

    /**
     * Oracle can populate a withdrawal date on records that remain explicitly
     * active. Prefer the synchronized status and use the date only when Oracle
     * supplied no status at all.
     */
    private function active_student_year_condition($alias) {
        $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
        if ($alias === '') {
            $alias = 'sy';
        }

        return "(
            LOWER(TRIM(COALESCE({$alias}.student_status, ''))) IN ('1', 'active', 'enabled', 'current')
            OR LOWER(TRIM(COALESCE({$alias}.student_status_name, ''))) IN ('active', 'enabled', 'current')
            OR TRIM(COALESCE({$alias}.student_status_name, '')) IN ('فعال', 'نشط', 'مستمر')
            OR (
                TRIM(COALESCE({$alias}.student_status, '')) = ''
                AND TRIM(COALESCE({$alias}.student_status_name, '')) = ''
                AND {$alias}.withdraw_date IS NULL
            )
        )";
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
                'core_family_uid' => (string) ($row['family_uid'] ?? ''),
                'core_source_hash' => (string) ($row['source_hash'] ?? ''),
                'core_last_synced_at' => $row['last_synced_at'] ?? null,
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
                'core_family_uid' => (string) ($row['family_uid'] ?? ''),
                'core_source_hash' => (string) ($row['source_hash'] ?? ''),
                'core_last_synced_at' => $row['last_synced_at'] ?? null,
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
            'core_family_uid' => (string) ($family['family_uid'] ?? ''),
            'core_source_hash' => (string) ($family['source_hash'] ?? ''),
            'core_last_synced_at' => $family['last_synced_at'] ?? null,
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

    private function response(array $items, $total, $limit, $offset, $audience, array $extra = array()) {
        return array_merge(array('success' => true, 'data_source' => 'olama_core', 'audience' => $audience, 'items' => $items, 'total' => (int) $total, 'limit' => (int) $limit, 'offset' => (int) $offset), $extra);
    }

    private function study_year(array $filters) {
        $study_year = $this->canonical_study_year(isset($filters['study_year']) ? $filters['study_year'] : '');
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
        $study_year = $this->canonical_study_year($study_year);
        if ($study_year !== '') {
            return $wpdb->get_col($wpdb->prepare("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE study_year = %s AND `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`", $study_year));
        }
        return $wpdb->get_col("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> '' ORDER BY `{$column}`");
    }

    private function canonical_study_year($value) {
        $value = sanitize_text_field((string) $value);
        if ($value === '' || !function_exists('olama_core')) {
            return '';
        }
        $calendar = olama_core()->academic_calendar();
        $year = $calendar->resolve_external_year('oracle', $value);
        return $year ? $calendar->canonical_year_code((int) $year->id) : '';
    }
}
