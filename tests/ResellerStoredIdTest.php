<?php

/**
 * Reseller commands act only on the reseller ID stored on the service. A
 * client with reseller A whose second reseller service B has no stored ID
 * (its creation failed) must never reach reseller A through a username or
 * email lookup. Run: php tests/ResellerStoredIdTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\ChangePassword;
use ModulesGarden\AdminBolt\Actions\CreateAccount;
use ModulesGarden\AdminBolt\Actions\ServiceSingleSignOn;
use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\TerminateAccount;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;
use WHMCS\Database\Capsule;

tests_reset();

const NO_RESELLER_ID = "This service has no AdminBolt reseller ID; set the resellerId field to the panel's reseller ID.";

// The panel holds reseller A (ID 7) of the same client, with one hosting account.
$panelWithResellerA = fn() => new TestApi(function(string $method, string $endpoint) {
    return match($method . ' ' . $endpoint) {
        'GET /api/resellers' => [['id' => 7, 'username' => 'resellera', 'email' => 'client@example.test']],
        'GET /api/hosting-accounts' => [['id' => 11, 'username' => 'shopa', 'domain' => 'shop-a.example.test', 'reseller_id' => 7]],
        'GET /api/resellers/7' => ['id' => 7, 'name' => 'Client', 'username' => 'resellera', 'email' => 'client@example.test'],
        default => ['sso_url' => 'https://panel.example.test/reseller/sso/a'],
    };
});

// Service B: same client, same e-mail, its own username, no stored reseller ID.
$serviceB = [
    'producttype' => 'reselleraccount',
    'serviceid' => 52,
    'packageid' => 3,
    'username' => 'resellerb',
    'password' => 'secret123',
    'domain' => '',
    'suspendreason' => 'Overdue on Payment',
    'clientsdetails' => ['fullname' => 'Client', 'email' => 'client@example.test', 'phonecc' => '', 'phonenumber' => '', 'address1' => '', 'city' => '', 'state' => '', 'postcode' => '', 'countrycode' => 'PL', 'company' => ''],
    'configoption1' => '30',
    'customfields' => ['resellerId' => ''],
];

$commands = [
    'Terminate' => TerminateAccount::class,
    'Suspend' => SuspendAccount::class,
    'Unsuspend' => UnsuspendAccount::class,
    'ChangePackage' => ChangePackage::class,
    'SSO' => ServiceSingleSignOn::class,
    'ChangePassword' => ChangePassword::class,
];

// 1. Every reseller command on B fails with a clear message and makes no panel call.
foreach(['' => 'empty', '  ' => 'blank', 'abc' => 'not a number', '0' => 'zero'] as $storedId => $label)
{
    foreach($commands as $name => $class)
    {
        Capsule::reset();
        // The product has the resellerId custom field, so a recovered ID could be saved.
        Capsule::$reader = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfields' && $operation === 'first' ? (object) ['id' => 77] : null;
        $api = $panelWithResellerA();
        $params = array_replace($serviceB, ['customfields' => ['resellerId' => $storedId]]);

        $error = error_of(fn() => with_api(new $class($params), $api)->execute());

        check($error === NO_RESELLER_ID, "$name with $label reseller ID fails clearly: " . var_export($error, true));
        check($api->calls === [], "$name with $label reseller ID makes no panel call: " . json_encode($api->endpoints()));
        check(Capsule::$writes === [], "$name with $label reseller ID stores nothing");
    }
}

// 2. With the stored ID the commands act on that reseller only.
$api = $panelWithResellerA();
with_api(new SuspendAccount(array_replace($serviceB, ['customfields' => ['resellerId' => '9']])), $api)->execute();
check($api->endpoints() === ['POST /api/resellers/9/suspend'], 'stored ID is used as is: ' . json_encode($api->endpoints()));

// 3. Create stores the reseller ID from the create response, without a lookup.
Capsule::reset();
Capsule::$reader = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfieldsvalues' && $operation === 'first' ? (object) ['id' => 501] : null;
$api = new TestApi(fn(string $method, string $endpoint) => $endpoint === '/api/resellers' && $method === 'POST' ? ['id' => 42, 'username' => 'resellerb'] : null);
$create = with_api(new CreateAccount($serviceB), $api);
$result = $create->execute();
check($result === 'success', 'create succeeds');
check($api->endpoints() === ['POST /api/resellers'], 'create makes only the create call: ' . json_encode($api->endpoints()));
check(Capsule::$writes === [['update', 'tblcustomfieldsvalues', ['id' => 501], ['value' => '42']]], 'create stores the ID from the response: ' . json_encode(Capsule::$writes));

// 4. A create response without an ID fails clearly instead of leaving the service without one.
Capsule::reset();
$api = new TestApi(fn(string $method, string $endpoint) => $endpoint === '/api/resellers' && $method === 'POST' ? ['message' => 'created'] : [['id' => 7, 'username' => 'resellerb', 'email' => 'client@example.test']]);
$error = error_of(fn() => with_api(new CreateAccount($serviceB), $api)->execute());
check(is_string($error) && str_contains($error, 'resellerId'), 'create without an ID in the response fails clearly: ' . var_export($error, true));
check($api->endpoints() === ['POST /api/resellers'] && Capsule::$writes === [], 'no lookup and nothing stored after such a create');

// 5. Shared products keep the hosting account recovery by domain or username.
Capsule::reset();
$api = new TestApi(fn(string $method, string $endpoint) => $endpoint === '/api/hosting-accounts' ? [['id' => 21, 'username' => 'shopb', 'domain' => 'shop-b.example.test']] : []);
$shared = ['producttype' => 'hostingaccount', 'serviceid' => 0, 'packageid' => 0, 'domain' => 'shop-b.example.test', 'username' => 'shopb', 'customfields' => ['hostingAccountId' => '']];
with_api(new SuspendAccount($shared), $api)->execute();
check($api->endpoints() === ['GET /api/hosting-accounts', 'POST /api/hosting-accounts/21/suspend'], 'shared recovery unchanged: ' . json_encode($api->endpoints()));

tests_done();
