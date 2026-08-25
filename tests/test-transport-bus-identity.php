<?php

define('ABSPATH', __DIR__);

function sanitize_text_field($value) { return trim((string) $value); }

class Olama_Core_Repository {
    public function table($name) { return 'wp_' . $name; }
}

require dirname(__DIR__) . '/includes/class-olama-core-transport-master-service.php';

$service = new Olama_Core_Transport_Master_Service(new Olama_Core_Repository());
$method = new ReflectionMethod($service, 'bus_identity');
$method->setAccessible(true);
$identity = $method->invoke($service, array(
    'bus_school_id' => 91,
    'oracle_bus_id' => 11,
    'bus_number' => 11,
));

$missing_rejected = false;
try {
    $method->invoke($service, array('oracle_bus_id' => 11, 'bus_number' => 11));
} catch (ReflectionException $exception) {
    throw $exception;
} catch (Throwable $exception) {
    $missing_rejected = strpos($exception->getMessage(), 'BUS_SCHOOL_ID') !== false;
}

if ($identity !== array('91', '91', '11') || !$missing_rejected) {
    fwrite(STDERR, "Core transport BUS_SCHOOL_ID mapping: FAIL\n");
    exit(1);
}

echo "Core transport BUS_SCHOOL_ID mapping: PASS\n";
