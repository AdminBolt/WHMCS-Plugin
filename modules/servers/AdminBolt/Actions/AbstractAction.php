<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Api\AdminBolt;
use ModulesGarden\AdminBolt\Api\ApiException;
use WHMCS\Database\Capsule;
use Exception;

abstract class AbstractAction
{
    protected ?AdminBolt $api = null;

    /**
     * The panel reseller read by getVerifiedResellerId().
     */
    protected array $verifiedReseller = [];

    public function __construct(
        protected array $params = []
    ) {}

    public function execute(): array|string
    {
        if($this->params['producttype'] == "reselleraccount")
        {
            return $this->resellerExecute();
        }

        return $this->sharedExecute();
    }

    public function sharedExecute(): array|string
    {
        throw new Exception('sharedExecute() method not implemented');
    }

    public function resellerExecute(): array|string
    {
        throw new Exception('resellerExecute() method not implemented');
    }

    protected function getApiInstance(): AdminBolt
    {
        if(!$this->api)
        {
            $this->api = new AdminBolt(
                $this->params['serverhttpprefix'] . "://" . $this->params['serverhostname'] . ':' . $this->params['serverport'],
                $this->params['serverusername'],
                $this->params['serverpassword'],
            );
        }

        return $this->api;
    }

    /**
     * Runs a panel API call and, when the panel refuses it, fails with the
     * panel's own reason (for example a 422 limit below the reseller's
     * current allocation) instead of a bare "API error".
     */
    protected function withPanelReason(callable $call): mixed
    {
        try
        {
            return $call();
        }
        catch(ApiException $e)
        {
            throw new Exception($e->getPanelReason(), 0, $e);
        }
    }

    /**
     * Runs a create or update call that sends the plan by name and, when the
     * panel refuses it, fails with the panel's reason (for example "Hosting
     * plan not found: <name>" or "Hosting plan name is ambiguous, use
     * hosting_plan_id: <name>"). A panel that still requires the plan ID
     * (AdminBolt before 1.8.2) gets a message that says so.
     */
    protected function withPlanReason(callable $call, string $outcome): mixed
    {
        try
        {
            return $call();
        }
        catch(ApiException $e)
        {
            if($e->getHttpCode() === 422 && self::panelRequiresPlanId($e))
            {
                throw new Exception(sprintf(
                    'This AdminBolt server does not take the hosting plan by name, which AdminBolt 1.8.2 and newer do. %s Update AdminBolt on this server. (%s)',
                    $outcome,
                    $e->getPanelReason()
                ), 0, $e);
            }

            throw new Exception($e->getPanelReason(), 0, $e);
        }
    }

