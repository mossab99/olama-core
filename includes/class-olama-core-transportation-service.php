<?php

if (!defined('ABSPATH')) {
    exit;
}

class Olama_Core_Transportation_Service {
    private $table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->table = $repo->table('olama_core_student_transportation');
    }

    public function replace_family_year_from_source($family_id, $study_year, array $rows) {
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
                throw new InvalidArgumentException('Invalid transportation row.');
            }
            $student_id = $this->text($row, array('oracle_student_id', 'student_id'));
            if ($student_id === null || $student_id === '') {
                throw new InvalidArgumentException('Transportation row is missing student number.');
            }
            $item = array(
                'family_uid' => $family_uid,
                'student_uid' => 'ORA-STU-' . $family_id . '-' . $student_id,
                'oracle_family_id' => $family_id,
                'oracle_student_id' => $student_id,
                'study_year' => $study_year,
                'family_id' => absint($family_id),
                'student_id' => absint($student_id),
                'class_id' => $this->text($row, array('class_id')),
                'class_name' => $this->text($row, array('class_name')),
                'section_id' => $this->text($row, array('section_id')),
                'section_name' => $this->text($row, array('section_name')),
                'group_id' => $this->text($row, array('group_id')),
                'group_name' => $this->text($row, array('group_name', 'transportation_group_name')),
                'departure_bus' => $this->text($row, array('departure_bus', 'departure_bus_id')),
                'departure_bus_name' => $this->text($row, array('departure_bus_name')),
                'departure_bus_seq' => $this->text($row, array('departure_bus_seq')),
                'arrival_bus' => $this->text($row, array('arrival_bus', 'arrival_bus_id')),
                'arrival_bus_name' => $this->text($row, array('arrival_bus_name')),
                'arrival_bus_seq' => $this->text($row, array('arrival_bus_seq')),
                'trans_route' => $this->text($row, array('trans_route')),
                'trans_route_name' => $this->text($row, array('trans_route_name', 'route_name')),
                'from_date' => $this->date($row, array('from_date')),
                'to_date' => $this->date($row, array('to_date')),
                'is_active' => $this->bool_value($row, array('is_active', 'active')),
                'is_active_name' => $this->text($row, array('is_active_name', 'status_name')),
                'trans_amount' => $this->money($row, array('trans_amount', 'amount', 'fees')),
                'raw_json' => $this->raw_json($row),
                'oracle_modified_at' => $this->datetime($row, array('date_modified', 'oracle_modified_at')),
                'last_synced_at' => $now,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            );
            $item['source_hash'] = hash('sha256', wp_json_encode($item));
            $normalized[] = $item;
        }

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($this->table, array('family_uid' => $family_uid, 'study_year' => $study_year));
            if ($wpdb->last_error) {
                throw new RuntimeException($wpdb->last_error);
            }
            foreach ($normalized as $item) {
                $wpdb->insert($this->table, $item);
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        return array('operation' => 'replaced', 'count' => count($normalized), 'uid' => $family_uid);
    }

    public function get_family($family_id, $study_year) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.*, st.student_name, sy.school_id, sy.school_name, sy.class_id AS year_class_id, sy.class_name AS year_class_name, sy.section_id AS year_section_id, sy.section_name AS year_section_name FROM `{$this->table}` tr LEFT JOIN `{$wpdb->prefix}olama_core_students` st ON st.student_uid = tr.student_uid LEFT JOIN `{$wpdb->prefix}olama_core_student_years` sy ON sy.student_uid = tr.student_uid AND sy.study_year = tr.study_year WHERE (tr.oracle_family_id = %s OR (tr.oracle_family_id IS NULL AND tr.family_id = %d)) AND tr.study_year = %s ORDER BY tr.student_id ASC, tr.id ASC",
            sanitize_text_field((string) $family_id),
            absint($family_id),
            sanitize_text_field((string) $study_year)
        ), ARRAY_A);
        foreach ($rows as &$row) {
            if (empty($row['last_synced_at']) && !empty($row['synced_at'])) {
                $row['last_synced_at'] = $row['synced_at'];
            }
            if (empty($row['class_id'])) {
                $row['class_id'] = $row['year_class_id'];
                $row['class_name'] = $row['year_class_name'];
            }
            if (empty($row['section_id'])) {
                $row['section_id'] = $row['year_section_id'];
                $row['section_name'] = $row['year_section_name'];
            }
        }
        unset($row);
        return $rows;
    }

    public function get_student($family_id, $student_id, $study_year) {
        foreach ($this->get_family($family_id, $study_year) as $row) {
            if ((string) ($row['oracle_student_id'] ?: $row['student_id']) === (string) $student_id) {
                return $row;
            }
        }
        return null;
    }

    public function get_options($study_year) {
        global $wpdb;
        $study_year = sanitize_text_field((string) $study_year);
        return array(
            'classes' => $wpdb->get_results($wpdb->prepare("SELECT class_id, MAX(class_name) AS class_name FROM `{$this->table}` WHERE study_year = %s AND class_id IS NOT NULL AND class_id <> '' GROUP BY class_id ORDER BY class_name", $study_year), ARRAY_A),
            'sections' => $wpdb->get_results($wpdb->prepare("SELECT class_id, section_id, MAX(section_name) AS section_name FROM `{$this->table}` WHERE study_year = %s AND section_id IS NOT NULL AND section_id <> '' GROUP BY class_id, section_id ORDER BY section_name", $study_year), ARRAY_A),
            'departure_buses' => $wpdb->get_results($wpdb->prepare("SELECT departure_bus AS bus_id, MAX(departure_bus_name) AS bus_name, MIN(departure_bus_seq) AS bus_seq FROM `{$this->table}` WHERE study_year = %s AND departure_bus IS NOT NULL AND departure_bus <> '' GROUP BY departure_bus ORDER BY bus_seq, bus_name", $study_year), ARRAY_A),
            'arrival_buses' => $wpdb->get_results($wpdb->prepare("SELECT arrival_bus AS bus_id, MAX(arrival_bus_name) AS bus_name, MIN(arrival_bus_seq) AS bus_seq FROM `{$this->table}` WHERE study_year = %s AND arrival_bus IS NOT NULL AND arrival_bus <> '' GROUP BY arrival_bus ORDER BY bus_seq, bus_name", $study_year), ARRAY_A),
            'routes' => $wpdb->get_results($wpdb->prepare("SELECT trans_route, MAX(trans_route_name) AS label FROM `{$this->table}` WHERE study_year = %s AND trans_route IS NOT NULL AND trans_route <> '' GROUP BY trans_route ORDER BY label", $study_year), ARRAY_A),
        );
    }

    public function query_recipients($study_year, array $filters = array()) {
        global $wpdb;
        $study_year = sanitize_text_field((string) $study_year);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT COALESCE(NULLIF(oracle_family_id, ''), family_id) FROM `{$this->table}` WHERE study_year = %s AND (is_active IS NULL OR is_active = 1) ORDER BY family_id",
            $study_year
        ));
        if (!empty($filters['family_id'])) {
            $requested_family = sanitize_text_field((string) $filters['family_id']);
            $ids = array_values(array_filter($ids, static function($family_id) use ($requested_family) {
                return (string) $family_id === $requested_family;
            }));
        }
        $recipients = array();
        foreach ($ids as $family_id) {
            $family = olama_core()->families()->get_by_oracle_id($family_id);
            if (!$family) {
                continue;
            }
            $matched = $this->filter_rows($this->get_family($family_id, $study_year), $filters);
            if (!$matched) {
                continue;
            }
            $recipients[] = array(
                'family_id' => absint($family_id),
                'sponsor_full_name' => isset($family['sponsor_full_name']) ? $family['sponsor_full_name'] : '',
                'father_name' => isset($family['father_name']) ? $family['father_name'] : '',
                'father_mobile' => isset($family['father_mobile']) ? $family['father_mobile'] : '',
                'mother_name' => isset($family['mother_name']) ? $family['mother_name'] : '',
                'mother_mobile' => isset($family['mother_mobile']) ? $family['mother_mobile'] : '',
                'matching_students' => $matched,
            );
        }

        $total = count($recipients);
        $limit = min(200, max(1, absint(isset($filters['limit']) ? $filters['limit'] : 50)));
        $offset = max(0, absint(isset($filters['offset']) ? $filters['offset'] : 0));
        return array('status' => 'ok', 'recipients' => array_slice($recipients, $offset, $limit), 'count' => $total, 'limit' => $limit, 'offset' => $offset, 'data_source' => 'olama_core');
    }

    public function filter_families(array $families, $study_year, array $filters = array()) {
        $out = array();
        foreach ($families as $family) {
            $family_id = absint(isset($family['family_id']) ? $family['family_id'] : (isset($family['oracle_family_id']) ? $family['oracle_family_id'] : 0));
            if (!$family_id) {
                continue;
            }
            $rows = $this->get_family($family_id, $study_year);
            $matched = $this->filter_rows($rows, $filters);
            if (!$matched) {
                continue;
            }
            $family['transportation_rows'] = $rows;
            $family['transportation_match_rows'] = $matched;
            $family['transportation_departure_buses'] = array_values(array_unique(array_filter(wp_list_pluck($rows, 'departure_bus'))));
            $family['transportation_arrival_buses'] = array_values(array_unique(array_filter(wp_list_pluck($rows, 'arrival_bus'))));
            $family['transportation_departure_bus_names'] = array_values(array_unique(array_filter(wp_list_pluck($rows, 'departure_bus_name'))));
            $family['transportation_arrival_bus_names'] = array_values(array_unique(array_filter(wp_list_pluck($rows, 'arrival_bus_name'))));
            $out[] = $family;
        }
        return $out;
    }

    private function filter_rows(array $rows, array $filters) {
        $map = array(
            'class_id' => array('class_id'),
            'class_name' => array('class_name'),
            'section_id' => array('section_id'),
            'section_name' => array('section_name'),
            'departure_bus' => array('departure_bus', 'departure_bus_name', 'departure_bus_seq'),
            'arrival_bus' => array('arrival_bus', 'arrival_bus_name', 'arrival_bus_seq'),
            'bus_name' => array('departure_bus_name', 'arrival_bus_name'),
            'trans_route' => array('trans_route', 'trans_route_name'),
            'round_name' => array('trans_route', 'trans_route_name'),
        );
        $matched = array();
        foreach ($rows as $row) {
            if (isset($row['is_active']) && $row['is_active'] !== null && (int) $row['is_active'] !== 1) {
                continue;
            }
            $include = true;
            foreach ($map as $filter => $fields) {
                $needle = isset($filters[$filter]) ? trim((string) $filters[$filter]) : '';
                if ($needle === '' && $filter === 'trans_route' && !empty($filters['round_name'])) {
                    $needle = trim((string) $filters['round_name']);
                }
                if ($needle === '') {
                    continue;
                }
                $field_match = false;
                foreach ($fields as $field) {
                    if (isset($row[$field]) && (string) $row[$field] !== '' && stripos((string) $row[$field], $needle) !== false) {
                        $field_match = true;
                        break;
                    }
                }
                if (!$field_match) {
                    $include = false;
                    break;
                }
            }
            if ($include) {
                $matched[] = $row;
            }
        }
        return $matched;
    }

    private function text($data, $keys, $default = null) {
        foreach ($keys as $key) {
            if (isset($data[$key]) && $data[$key] !== '') {
                return sanitize_text_field((string) $data[$key]);
            }
        }
        return $default;
    }

    private function bool_value($data, $keys) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== '' && $data[$key] !== null) {
                return in_array(strtolower(trim((string) $data[$key])), array('1', 'true', 'yes', 'active', 'enabled'), true) ? 1 : 0;
            }
        }
        return null;
    }

    private function money($data, $keys) {
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

    private function datetime($data, $keys) {
        foreach ($keys as $key) {
            if (!empty($data[$key])) {
                $timestamp = strtotime((string) $data[$key]);
                return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null;
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
