<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Api\AdminBolt;
use WHMCS\Database\Capsule;
use Exception;
use stdClass;

class ConfigOptions extends AbstractAction
{
    public function resellerExecute(): array
    {
        $this->createCustomField('resellerId', 'Reseller ID', true);

        return [];
    }

    public function sharedExecute(): array
    {
        $this->createCustomField('hostingAccountId', 'Hosting Account ID', true);

        $hostingPlansOptions = [];

        $api = $this->getApiInstanceFromFirstServer();
        if($api)
        {
            $response = $api->get('/api/hosting-plans');
            $hostingPlans = $this->extractHostingPlans($response);

            foreach($hostingPlans as $hostingPlan)
            {
                if(!is_array($hostingPlan) || !isset($hostingPlan['id']))
                {
                    continue;
                }

                $name = $hostingPlan['name'] ?? ('Plan #' . $hostingPlan['id']);
                $hostingPlansOptions[$hostingPlan['id']] = $name;
            }
        }

        return [
            'Hosting Plan' => [
                'Type' => 'dropdown',
                'Options' => $hostingPlansOptions,
            ],
            'SSH Access' => [
                'Type' => 'yesno'
            ]
        ];
    }

    protected function extractHostingPlans(mixed $response): array
    {
        if(!is_array($response))
        {
            return [];
        }

        if($this->isListOfPlans($response))
        {
            return $response;
        }

        foreach(['hostingPlans', 'hosting_plans', 'data', 'plans', 'items', 'results'] as $key)
        {
            if(isset($response[$key]) && is_array($response[$key]) && $this->isListOfPlans($response[$key]))
            {
                return $response[$key];
            }
        }

        if(isset($response['data']) && is_array($response['data']))
        {
            foreach(['hostingPlans', 'hosting_plans', 'plans', 'items'] as $key)
            {
                if(isset($response['data'][$key]) && is_array($response['data'][$key]) && $this->isListOfPlans($response['data'][$key]))
                {
                    return $response['data'][$key];
                }
            }
        }

        if(isset($response['id']))
        {
            return [$response];
        }

        \logModuleCall('AdminBolt', 'ConfigOptions/hostingPlans', '', json_encode($response), 'Unexpected hosting-plans response shape');

        return [];
    }

    protected function isListOfPlans(array $value): bool
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

    protected function getApiInstanceFromFirstServer(): ?AdminBolt
    {
        $server = $this->getServer();
        if(!$server)
        {
            return null;
        }

        $httpPrefix = $server->secure == "on" ? 'https' : 'http';
        $port = $server->port ?? '8443';

        $resultDecryptPassword = localAPI('DecryptPassword', [
            'password2' => $server->password
        ]);

        if($resultDecryptPassword['result'] != 'success')
        {
            throw new Exception('Local API: ' . $resultDecryptPassword['message']);
        }

        return new AdminBolt(
            $httpPrefix . "://" . $server->hostname . ':' . $port,
            $server->username,
            $resultDecryptPassword['password'],
        );
    }

    protected function getServer(): ?stdClass
    {
        $columns = [
            'tblservers.id',
            'tblservers.hostname',
            'tblservers.username',
            'tblservers.password',
            'tblservers.secure',
            'tblservers.port'
        ];

        $serverGroupId = (int) ($_POST['servergroup'] ?? 0);

        if($serverGroupId > 0)
        {
            $server = Capsule::table('tblservers')
                ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
                ->join('tblservergroups', 'tblservergroupsrel.groupid', '=', 'tblservergroups.id')
                ->where('tblservergroups.id', '=', $serverGroupId)
                ->where('tblservers.type', '=', 'AdminBolt')
                ->where('tblservers.disabled', '=', 0)
                ->first($columns);

            if($server)
            {
                return $server;
            }
        }

        return Capsule::table('tblservers')
            ->where('tblservers.type', '=', 'AdminBolt')
            ->where('tblservers.disabled', '=', 0)
            ->orderBy('tblservers.id', 'asc')
            ->first($columns);
    }

    protected function createCustomField(string $name, string $friendlyName, bool $adminOnly = false): void
    {
        $productId = (int) ($_POST['id'] ?? 0);

        if($productId <= 0)
        {
            return;
        }

        $customField = Capsule::table('tblcustomfields')
            ->where('type', '=', 'product')
            ->where('relid', '=', $productId)
            ->where('fieldname', 'LIKE', "$name|%")
            ->first(['id']);

        if(!$customField)
        {
            Capsule::table('tblcustomfields')
                ->insert([
                    'type' => 'product',
                    'relid' => $productId,
                    'fieldname' => "$name|$friendlyName",
                    'fieldtype' => 'text',
                    'adminonly' => $adminOnly ? 'on' : '',
                    'sortorder' => 0
                ]);
        }
    }
}