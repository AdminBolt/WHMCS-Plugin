<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Api\AdminBolt;
use WHMCS\Database\Capsule;
use Exception;

abstract class AbstractAction
{
    protected ?AdminBolt $api = null;

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

    protected function getHostingAccountId(): int
    {
        $id = (int) ($this->params['customfields']['hostingAccountId'] ?? 0);

        if($id > 0)
        {
            return $id;
        }

        $domain = trim((string) ($this->params['domain'] ?? ''));
        $username = trim((string) ($this->params['username'] ?? ''));

        if($domain === '' && $username === '')
        {
            throw new Exception('Hosting Account ID is not set on this service and there is no domain or username to recover it.');
        }

        $api = $this->getApiInstance();
        $accounts = $this->extractList($api->get('/api/hosting-accounts'));

        foreach($accounts as $account)
        {
            if(!is_array($account) || !isset($account['id']))
            {
                continue;
            }

            $accountDomain = (string) ($account['domain'] ?? '');
            $accountUsername = (string) ($account['username'] ?? '');

            $domainMatch = $domain !== '' && strcasecmp($accountDomain, $domain) === 0;
            $usernameMatch = $username !== '' && strcasecmp($accountUsername, $username) === 0;

            if($domainMatch || $usernameMatch)
            {
                $id = (int) $account['id'];
                $this->saveCustomFieldValue('hostingAccountId', $id);
                return $id;
            }
        }

        throw new Exception(sprintf(
            "Hosting Account ID is not set on this service and no AdminBolt account was found (domain='%s', username='%s').",
            $domain,
            $username
        ));
    }

    protected function getResellerId(): int
    {
        $id = (int) ($this->params['customfields']['resellerId'] ?? 0);

        if($id > 0)
        {
            return $id;
        }

        $username = trim((string) ($this->params['username'] ?? ''));
        $email = trim((string) ($this->params['clientsdetails']['email'] ?? ''));

        if($username === '' && $email === '')
        {
            throw new Exception('Reseller ID is not set on this service and there is no username or email to recover it.');
        }

        $api = $this->getApiInstance();
        $resellers = $this->extractList($api->get('/api/resellers'));

        foreach($resellers as $reseller)
        {
            if(!is_array($reseller) || !isset($reseller['id']))
            {
                continue;
            }

            $rUsername = (string) ($reseller['username'] ?? '');
            $rEmail = (string) ($reseller['email'] ?? '');

            $usernameMatch = $username !== '' && strcasecmp($rUsername, $username) === 0;
            $emailMatch = $email !== '' && strcasecmp($rEmail, $email) === 0;

            if($usernameMatch || $emailMatch)
            {
                $id = (int) $reseller['id'];
                $this->saveCustomFieldValue('resellerId', $id);
                return $id;
            }
        }

        throw new Exception(sprintf(
            "Reseller ID is not set on this service and no AdminBolt reseller was found (username='%s', email='%s').",
            $username,
            $email
        ));
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
