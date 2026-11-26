<?php

/**
 * AB-2708: a reseller's API key on the WHMCS server. The panel accepts it
 * only on the reseller API (/api/reseller/...), so the client sends every
 * hosting plan and hosting account call there once the admin API refuses
 * the key as one of another owner type. Calls only the admin API has
 * (resellers, the admin login) fail with a clear message. Administrator keys
 * call the same admin API endpoints as before.
 * Run: php tests/ResellerApiKeyTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\AdminSingleSignOn;
use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\ChangePassword;
use ModulesGarden\AdminBolt\Actions\CreateAccount;
use ModulesGarden\AdminBolt\Actions\ServiceSingleSignOn;
use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\TerminateAccount;
use ModulesGarden\AdminBolt\Actions\TestConnection;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;
use ModulesGarden\AdminBolt\Actions\UsageUpdate;
use ModulesGarden\AdminBolt\Api\AdminBolt;
use ModulesGarden\AdminBolt\Api\ApiException;
use WHMCS\Database\Capsule;

tests_reset();

// 1. Path mapping.
check(AdminBolt::resellerEndpoint('/api/hosting-plans') === '/api/reseller/hosting-plans', 'plans list mapped');
check(AdminBolt::resellerEndpoint('/api/hosting-plans/12') === '/api/reseller/hosting-plans/12', 'plan read mapped');
check(AdminBolt::resellerEndpoint('/api/hosting-accounts') === '/api/reseller/hosting-accounts', 'accounts list mapped');
check(AdminBolt::resellerEndpoint('/api/hosting-accounts/5/usage-details') === '/api/reseller/hosting-accounts/5/usage-details', 'sub-path mapped');
check(AdminBolt::resellerEndpoint('/api/hosting-accounts?username=a') === '/api/reseller/hosting-accounts?username=a', 'query kept');
foreach(['/api/hosting-accountsx', '/api/resellers', '/api/resellers/7/suspend', '/api/admin/generate-sso-token', '/api/reseller/hosting-plans'] as $endpoint)
{
    check(AdminBolt::resellerEndpoint($endpoint) === null, "$endpoint has no reseller counterpart");
}

$wrongType = fn() => new ApiException('API error: API key does not have access to this resource.', 403, ['error' => 'API key does not have access to this resource.']);

/**
 * A panel whose key belongs to a reseller (or to a client, $client = true):
 * the admin API refuses it in the key middleware; the reseller API answers
 * for the reseller's own account 5 on plan 11 "X" (plan 12 "Y").
 */
$panel = function(bool $client = false) use ($wrongType) {
    return new TestApi(function(string $method, string $endpoint, ?array $body) use ($client, $wrongType) {
        if($client || !str_starts_with($endpoint, '/api/reseller/'))
        {
            throw $wrongType();
        }
        static $planId = 11;
        $path = substr($endpoint, strlen('/api/reseller'));
        return match(true) {
            $path === '/hosting-plans' => [['id' => 11, 'name' => 'X', 'reseller_id' => 2], ['id' => 12, 'name' => 'Y', 'reseller_id' => 2]],
            $method === 'POST' && $path === '/hosting-accounts' => ['id' => 5, 'username' => $body['username']],
            $method === 'PUT' && $path === '/hosting-accounts/5' => (function() use ($body, &$planId) { $planId = $body['hosting_plan'] === 'Y' ? 12 : 11; return ['id' => 5]; })(),
            $path === '/hosting-accounts/5' => ['id' => 5, 'username' => 'shopr', 'hosting_plan_id' => $planId, 'reseller_id' => 2],
            $path === '/hosting-accounts/5/usage-details' => ['usageDetails' => ['diskUsage' => 1, 'totalDiskUsage' => 2, 'bandwidth' => 3, 'totalBandwidth' => 4]],
            str_ends_with($path, '/generate-sso-token') => ['sso_url' => 'https://panel.example.test/sso/r'],
            default => ['message' => 'ok'],
        };
    });
};

$client = ['fullname' => 'Jan Test', 'email' => 'c@example.test', 'phonecc' => '', 'phonenumber' => '', 'address1' => 'A', 'city' => 'C', 'state' => 'S', 'postcode' => '1', 'countrycode' => 'PL', 'company' => ''];
$shared = ['producttype' => 'hostingaccount', 'serviceid' => 70, 'packageid' => 6, 'domain' => 'shopr.example.test', 'username' => 'shopr', 'password' => 'Secret-123', 'configoption1' => 'name:X', 'configoption2' => '', 'clientsdetails' => $client, 'customfields' => ['hostingAccountId' => '5'], 'suspendreason' => ''];
Capsule::$reader = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfields' && $operation === 'first' ? (object) ['id' => 88] : null;

