<?php

/**
 * Plugin features sold as configurable options named "plugin:<id>|...":
 * switched on or off for the account on create and on a package change,
 * and nothing sent for a product without such options.
 * Run: php tests/PluginFeaturesTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\CreateAccount;
use ModulesGarden\AdminBolt\Helpers\PluginFeatures;

tests_reset();

// 1. Which options are plugin features, and what counts as bought.
check(PluginFeatures::fromParams(['configoptions' => [
    'plugin:matomo' => '1',
    'plugin:tawkto' => '0',
    'plugin:grafana' => 'Yes',
    'plugin:laravel-toolkit' => 'No',
    'plugin:clean-theme' => 2,
    'plugin:' => '1',
    'Disk Space' => '10',
]]) === [
    'plugin:matomo' => true,
    'plugin:tawkto' => false,
    'plugin:grafana' => true,
    'plugin:laravel-toolkit' => false,
    'plugin:clean-theme' => true,
], 'plugin options read, others ignored');
check(PluginFeatures::fromParams([]) === [], 'no configurable options, no features');

$client = ['fullname' => 'Jan Test', 'email' => 'c@example.test', 'phonecc' => '48', 'phonenumber' => '123', 'address1' => 'A', 'city' => 'C', 'state' => 'S', 'postcode' => '00-001', 'countrycode' => 'PL', 'company' => ''];
$service = [
    'producttype' => 'hostingaccount',
    'serviceid' => 0,
    'packageid' => 0,
    'domain' => 'shop.example.test',
    'username' => 'shop5',
    'password' => 'Secret-123',
    'configoption1' => '2',
    'configoption2' => '',
    'clientsdetails' => $client,
    'customfields' => ['hostingAccountId' => '5'],
];
$panel = fn() => new TestApi(function(string $method, string $endpoint) {
    if($method === 'POST' && $endpoint === '/api/hosting-accounts')
    {
        return ['id' => 5];
    }
    if($method === 'GET' && $endpoint === '/api/hosting-accounts/5')
    {
        return ['id' => 5, 'username' => 'shop5'];
    }
    return [];
});

// 2. Create with the add-on bought: the account gets it after it is made.
$api = $panel();
$result = with_api(new CreateAccount($service + ['configoptions' => ['plugin:matomo' => '1', 'Disk Space' => '10']]), $api)->execute();
check($result === 'success', 'create succeeds');
check($api->endpoints() === ['POST /api/hosting-accounts', 'PUT /api/hosting-accounts/5/feature-overrides'], 'create then feature overrides: ' . json_encode($api->endpoints()));
check(($api->calls[1][1] ?? null) === ['features' => ['plugin:matomo' => true]], 'only the plugin feature is sent');

// 3. A product without plugin options sends nothing more than before.
$api = $panel();
with_api(new CreateAccount($service), $api)->execute();
check($api->endpoints() === ['POST /api/hosting-accounts'], 'no plugin options, no extra call');

// 4. Cancelling the add-on is a package change: switched off.
$api = $panel();
$result = with_api(new ChangePackage($service + ['configoptions' => ['plugin:matomo' => '0']]), $api)->execute();
check($result === 'success', 'change package succeeds');
check(end($api->calls) === ['PUT /api/hosting-accounts/5/feature-overrides', ['features' => ['plugin:matomo' => false]]], 'add-on switched off: ' . json_encode(end($api->calls)));

tests_done();
