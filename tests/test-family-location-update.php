<?php

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function sanitize_email($value) { return (string) $value; }
function absint($value) { return abs((int) $value); }
function current_time($type) { return '2026-08-19 18:30:00'; }
function wp_json_encode($value) { return json_encode($value); }

class Olama_Core_Repository {
    public $row;
    public $updates = array();
    public function __construct() {
        $this->row = array(
            'id' => 5,
            'family_uid' => 'ORA-FAM-1200',
            'oracle_family_id' => '1200',
            'address' => 'Old address',
            'family_address' => 'Old address',
            'building_no' => '3',
            'home_no' => '9',
            'trans_region_id' => '10',
            'trans_region_name' => 'Old Area',
        );
    }
    public function table($name) { return 'wp_' . $name; }
    public function get_row($table, $where) { return '1200' === (string) reset($where) ? $this->row : null; }
    public function update($table, $data, $where) {
        $this->updates[] = $data;
        $this->row = array_merge($this->row, $data);
        return 1;
    }
}

require dirname(__DIR__) . '/includes/class-olama-core-family-service.php';

$repo = new Olama_Core_Repository();
$service = new Olama_Core_Family_Service($repo);
$updated = $service->update_location_from_source(array(
    'family_id' => 1200,
    'family_address' => 'New address',
    'building_no' => '7',
    'home_no' => '11',
    'trans_region_id' => 89,
    'trans_region_name' => 'عدن',
));
$cleared = $service->update_location_from_source(array(
    'family_id' => 1200,
    'family_address' => '',
    'building_no' => '',
    'home_no' => '',
    'trans_region_id' => null,
    'trans_region_name' => null,
));

if ($updated['operation'] !== 'updated'
    || $repo->updates[0]['trans_region_id'] !== '89'
    || $repo->updates[0]['trans_region_name'] !== 'عدن'
    || $cleared['operation'] !== 'updated'
    || $repo->row['family_address'] !== null
    || $repo->row['trans_region_id'] !== null) {
    fwrite(STDERR, "Core family location update: FAIL\n");
    exit(1);
}

echo "Core family location update: PASS\n";
