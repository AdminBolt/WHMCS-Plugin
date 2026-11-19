<?php

/**
 * TerminateAccount on reseller products, with a recording API client
 * (no WHMCS, no panel). Run: php tests/TerminateAccountResellerTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\TerminateAccount;
use ModulesGarden\AdminBolt\Api\ApiException;

tests_reset();

/**
 * A panel before AdminBolt 1.9.7 (no terminate endpoint) with the given
 * hosting accounts. Deleting an account in $failingIds fails, one in
 * $warningIds succeeds with a cleanup warning.
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

        if($method === 'POST' && $endpoint === '/api/resellers/7/terminate')
        {
            throw new ApiException('API error: The route api/resellers/7/terminate could not be found.', 404, ['message' => 'The route api/resellers/7/terminate could not be found.']);
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

// Legacy panel (no terminate endpoint), cases 1-6.
// 1. All accounts of the reseller are deleted, then the reseller; other accounts untouched.
$GLOBALS['moduleLog'] = [];
$api = $terminateApi($accounts);
$result = with_api(new TerminateAccount($resellerParams), $api)->execute();
check($result === 'success', 'happy path returns success');
check($api->endpoints() === [
    'GET /api/resellers/7',
    'POST /api/resellers/7/terminate',
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
    'POST /api/resellers/7/terminate',
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
check($api->endpoints() === ['GET /api/resellers/7', 'POST /api/resellers/7/terminate', 'GET /api/hosting-accounts', 'DELETE /api/resellers/7'], 'reseller without accounts');

// 5. Account deleted with a cleanup warning: continue, report the warning in the Module Log.
$GLOBALS['moduleLog'] = [];
$api = $terminateApi($accounts, [], [12]);
$result = with_api(new TerminateAccount($resellerParams), $api)->execute();
check($result === 'success', 'warning does not stop termination');
check(str_contains($GLOBALS['moduleLog'][0][2] ?? '', 'unfinished cleanup steps: #12'), 'warning recorded in Module Log');

// 6. Reseller deletion refused by the panel (409): error propagates.
$api = new TestApi(function(string $method, string $endpoint) {
    if($method === 'POST')
    {
        throw new ApiException('API error (HTTP 404)', 404, null);
    }
    if($method === 'DELETE')
    {
        throw new Exception('API error: This reseller has active hosting accounts.');
    }
    return $method === 'GET' ? ['id' => 7, 'username' => 'resellera'] : [];
});
$error = error_of(fn() => with_api(new TerminateAccount($resellerParams), $api)->execute());
check($error === 'API error: This reseller has active hosting accounts.', 'reseller refusal reaches WHMCS');

// Panel 1.9.7 and newer: one call, the panel does the rest in the background.
$queuedApi = fn(array $answer, ?ApiException $refusal = null) => new TestApi(
    function(string $method, string $endpoint) use ($answer, $refusal) {
        if($method === 'GET' && $endpoint === '/api/resellers/7')
        {
            return ['id' => 7, 'username' => 'resellera'];
        }
        if($method === 'POST' && $endpoint === '/api/resellers/7/terminate')
        {
            if($refusal)
            {
                throw $refusal;
            }
            return $answer;
        }
        throw new Exception('unexpected call ' . $method . ' ' . $endpoint);
    }
);

// 8. Queued: one POST, no account deletion from WHMCS, the Module Log says it was queued.
$GLOBALS['moduleLog'] = [];
$api = $queuedApi(['id' => 7, 'termination_state' => 'queued', 'accounts_deleted' => [], 'accounts_failed' => [], 'accounts_pending' => ['alpha', 'beta']]);
$result = with_api(new TerminateAccount($resellerParams), $api)->execute();
check($result === 'success', 'queued termination returns success');
check($api->endpoints() === ['GET /api/resellers/7', 'POST /api/resellers/7/terminate'], 'queued termination calls: ' . implode(' | ', $api->endpoints()));
$logged = $GLOBALS['moduleLog'][0][2] ?? '';
check(str_starts_with($logged, 'Termination of reseller #7 queued'), 'Module Log says queued: ' . $logged);
check(str_contains($logged, 'still to delete: 2 (alpha, beta)'), 'Module Log lists the accounts still to delete: ' . $logged);

// 9. A second Terminate while the panel works: joins, still success, nothing deleted from here.
$GLOBALS['moduleLog'] = [];
$api = $queuedApi(['id' => 7, 'termination_state' => 'running', 'accounts_deleted' => ['alpha'], 'accounts_failed' => [], 'accounts_pending' => ['beta']]);
check(with_api(new TerminateAccount($resellerParams), $api)->execute() === 'success', 'joined termination returns success');
check(str_starts_with($GLOBALS['moduleLog'][0][2] ?? '', 'Termination of reseller #7 already in progress'), 'Module Log says in progress');
check(!in_array('DELETE /api/hosting-accounts/11', $api->endpoints(), true), 'no account deletion from WHMCS while the panel works');

// 10. After a failed run: the new round is queued and the Module Log names the failed account and its reason.
$GLOBALS['moduleLog'] = [];
$api = $queuedApi(['id' => 7, 'termination_state' => 'queued', 'accounts_deleted' => ['alpha'], 'accounts_failed' => [['username' => 'beta', 'reason' => 'Cannot delete account with reserved system username'], ['username' => null, 'reason' => 'This hosting plan has active hosting subscription.']], 'accounts_pending' => []]);
check(with_api(new TerminateAccount($resellerParams), $api)->execute() === 'success', 'a new round after a failure returns success');
$logged = $GLOBALS['moduleLog'][0][2] ?? '';
check(str_contains($logged, 'Not deleted in the last run, tried again now: beta: Cannot delete account with reserved system username; the reseller: This hosting plan has active hosting subscription.'), 'Module Log names the failure: ' . $logged);

// 11. A refusal of the panel (409 busy) is reported with the panel's reason; no fallback.
$api = $queuedApi([], new ApiException('API error: Another operation on this reseller is in progress. Try again in a moment.', 409, ['message' => 'Another operation on this reseller is in progress. Try again in a moment.']));
$error = error_of(fn() => with_api(new TerminateAccount($resellerParams), $api)->execute());
check($error === 'API error (HTTP 409): Another operation on this reseller is in progress. Try again in a moment.', 'busy refusal reaches WHMCS: ' . $error);
check($api->endpoints() === ['GET /api/resellers/7', 'POST /api/resellers/7/terminate'], 'no fallback on a refusal');

// 12. The reseller deleted meanwhile (404 Reseller not found): an error, no fallback.
$api = $queuedApi([], new ApiException('API error: Reseller not found', 404, ['message' => 'Reseller not found']));
$error = error_of(fn() => with_api(new TerminateAccount($resellerParams), $api)->execute());
check($error !== null && str_contains($error, 'Reseller not found'), 'reseller not found reaches WHMCS');
check($api->endpoints() === ['GET /api/resellers/7', 'POST /api/resellers/7/terminate'], 'no fallback for a missing reseller');

// 13. The API client takes the panel's 202 (accepted, done in the background) as a success.
check(\ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(202), '202 is a success');
check(\ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(200) && \ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(201) && \ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(204), '200, 201 and 204 stay successes');
check(!\ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(409) && !\ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(404) && !\ModulesGarden\AdminBolt\Api\AdminBolt::isSuccessCode(302), 'errors and redirects stay errors');

// 7. Shared product: unchanged single account deletion.
$api = $terminateApi($accounts);
$result = with_api(new TerminateAccount(['producttype' => 'hostingaccount', 'username' => 'other', 'customfields' => ['hostingAccountId' => '21']]), $api)->execute();
check($result === 'success' && $api->endpoints() === ['GET /api/hosting-accounts/21', 'DELETE /api/hosting-accounts/21'], 'shared product: username checked, then deleted');

tests_done();
