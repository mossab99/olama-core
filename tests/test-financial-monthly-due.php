<?php

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function sanitize_text_field($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }

class Olama_Core_Repository {
    public function table($name) { return 'wp_' . $name; }
}

require dirname(__DIR__) . '/includes/class-olama-core-financial-service.php';

$service = new Olama_Core_Financial_Service(new Olama_Core_Repository());
$dues = array(
    array('due_date' => '2026-08-31', 'due_amount' => '206.000', 'balance' => '123.600'),
    array('due_date' => '2026-09-30', 'due_amount' => '69.000', 'balance' => '41.400'),
);

class Financial_Due_Test_Db {
    public $prefix = 'wp_';
    public $dues;
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($sql, $format) {
        if (strpos($sql, 'family_financial_dues') !== false) { return $this->dues; }
        return array(array(
            'oracle_family_id' => '55', 'family_uid' => 'ORA-FAM-55',
            'source_hash' => 'test', 'last_synced_at' => '2026-09-25 12:00:00',
            'sponsor_full_name' => 'Family 55', 'father_name' => 'Father',
            'father_mobile' => '0790000000', 'mother_name' => 'Mother',
            'mother_mobile' => '0790000001', 'balance' => '800.000', 'currency' => 'JOD',
        ));
    }
}
class Financial_Due_Test_Core {
    public function academic_calendar() { return $this; }
    public function resolve_external_year($source, $year) { return (object) array('id' => 1); }
    public function canonical_year_code($id) { return '2026-2027'; }
    public function student_years() { return $this; }
    public function get_by_family($family_uid, $year) { return array(); }
}
function olama_core() { static $core; return $core ?: ($core = new Financial_Due_Test_Core()); }
$GLOBALS['wpdb'] = new Financial_Due_Test_Db();
$GLOBALS['wpdb']->dues = $dues;
$recipients = $service->query_recipients('2026-2027', array('due_month' => '2026-09'));
$family = $recipients['recipients'][0];

if ($service->get_amount_due_through_month('55', '2026-2027', '2026-08', $dues) !== 123.6
    || $service->get_amount_due_through_month('55', '2026-2027', '2026-09', $dues) !== 165.0
    || $service->get_amount_due_through_month('55', '2026-2027', '2026-09', array()) !== null
    || $family['amount_due'] !== 165.0
    || $family['monthly_due'] !== 165.0) {
    throw new RuntimeException('Due through September must add the remaining August and September balances.');
}

echo "Financial cumulative due checks passed.\n";
