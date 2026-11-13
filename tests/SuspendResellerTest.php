<?php

/**
 * SuspendAccount / UnsuspendAccount on reseller products, with a recording
 * API client (no WHMCS, no panel). Run: php tests/SuspendResellerTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;

tests_reset();

$suspendApi = fn(?string $failWith = null) => new TestApi(function(string $method, string $endpoint) use ($failWith) {
    if($failWith !== null)
    {
        throw new Exception($failWith);
    }

    if($method === 'GET')
    {
        return $endpoint === '/api/hosting-accounts/21' ? ['id' => 21, 'username' => 'shop21'] : ['id' => 7, 'username' => 'resellera'];
    }

    return ['id' => 7, 'suspended' => true];
});

$reseller = ['producttype' => 'reselleraccount', 'username' => 'resellera', 'customfields' => ['resellerId' => '7']];

// 1. Suspend calls the panel with the WHMCS suspend reason.
$api = $suspendApi();
$result = with_api(new SuspendAccount($reseller + ['suspendreason' => 'Overdue on Payment']), $api)->execute();
check($result === 'success', 'suspend returns success');
check($api->calls === [['GET /api/resellers/7', null], ['POST /api/resellers/7/suspend', ['reason' => 'Overdue on Payment']]], 'suspend endpoint and reason: ' . json_encode($api->calls));

// 2. Suspend without a reason sends no body.
$api = $suspendApi();
with_api(new SuspendAccount($reseller + ['suspendreason' => '  ']), $api)->execute();
check($api->calls === [['GET /api/resellers/7', null], ['POST /api/resellers/7/suspend', null]], 'suspend without reason');

// 3. Unsuspend calls the panel.
$api = $suspendApi();
$result = with_api(new UnsuspendAccount($reseller), $api)->execute();
check($result === 'success', 'unsuspend returns success');
check($api->calls === [['POST /api/resellers/7/unsuspend', null]], 'unsuspend endpoint');

// 4. Panel errors are not swallowed (the module entry point returns them to WHMCS).
foreach([SuspendAccount::class, UnsuspendAccount::class] as $class)
{
    $error = error_of(fn() => with_api(new $class($reseller), $suspendApi('API error: Reseller not found.'))->execute());
    check($error === 'API error: Reseller not found.', "$class reports the panel error");
}

// 5. Shared products unchanged.
$shared = ['producttype' => 'hostingaccount', 'username' => 'shop21', 'customfields' => ['hostingAccountId' => '21'], 'suspendreason' => 'Abuse'];
$api = $suspendApi();
with_api(new SuspendAccount($shared), $api)->execute();
with_api(new UnsuspendAccount($shared), $api)->execute();
check($api->calls === [['GET /api/hosting-accounts/21', null], ['POST /api/hosting-accounts/21/suspend', null], ['POST /api/hosting-accounts/21/unsuspend', null]], 'shared products unchanged: ' . json_encode($api->calls));

tests_done();
