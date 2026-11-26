<?php

/**
 * AB-2742: shared hosting products send the hosting plan by name, so one
 * WHMCS product works on every AdminBolt server of its group although plan
 * IDs differ between servers. Covers the stored setting (prefix "name:"),
 * Create, Change Package, the panel's 422 texts, a panel that still requires
 * the plan ID (before 1.8.2), a plan ID stored by module 0.0.5 and the
 * Hosting Plan dropdown.
 * Run: php tests/HostingPlanByNameTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\ConfigOptions;
use ModulesGarden\AdminBolt\Actions\CreateAccount;
use ModulesGarden\AdminBolt\Api\AdminBolt;
use ModulesGarden\AdminBolt\Api\ApiException;
use ModulesGarden\AdminBolt\Helpers\HostingPlanSetting;
use WHMCS\Database\Capsule;

tests_reset();

// 1. The stored setting.
check(HostingPlanSetting::forName('X') === 'name:X', 'a chosen plan is stored as name:<plan name>');
check(HostingPlanSetting::nameFrom('name:X') === 'X', 'name:X sends X');
check(HostingPlanSetting::nameFrom('name:3') === '3', 'a plan named "3" stays a name');
check(HostingPlanSetting::forName("Gold Plus & Co's 100%") === 'name:Gold%20Plus%20%26%20Co%27s%20100%25', 'the name is stored URL-encoded, no HTML-special character left');
check(HostingPlanSetting::nameFrom(HostingPlanSetting::forName("Gold Plus & Co's 100%")) === "Gold Plus & Co's 100%", 'URL-encoded name sent as the plan name');
check(HostingPlanSetting::storedName('name:A%20B') === 'A B' && HostingPlanSetting::storedName('3') === null, 'storedName decodes only prefixed values');
check(HostingPlanSetting::legacyPlanId('3') === 3 && HostingPlanSetting::legacyPlanId(' 12 ') === 12, 'digits only: a plan ID of module 0.0.5');
check(HostingPlanSetting::legacyPlanId('name:3') === null && HostingPlanSetting::legacyPlanId('') === null && HostingPlanSetting::legacyPlanId('3a') === null && HostingPlanSetting::legacyPlanId('1e3') === null && HostingPlanSetting::legacyPlanId('3.0') === null, 'anything else is no plan ID (digits only, as the 0.0.5 dropdown stored them)');
$legacyError = (string) error_of(fn() => HostingPlanSetting::nameFrom('3'));
check(str_contains($legacyError, 'plan ID 3 saved by AdminBolt module 0.0.5') && str_contains($legacyError, 'Nothing was changed') && str_contains($legacyError, 'choose the hosting plan by name and save'), 'a 0.0.5 plan ID is refused with the way out: ' . $legacyError);
foreach(['', '   ', 'name:', 'name:  '] as $value)
{
    check(str_contains((string) error_of(fn() => HostingPlanSetting::nameFrom($value)), 'has no hosting plan chosen'), "empty setting " . json_encode($value) . " is refused");
}
check(str_contains((string) error_of(fn() => HostingPlanSetting::nameFrom('Basic')), 'holds "Basic", which is not a hosting plan chosen'), 'a value without the prefix is refused, not guessed');

// Two panels where plan "X" has different IDs (server A: 4, server B: 9).
$panel = function(array $plans, array &$accounts, ?callable $refuse = null) {
    return new TestApi(function(string $method, string $endpoint, ?array $body) use ($plans, &$accounts, $refuse) {
        if($refuse && ($answer = $refuse($method, $endpoint, $body)) !== null)
        {
            return $answer;
        }
        if($method === 'POST' && $endpoint === '/api/hosting-accounts')
        {
            $ids = array_keys(array_filter($plans, fn($p) => $p['name'] === $body['hosting_plan'] && empty($p['reseller_id'])));
            if(count($ids) === 0)
            {
                throw new ApiException('API error: Hosting plan not found: ' . $body['hosting_plan'], 422, ['message' => 'Hosting plan not found: ' . $body['hosting_plan']]);
            }
            if(count($ids) > 1)
            {
                throw new ApiException('API error: ambiguous', 422, ['message' => 'Hosting plan name is ambiguous, use hosting_plan_id: ' . $body['hosting_plan']]);
            }
            $id = count($accounts) + 50;
            $accounts[$id] = ['id' => $id, 'username' => $body['username'], 'hosting_plan_id' => $ids[0]];
            return ['id' => $id, 'username' => $body['username']];
        }
        if(preg_match('#^/api/hosting-accounts/(\d+)$#', $endpoint, $m) && isset($accounts[(int) $m[1]]))
        {
            if($method === 'PUT')
            {
                $ids = array_keys(array_filter($plans, fn($p) => $p['name'] === $body['hosting_plan'] && empty($p['reseller_id'])));
                if(count($ids) !== 1)
                {
                    throw new ApiException('API error', 422, ['message' => (count($ids) ? 'Hosting plan name is ambiguous, use hosting_plan_id: ' : 'Hosting plan not found: ') . $body['hosting_plan'], 'fields' => ['hosting_plan']]);
                }
                $accounts[(int) $m[1]]['hosting_plan_id'] = $ids[0];
            }
            return $accounts[(int) $m[1]];
        }
        if($method === 'GET' && $endpoint === '/api/hosting-plans')
        {
            return array_map(fn($id) => ['id' => $id] + $plans[$id], array_keys($plans));
        }
        throw new ApiException('API error: Not found', 404, ['message' => 'Not found']);
    });
};
$plansA = [3 => ['name' => 'Y'], 4 => ['name' => 'X'], 5 => ['name' => 'X', 'reseller_id' => 2]];
$plansB = [7 => ['name' => 'Y'], 8 => ['name' => 'Old'], 9 => ['name' => 'X']];
$client = ['fullname' => 'Jan Test', 'email' => 'c@example.test', 'phonecc' => '48', 'phonenumber' => '1', 'address1' => 'A', 'city' => 'C', 'state' => 'S', 'postcode' => '1', 'countrycode' => 'PL', 'company' => ''];
$service = fn(string $username, string $plan, string $accountId = '') => [
    'producttype' => 'hostingaccount', 'serviceid' => 70, 'packageid' => 6, 'domain' => $username . '.example.test',
    'username' => $username, 'password' => 'Secret-123', 'configoption1' => $plan, 'configoption2' => '',
    'clientsdetails' => $client, 'customfields' => ['hostingAccountId' => $accountId],
];
$fieldExists = fn(string $table, array $wheres, string $operation) => $table === 'tblcustomfields' && $operation === 'first' ? (object) ['id' => 88] : null;

// 2. Create on both servers sends the name and no ID; each panel uses its own plan "X".
$accountsA = [];
$accountsB = [];
foreach([['A', $plansA, &$accountsA, 4], ['B', $plansB, &$accountsB, 9]] as [$label, $plans, &$accounts, $expectedPlanId])
{
    Capsule::reset();
    Capsule::$reader = $fieldExists;
    $api = $panel($plans, $accounts);
    $result = with_api(new CreateAccount($service('shop' . strtolower($label), 'name:X')), $api)->execute();
    $sent = $api->calls[0][1] ?? [];
    check($result === 'success', "Create on server $label succeeds");
    check(($sent['hosting_plan'] ?? null) === 'X' && !array_key_exists('hosting_plan_id', $sent), "Create on server $label sends hosting_plan X and no hosting_plan_id: " . json_encode($sent));
    check((reset($accounts)['hosting_plan_id'] ?? null) === $expectedPlanId, "server $label account on its own plan X (ID $expectedPlanId)");
    check((Capsule::$writes[0][3]['value'] ?? null) === '50', "server $label account ID saved");
}
unset($accounts);

// 3. No plan "X" on the server: the panel's text, nothing saved.
Capsule::reset();
Capsule::$reader = $fieldExists;
$none = [];
$api = $panel([7 => ['name' => 'Y']], $none);
$error = (string) error_of(fn() => with_api(new CreateAccount($service('shopc', 'name:X')), $api)->execute());
check(str_contains($error, 'Hosting plan not found: X'), 'missing plan: panel text shown: ' . $error);
check($none === [] && Capsule::$writes === [], 'missing plan: nothing created, nothing saved');

// 4. Two administrator plans "X" on the server: the ambiguity text, nothing saved.
Capsule::reset();
Capsule::$reader = $fieldExists;
$none = [];
$api = $panel([4 => ['name' => 'X'], 6 => ['name' => 'X']], $none);
$error = (string) error_of(fn() => with_api(new CreateAccount($service('shopd', 'name:X')), $api)->execute());
check(str_contains($error, 'Hosting plan name is ambiguous, use hosting_plan_id: X'), 'ambiguous plan: panel text shown: ' . $error);
check($none === [] && Capsule::$writes === [], 'ambiguous plan: nothing created, nothing saved');

// 5. A plan ID stored by module 0.0.5: Create and Change Package stop before any panel call.
foreach([CreateAccount::class => $service('shope', '4'), ChangePackage::class => $service('shopa', '4', '50')] as $class => $params)
{
    Capsule::reset();
    Capsule::$reader = $fieldExists;
    $api = $panel($plansB, $accountsB);
    $error = (string) error_of(fn() => with_api(new $class($params), $api)->execute());
    check(str_contains($error, 'plan ID 4 saved by AdminBolt module 0.0.5'), "$class refuses the stored plan ID: $error");
    check($api->calls === [] && Capsule::$writes === [], "$class with a stored plan ID makes no panel call and saves nothing");
}

// 6. A panel that still requires hosting_plan_id (AdminBolt before 1.8.2): a clear message, nothing created.
Capsule::reset();
Capsule::$reader = $fieldExists;
$none = [];
$oldPanel = fn($method, $endpoint) => $method === 'POST'
    ? throw new ApiException('API error: The hosting plan id field is required.', 422, ['message' => 'The hosting plan id field is required.', 'errors' => ['hosting_plan_id' => ['The hosting plan id field is required.']]])
    : null;
$api = $panel($plansB, $none, $oldPanel);
$error = (string) error_of(fn() => with_api(new CreateAccount($service('shopf', 'name:X')), $api)->execute());
check(str_contains($error, 'does not take the hosting plan by name, which AdminBolt 1.8.2 and newer do. Nothing was created.') && str_contains($error, 'hosting_plan_id: The hosting plan id field is required.'), 'old panel on Create: ' . $error);
check(Capsule::$writes === [], 'old panel on Create: nothing saved');
// Both fields refused: not taken for an older panel.
$both = fn($method) => $method === 'POST' ? throw new ApiException('API error', 422, ['message' => 'The hosting plan id field is required.', 'errors' => ['hosting_plan_id' => ['The hosting plan id field is required.'], 'hosting_plan' => ['The hosting plan field must be a string.']]]) : null;
$error = (string) error_of(fn() => with_api(new CreateAccount($service('shopg', 'name:X')), $panel($plansB, $none, $both))->execute());
check(!str_contains($error, '1.8.2') && str_contains($error, 'hosting_plan: The hosting plan field must be a string.'), 'refusal naming hosting_plan too: panel text only: ' . $error);
// A refusal that names hosting_plan_id for another reason (reseller LVE maximum) keeps the panel's text only.
$lve = fn($method) => $method === 'POST' ? throw new ApiException('API error', 422, ['message' => 'Over the maximum', 'errors' => ['hosting_plan_id' => ['Over the maximum']]]) : null;
$error = (string) error_of(fn() => with_api(new CreateAccount($service('shopg', 'name:X')), $panel($plansB, $none, $lve))->execute());
check($error === 'API error (HTTP 422): hosting_plan_id: Over the maximum', 'other hosting_plan_id refusal: panel text only: ' . $error);

// 7. Change Package to "Y" on both servers: the name is sent, then the account's plan is read back.
foreach([['A', $plansA, &$accountsA, 3], ['B', $plansB, &$accountsB, 7]] as [$label, $plans, &$accounts, $expectedPlanId])
{
    $api = $panel($plans, $accounts);
    $result = with_api(new ChangePackage($service('shop' . strtolower($label), 'name:Y', '50')), $api)->execute();
    check($result === 'success', "Change Package on server $label succeeds");
    check($api->calls[1] === ['PUT /api/hosting-accounts/50', ['hosting_plan' => 'Y', 'ssh_access' => false]], "server $label PUT sends the name: " . json_encode($api->calls[1]));
    check(array_slice($api->endpoints(), 2) === ['GET /api/hosting-plans'], "server $label plan read back from the update's answer and the plan list (endpoints module 0.0.5 used): " . json_encode($api->endpoints()));
    check($accounts[50]['hosting_plan_id'] === $expectedPlanId, "server $label account now on its own plan Y");
}
unset($accounts);

// 8. Change Package to a name the server lacks: the panel's text, the plan is not read back.
$api = $panel($plansA, $accountsA);
$error = (string) error_of(fn() => with_api(new ChangePackage($service('shopa', 'name:Gold', '50')), $api)->execute());
check(str_contains($error, 'Hosting plan not found: Gold') && $api->endpoints() === ['GET /api/hosting-accounts/50', 'PUT /api/hosting-accounts/50'], 'Change Package to a missing plan: ' . $error);
check($accountsA[50]['hosting_plan_id'] === 3, 'account kept its plan');

// 9. A panel before 1.8.2 ignores hosting_plan in an update and answers 200: the read-back says so.
$oldAccounts = [50 => ['id' => 50, 'username' => 'shopb', 'hosting_plan_id' => 8]];
$ignoresName = fn($method, $endpoint) => $method === 'PUT' ? ['id' => 50, 'username' => 'shopb', 'hosting_plan_id' => 8] : null;
$api = $panel($plansB, $oldAccounts, $ignoresName);
$error = (string) error_of(fn() => with_api(new ChangePackage($service('shopb', 'name:X', '50')), $api)->execute());
check(str_contains($error, 'hosting account ID 50 is on the plan "Old" (ID 8), not "X"') && str_contains($error, 'AdminBolt before 1.8.2 ignores a plan sent by name'), 'old panel on Change Package: ' . $error);

// 10. The plan cannot be read back: the command fails and says so (no success on an unknown end state).
$noPlanRead = fn($method, $endpoint) => $endpoint === '/api/hosting-plans' ? throw new ApiException('API error (HTTP 500)', 500, null) : null;
$api = $panel($plansB, $accountsB, $noPlanRead);
$error = (string) error_of(fn() => with_api(new ChangePackage($service('shopb', 'name:X', '50')), $api)->execute());
check(str_contains($error, 'could not be read back to confirm it is "X"') && str_contains($error, 'API error (HTTP 500)'), 'plan read-back failure fails the command: ' . $error);

// 10b. Neither the update's answer nor the account read names a plan: unknown is a failure, not a success.
$noPlanAccounts = [50 => ['id' => 50, 'username' => 'shopb']];
$api = $panel($plansB, $noPlanAccounts, fn($method) => $method === 'PUT' ? ['id' => 50] : null);
$error = (string) error_of(fn() => with_api(new ChangePackage($service('shopb', 'name:X', '50')), $api)->execute());
check(str_contains($error, "could not be read back") && str_contains($error, 'no plan ID for the account') && $api->endpoints() === ['GET /api/hosting-accounts/50', 'PUT /api/hosting-accounts/50', 'GET /api/hosting-accounts/50'], 'no plan ID in any answer: ' . $error . ' ' . json_encode($api->endpoints()));
// The plan list does not have the account's plan.
$api = $panel([9 => ['name' => 'X']], $oldAccounts, $ignoresName);
$error = (string) error_of(fn() => with_api(new ChangePackage($service('shopb', 'name:X', '50')), $api)->execute());
check(str_contains($error, 'plan list has no plan with ID 8'), 'plan missing from the list: ' . $error);

// 11. The plan matches with another letter case (the panel's database compares names case-insensitively).
$caseAccounts = [50 => ['id' => 50, 'username' => 'shopb', 'hosting_plan_id' => 9]];
$api = $panel([9 => ['name' => 'X']], $caseAccounts, fn($method) => $method === 'PUT' ? ['id' => 50] : null);
check(with_api(new ChangePackage($service('shopb', 'name:x', '50')), $api)->execute() === 'success', 'plan name compared case-insensitively');
check(array_slice($api->endpoints(), 2) === ['GET /api/hosting-accounts/50', 'GET /api/hosting-plans'], 'an update answer without the plan ID: the account is read again: ' . json_encode($api->endpoints()));

// 12. The Hosting Plan dropdown.
class DropdownConfigOptions extends ConfigOptions
{
    public ?AdminBolt $testApi = null;

    protected function getServer(): ?stdClass
    {
        return (object) ['id' => 1, 'hostname' => 'panel-a.example.test'];
    }

    protected function getApiInstanceForServer(stdClass $server): AdminBolt
    {
        return $this->testApi;
    }
}
$dropdown = function(array $plans, string $stored, ?callable $handler = null) {
    Capsule::reset();
    Capsule::$reader = fn(string $table, array $wheres, string $operation) => match($table) {
        'tblproducts' => (object) ['configoption1' => $stored],
        'tblcustomfields' => (object) ['id' => 1],
        default => null,
    };
    $_POST['id'] = 6;
    $action = new DropdownConfigOptions(['producttype' => 'hostingaccount']);
    $action->testApi = new TestApi($handler ?? fn() => $plans);
    return $action->execute()['Hosting Plan']['Options'];
};
$listA = [['id' => 3, 'name' => 'Y', 'reseller_id' => null], ['id' => 4, 'name' => 'X', 'reseller_id' => null], ['id' => 5, 'name' => 'Z', 'reseller_id' => 2], ['id' => 6, 'name' => ' '], ['id' => 7]];
check($dropdown($listA, 'name:X') === ['name:Y' => 'Y', 'name:X' => 'X'], 'admin key: own plans keyed by name, reseller plans and nameless plans left out: ' . json_encode($dropdown($listA, 'name:X')));
$options = $dropdown(array_merge($listA, [['id' => 8, 'name' => 'X', 'reseller_id' => 0]]), 'name:Y');
check(str_starts_with($options['name:X'], 'X (2 plans have this name on panel-a.example.test'), 'duplicate name marked: ' . json_encode($options));
$options = $dropdown($listA, '4');
check((string) array_key_first($options) === '4' && $options['4'] === 'Plan ID 4 saved by module 0.0.5 or older (on panel-a.example.test it is "X"): choose the plan by name', 'stored 0.0.5 plan ID kept selectable first, with its name on the listed server: ' . json_encode($options));
$options = $dropdown($listA, 'name:Gold%20%26%20Co');
check(array_key_first($options) === 'name:Gold%20%26%20Co' && $options['name:Gold%20%26%20Co'] === 'Gold & Co (not found on panel-a.example.test)', 'stored name missing on the listed server stays selected: ' . json_encode($options));
$options = $dropdown(array_merge($listA, [['id' => 9, 'name' => 'Gold & Co', 'reseller_id' => null]]), 'name:Gold%20%26%20Co');
check(($options['name:Gold%20%26%20Co'] ?? null) === 'Gold & Co' && count($options) === 3, 'a saved name with special characters is found among the options again (no extra entry): ' . json_encode($options));
$wrongType = new ApiException('API error: API key does not have access to this resource.', 403, ['error' => 'API key does not have access to this resource.']);
$options = $dropdown([], 'name:X', fn($method, $endpoint) => str_starts_with($endpoint, '/api/reseller/') ? [['id' => 11, 'name' => 'X', 'reseller_id' => 2], ['id' => 12, 'name' => 'R2', 'reseller_id' => 2]] : throw $wrongType);
check($options === ['name:X' => 'X', 'name:R2' => 'R2'], 'reseller key: the reseller\'s own plans are listed: ' . json_encode($options));
unset($_POST['id']);

tests_done();
