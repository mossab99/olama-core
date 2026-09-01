<?php

define('ABSPATH', __DIR__);

function apply_filters($hook, $value) {
    return $value;
}

class Olama_Core_Academic_Calendar_Service {
    public function normalize_year_code($code) {
        return str_replace(array('/', '_'), '-', (string) $code);
    }
}

class Olama_Core_Academic_Context_Service {}

class Olama_Core_Test_Year_Closeout_DB {
    public $prefix = 'wp_';
}

$wpdb = new Olama_Core_Test_Year_Closeout_DB();

require dirname(__DIR__) . '/includes/class-olama-core-year-closeout-service.php';

$service = new Olama_Core_Year_Closeout_Service(
    new Olama_Core_Academic_Calendar_Service(),
    new Olama_Core_Academic_Context_Service()
);
$method = new ReflectionMethod($service, 'datasets');
$method->setAccessible(true);
$datasets = $method->invoke($service, (object) array(
    'id' => 1,
    'code' => '2025-2026',
    'year_name' => '2025-2026',
));

$by_key = array();
foreach ($datasets as $dataset) {
    $by_key[$dataset['key']] = $dataset;
}

$expected = array(
    'core_transferred_students' => array(
        'table' => 'wp_olama_core_academic_transferred_students',
        'purge' => true,
        'where' => "REPLACE(REPLACE(`study_year`, '/', '-'), '_', '-') = %s",
    ),
    'drive_sync_events' => array(
        'table' => 'wp_olama_drive_sync_events',
        'purge' => true,
        'where' => 'run_id IN (SELECT id FROM `wp_olama_drive_sync_runs` WHERE academic_year_id = %d)',
    ),
    'drive_sync_runs' => array(
        'table' => 'wp_olama_drive_sync_runs',
        'purge' => true,
        'where' => '`academic_year_id` = %d',
    ),
    'preserve_lesson_video_links' => array(
        'table' => 'wp_olama_lesson_video_links',
        'purge' => false,
        'where' => '`academic_year_id` = %d',
    ),
);

foreach ($expected as $key => $policy) {
    if (!isset($by_key[$key])) {
        fwrite(STDERR, "Year closeout dataset policies: FAIL missing {$key}\n");
        exit(1);
    }
    foreach ($policy as $field => $value) {
        if ($by_key[$key][$field] !== $value) {
            fwrite(STDERR, "Year closeout dataset policies: FAIL {$key}.{$field}\n");
            exit(1);
        }
    }
}

$event_position = array_search('drive_sync_events', array_keys($by_key), true);
$run_position = array_search('drive_sync_runs', array_keys($by_key), true);
if ($event_position === false || $run_position === false || $event_position >= $run_position) {
    fwrite(STDERR, "Year closeout dataset policies: FAIL Drive child/parent order\n");
    exit(1);
}

echo "Year closeout dataset policies: PASS\n";
