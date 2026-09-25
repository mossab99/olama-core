<?php

define('ABSPATH', __DIR__);

class Olama_Core_Repository {
    public function table($name) { return 'wp_' . $name; }
}

require dirname(__DIR__) . '/includes/class-olama-core-financial-service.php';

$service = new Olama_Core_Financial_Service(new Olama_Core_Repository());
$dues = array(
    array('due_date' => '2026-08-31', 'due_amount' => '206.000', 'balance' => '123.600'),
    array('due_date' => '2026-09-30', 'due_amount' => '69.000', 'balance' => '41.400'),
    array('due_date' => '2026-09-30', 'due_amount' => '10.000', 'balance' => '5.000'),
);

if ($service->get_monthly_due('55', '2026-2027', '2026-09', $dues) !== 79.0
    || $service->get_monthly_due('55', '2026-2027', '2026-10', $dues) !== 0.0
    || $service->get_monthly_due('55', '2026-2027', '2026-09', array()) !== null
    || $service->get_amount_due_through_month('55', '2026-2027', '2026-09', $dues) !== 170.0) {
    throw new RuntimeException('Monthly due must use only the selected month, while outstanding amount uses balances through that month.');
}

echo "Financial monthly due checks passed.\n";
