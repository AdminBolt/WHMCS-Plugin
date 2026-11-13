<?php

/**
 * Reseller product limits (module settings sent on create and package change)
 * and the reseller panel login, with a recording API client.
 * Run: php tests/ResellerLimitsTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\ConfigOptions;
use ModulesGarden\AdminBolt\Actions\CreateAccount;
use ModulesGarden\AdminBolt\Actions\ServiceSingleSignOn;
use ModulesGarden\AdminBolt\Api\ApiException;

tests_reset();

$answer = fn(?array $response = null, ?Throwable $throw = null) => new TestApi(function() use ($response, $throw) {
    if($throw)
    {
        throw $throw;
    }
    return $response;
});

$client = ['fullname' => 'Jan Test', 'email' => 'r@example.test', 'phonecc' => '48', 'phonenumber' => '123', 'address1' => 'A', 'city' => 'C', 'state' => 'S', 'postcode' => '00-001', 'countrycode' => 'PL', 'company' => ''];
$base = ['producttype' => 'reselleraccount', 'username' => 'resel1', 'password' => 'secret123', 'serviceid' => 0, 'packageid' => 0, 'clientsdetails' => $client, 'customfields' => ['resellerId' => '7']];
$empty = array_fill_keys(array_map(fn($i) => "configoption$i", range(1, 10)), '');
$contactKeys = ['name', 'username', 'password', 'email', 'phone', 'address', 'city', 'state', 'zip', 'country', 'company'];
$lveKeys = ['resource_cpu_speed', 'resource_physical_memory', 'resource_virtual_memory', 'resource_io', 'resource_iops', 'resource_processes', 'resource_entry_processes'];

// 1. Module settings of a reseller product: ten text fields in configoption order.
$options = (new ConfigOptions(['producttype' => 'reselleraccount']))->execute();
check(array_keys($options) === ['Maximum Accounts', 'Disk Limit', 'Bandwidth Limit', 'CPU Speed', 'Physical Memory', 'Virtual Memory', 'I/O Throughput', 'I/O Operations', 'Processes', 'Entry Processes'], 'setting labels and order');
check(count(array_filter($options, fn($o) => $o['Type'] === 'text')) === 10, 'all settings are text fields');

// 2. Create sends only the filled limits, whole numbers as integers.
$api = $answer(['id' => 7]);
$result = with_api(new CreateAccount($base + ['configoption1' => '15', 'configoption2' => '20480', 'configoption3' => '102400'] + $empty), $api)->execute();
$body = $api->calls[0][1] ?? [];
check($result === 'success' && $api->endpoints() === ['POST /api/resellers'], 'create calls POST /api/resellers once');
check(($body['max_accounts'] ?? null) === 15 && ($body['disk_limit_mb'] ?? null) === 20480 && ($body['bandwidth_limit_mb'] ?? null) === 102400, 'filled limits sent as integers');
check(array_intersect_key($body, array_flip($lveKeys)) === [], 'empty LVE limits not sent');
check($body['name'] === 'Jan Test' && $body['username'] === 'resel1' && $body['email'] === 'r@example.test' && $body['phone'] === '+48123', 'contact fields unchanged');

// 3. Create with every setting empty, or a product saved before the settings existed: body as in 0.0.3.
$api = $answer(['id' => 8]);
with_api(new CreateAccount($base + $empty), $api)->execute();
check(array_keys($api->calls[0][1]) === $contactKeys, 'all settings empty: no limit keys');
$api = $answer(['id' => 8]);
with_api(new CreateAccount($base), $api)->execute();
check(array_keys($api->calls[0][1]) === $contactKeys, 'no settings at all: no limit keys');

// 4. LVE settings map to the panel field names.
$api = $answer(['id' => 9]);
with_api(new CreateAccount(array_replace($base, $empty, ['configoption4' => '100', 'configoption10' => ' 30 '])), $api)->execute();
check(($api->calls[0][1]['resource_cpu_speed'] ?? null) === 100 && ($api->calls[0][1]['resource_entry_processes'] ?? null) === 30, 'LVE fields mapped');

// 5. Package change sends only the filled limits.
$api = $answer(['id' => 7, 'max_accounts' => 30]);
$result = with_api(new ChangePackage(array_replace($base, $empty, ['configoption1' => '30'])), $api)->execute();
check($result === 'success' && $api->calls === [['PUT /api/resellers/7', ['max_accounts' => 30]]], 'package change: PUT with max_accounts only');

// 6. Package change with every setting empty: no panel call.
$api = $answer();
check(with_api(new ChangePackage($base + $empty), $api)->execute() === 'success' && $api->calls === [], 'all empty: no panel call');

// 7. The panel refuses a limit (422): the command fails with the panel's reason and nothing else is changed.
$refusal = new ApiException('API error: The given data was invalid.', 422, ['message' => 'The given data was invalid.', 'errors' => ['max_accounts' => ['The reseller already has 20 accounts.']]]);
$api = $answer(null, $refusal);
$error = error_of(fn() => with_api(new ChangePackage(array_replace($base, $empty, ['configoption1' => '10', 'configoption2' => '512'])), $api)->execute());
check(str_contains((string) $error, 'HTTP 422') && str_contains((string) $error, 'max_accounts: The reseller already has 20 accounts.') && str_contains((string) $error, 'The given data was invalid.'), '422 reason in the error: ' . $error);
check($api->endpoints() === ['PUT /api/resellers/7'], 'one PUT, no further change after the refusal');

// 8. A refusal with only a message (LVE limits on a server without CloudLinux).
$api = $answer(null, new ApiException('API error: CloudLinux is not installed on this server, so the reseller LVE limits cannot be set: resource_cpu_speed.', 422, ['message' => 'CloudLinux is not installed on this server, so the reseller LVE limits cannot be set: resource_cpu_speed.']));
$error = error_of(fn() => with_api(new ChangePackage(array_replace($base, $empty, ['configoption4' => '100'])), $api)->execute());
check(str_contains((string) $error, 'CloudLinux is not installed'), 'message-only 422 reason: ' . $error);

// 9. Reseller login: stored reseller ID, panel link returned to WHMCS.
$api = $answer(['message' => 'ok', 'sso_url' => 'https://panel.example.test/reseller/sso/abc', 'token' => 'abc']);
$result = with_api(new ServiceSingleSignOn($base), $api)->execute();
check($result === ['success' => true, 'redirectTo' => 'https://panel.example.test/reseller/sso/abc'], 'reseller login returns the panel link');
check($api->endpoints() === ['POST /api/resellers/7/generate-sso-token'], 'reseller login uses the stored reseller ID only');
$api = $answer(['message' => 'x']);
check(str_contains((string) error_of(fn() => with_api(new ServiceSingleSignOn($base), $api)->execute()), 'sign-in link'), 'missing link is an error');

// 9b. Non-numeric values (left over from a shared product's settings, or mistyped) are not limits.
$api = $answer(['id' => 7]);
with_api(new CreateAccount(array_replace($base, $empty, ['configoption1' => '12', 'configoption2' => 'on', 'configoption3' => 'abc', 'configoption4' => '-5', 'configoption5' => '1.5'])), $api)->execute();
check(array_diff_key($api->calls[0][1], array_flip($contactKeys)) === ['max_accounts' => 12], 'only whole numbers sent: ' . json_encode(array_diff_key($api->calls[0][1], array_flip($contactKeys))));
$api = $answer();
check(with_api(new ChangePackage(array_replace($base, $empty, ['configoption2' => 'on'])), $api)->execute() === 'success' && $api->calls === [], 'only non-numeric values: no panel call');
check(str_contains($options['Maximum Accounts']['Description'], 'save the product'), 'settings tell to save the product after switching type');

// 10. Shared products unchanged.
$shared = ['producttype' => 'hostingaccount', 'configoption1' => '3', 'configoption2' => 'on', 'customfields' => ['hostingAccountId' => '5'], 'serviceid' => 0, 'packageid' => 0];
$api = $answer();
with_api(new ChangePackage($shared), $api)->execute();
check($api->calls === [['PUT /api/hosting-accounts/5', ['hosting_plan_id' => '3', 'ssh_access' => true]]], 'shared package change unchanged');
$api = $answer(['sso_url' => 'u']);
check(with_api(new ServiceSingleSignOn($shared), $api)->execute() === ['success' => true, 'redirectTo' => 'u'] && $api->endpoints() === ['POST /api/hosting-accounts/5/generate-sso-token'], 'shared login unchanged');

tests_done();
