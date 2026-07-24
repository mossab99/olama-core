<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Financial_Service {
    private $summary_table;
    private $dues_table;
    private $transactions_table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->summary_table = $repo->table('olama_core_family_financial_years');
        $this->dues_table = $repo->table('olama_core_family_financial_dues');
        $this->transactions_table = $repo->table('olama_core_financial_transactions');
    }

    public function upsert_summary_from_source(array $data) {
        global $wpdb;

        $family_id = $this->required_text($data, array('oracle_family_id', 'family_id'), 'Missing family number.');
        $study_year = $this->required_text($data, array('study_year'), 'Missing study year.');
        $family_uid = 'ORA-FAM-' . $family_id;
        $begin_debit = $this->money($data, array('begin_debit', 'begin_dr'));
        $begin_credit = $this->money($data, array('begin_credit', 'begin_cr'));
        $year_debit = $this->money($data, array('year_debit', 'year_dr'));
        $year_credit = $this->money($data, array('year_credit', 'year_cr'));
        $payload = array(
            'family_uid' => $family_uid,
            'oracle_family_id' => $family_id,
            'study_year' => $study_year,
            'begin_debit' => $begin_debit,
            'begin_credit' => $begin_credit,
            'year_debit' => $year_debit,
            'year_credit' => $year_credit,
            'balance' => isset($data['balance']) && is_numeric($data['balance'])
                ? round((float) $data['balance'], 3)
                : round($begin_debit - $begin_credit + $year_debit - $year_credit, 3),
            'currency' => $this->text($data, array('currency'), 'JOD'),
            'source_system' => 'oracle',
            'raw_json' => $this->raw_json($data),
        );
        $payload['source_hash'] = hash('sha256', wp_json_encode($payload));
        $now = current_time('mysql');

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$this->summary_table}` WHERE family_uid = %s AND study_year = %s LIMIT 1",
            $family_uid,
            $study_year
        ), ARRAY_A);

        if ($existing && hash_equals((string) $existing['source_hash'], $payload['source_hash'])) {
            $wpdb->update($this->summary_table, array('last_synced_at' => $now), array('id' => (int) $existing['id']));
            return array('operation' => 'skipped', 'id' => (int) $existing['id'], 'uid' => $family_uid);
        }

        $payload['last_synced_at'] = $now;
        $payload['updated_at'] = $now;
        if ($existing) {
            $wpdb->update($this->summary_table, $payload, array('id' => (int) $existing['id']));
            $this->throw_on_error($wpdb);
            return array('operation' => 'updated', 'id' => (int) $existing['id'], 'uid' => $family_uid);
        }

        $payload['created_at'] = $now;
        $wpdb->insert($this->summary_table, $payload);
        $this->throw_on_error($wpdb);
        return array('operation' => 'created', 'id' => (int) $wpdb->insert_id, 'uid' => $family_uid);
    }

    public function replace_dues_from_source($family_id, $study_year, array $rows) {
        global $wpdb;

        $family_id = sanitize_text_field((string) $family_id);
        $study_year = sanitize_text_field((string) $study_year);
        if ($family_id === '' || $study_year === '') {
            throw new InvalidArgumentException('Family number and study year are required.');
        }

        $family_uid = 'ORA-FAM-' . $family_id;
        $now = current_time('mysql');
        $normalized = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid due row.');
            }
            $due_amount = $this->money($row, array('due_amount'));
            $paid_amount = $this->money($row, array('paid_amount'));
            $receipt_paid = $this->money($row, array('receipt_paid'));
            $balance = isset($row['balance']) && is_numeric($row['balance'])
                ? round((float) $row['balance'], 3)
                : round($due_amount - $paid_amount - $receipt_paid, 3);
            $item = array(
                'family_uid' => $family_uid,
                'oracle_family_id' => $family_id,
                'study_year' => $study_year,
                'due_date' => $this->date($row, array('due_date')),
                'percent_value' => $this->nullable_money($row, array('percent_value', 'pct_value')),
                'due_amount' => $due_amount,
                'paid_amount' => $paid_amount,
                'receipt_paid' => $receipt_paid,
                'balance' => $balance,
                'due_status' => $balance <= 0 ? 'paid' : (($paid_amount + $receipt_paid) > 0 ? 'partial' : 'open'),
                'raw_json' => $this->raw_json($row),
                'last_synced_at' => $now,
                'created_at' => $now,
            );
            $item['source_hash'] = hash('sha256', wp_json_encode($item));
            $normalized[] = $item;
        }

        return $this->replace_scope($this->dues_table, $family_uid, $study_year, $normalized);
    }

    public function replace_transactions_from_source($family_id, $study_year, array $rows) {
        $family_id = sanitize_text_field((string) $family_id);
        $study_year = sanitize_text_field((string) $study_year);
        if ($family_id === '' || $study_year === '') {
            throw new InvalidArgumentException('Family number and study year are required.');
        }

        $family_uid = 'ORA-FAM-' . $family_id;
        $now = current_time('mysql');
        $normalized = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Invalid financial transaction row.');
            }
            $student_id = $this->text($row, array('oracle_student_id', 'student_id'));
            $item = array(
                'family_uid' => $family_uid,
                'student_uid' => $student_id !== null ? 'ORA-STU-' . $family_id . '-' . $student_id : null,
                'oracle_family_id' => $family_id,
                'oracle_student_id' => $student_id,
                'study_year' => $study_year,
                'serial_id' => $this->text($row, array('serial_id')),
                'receipt_id' => $this->text($row, array('receipt_id')),
                'transaction_date' => $this->date($row, array('trans_date', 'transaction_date')),
                'title_id' => $this->text($row, array('title_id')),
                'title_type' => $this->text($row, array('title_type')),
                'title' => $this->text($row, array('title', 'title_desc')),
                'debit_amount' => $this->money($row, array('debit_amount', 'dr_amount')),
                'credit_amount' => $this->money($row, array('credit_amount', 'cr_amount')),
                'notes' => $this->textarea($row, array('notes')),
                'transaction_status' => $this->text($row, array('trans_status', 'status')),
                'begin_year' => $this->text($row, array('begin_year')),
                'raw_json' => $this->raw_json($row),
                'last_synced_at' => $now,
                'created_at' => $now,
            );
            $item['source_hash'] = hash('sha256', wp_json_encode($item));
            $normalized[] = $item;
        }

        return $this->replace_scope($this->transactions_table, $family_uid, $study_year, $normalized);
    }

    public function get_summary($family_id, $study_year) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$this->summary_table}` WHERE oracle_family_id = %s AND study_year = %s LIMIT 1",
            sanitize_text_field((string) $family_id),
            sanitize_text_field((string) $study_year)
        ), ARRAY_A);
    }

    public function get_dues($family_id, $study_year) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$this->dues_table}` WHERE oracle_family_id = %s AND study_year = %s ORDER BY due_date ASC, id ASC",
            sanitize_text_field((string) $family_id),
            sanitize_text_field((string) $study_year)
        ), ARRAY_A);
    }

    public function get_transactions($family_id, $study_year) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tx.*, st.student_name FROM `{$this->transactions_table}` tx LEFT JOIN `{$wpdb->prefix}olama_core_students` st ON st.student_uid = tx.student_uid WHERE tx.oracle_family_id = %s AND tx.study_year = %s ORDER BY tx.transaction_date ASC, tx.id ASC",
            sanitize_text_field((string) $family_id),
            sanitize_text_field((string) $study_year)
        ), ARRAY_A);
        foreach ($rows as &$row) {
            $row['trans_date'] = $row['transaction_date'];
            $row['trans_status'] = $row['transaction_status'];
        }
        unset($row);
        return $rows;
    }

    public function get_family_card($family_id, $study_year) {
        $summary = $this->get_summary($family_id, $study_year);
        return array(
            'family_id' => sanitize_text_field((string) $family_id),
            'study_year' => sanitize_text_field((string) $study_year),
            'family_summary' => $summary ?: array(),
            'due_allocations' => $this->get_dues($family_id, $study_year),
            'student_transactions' => $this->get_transactions($family_id, $study_year),
            'last_synced_at' => $summary ? $summary['last_synced_at'] : null,
        );
    }

    public function query_recipients($study_year, array $filters = array()) {
        global $wpdb;
        $study_year = sanitize_text_field((string) $study_year);
        $where = array(
            'fy.study_year = %s',
            'f.is_active = 1',
            "EXISTS (
                SELECT 1 FROM `{$wpdb->prefix}olama_core_student_years` sy
                WHERE sy.family_uid = fy.family_uid
                  AND sy.study_year = fy.study_year
                  AND sy.withdraw_date IS NULL
            )",
        );
        $values = array($study_year);
        if (isset($filters['min_balance']) && $filters['min_balance'] !== '') {
            $where[] = 'fy.balance >= %f';
            $values[] = (float) $filters['min_balance'];
        }
        if (!empty($filters['family_id'])) {
            $where[] = 'fy.oracle_family_id = %s';
            $values[] = sanitize_text_field((string) $filters['family_id']);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT fy.*, f.sponsor_full_name, f.father_name, f.father_mobile, f.mother_name, f.mother_mobile FROM `{$this->summary_table}` fy INNER JOIN `{$wpdb->prefix}olama_core_families` f ON f.family_uid = fy.family_uid WHERE " . implode(' AND ', $where) . ' ORDER BY CAST(fy.oracle_family_id AS UNSIGNED)',
            $values
        ), ARRAY_A);

        $recipients = array();
        foreach ($rows as $row) {
            $years = olama_core()->student_years()->get_by_family($row['family_uid'], $study_year);
            $students = array();
            foreach ($years as $year) {
                if (!empty($filters['class_id']) && (string) $year['class_id'] !== (string) $filters['class_id']) {
                    continue;
                }
                if (!empty($filters['section_id']) && (string) $year['section_id'] !== (string) $filters['section_id']) {
                    continue;
                }
                if (!empty($filters['class_name']) && strcasecmp((string) $year['class_name'], (string) $filters['class_name']) !== 0) {
                    continue;
                }
                if (!empty($filters['section_name']) && strcasecmp((string) $year['section_name'], (string) $filters['section_name']) !== 0) {
                    continue;
                }
                $student = olama_core()->students()->get_by_uid($year['student_uid']);
                $students[] = array_merge($year, array('student_id' => $student ? $student['oracle_student_id'] : '', 'student_name' => $student ? $student['student_name'] : ''));
            }
            if ((array_filter(array_intersect_key($filters, array_flip(array('class_id', 'section_id', 'class_name', 'section_name'))))) && !$students) {
                continue;
            }
            $dues = $this->get_dues($row['oracle_family_id'], $study_year);
            $recipients[] = array(
                'family_id' => absint($row['oracle_family_id']),
                'family_uid' => (string) $row['family_uid'],
                'source_hash' => (string) ($row['source_hash'] ?? ''),
                'last_synced_at' => $row['last_synced_at'] ?? null,
                'sponsor_full_name' => $row['sponsor_full_name'],
                'father_name' => $row['father_name'],
                'father_mobile' => $row['father_mobile'],
                'mother_name' => $row['mother_name'],
                'mother_mobile' => $row['mother_mobile'],
                'students' => $students,
                'balance' => (float) $row['balance'],
                'monthly_due' => $dues ? (float) $dues[0]['due_amount'] : null,
                'currency' => $row['currency'],
            );
        }
        $total = count($recipients);
        $limit = min(200, max(1, absint(isset($filters['limit']) ? $filters['limit'] : 50)));
        $offset = max(0, absint(isset($filters['offset']) ? $filters['offset'] : 0));
        return array('status' => 'ok', 'recipients' => array_slice($recipients, $offset, $limit), 'count' => $total, 'limit' => $limit, 'offset' => $offset, 'data_source' => 'olama_core');
    }

    public function get_payment_report($family_id, $study_year) {
        $family = olama_core()->families()->get_by_oracle_id($family_id);
        $summary = $this->get_summary($family_id, $study_year);
        if (!$family || !$summary) {
            return false;
        }
        $card = olama_core()->knowledge()->get_family_card($family_id, $study_year);
        $transactions = $this->get_transactions($family_id, $study_year);
        $last_payment = null;
        foreach (array_reverse($transactions) as $transaction) {
            if ((float) $transaction['credit_amount'] > 0) {
                $last_payment = $transaction;
                break;
            }
        }
        return array(
            'family_id' => absint($family_id),
            'oracle_family_id' => (string) $family_id,
            'sponsor_name' => $family['sponsor_full_name'],
            'father_name' => $family['father_name'],
            'mother_name' => $family['mother_name'],
            'father_mobile' => $family['father_mobile'],
            'mother_mobile' => $family['mother_mobile'],
            'students' => $card ? $card['students'] : array(),
            'study_year' => $study_year,
            'financial' => $this->get_family_card($family_id, $study_year),
            'balance' => (float) $summary['balance'],
            'monthly_due' => null,
            'due_items' => $this->get_dues($family_id, $study_year),
            'last_payment' => $last_payment,
            'currency' => $summary['currency'],
            'financial_available' => true,
            'financial_warning' => '',
            'data_source' => 'olama_core',
            'last_synced_at' => $summary['last_synced_at'],
        );
    }

    private function replace_scope($table, $family_uid, $study_year, array $rows) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($table, array('family_uid' => $family_uid, 'study_year' => $study_year));
            $this->throw_on_error($wpdb);
            foreach ($rows as $row) {
                $wpdb->insert($table, $row);
                $this->throw_on_error($wpdb);
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
        return array('operation' => 'replaced', 'count' => count($rows), 'uid' => $family_uid);
    }

    private function throw_on_error($wpdb) {
        if ($wpdb->last_error) {
            throw new RuntimeException($wpdb->last_error);
        }
    }

    private function required_text($data, $keys, $message) {
        $value = $this->text($data, $keys);
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private function text($data, $keys, $default = null) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_text_field((string) $data[$key]);
            }
        }
        return $default;
    }

    private function textarea($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_textarea_field((string) $data[$key]);
            }
        }
        return null;
    }

    private function money($data, $keys) {
        $value = $this->nullable_money($data, $keys);
        return $value === null ? 0.0 : $value;
    }

    private function nullable_money($data, $keys) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '' && is_numeric($data[$key])) {
                return round((float) $data[$key], 3);
            }
        }
        return null;
    }

    private function date($data, $keys) {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                $timestamp = strtotime((string) $data[$key]);
                return $timestamp ? gmdate('Y-m-d', $timestamp) : null;
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
}
