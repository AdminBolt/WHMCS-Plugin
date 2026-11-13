<?php

/**
 * Shared hosting: an empty Hosting Account ID is recovered only from the one
 * panel account with the service's domain and username. Scenario: Create for "shopb"
 * failed (username taken by another customer's "shopb"), so the service has
 * no ID; no command may touch the other customer's account. Commands that act
 * on the account (all but Change Package) also check the account's username.
 * Run: php tests/SharedAccountLookupTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\ChangePassword;
use ModulesGarden\AdminBolt\Actions\ServiceSingleSignOn;
use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\TerminateAccount;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;
use WHMCS\Database\Capsule;

tests_reset();

$otherCustomer = ['id' => 33, 'username' => 'shopb', 'domain' => 'other-shop.example.test', 'reseller_id' => 8];
$panel = fn(array $accounts) => new TestApi(function(string $method, string $endpoint) use ($accounts) {
    if($method === 'GET' && $endpoint === '/api/hosting-accounts')
    {
        return $accounts;
    }
    if($method === 'GET' && preg_match('#^/api/hosting-accounts/(\d+)$#', $endpoint, $m))
    {
        foreach($accounts as $account)
        {
            if($account['id'] === (int) $m[1])
            {
                return $account;
            }
        }
        return null;
    }
    return ['sso_url' => 'https://panel.example.test/sso/x'];
});

$service = [
    'producttype' => 'hostingaccount',
    'serviceid' => 52,
    'packageid' => 3,
    'domain' => 'shop-b.example.test',
    'username' => 'shopb',
    'password' => 'Secret-123',
    'configoption1' => '2',
    'configoption2' => '',
    'customfields' => ['hostingAccountId' => ''],
];
$fieldExists = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfields' && $operation === 'first' ? (object) ['id' => 88] : null;

$commands = [
    'Terminate' => TerminateAccount::class,
    'Suspend' => SuspendAccount::class,
    'Unsuspend' => UnsuspendAccount::class,
    'ChangePackage' => ChangePackage::class,
    'ChangePassword' => ChangePassword::class,
    'SSO' => ServiceSingleSignOn::class,
];

// 1. Only another customer's account has the username: every command fails, reads the list only, saves nothing.
foreach($commands as $name => $class)
{
    Capsule::reset();
    Capsule::$reader = $fieldExists;
    $api = $panel([$otherCustomer]);
    $error = (string) error_of(fn() => with_api(new $class($service), $api)->execute());
    check(str_contains($error, "no panel account has the domain 'shop-b.example.test' and the username 'shopb'") && str_contains($error, 'nothing was changed'), "$name fails clearly: $error");
    check($api->endpoints() === ['GET /api/hosting-accounts'], "$name does not touch the other account: " . json_encode($api->endpoints()));
    check(Capsule::$writes === [], "$name saves no ID");
}

// 2. Domain matches but the username does not: no recovery.
Capsule::reset();
Capsule::$reader = $fieldExists;
$api = $panel([['id' => 34, 'username' => 'someoneelse', 'domain' => 'shop-b.example.test']]);
$error = (string) error_of(fn() => with_api(new SuspendAccount($service), $api)->execute());
check(str_contains($error, 'no panel account') && $api->endpoints() === ['GET /api/hosting-accounts'] && Capsule::$writes === [], 'domain alone is not enough');

// 3. Two accounts match: no recovery.
Capsule::reset();
Capsule::$reader = $fieldExists;
$api = $panel([['id' => 35, 'username' => 'shopb', 'domain' => 'shop-b.example.test'], ['id' => 36, 'username' => 'SHOPB', 'domain' => 'Shop-B.example.test']]);
$error = (string) error_of(fn() => with_api(new SuspendAccount($service), $api)->execute());
check(str_contains($error, 'more than one panel account') && $api->endpoints() === ['GET /api/hosting-accounts'] && Capsule::$writes === [], 'several matches: refused: ' . $error);

// 4. Exactly one account matches domain and username (case-insensitive): ID recovered, saved and used.
Capsule::reset();
Capsule::$reader = $fieldExists;
$api = $panel([$otherCustomer, ['id' => 37, 'username' => 'ShopB', 'domain' => 'SHOP-B.example.test']]);
$result = with_api(new SuspendAccount($service), $api)->execute();
check($result === 'success' && $api->endpoints() === ['GET /api/hosting-accounts', 'GET /api/hosting-accounts/37', 'POST /api/hosting-accounts/37/suspend'], 'single match recovered and used: ' . json_encode($api->endpoints()));
check(Capsule::$writes === [['insert', 'tblcustomfieldsvalues', [], ['fieldid' => 88, 'relid' => 52, 'value' => '37']]], 'recovered ID saved: ' . json_encode(Capsule::$writes));

// 5. No domain or no username: no lookup at all.
Capsule::reset();
$api = $panel([$otherCustomer]);
$error = (string) error_of(fn() => with_api(new SuspendAccount(array_replace($service, ['domain' => ''])), $api)->execute());
check(str_contains($error, 'no domain and username') && $api->calls === [], 'no domain: no panel call');

// 6. Stored ID of another customer's account (33 typed instead of 23): every command that acts on the
//    account refuses after reading it, so nothing is sent (no login link, no password change).
foreach(array_diff_key($commands, ['ChangePackage' => true]) as $name => $class)
{
    $api = $panel([$otherCustomer]);
    $error = (string) error_of(fn() => with_api(new $class(array_replace($service, ['username' => 'shopc', 'customfields' => ['hostingAccountId' => '33']])), $api)->execute());
    check(str_contains($error, 'Hosting account ID 33 belongs to the panel account "shopb"') && str_contains($error, '"shopc"') && str_contains($error, 'Nothing was changed'), "$name refuses another account: $error");
    check($api->endpoints() === ['GET /api/hosting-accounts/33'], "$name only reads the account");
}

// 7. Stored ID of the service's own account: commands work as before.
$own = array_replace($service, ['customfields' => ['hostingAccountId' => '33']]);
$expected = [
    'Terminate' => ['GET /api/hosting-accounts/33', 'DELETE /api/hosting-accounts/33'],
    'Suspend' => ['GET /api/hosting-accounts/33', 'POST /api/hosting-accounts/33/suspend'],
    'Unsuspend' => ['GET /api/hosting-accounts/33', 'POST /api/hosting-accounts/33/unsuspend'],
    'ChangePackage' => ['PUT /api/hosting-accounts/33'],
    'ChangePassword' => ['GET /api/hosting-accounts/33', 'POST /api/hosting-accounts/33/change-password'],
    'SSO' => ['GET /api/hosting-accounts/33', 'POST /api/hosting-accounts/33/generate-sso-token'],
];
foreach($commands as $name => $class)
{
    $api = $panel([$otherCustomer]);
    $result = with_api(new $class($own), $api)->execute();
    check(($result === 'success' || ($result['success'] ?? false) === true) && $api->endpoints() === $expected[$name], "$name with the stored ID: " . json_encode($api->endpoints()));
}

tests_done();
