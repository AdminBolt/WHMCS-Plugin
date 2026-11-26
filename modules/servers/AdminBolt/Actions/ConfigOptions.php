<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Api\AdminBolt;
use ModulesGarden\AdminBolt\Helpers\HostingPlanSetting;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;
use ModulesGarden\AdminBolt\Helpers\ResellerLimits;
use WHMCS\Database\Capsule;
use Exception;
use stdClass;

class ConfigOptions extends AbstractAction
{
    public function resellerExecute(): array
    {
        $this->createCustomField('resellerId', 'Reseller ID', true);

        return ResellerLimits::configOptions();
    }

    public function sharedExecute(): array
    {
        $this->createCustomField('hostingAccountId', 'Hosting Account ID', true);

        $hostingPlansOptions = [];
        $listedPlanNames = [];

        $server = $this->getServer();
        $api = $server ? $this->getApiInstanceForServer($server) : null;
        if($api)
        {
            $response = $api->get('/api/hosting-plans');
            $hostingPlans = $this->extractHostingPlans($response);

            // With an administrator key the list has every plan, the
            // resellers' plans too, while the panel finds a plan name only
            // among the administrator's own plans; a reseller's key lists
            // only the reseller's plans.
            $countByName = [];
            foreach($hostingPlans as $hostingPlan)
            {
                if(!is_array($hostingPlan) || !isset($hostingPlan['id']) || !is_string($hostingPlan['name'] ?? null) || trim($hostingPlan['name']) === '')
                {
                    continue;
                }

                if($api->keyType() === AdminBolt::KEY_ADMIN && !empty($hostingPlan['reseller_id']))
                {
                    continue;
                }

                $name = trim($hostingPlan['name']);
                $listedPlanNames[(int) $hostingPlan['id']] = $name;
                $countByName[$name] = ($countByName[$name] ?? 0) + 1;
            }

            foreach($countByName as $name => $count)
            {
                $hostingPlansOptions[HostingPlanSetting::forName($name)] = $count > 1
                    ? sprintf('%s (%d plans have this name on %s, so Create and Change Package fail there until one is renamed)', $name, $count, $server->hostname)
                    : $name;
            }
        }

        // The product's current setting stays selectable, so saving the
        // product page does not switch it to the first plan of the list.
        $current = $this->currentPlanSetting();
        if($current !== '' && !isset($hostingPlansOptions[$current]))
        {
            $legacyId = HostingPlanSetting::legacyPlanId($current);

            if($legacyId !== null)
            {
                $hostingPlansOptions = [$current => sprintf(
                    'Plan ID %d saved by module 0.0.5 or older%s: choose the plan by name',
                    $legacyId,
                    isset($listedPlanNames[$legacyId]) ? sprintf(' (on %s it is "%s")', $server->hostname, $listedPlanNames[$legacyId]) : ''
                )] + $hostingPlansOptions;
            }
            elseif(($storedName = HostingPlanSetting::storedName($current)) !== null)
            {
                $hostingPlansOptions = [$current => sprintf(
                    '%s (not found on %s)',
                    $storedName,
                    $server->hostname ?? 'the AdminBolt server'
                )] + $hostingPlansOptions;
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

        ModuleLog::call('ConfigOptions/hostingPlans', '', json_encode($response), 'Unexpected hosting-plans response shape');

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

    /**
     * The Hosting Plan setting saved on the product, as WHMCS stores it.
     */
    protected function currentPlanSetting(): string
    {
        $productId = (int) ($_POST['id'] ?? 0);

        if($productId <= 0)
        {
            return '';
        }

        $product = Capsule::table('tblproducts')
            ->where('id', '=', $productId)
            ->where('servertype', '=', 'AdminBolt')
            ->first(['configoption1']);

        return $product ? trim((string) $product->configoption1) : '';
    }

    protected function getApiInstanceForServer(stdClass $server): AdminBolt
    {
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