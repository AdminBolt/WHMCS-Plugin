<?php

/**
 * UsageUpdate (daily WHMCS usage cron): the class loads, and an error on one
 * service is logged while the other services are still updated.
 * Run: php tests/UsageUpdateTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\UsageUpdate;
use ModulesGarden\AdminBolt\Api\ApiException;
use WHMCS\Database\Capsule;

tests_reset();

// 1. The class can be declared (0.0.3 stopped here with a PHP fatal).
check(class_exists(UsageUpdate::class), 'UsageUpdate loads');

// Three services; the stored hosting account ID is 100 + service ID.
Capsule::$reader = function(string $table, array $wheres, string $operation) {
    if($table === 'tblhosting' && $operation === 'get')
    {
        return [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3]];
    }
    if($table === 'tblcustomfieldsvalues' && $operation === 'first')
    {
        return (object) ['value' => (string) (100 + $wheres['tblcustomfieldsvalues.relid'])];
    }
    return null;
};

// Service 1: account deleted in the panel (404). Service 3: engine error. Service 2: fine.
$api = new TestApi(function(string $method, string $endpoint) {
    if(str_contains($endpoint, '/101/'))
    {
        throw new ApiException('API error: Hosting account not found', 404, ['message' => 'Hosting account not found']);
    }
    if(str_contains($endpoint, '/103/'))
    {
        throw new Error('simulated engine error');
    }
    return ['usageDetails' => ['diskUsage' => 5, 'totalDiskUsage' => 10, 'bandwidth' => 7, 'totalBandwidth' => 20]];
});

$result = with_api(new UsageUpdate(['serverid' => 1]), $api)->execute();

check($result === 'success', 'execute returns success');
check(count(Capsule::$writes) === 1 && Capsule::$writes[0][2] === ['id' => 2], 'only service 2 updated: ' . json_encode(Capsule::$writes));
check((Capsule::$writes[0][3] ?? null) === ['diskusage' => 5, 'disklimit' => 10, 'bwusage' => 7, 'bwlimit' => 20, 'lastupdate' => 'NOW'], 'service 2 values from usage-details');
check($api->endpoints() === [
    'GET /api/hosting-accounts/101/usage-details',
    'GET /api/hosting-accounts/102/usage-details',
    'GET /api/hosting-accounts/103/usage-details',
], 'every service asked: ' . json_encode($api->endpoints()));
check(count($GLOBALS['moduleLog']) === 2, 'two Module Log entries');
check(($GLOBALS['moduleLog'][0][1] ?? '') === 'Service ID: 1' && str_contains($GLOBALS['moduleLog'][0][2] ?? '', 'not found'), 'service 1 logged with the API reason');
check(($GLOBALS['moduleLog'][1][1] ?? '') === 'Service ID: 3' && str_contains($GLOBALS['moduleLog'][1][2] ?? '', 'simulated engine error'), 'service 3 (Error) logged');

// 2. An error outside the per-service loop is logged by the module entry point, not thrown into the cron.
tests_reset();
Capsule::$reader = function() {
    throw new Error('database gone');
};
$error = error_of(fn() => AdminBolt_UsageUpdate(['serverid' => 1]));
check($error === null, 'AdminBolt_UsageUpdate does not throw');
check(($GLOBALS['moduleLog'][0][0] ?? '') === 'UsageUpdate' && ($GLOBALS['moduleLog'][0][2] ?? '') === 'database gone', 'entry point logs the error');

tests_done();