// 2. Every shared hosting command with a reseller key: one refused admin call, then only reseller API calls.
$expected = [
    'TestConnection' => [new TestConnection($shared), ['GET /api/reseller/hosting-plans']],
    'Create' => [new CreateAccount(array_replace($shared, ['customfields' => ['hostingAccountId' => '']])), ['POST /api/reseller/hosting-accounts']],
    'Suspend' => [new SuspendAccount($shared), ['GET /api/reseller/hosting-accounts/5', 'POST /api/reseller/hosting-accounts/5/suspend']],
    'Unsuspend' => [new UnsuspendAccount($shared), ['GET /api/reseller/hosting-accounts/5', 'POST /api/reseller/hosting-accounts/5/unsuspend']],
    'ChangePackage' => [new ChangePackage(array_replace($shared, ['configoption1' => 'name:Y'])), ['GET /api/reseller/hosting-accounts/5', 'PUT /api/reseller/hosting-accounts/5', 'GET /api/reseller/hosting-accounts/5', 'GET /api/reseller/hosting-plans']],
    'ChangePassword' => [new ChangePassword($shared), ['GET /api/reseller/hosting-accounts/5', 'POST /api/reseller/hosting-accounts/5/change-password']],
    'SSO' => [new ServiceSingleSignOn($shared), ['GET /api/reseller/hosting-accounts/5', 'POST /api/reseller/hosting-accounts/5/generate-sso-token']],
    'Terminate' => [new TerminateAccount($shared), ['GET /api/reseller/hosting-accounts/5', 'DELETE /api/reseller/hosting-accounts/5']],
];
foreach($expected as $name => [$action, $resellerCalls])
{
    Capsule::$writes = [];
    $api = $panel();
    $result = with_api($action, $api)->execute();
    $endpoints = $api->endpoints();
    check($result === 'success' || ($result['success'] ?? false) === true, "$name succeeds with a reseller key: " . json_encode($result));
    check(count($endpoints) === count($resellerCalls) + 1 && !str_starts_with($endpoints[0], 'GET /api/reseller/') && !str_starts_with($endpoints[0], 'POST /api/reseller/') && array_slice($endpoints, 1) === $resellerCalls, "$name: one refused admin call, then the reseller API: " . json_encode($endpoints));
    check($api->keyType() === AdminBolt::KEY_RESELLER, "$name: key type remembered");
}
$createApi = $panel();
with_api(new CreateAccount(array_replace($shared, ['customfields' => ['hostingAccountId' => '']])), $createApi)->execute();
check($createApi->calls[0][1] === $createApi->calls[1][1] && ($createApi->calls[1][1]['hosting_plan'] ?? null) === 'X', 'Create body sent again unchanged, with the plan name');

// 3. Usage Update with a reseller key.
Capsule::reset();
Capsule::$reader = function(string $table, array $wheres, string $operation) {
    if($table === 'tblhosting' && $operation === 'get')
    {
        return [(object) ['id' => 70]];
    }
    return $table === 'tblcustomfieldsvalues' ? (object) ['value' => '5'] : null;
};
$api = $panel();
with_api(new UsageUpdate(['serverid' => 1]), $api)->execute();
check(array_slice($api->endpoints(), 1) === ['GET /api/reseller/hosting-accounts/5/usage-details'], 'Usage Update asks the reseller API: ' . json_encode($api->endpoints()));
check((Capsule::$writes[0][3]['diskusage'] ?? null) === 1, 'usage saved');
Capsule::$reader = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfields' && $operation === 'first' ? (object) ['id' => 88] : null;

