<?php
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    public function __construct($code, $message) {}
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function get_option($name, $default = array()) { return $default; }

class Test_Students {
    public function get_by_uid($uid) {
        global $test_nationality, $test_birth_place;
        return array(
            'student_uid' => $uid, 'family_uid' => 'F1',
            'student_national_no' => '123', 'nationality' => $test_nationality,
            'birth_date' => '2012-03-15', 'birth_place' => $test_birth_place,
            'student_gender_name' => 'ذكر', 'mother_name' => 'أم الطالب',
        );
    }
}
class Test_Years {
    public function get_current_year($uid, $year) {
        return array('student_uid' => $uid, 'study_year' => $year,
            'student_status' => '1', 'class_id' => '9', 'school_id' => '1',
            'school_name' => 'School', 'class_name' => '9', 'section_name' => 'B',
            'branch_name' => 'General');
    }
}
class Test_Families {
    public function get_by_uid($uid) { return array('family_uid' => $uid); }
}
class Olama_Core_Container {
    public function students() { return new Test_Students(); }
    public function student_years() { return new Test_Years(); }
    public function families() { return new Test_Families(); }
}
class Test_WPDB {
    public $prefix = 'wp_';
    public $pending = array();
    public $accepted = array();
    public function prepare($sql, ...$args) { return $sql; }
    public function get_results($sql, $format) {
        if (strpos($sql, "status = 'DRAFT'") !== false) return array();
        return strpos($sql, 'ministry_values') !== false ? $this->accepted : $this->pending;
    }
}
$wpdb = new Test_WPDB();
$test_nationality = 'أردني';
$test_birth_place = 'عمان';
require dirname(__DIR__) . '/includes/class-olama-core-ministry-service.php';
$service = new Olama_Core_Ministry_Service(new Olama_Core_Container());
$fields = $service->fields();
if (!isset($fields['school_national_id'], $fields['national_id'], $fields['student_display_name'])) {
    throw new RuntimeException('Required semantic fields missing.');
}
$first = $service->evaluate('S1', '2026-2027');
if ($first['fields']['document_type']['status'] !== 'NOT_APPLICABLE' ||
    $first['fields']['school_national_id']['status'] !== 'NEEDS_SCHOOL' ||
    $first['primary_status'] !== 'NEEDS_SCHOOL') {
    throw new RuntimeException('Conditional or school-owned classification failed.');
}
$wpdb->pending = array(array('field_key' => 'father_name', 'readiness_impact' => 'BLOCKING'));
$second = $service->evaluate('S1', '2026-2027');
if ($second['fields']['father_name']['status'] !== 'NEEDS_REVIEW' ||
    $second['primary_status'] !== 'NEEDS_REVIEW' ||
    $second['is_complete']) {
    throw new RuntimeException('Pending submission incorrectly resolved.');
}
$wpdb->pending = array();
$wpdb->pending = array(array('field_key' => 'birth_date', 'readiness_impact' => 'NON_BLOCKING'));
$non_blocking = $service->evaluate('S1', '2026-2027');
if ($non_blocking['fields']['birth_date']['status'] !== 'AVAILABLE' ||
    !in_array('birth_date', $non_blocking['operational_review'], true)) {
    throw new RuntimeException('Non-blocking correction affected readiness.');
}
$wpdb->pending = array();
$wpdb->accepted = array(array('field_key' => 'birth_place', 'value' => 'إربد',
    'source' => 'approved_family', 'original_value' => 'عمان'));
$approved = $service->evaluate('S1', '2026-2027');
if ($approved['fields']['birth_place']['value'] !== 'إربد' ||
    $approved['fields']['birth_place']['status'] !== 'AVAILABLE') {
    throw new RuntimeException('Approved value was not selected.');
}
$test_birth_place = 'الزرقاء';
$conflicted = $service->evaluate('S1', '2026-2027');
if ($conflicted['fields']['birth_place']['status'] !== 'NEEDS_REVIEW') {
    throw new RuntimeException('Later source conflict was not flagged.');
}
$test_birth_place = '';
$cleared = $service->evaluate('S1', '2026-2027');
if ($cleared['fields']['birth_place']['status'] !== 'NEEDS_REVIEW') {
    throw new RuntimeException('Cleared source value was not flagged as a conflict.');
}
$test_nationality = 'سوري';
$non_jordanian = $service->evaluate('S1', '2026-2027');
if ($non_jordanian['fields']['civil_register']['status'] !== 'NOT_APPLICABLE' ||
    $non_jordanian['fields']['document_type']['status'] === 'NOT_APPLICABLE' ||
    $non_jordanian['fields']['national_id']['status'] !== 'NEEDS_SCHOOL') {
    throw new RuntimeException('Non-Jordanian applicability failed.');
}
echo "Ministry evaluator checks passed.\n";
