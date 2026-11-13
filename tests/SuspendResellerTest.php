<?php

/**
 * Plain PHP test for SuspendAccount / UnsuspendAccount on reseller products,
 * with a stubbed API client (no WHMCS, no panel). Run: php tests/SuspendResellerTest.php
 */

require __DIR__ . '/../modules/servers/AdminBolt/vendor/autoload.php';

use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;
use ModulesGarden\AdminBolt\Api\AdminBolt;

function logModuleCall($module, $action, $request, $response, $processed = null): void
{
}

class StubApi extends AdminBolt
{
    public array $calls = [];

    public function __construct(private ?string $failWith = null)
    {
        parent::__construct('https://panel.invalid', 'key', 'secret');
    }

    public function post(string $endpoint, ?array $content = null): ?array
    {
        $this->calls[] = ['POST ' . $endpoint, $content];

        if($this->failWith !== null)
        {
            throw new Exception($this->failWith);
        }

        return ['id' => 7, 'suspended' => true];
    }
}

trait InjectsApi
{
    public function withApi(AdminBolt $api): static
    {
        $this->api = $api;
        return $this;
    }
}

class TestableSuspend extends SuspendAccount { use InjectsApi; }
class TestableUnsuspend extends UnsuspendAccount { use InjectsApi; }

$failures = 0;
$assertions = 0;

function check(bool $condition, string $label): void
{
    global $failures, $assertions;
    $assertions++;
    if(!$condition)
    {
        $failures++;
        echo "FAIL: $label\n";
    }
}

$reseller = ['producttype' => 'reselleraccount', 'customfields' => ['resellerId' => '7']];

// 1. Suspend calls the panel with the WHMCS suspend reason.
$api = new StubApi();
$result = (new TestableSuspend($reseller + ['suspendreason' => 'Overdue on Payment']))->withApi($api)->execute();
check($result === 'success', 'suspend returns success');
check($api->calls === [['POST /api/resellers/7/suspend', ['reason' => 'Overdue on Payment']]], 'suspend endpoint and reason: ' . json_encode($api->calls));

// 2. Suspend without a reason sends no body.
$api = new StubApi();
(new TestableSuspend($reseller + ['suspendreason' => '  ']))->withApi($api)->execute();
check($api->calls === [['POST /api/resellers/7/suspend', null]], 'suspend without reason');

// 3. Unsuspend calls the panel.
$api = new StubApi();
$result = (new TestableUnsuspend($reseller))->withApi($api)->execute();
check($result === 'success', 'unsuspend returns success');
check($api->calls === [['POST /api/resellers/7/unsuspend', null]], 'unsuspend endpoint');

// 4. Panel errors are not swallowed (the module entry point returns them to WHMCS).
foreach([TestableSuspend::class, TestableUnsuspend::class] as $class)
{
    $error = null;
    try
    {
        (new $class($reseller))->withApi(new StubApi('API error: Reseller not found.'))->execute();
    }
    catch(Exception $e)
    {
        $error = $e->getMessage();
    }
    check($error === 'API error: Reseller not found.', "$class reports the panel error");
}

// 5. Shared products unchanged.
$shared = ['producttype' => 'hostingaccount', 'customfields' => ['hostingAccountId' => '21'], 'suspendreason' => 'Abuse'];
$api = new StubApi();
(new TestableSuspend($shared))->withApi($api)->execute();
(new TestableUnsuspend($shared))->withApi($api)->execute();
check($api->calls === [['POST /api/hosting-accounts/21/suspend', null], ['POST /api/hosting-accounts/21/unsuspend', null]], 'shared products unchanged: ' . json_encode($api->calls));

echo ($failures === 0 ? 'OK' : 'FAILED') . " ($assertions assertions, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