    /**
     * A validation refusal that asks for hosting_plan_id although the module
     * sent hosting_plan: the panel does not know the field.
     */
    protected static function panelRequiresPlanId(ApiException $e): bool
    {
        $errors = $e->getResponseBody()['errors'] ?? null;

        if(!is_array($errors) || isset($errors['hosting_plan']) || !isset($errors['hosting_plan_id']))
        {
            return false;
        }

        foreach((array) $errors['hosting_plan_id'] as $message)
        {
            if(is_string($message) && stripos($message, 'required') !== false)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * After a package change: the account must be on a plan with the name
     * that was sent. A panel before AdminBolt 1.8.2 ignores the plan name in
     * an update and answers success, so the answer alone does not show it.
     * The plan ID comes from the update's answer (the account as saved; read
     * again only when the answer lacks it) and its name from the plan list,
     * so the check needs no API endpoint that module 0.0.5 did not call.
     */
    protected function confirmAccountPlan(int $hostingAccountId, string $planName, mixed $updateAnswer): void
    {
        $api = $this->getApiInstance();
        $planIdOf = fn(mixed $account) => is_array($account)
            ? (int) ($account['hosting_plan_id'] ?? $account['hostingAccount']['hosting_plan_id'] ?? $account['data']['hosting_plan_id'] ?? 0)
            : 0;

        try
        {
            $planId = $planIdOf($updateAnswer);

            if($planId <= 0)
            {
                $planId = $planIdOf($api->get('/api/hosting-accounts/' . $hostingAccountId));
            }

            if($planId <= 0)
            {
                throw new Exception("the panel's answer has no plan ID for the account");
            }

            $currentName = null;
            foreach($this->extractList($api->get('/api/hosting-plans')) as $plan)
            {
                if(is_array($plan) && (int) ($plan['id'] ?? 0) === $planId && is_string($plan['name'] ?? null))
                {
                    $currentName = $plan['name'];
                    break;
                }
            }

            if($currentName === null)
            {
                throw new Exception(sprintf("the panel's plan list has no plan with ID %d", $planId));
            }
        }
        catch(Exception $e)
        {
            throw new Exception(sprintf(
                'The panel accepted the package change, but the plan of hosting account ID %d could not be read back to confirm it is "%s": %s. Check the account\'s plan in the panel.',
                $hostingAccountId,
                $planName,
                rtrim($e instanceof ApiException ? $e->getPanelReason() : $e->getMessage(), '.')
            ), 0, $e);
        }

        if(mb_strtolower(trim($currentName)) !== mb_strtolower(trim($planName)))
        {
            throw new Exception(sprintf(
                'The panel answered the package change, but hosting account ID %d is on the plan "%s" (ID %d), not "%s". AdminBolt before 1.8.2 ignores a plan sent by name, so check the AdminBolt version of this server. The SSH access setting was sent in the same request.',
                $hostingAccountId,
                $currentName,
                $planId,
                $planName
            ));
        }
    }

    /**
     * The hosting account ID stored on the service. When it is empty, it is
     * recovered only from a panel account whose domain and username both
     * equal the service's (case-insensitive), and only when exactly one
     * account matches; otherwise the command fails before any change, so it
     * cannot reach another customer's account with the same username.
     */
    protected function getHostingAccountId(): int
    {
        $id = (int) ($this->params['customfields']['hostingAccountId'] ?? 0);

        if($id > 0)
        {
            return $id;
        }

        $domain = trim((string) ($this->params['domain'] ?? ''));
        $username = trim((string) ($this->params['username'] ?? ''));

        if($domain === '' || $username === '')
        {
            throw new Exception("This service has no AdminBolt hosting account ID and no domain and username to find it; set the Hosting Account ID field to the panel's hosting account ID.");
        }

        $api = $this->getApiInstance();
        $matches = [];

        foreach($this->extractList($api->get('/api/hosting-accounts')) as $account)
        {
            if(!is_array($account) || !isset($account['id']))
            {
                continue;
            }

            if(strcasecmp((string) ($account['domain'] ?? ''), $domain) === 0
                && strcasecmp((string) ($account['username'] ?? ''), $username) === 0)
            {
                $matches[] = (int) $account['id'];
            }
        }

        if(count($matches) !== 1)
        {
            throw new Exception(sprintf(
                "This service has no AdminBolt hosting account ID and %s panel account has the domain '%s' and the username '%s'; nothing was changed. Set the Hosting Account ID field to the panel's hosting account ID.",
                $matches ? 'more than one' : 'no',
                $domain,
                $username
            ));
        }

        $this->saveCustomFieldValue('hostingAccountId', $matches[0]);

        return $matches[0];
    }

    /**
     * The hosting account ID, checked against the panel before any command
     * that acts on the account (package change, suspend, unsuspend,
     * terminate, login, password change): the panel account must have this service's
     * username, so a mistyped ID cannot reach another customer's account.
     */
    protected function getVerifiedHostingAccountId(): int
    {
        $hostingAccountId = $this->getHostingAccountId();
        $serviceUsername = trim((string) ($this->params['username'] ?? ''));

        $account = $this->getApiInstance()->get('/api/hosting-accounts/' . $hostingAccountId);
        $panelUsername = '';

        if(is_array($account))
        {
            $panelUsername = (string) ($account['username'] ?? $account['hostingAccount']['username'] ?? $account['data']['username'] ?? '');
        }

        if($serviceUsername === '' || strcasecmp($panelUsername, $serviceUsername) !== 0)
        {
            throw new Exception(sprintf(
                'Hosting account ID %d belongs to the panel account "%s", but this service\'s username is "%s". Nothing was changed in the panel; check the Hosting Account ID field and the username of this service.',
                $hostingAccountId,
                $panelUsername,
                $serviceUsername
            ));
        }

        return $hostingAccountId;
    }

    /**
     * The reseller ID stored on the service. It is never looked up by
     * username or e-mail: the panel reseller with the client's e-mail can be
     * one made outside WHMCS or one of another client after an e-mail change,
     * and a match would act on that reseller.
     */
    protected function getResellerId(): int
    {
        $id = trim((string) ($this->params['customfields']['resellerId'] ?? ''));

        if(!ctype_digit($id) || (int) $id <= 0)
        {
            throw new Exception("This service has no AdminBolt reseller ID; set the resellerId field to the panel's reseller ID.");
        }

        return (int) $id;
    }

    /**
     * The stored reseller ID, checked against the panel before any command
     * that acts on the reseller (package change, suspend, unsuspend,
     * terminate, login, password change): the panel reseller with that ID must have this
     * service's username, so a mistyped Reseller ID cannot reach another
     * reseller.
     */
    protected function getVerifiedResellerId(): int
    {
        $resellerId = $this->getResellerId();
        $serviceUsername = trim((string) ($this->params['username'] ?? ''));

        $reseller = $this->getApiInstance()->get('/api/resellers/' . $resellerId);
        $panelUsername = '';

        if(is_array($reseller))
        {
            $panelUsername = (string) ($reseller['username'] ?? $reseller['reseller']['username'] ?? $reseller['data']['username'] ?? '');
        }

        if($serviceUsername === '' || strcasecmp($panelUsername, $serviceUsername) !== 0)
        {
            $this->verifiedReseller = [];

            throw new Exception(sprintf(
                'Reseller ID %d belongs to the panel reseller "%s", but this service\'s username is "%s". Nothing was changed in the panel; check the Reseller ID field and the username of this service.',
                $resellerId,
                $panelUsername,
                $serviceUsername
            ));
        }

        $this->verifiedReseller = isset($reseller['username']) ? $reseller : (array) ($reseller['reseller'] ?? $reseller['data'] ?? []);

        return $resellerId;
    }

    protected function extractList(mixed $response): array
    {
        if(!is_array($response))
        {
            return [];
        }

        if($this->looksLikeList($response))
        {
            return $response;
        }

        foreach(['data', 'items', 'results', 'hostingAccounts', 'hosting_accounts', 'resellers', 'hostingPlans', 'hosting_plans', 'plans'] as $key)
        {
            if(isset($response[$key]) && is_array($response[$key]) && $this->looksLikeList($response[$key]))
            {
                return $response[$key];
            }
        }

        if(isset($response['data']) && is_array($response['data']))
        {
            foreach(['data', 'items', 'results', 'hostingAccounts', 'hosting_accounts', 'resellers', 'hostingPlans', 'hosting_plans', 'plans'] as $key)
            {
                if(isset($response['data'][$key]) && is_array($response['data'][$key]) && $this->looksLikeList($response['data'][$key]))
                {
                    return $response['data'][$key];
                }
            }
        }

        if(isset($response['id']))
        {
            return [$response];
        }

        return [];
    }

    protected function looksLikeList(array $value): bool
    {
        if(empty($value))
        {
            return true;
        }

        if(array_keys($value) !== range(0, count($value) - 1))
        {
            $first = reset($value);
            return is_array($first) && isset($first['id']);
        }

        $first = $value[0];
        return is_array($first) && isset($first['id']);
    }

    protected function saveCustomFieldValue(string $customFieldName, string|int $value): void
    {
        $serviceId = (int) ($this->params['serviceid'] ?? 0);
        $packageId = (int) ($this->params['packageid'] ?? 0);

        if($serviceId <= 0 || $packageId <= 0)
        {
            return;
        }

        $customFieldValue = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.type', '=', 'product')
            ->where('tblcustomfields.relid', '=', $packageId)
            ->where('tblcustomfieldsvalues.relid', '=', $serviceId)
            ->where('tblcustomfields.fieldname', 'LIKE', "$customFieldName|%")
            ->first(['tblcustomfieldsvalues.id']);

        if($customFieldValue)
        {
            Capsule::table('tblcustomfieldsvalues')
                ->where('id', '=', $customFieldValue->id)
                ->update([
                    'value' => (string) $value
                ]);

            $this->params['customfields'][$customFieldName] = (string) $value;
            return;
        }

        $customField = Capsule::table('tblcustomfields')
            ->where('type', '=', 'product')
            ->where('relid', '=', $packageId)
            ->where('fieldname', 'LIKE', "$customFieldName|%")
            ->first(['id']);

        if(!$customField)
        {
            throw new Exception("Custom field $customFieldName does not exist");
        }

        Capsule::table('tblcustomfieldsvalues')
            ->insert([
                'fieldid' => $customField->id,
                'relid' => $serviceId,
                'value' => (string) $value
            ]);

        $this->params['customfields'][$customFieldName] = (string) $value;
    }
}
