<?php

/**
 * Plain PHP test for TerminateAccount on reseller products, with a stubbed
 * API client (no WHMCS, no panel). Run: php tests/TerminateAccountResellerTest.php
 */

require __DIR__ . '/../modules/servers/AdminBolt/vendor/autoload.php';

use ModulesGarden\AdminBolt\Actions\TerminateAccount;
use ModulesGarden\AdminBolt\Api\AdminBolt;

$GLOBALS['moduleLog'] = [];

function logModuleCall($module, $action, $request, $response, $processed = null): void
{
    $GLOBALS['moduleLog'][] = [$action, $response];
}

class StubApi extends AdminBolt
{
    public array $calls = [];

    public function __construct(private array $accounts, private array $failingIds = [], private array $warningIds = [])
    {
        parent::__construct('https://panel.invalid', 'key', 'secret');
    }

    public function get(string $endpoint): ?array
    {
        $this->calls[] = 'GET ' . $endpoint;
        return $endpoint === '/api/hosting-accounts' ? $this->accounts : null;
    }

    public function post(string $endpoint, ?array $content = null): ?array
    {
        $this->calls[] = 'POST ' . $endpoint;
        return [];
    }

    public function delete(string $endpoint): ?array
    {
        $this->calls[] = 'DELETE ' . $endpoint;

        if(preg_match('#^/api/hosting-accounts/(\d+)$#', $endpoint, $m))
        {
            $id = (int) $m[1];
            if(in_array($id, $this->failingIds, true))
            {
                throw new Exception('API error: Cannot delete account with reserved system username');
            }
            if(in_array($id, $this->warningIds, true))
            {
                return ['status' => 'warning', 'message' => 'The hosting account was deleted. Failed cleanup steps: delete_domain:9.'];
            }
            return ['status' => 'success', 'message' => 'Hosting account deleted successfully'];
        }

        return null;
    }
}

class TestableTerminateAccount extends TerminateAccount
{
    public function withApi(AdminBolt $api): static
    {
        $this->api = $api;
        return $this;
    }
}

$failures = 0;
$assertions = 0;

function check(bool $condition, string $label): void
{
    global $failures, $assertions;
    $assertions++;
    if(!$condition)
    {
        $failures++;
        echo "FAIL: $label\n";
    }
}

$accounts = [
    ['id' => 11, 'username' => 'alpha', 'domain' => 'alpha.example.test', 'reseller_id' => 7],
    ['id' => 12, 'username' => 'beta', 'domain' => 'beta.example.test', 'reseller_id' => 7],
    ['id' => 13, 'username' => 'gamma', 'domain' => 'gamma.example.test', 'reseller_id' => '7'],
    ['id' => 21, 'username' => 'other', 'domain' => 'other.example.test', 'reseller_id' => 8],
    ['id' => 31, 'username' => 'admins', 'domain' => 'admins.example.test', 'reseller_id' => null],
];

$resellerParams = ['producttype' => 'reselleraccount', 'customfields' => ['resellerId' => '7']];

// 1. All accounts of the reseller are deleted, then the reseller; other accounts untouched.
$GLOBALS['moduleLog'] = [];
$api = new StubApi($accounts);
$result = (new TestableTerminateAccount($resellerParams))->withApi($api)->execute();
check($result === 'success', 'happy path returns success');
check($api->calls === [
    'GET /api/hosting-accounts',
    'DELETE /api/hosting-accounts/11',
    'DELETE /api/hosting-accounts/12',
    'DELETE /api/hosting-accounts/13',
    'DELETE /api/resellers/7',
], 'happy path calls: ' . implode(' | ', $api->calls));
check(count($GLOBALS['moduleLog']) === 1 && str_contains($GLOBALS['moduleLog'][0][1], '#11, #12, #13'), 'summary in Module Log lists deleted accounts');

// 2. Failure on the second account: stop, keep the reseller, name the account.
$GLOBALS['moduleLog'] = [];
$api = new StubApi($accounts, [12]);
$error = null;
try
{
    (new TestableTerminateAccount($resellerParams))->withApi($api)->execute();
}
catch(Exception $e)
{
    $error = $e->getMessage();
}
check($error !== null, 'failure throws so WHMCS reports an error');
check($api->calls === [
    'GET /api/hosting-accounts',
    'DELETE /api/hosting-accounts/11',
    'DELETE /api/hosting-accounts/12',
], 'failure stops before further deletions and the reseller: ' . implode(' | ', $api->calls));
check(str_contains((string) $error, 'Hosting account #12 (beta, beta.example.test)'), 'message names the failing account: ' . $error);
check(str_contains((string) $error, 'reserved system username'), 'message carries the panel reason');
check(str_contains((string) $error, '1 of 3 accounts were deleted'), 'message says how far it got');
check(!str_contains((string) $error, '..'), 'no doubled full stop');

// 3. Retry after the cause is fixed: only the remaining accounts are listed and deleted.
$api = new StubApi(array_values(array_filter($accounts, fn($a) => $a['id'] !== 11)));
$result = (new TestableTerminateAccount($resellerParams))->withApi($api)->execute();
check($result === 'success', 'retry succeeds');
check(end($api->calls) === 'DELETE /api/resellers/7', 'retry deletes the reseller last');

// 4. Reseller without accounts: only the reseller is deleted.
$api = new StubApi([$accounts[3]]);
(new TestableTerminateAccount($resellerParams))->withApi($api)->execute();
check($api->calls === ['GET /api/hosting-accounts', 'DELETE /api/resellers/7'], 'reseller without accounts');

// 5. Account deleted with a cleanup warning: continue, report the warning in the Module Log.
$GLOBALS['moduleLog'] = [];
$api = new StubApi($accounts, [], [12]);
$result = (new TestableTerminateAccount($resellerParams))->withApi($api)->execute();
check($result === 'success', 'warning does not stop termination');
check(str_contains($GLOBALS['moduleLog'][0][1] ?? '', 'unfinished cleanup steps: #12'), 'warning recorded in Module Log');

// 6. Reseller deletion refused by the panel (409): error propagates.
$api = new class([]) extends StubApi {
    public function delete(string $endpoint): ?array
    {
        $this->calls[] = 'DELETE ' . $endpoint;
        throw new Exception('API error: This reseller has active hosting accounts.');
    }
};
$error = null;
try
{
    (new TestableTerminateAccount($resellerParams))->withApi($api)->execute();
}
catch(Exception $e)
{
    $error = $e->getMessage();
}
check($error === 'API error: This reseller has active hosting accounts.', 'reseller refusal reaches WHMCS');

// 7. Shared product: unchanged single account deletion.
$api = new StubApi($accounts);
$result = (new TestableTerminateAccount(['producttype' => 'hostingaccount', 'customfields' => ['hostingAccountId' => '21']]))->withApi($api)->execute();
check($result === 'success' && $api->calls === ['DELETE /api/hosting-accounts/21'], 'shared product unchanged');

echo ($failures === 0 ? 'OK' : 'FAILED') . " ($assertions assertions, $failures failures)\n";
exit($failures === 0 ? 0 : 1);
