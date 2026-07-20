<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical local mirror for Oracle-owned transportation master data.
 *
 * Only ingestion adapters such as olama-oracle-sync may write through this
 * service. Domain plugins read these tables and never call Oracle themselves.
 */
class Olama_Core_Transport_Master_Service {
    private $buses_table;
    private $regions_table;

    public function __construct(Olama_Core_Repository $repo) {
        $this->buses_table = $repo->table('olama_core_transport_buses');
        $this->regions_table = $repo->table('olama_core_transport_regions');
    }

    public function replace_buses_from_source(array $rows) {
        global $wpdb;

        $now = current_time('mysql');
        $seen = array();
        $summary = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'deactivated' => 0);

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new InvalidArgumentException('Invalid transport bus row.');
                }
                $oracle_id = $this->text($row, array('oracle_bus_id', 'bus_id', 'bus_school_id'));
                $bus_number = $this->text($row, array('bus_number', 'bus_school_num'));
                if ($oracle_id === '' || $bus_number === '') {
                    throw new InvalidArgumentException('Transport bus requires Oracle ID and bus number.');
                }

                $uid = 'ORA-BUS-' . $oracle_id;
                $seen[] = $uid;
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, source_hash FROM `{$this->buses_table}` WHERE bus_uid = %s",
                    $uid
                ), ARRAY_A);
                if (!$existing) {
                    // Migrate earlier composite IDs (school:bus) to the stable
                    // bus number used by Oracle transportation assignments.
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, source_hash FROM `{$this->buses_table}`
                         WHERE source_system = 'oracle' AND bus_number = %s
                         ORDER BY is_active DESC, id ASC LIMIT 1",
                        $bus_number
                    ), ARRAY_A);
                }
                $driver_id = $this->text($row, array('driver_employee_id', 'emp_id'));
                $companion_id = $this->text($row, array('companion_employee_id'));
                $item = array(
                    'bus_uid' => $uid,
                    'oracle_bus_id' => $oracle_id,
                    'bus_number' => $bus_number,
                    'description' => $this->nullable_text($row, array('description', 'bus_desc')),
                    'model' => $this->nullable_text($row, array('model', 'bus_model')),
                    'plate_number' => null,
                    'government_number' => $this->nullable_text($row, array('government_number', 'bus_gov_number')),
                    'driver_license_number' => $this->nullable_text($row, array('driver_license_number', 'bus_license_num', 'plate_number')),
                    'chassis_number' => $this->nullable_text($row, array('chassis_number', 'bus_shusi_number')),
                    'registered_capacity' => $this->nullable_int($row, array('registered_capacity', 'capacity', 'bus_capacity')),
                    'engine_capacity' => $this->nullable_text($row, array('engine_capacity', 'bus_cc')),
                    'fuel_type' => $this->nullable_text($row, array('fuel_type')),
                    'driver_employee_uid' => $driver_id !== '' ? 'ORA-EMP-' . $driver_id : null,
                    'driver_employee_id' => $driver_id ?: null,
                    'driver_employee_name' => $this->nullable_text($row, array('driver_employee_name', 'driver_name', 'emp_id_desc')),
                    'companion_employee_uid' => $companion_id !== '' ? 'ORA-EMP-' . $companion_id : null,
                    'companion_employee_id' => $companion_id ?: null,
                    'companion_employee_name' => $this->nullable_text($row, array('companion_employee_name', 'companion_name', 'companion_emp_id_desc')),
                    'last_license_renewal' => $this->date($row, array('last_license_renewal', 'last_renew_license')),
                    'next_license_renewal' => $this->date($row, array('next_license_renewal', 'next_renew_license')),
                    'is_active' => $this->bool_value($row, array('is_active', 'active'), 1),
                    'source_system' => 'oracle',
                    'raw_json' => $this->raw_json($row),
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                );
                $hash_data = $item;
                unset($hash_data['raw_json'], $hash_data['last_synced_at'], $hash_data['updated_at']);
                $item['source_hash'] = hash('sha256', wp_json_encode($hash_data));

                if ($existing && hash_equals((string) $existing['source_hash'], $item['source_hash'])) {
                    $wpdb->update($this->buses_table, array(
                        'is_active' => $item['is_active'],
                        'last_synced_at' => $now,
                        'raw_json' => $item['raw_json'],
                        'updated_at' => $now,
                    ), array('id' => $existing['id']));
                    $summary['skipped']++;
                } elseif ($existing) {
                    $wpdb->update($this->buses_table, $item, array('id' => $existing['id']));
                    $summary['updated']++;
                } else {
                    $item['created_at'] = $now;
                    $wpdb->insert($this->buses_table, $item);
                    $summary['created']++;
                }
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }
            }

            if ($seen) {
                $placeholders = implode(',', array_fill(0, count($seen), '%s'));
                $sql = $wpdb->prepare(
                    "UPDATE `{$this->buses_table}` SET is_active = 0, updated_at = %s
                     WHERE source_system = 'oracle' AND bus_uid NOT IN ({$placeholders}) AND is_active = 1",
                    array_merge(array($now), $seen)
                );
                $summary['deactivated'] = (int) $wpdb->query($sql);
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }

        return $summary;
    }

    public function replace_regions_from_source(array $rows) {
        global $wpdb;

        $now = current_time('mysql');
        $seen = array();
        $summary = array('created' => 0, 'updated' => 0, 'deactivated' => 0);
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $row) {
                $oracle_id = $this->text($row, array('oracle_region_id', 'region_id'));
                if ($oracle_id === '') {
                    throw new InvalidArgumentException('Transport region requires an Oracle ID.');
                }
                $uid = 'ORA-REGION-' . $oracle_id;
                $seen[] = $uid;
                $existing_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM `{$this->regions_table}` WHERE region_uid = %s",
                    $uid
                ));
                $item = array(
                    'region_uid' => $uid,
                    'oracle_region_id' => $oracle_id,
                    'region_name' => $this->nullable_text($row, array('region_name', 'oracle_region_name')),
                    'sample_address' => $this->nullable_text($row, array('sample_address')),
                    'source_family_count' => $this->nullable_int($row, array('family_count')),
                    'source_student_count' => $this->nullable_int($row, array('student_count')),
                    'is_active' => 1,
                    'source_system' => 'oracle',
                    'raw_json' => $this->raw_json($row),
                    'last_synced_at' => $now,
                    'updated_at' => $now,
                );
                $hash_data = $item;
                unset($hash_data['raw_json'], $hash_data['last_synced_at'], $hash_data['updated_at']);
                $item['source_hash'] = hash('sha256', wp_json_encode($hash_data));
                if ($existing_id) {
                    $wpdb->update($this->regions_table, $item, array('id' => $existing_id));
                    $summary['updated']++;
                } else {
                    $item['created_at'] = $now;
                    $wpdb->insert($this->regions_table, $item);
                    $summary['created']++;
                }
                if ($wpdb->last_error) {
                    throw new RuntimeException($wpdb->last_error);
                }
            }
            if ($seen) {
                $placeholders = implode(',', array_fill(0, count($seen), '%s'));
                $sql = $wpdb->prepare(
                    "UPDATE `{$this->regions_table}` SET is_active = 0, updated_at = %s
                     WHERE source_system = 'oracle' AND region_uid NOT IN ({$placeholders}) AND is_active = 1",
                    array_merge(array($now), $seen)
                );
                $summary['deactivated'] = (int) $wpdb->query($sql);
            }
            $wpdb->query('COMMIT');
        } catch (Exception $exception) {
            $wpdb->query('ROLLBACK');
            throw $exception;
        }
        return $summary;
    }

    public function get_buses($active_only = true) {
        global $wpdb;
        $where = $active_only ? ' WHERE is_active = 1' : '';
        return $wpdb->get_results("SELECT * FROM `{$this->buses_table}`{$where} ORDER BY bus_number", ARRAY_A);
    }

    public function get_bus($bus_uid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$this->buses_table}` WHERE bus_uid = %s",
            sanitize_text_field($bus_uid)
        ), ARRAY_A);
    }

    public function get_regions($active_only = true) {
        global $wpdb;
        $where = $active_only ? ' WHERE is_active = 1' : '';
        return $wpdb->get_results("SELECT * FROM `{$this->regions_table}`{$where} ORDER BY region_name, oracle_region_id", ARRAY_A);
    }

    public function last_synced_at() {
        global $wpdb;
        return array(
            'buses' => $wpdb->get_var("SELECT MAX(last_synced_at) FROM `{$this->buses_table}`"),
            'regions' => $wpdb->get_var("SELECT MAX(last_synced_at) FROM `{$this->regions_table}`"),
        );
    }

    private function text($row, $keys) {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '') {
                return sanitize_text_field((string) $row[$key]);
            }
        }
        return '';
    }

    private function nullable_text($row, $keys) {
        $value = $this->text($row, $keys);
        return $value === '' ? null : $value;
    }

    private function nullable_int($row, $keys) {
        foreach ($keys as $key) {
            if (isset($row[$key]) && $row[$key] !== '' && is_numeric($row[$key])) {
                return max(0, (int) $row[$key]);
            }
        }
        return null;
    }

    private function bool_value($row, $keys, $default) {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== '' && $row[$key] !== null) {
                return in_array(strtolower(trim((string) $row[$key])), array('1', 'true', 'yes', 'active', 'enabled'), true) ? 1 : 0;
            }
        }
        return (int) $default;
    }

    private function date($row, $keys) {
        foreach ($keys as $key) {
            if (!empty($row[$key])) {
                $timestamp = strtotime((string) $row[$key]);
                return $timestamp ? gmdate('Y-m-d', $timestamp) : null;
            }
        }
        return null;
    }

    private function raw_json($row) {
        if (class_exists('Olama_Oracle_Settings') && Olama_Oracle_Settings::get('store_raw_payloads') !== 'yes') {
            return null;
        }
        return wp_json_encode($row);
    }
}
