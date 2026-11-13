<?php

/**
 * Terminate, Suspend, Unsuspend, login and Change Password of a reseller
 * check that the panel reseller with the stored ID has this service's
 * username, so a mistyped Reseller ID (17 instead of 71) cannot act on
 * another reseller.
 * Run: php tests/ResellerUsernameCheckTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\ChangePassword;
use ModulesGarden\AdminBolt\Actions\ServiceSingleSignOn;
use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;
use ModulesGarden\AdminBolt\Actions\TerminateAccount;

tests_reset();

// Panel: reseller 17 is "otherco" with one account, reseller 71 is "resellerb".
$panel = fn() => new TestApi(fn(string $method, string $endpoint) => match($method . ' ' . $endpoint) {
    'GET /api/resellers/17' => ['id' => 17, 'username' => 'otherco'],
    'GET /api/resellers/71' => ['id' => 71, 'username' => 'ResellerB'],
    'GET /api/hosting-accounts' => [['id' => 5, 'username' => 'shop', 'domain' => 'shop.example.test', 'reseller_id' => 17]],
    default => ['sso_url' => 'https://panel.example.test/reseller/sso/x'],
});

$service = ['producttype' => 'reselleraccount', 'username' => 'resellerb', 'suspendreason' => '', 'password' => 'N3w-Pass', 'customfields' => ['resellerId' => '17']];

foreach(['Terminate' => TerminateAccount::class, 'Suspend' => SuspendAccount::class, 'Unsuspend' => UnsuspendAccount::class, 'SSO' => ServiceSingleSignOn::class, 'ChangePassword' => ChangePassword::class] as $name => $class)
{
    // 1. Mistyped ID: refused after the read, nothing changed.
    $api = $panel();
    $error = (string) error_of(fn() => with_api(new $class($service), $api)->execute());
    check(str_contains($error, 'Reseller ID 17 belongs to the panel reseller "otherco"') && str_contains($error, '"resellerb"') && str_contains($error, 'Nothing was changed'), "$name refuses another reseller: $error");
    check($api->endpoints() === ['GET /api/resellers/17'], "$name only reads the reseller: " . json_encode($api->endpoints()));

    // 2. Matching username (case does not matter): the command runs.
    $api = $panel();
    $result = with_api(new $class(array_replace($service, ['customfields' => ['resellerId' => '71']])), $api)->execute();
    $endpoints = $api->endpoints();
    check(($result === 'success' || ($result['success'] ?? false) === true) && $endpoints[0] === 'GET /api/resellers/71' && count($endpoints) > 1, "$name runs on the matching reseller: " . json_encode($endpoints));

    // 3. Service without a username: refused, nothing changed.
    $api = $panel();
    $error = (string) error_of(fn() => with_api(new $class(array_replace($service, ['username' => '', 'customfields' => ['resellerId' => '71']])), $api)->execute());
    check(str_contains($error, 'Nothing was changed') && $api->endpoints() === ['GET /api/resellers/71'], "$name without a service username is refused: $error");

    // 4. Panel answer without a username: refused.
    $api = new TestApi(fn() => ['id' => 71]);
    $error = (string) error_of(fn() => with_api(new $class(array_replace($service, ['customfields' => ['resellerId' => '71']])), $api)->execute());
    check(str_contains($error, 'Nothing was changed') && count($api->calls) === 1, "$name refuses when the panel gives no username");
}

tests_done();
