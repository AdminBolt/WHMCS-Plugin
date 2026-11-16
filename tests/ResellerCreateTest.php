<?php

/**
 * Reseller Create after the panel created the reseller but WHMCS could not
 * keep its ID: the message says the reseller exists, not to run Create again,
 * and what to do by hand. Run: php tests/ResellerCreateTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\CreateAccount;
use WHMCS\Database\Capsule;

tests_reset();

$params = [
    'producttype' => 'reselleraccount',
    'serviceid' => 52,
    'packageid' => 3,
    'username' => 'resellerb',
    'password' => 'secret123',
    'clientsdetails' => ['fullname' => 'Client', 'email' => 'client@example.test', 'phonecc' => '', 'phonenumber' => '', 'address1' => '', 'city' => '', 'state' => '', 'postcode' => '', 'countrycode' => 'PL', 'company' => ''],
    'customfields' => ['resellerId' => ''],
];

// 1. The panel response has no reseller ID.
$api = new TestApi(fn() => ['message' => 'created']);
$error = (string) error_of(fn() => with_api(new CreateAccount($params), $api)->execute());
check(str_contains($error, 'was created in the panel'), 'says the reseller exists in the panel: ' . $error);
check(str_contains($error, 'Do not run Create again'), 'says not to run Create again');
check(str_contains($error, 'Reseller ID field') && str_contains($error, 'activate the service by hand'), 'says to fill the Reseller ID and activate by hand');
check($api->endpoints() === ['POST /api/resellers'] && Capsule::$writes === [], 'one create call, nothing stored');

// 2. The product has no resellerId custom field: the ID cannot be saved after a successful create.
tests_reset();
Capsule::$reader = fn() => null;
$api = new TestApi(fn() => ['id' => 42, 'username' => 'resellerb']);
$error = (string) error_of(fn() => with_api(new CreateAccount($params), $api)->execute());
check(str_contains($error, 'was created in the panel with ID 42'), 'says the reseller was created, with its ID: ' . $error);
check(str_contains($error, 'Custom field resellerId does not exist'), 'names the missing field');
check(str_contains($error, 'Do not run Create again') && str_contains($error, "Module Settings") && str_contains($error, 'enter 42'), 'says how to add the field and fill it');
check($api->endpoints() === ['POST /api/resellers'] && Capsule::$writes === [], 'one create call, nothing stored');

// 3. Normal create: the ID is stored, no error.
tests_reset();
Capsule::$reader = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfields' && $operation === 'first' ? (object) ['id' => 77] : null;
$api = new TestApi(fn() => ['id' => 42]);
check(with_api(new CreateAccount($params), $api)->execute() === 'success', 'create succeeds');
check(Capsule::$writes === [['insert', 'tblcustomfieldsvalues', [], ['fieldid' => 77, 'relid' => 52, 'value' => '42']]], 'ID stored in a new field value: ' . json_encode(Capsule::$writes));

tests_done();