// 4. Reseller products and the admin login with a reseller key: a clear message, nothing sent after the refusal.
$resellerProduct = array_replace($shared, array_fill_keys(array_map(fn($i) => "configoption$i", range(1, 10)), ''), ['producttype' => 'reselleraccount', 'customfields' => ['resellerId' => '7']]);
foreach(['Create' => CreateAccount::class, 'Suspend' => SuspendAccount::class, 'Unsuspend' => UnsuspendAccount::class, 'Terminate' => TerminateAccount::class, 'ChangePackage' => ChangePackage::class, 'ChangePassword' => ChangePassword::class, 'SSO' => ServiceSingleSignOn::class] as $name => $class)
{
    Capsule::$writes = [];
    $params = $name === 'ChangePackage' ? array_replace($resellerProduct, ['configoption1' => '5']) : $resellerProduct;
    $api = $panel();
    $error = (string) error_of(fn() => with_api(new $class($params), $api)->execute());
    check(str_contains($error, 'This needs an administrator API key, and the API key of this server is not one') && str_contains($error, 'reseller products and the admin login to the panel need an administrator API key') && str_contains($error, 'Nothing was changed'), "reseller product $name with a reseller key: $error");
    check(count($api->calls) === 1 && Capsule::$writes === [], "reseller product $name: only the refused call, nothing saved: " . json_encode($api->endpoints()));
}
$api = $panel();
$error = (string) error_of(fn() => with_api(new AdminSingleSignOn([]), $api)->execute());
check(str_contains($error, 'This needs an administrator API key') && $api->endpoints() === ['POST /api/admin/generate-sso-token'], 'admin login with a reseller key: ' . $error);

// Once the key is known as a reseller key, an admin-only call is not sent at all.
$api = $panel();
$api->get('/api/hosting-plans');
check(str_contains((string) error_of(fn() => $api->post('/api/resellers', [])), 'This needs an administrator API key') && count($api->calls) === 2, 'admin-only call after detection: refused without a request');

// 5. A key that both APIs refuse (a client key): it says so.
$api = $panel(true);
$error = (string) error_of(fn() => with_api(new TestConnection($shared), $api)->execute());
check(str_contains($error, 'neither an administrator key nor a reseller key') && $api->endpoints() === ['GET /api/hosting-plans', 'GET /api/reseller/hosting-plans'], 'client key: ' . $error);

// 6. Administrator key: the same calls as before, no reseller API call, no extra call.
$admin = new TestApi(fn($method, $endpoint) => $endpoint === '/api/hosting-plans' ? [] : null);
with_api(new TestConnection($shared), $admin)->execute();
check($admin->endpoints() === ['GET /api/hosting-plans'] && $admin->keyType() === AdminBolt::KEY_ADMIN, 'admin key: Test Connection unchanged');

// 7. Other refusals of the admin API are not taken for a reseller key.
foreach([
    'endpoint not granted' => new ApiException('API error: API key does not have access to this endpoint or method', 403, ['error' => 'API key does not have access to this endpoint or method']),
    'address not whitelisted' => new ApiException('API error: IP address not whitelisted', 403, ['error' => 'IP address not whitelisted']),
    'bad secret' => new ApiException('API error: Invalid API key or secret', 401, ['error' => 'Invalid API key or secret']),
    'same text, other status' => new ApiException('API error', 401, ['error' => 'API key does not have access to this resource.']),
] as $label => $refusal)
{
    $api = new TestApi(fn() => throw $refusal);
    $error = error_of(fn() => $api->get('/api/hosting-plans'));
    check($error === $refusal->getMessage() && $api->endpoints() === ['GET /api/hosting-plans'] && $api->keyType() === null, "$label: no reseller API retry: " . json_encode($api->endpoints()));
}

// 8. Once the key is known as an administrator key, a later owner-type refusal is not retried on the reseller API.
$calls = 0;
$api = new TestApi(function() use (&$calls, $wrongType) { return ++$calls === 1 ? [] : throw $wrongType(); });
$api->get('/api/hosting-plans');
$error = error_of(fn() => $api->get('/api/hosting-accounts/5'));
check($error === 'API error: API key does not have access to this resource.' && $api->endpoints() === ['GET /api/hosting-plans', 'GET /api/hosting-accounts/5'], 'admin key known: no reseller API retry later: ' . json_encode($api->endpoints()));

// 9. A reseller API error after the switch is the reseller API's own reason, and the key type is kept.
$api = new TestApi(fn($method, $endpoint) => str_starts_with($endpoint, '/api/reseller/')
    ? throw new ApiException('API error: Hosting plan not found: X', 422, ['message' => 'Hosting plan not found: X'])
    : throw $wrongType());
$error = (string) error_of(fn() => with_api(new CreateAccount(array_replace($shared, ['customfields' => ['hostingAccountId' => '']])), $api)->execute());
check($error === 'API error (HTTP 422): Hosting plan not found: X' && $api->keyType() === AdminBolt::KEY_RESELLER, 'reseller API 422 shown: ' . $error);

tests_done();
