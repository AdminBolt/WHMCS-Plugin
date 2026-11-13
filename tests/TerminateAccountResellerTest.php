<?php

/**
 * TerminateAccount on reseller products, with a recording API client
 * (no WHMCS, no panel). Run: php tests/TerminateAccountResellerTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\TerminateAccount;

tests_reset();

/**
 * Panel with the given hosting accounts. Deleting an account in $failingIds
 * fails, one in $warningIds succeeds with a cleanup warning.
 */
$terminateApi = fn(array $accounts, array $failingIds = [], array $warningIds = []) => new TestApi(
    function(string $method, string $endpoint) use ($accounts, $failingIds, $warningIds) {
        if($method === 'GET')
        {
            return match($endpoint) {
                '/api/hosting-accounts' => $accounts,
                '/api/resellers/7' => ['id' => 7, 'username' => 'resellera'],
                '/api/hosting-accounts/21' => $accounts[3],
                default => null,
            };
        }

        if($method === 'DELETE' && preg_match('#^/api/hosting-accounts/(\d+)$#', $endpoint, $m))
        {
            $id = (int) $m[1];
            if(in_array($id, $failingIds, true))
            {
                throw new Exception('API error: Cannot delete account with reserved system username');
            }
            if(in_array($id, $warningIds, true))
            {
                return ['status' => 'warning', 'message' => 'The hosting account was deleted. Failed cleanup steps: delete_domain:9.'];
            }
            return ['status' => 'success', 'message' => 'Hosting account deleted successfully'];
        }

        return $method === 'POST' ? [] : null;
    }
);

$accounts = [
    ['id' => 11, 'username' => 'alpha', 'domain' => 'alpha.example.test', 'reseller_id' => 7],
    ['id' => 12, 'username' => 'beta', 'domain' => 'beta.example.test', 'reseller_id' => 7],
    ['id' => 13, 'username' => 'gamma', 'domain' => 'gamma.example.test', 'reseller_id' => '7'],
    ['id' => 21, 'username' => 'other', 'domain' => 'other.example.test', 'reseller_id' => 8],
    ['id' => 31, 'username' => 'admins', 'domain' => 'admins.example.test', 'reseller_id' => null],
];

$resellerParams = ['producttype' => 'reselleraccount', 'username' => 'resellera', 'customfields' => ['resellerId' => '7']];

// 1. All accounts of the reseller are deleted, then the reseller; other accounts untouched.
$GLOBALS['moduleLog'] = [];
$api = $terminateApi($accounts);
$result = with_api(new TerminateAccount($resellerParams), $api)->execute();
check($result === 'success', 'happy path returns success');
check($api->endpoints() === [
    'GET /api/resellers/7',
    'GET /api/hosting-accounts',
    'DELETE /api/hosting-accounts/11',
    'DELETE /api/hosting-accounts/12',
    'DELETE /api/hosting-accounts/13',
    'DELETE /api/resellers/7',
], 'happy path calls: ' . implode(' | ', $api->endpoints()));
check(count($GLOBALS['moduleLog']) === 1 && str_contains($GLOBALS['moduleLog'][0][2], '#11, #12, #13'), 'summary in Module Log lists deleted accounts');

// 2. Failure on the second account: stop, keep the reseller, name the account.
$GLOBALS['moduleLog'] = [];
$api = $terminateApi($accounts, [12]);
$error = error_of(fn() => with_api(new TerminateAccount($resellerParams), $api)->execute());
check($error !== null, 'failure throws so WHMCS reports an error');
check($api->endpoints() === [
    'GET /api/resellers/7',
    'GET /api/hosting-accounts',
    'DELETE /api/hosting-accounts/11',
    'DELETE /api/hosting-accounts/12',
], 'failure stops before further deletions and the reseller: ' . implode(' | ', $api->endpoints()));
check(str_contains((string) $error, 'Hosting account #12 (beta, beta.example.test)'), 'message names the failing account: ' . $error);
check(str_contains((string) $error, 'reserved system username'), 'message carries the panel reason');
check(str_contains((string) $error, '1 of 3 accounts were deleted'), 'message says how far it got');
check(!str_contains((string) $error, '..'), 'no doubled full stop');

// 3. Retry after the cause is fixed: only the remaining accounts are listed and deleted.
$api = $terminateApi(array_values(array_filter($accounts, fn($a) => $a['id'] !== 11)));
$result = with_api(new TerminateAccount($resellerParams), $api)->execute();
check($result === 'success', 'retry succeeds');
$endpoints = $api->endpoints();
check(end($endpoints) === 'DELETE /api/resellers/7', 'retry deletes the reseller last');

// 4. Reseller without accounts: only the reseller is deleted.
$api = $terminateApi([$accounts[3]]);
with_api(new TerminateAccount($resellerParams), $api)->execute();
check($api->endpoints() === ['GET /api/resellers/7', 'GET /api/hosting-accounts', 'DELETE /api/resellers/7'], 'reseller without accounts');

// 5. Account deleted with a cleanup warning: continue, report the warning in the Module Log.
$GLOBALS['moduleLog'] = [];
$api = $terminateApi($accounts, [], [12]);
$result = with_api(new TerminateAccount($resellerParams), $api)->execute();
check($result === 'success', 'warning does not stop termination');
check(str_contains($GLOBALS['moduleLog'][0][2] ?? '', 'unfinished cleanup steps: #12'), 'warning recorded in Module Log');

// 6. Reseller deletion refused by the panel (409): error propagates.
$api = new TestApi(function(string $method) {
    if($method === 'DELETE')
    {
        throw new Exception('API error: This reseller has active hosting accounts.');
    }
    return $method === 'GET' ? ['id' => 7, 'username' => 'resellera'] : [];
});
$error = error_of(fn() => with_api(new TerminateAccount($resellerParams), $api)->execute());
check($error === 'API error: This reseller has active hosting accounts.', 'reseller refusal reaches WHMCS');

// 7. Shared product: unchanged single account deletion.
$api = $terminateApi($accounts);
$result = with_api(new TerminateAccount(['producttype' => 'hostingaccount', 'username' => 'other', 'customfields' => ['hostingAccountId' => '21']]), $api)->execute();
check($result === 'success' && $api->endpoints() === ['GET /api/hosting-accounts/21', 'DELETE /api/hosting-accounts/21'], 'shared product: username checked, then deleted');

tests_done();
