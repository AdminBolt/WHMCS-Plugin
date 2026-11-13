<?php

/**
 * Module Log entries carry no secrets: server API key and secret, access
 * hash, account passwords. Run: php tests/ModuleLogTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Api\AdminBolt;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;

tests_reset();

$apiKey = 'ApiKeyId-123';
$apiSecret = 'Sup3rSecretApiValue';
$accessHash = 'hash-xyz-789';
$clientPassword = 'Cl"ient/Pass!42';
$secrets = [$apiKey, $apiSecret, $accessHash, $clientPassword, json_encode($clientPassword), substr(json_encode($clientPassword), 1, -1)];

$leaks = function(array $entry) use ($secrets): array {
    $found = [];
    foreach(array_slice($entry, 1, 3) as $text)
    {
        foreach($secrets as $secret)
        {
            if(str_contains((string) $text, $secret))
            {
                $found[] = $secret;
            }
        }
    }
    return array_unique($found);
};

$params = [
    'producttype' => 'reselleraccount',
    'serviceid' => 52,
    'serverhttpprefix' => 'https',
    'serverhostname' => 'panel.example.test',
    'serverport' => '8443',
    'serverusername' => $apiKey,
    'serverpassword' => $apiSecret,
    'serveraccesshash' => $accessHash,
    'username' => 'resellerb',
    'password' => $clientPassword,
    'customfields' => ['resellerId' => ''],
];

// 1. A failed module command logs its parameters without secrets.
$result = AdminBolt_SuspendAccount($params);
$entry = $GLOBALS['moduleLog'][0] ?? [];
check(str_contains($result, 'no AdminBolt reseller ID'), 'command failed as expected: ' . $result);
check(($entry[0] ?? '') === 'SuspendAccount' && str_contains($entry[1] ?? '', 'resellerb'), 'parameters are logged');
check($leaks($entry) === [], 'no secret in the command log: ' . json_encode($leaks($entry)));
check(substr_count($entry[1] ?? '', ModuleLog::MASK) === 4, 'four secret parameters masked');
check(array_diff([$apiKey, $apiSecret, $accessHash, $clientPassword], $entry[4] ?? []) === [], 'secret values passed to WHMCS for masking');

// 2. A secret inside an error message is masked too.
tests_reset();
ModuleLog::error('ChangePassword', $params, 'Request failed with ' . $apiSecret);
check($leaks($GLOBALS['moduleLog'][0]) === [] && str_contains($GLOBALS['moduleLog'][0][2], 'Request failed with ' . ModuleLog::MASK), 'secret in the message masked');

// 3. An API call: request headers and a password in the body are masked; the rest stays.
tests_reset();
$client = new AdminBolt('https://panel.example.test:8443', $apiKey, $apiSecret);
$content = ['name' => 'Client', 'username' => 'resellerb', 'password' => $clientPassword];
$request = "POST /api/resellers HTTP/1.1\r\nHost: panel.example.test:8443\r\nContent-Type: application/json\r\nX-API-Key: $apiKey\r\nX-API-Secret: $apiSecret\r\n\r\n" . json_encode($content, JSON_PRETTY_PRINT);
$response = "HTTP/1.1 422 Unprocessable Content\r\n\r\n" . json_encode(['message' => 'The username has already been taken.']);
(function() use ($request, $response, $content) { $this->logCall('/api/resellers', $request, $response, $content); })->call($client);
$entry = $GLOBALS['moduleLog'][0] ?? [];
check(($entry[0] ?? '') === '/api/resellers', 'API call logged under its endpoint');
check($leaks($entry) === [], 'no secret in the API call log: ' . json_encode($leaks($entry)));
check(str_contains($entry[1] ?? '', "X-API-Key: " . ModuleLog::MASK . "\r\n") && str_contains($entry[1] ?? '', "X-API-Secret: " . ModuleLog::MASK . "\r\n"), 'headers masked');
check(str_contains($entry[1] ?? '', '"password": "' . ModuleLog::MASK . '"') && str_contains($entry[1] ?? '', '"username": "resellerb"'), 'password field masked, other fields kept');
check(str_contains($entry[2] ?? '', 'already been taken'), 'panel response kept');
check(array_diff([$apiKey, $apiSecret, $clientPassword], $entry[4] ?? []) === [], 'API key, secret and password passed to WHMCS for masking');

// 4. Short values are masked by key but not replaced in free text.
check(ModuleLog::params(['password' => 'abc', 'domain' => 'abc.example.test'])['password'] === ModuleLog::MASK, 'short password masked by key');
check(ModuleLog::text('abc.example.test', ['abc']) === 'abc.example.test', 'short value does not garble text');

tests_done();
