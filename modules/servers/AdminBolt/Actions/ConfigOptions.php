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
            $hostingPlans = $api->get('/api/hosting-plans');

            foreach($hostingPlans as $hostingPlan)
            {
                $hostingPlansOptions[$hostingPlan['id']] = $hostingPlan['name'];
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
        $serverGroupId = $_POST['servergroup'];

        return Capsule::table('tblservers')
            ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
            ->join('tblservergroups', 'tblservergroupsrel.groupid', '=', 'tblservergroups.id')
            ->where('tblservergroups.id', '=', $serverGroupId)
            ->first([
                'tblservers.id',
                'tblservers.hostname',
                'tblservers.username',
                'tblservers.password',
                'tblservers.secure',
                'tblservers.port'
            ]);
    }

    protected function createCustomField(string $name, string $friendlyName, bool $adminOnly = false): void
    {
        $productId = $_POST['id'];

        if(!$productId)
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