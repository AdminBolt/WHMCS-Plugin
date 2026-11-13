<?php

namespace ModulesGarden\AdminBolt\Actions;

use Exception;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;
use WHMCS\Database\Capsule;
use Carbon\Carbon;

class UsageUpdate extends AbstractAction
{
    public function execute(): string
    {
        $services = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblproducts.type', '!=', 'reselleraccount')
            ->where('tblproducts.servertype', '=', 'AdminBolt')
            ->where('tblhosting.server', '=', $this->params['serverid'])
            ->get([
                'tblhosting.id'
            ]);

        foreach($services as $service)
        {
            try
            {
                $this->updateService($service->id);
            }
            catch(\Throwable $e)
            {
                ModuleLog::call('UsageUpdate', 'Service ID: ' . $service->id, $e->getMessage());
            }
        }

        return 'success';
    }

    protected function updateService(int $serviceId): void
    {
        $hostingAccountId = $this->getStoredHostingAccountId($serviceId);

        if(!$hostingAccountId)
        {
            return;
        }

        $api = $this->getApiInstance();
        $result = $api->get('/api/hosting-accounts/' . $hostingAccountId . '/usage-details');

        Capsule::table('tblhosting')
            ->where('id', '=', $serviceId)
            ->update([
                'diskusage' => $result['usageDetails']['diskUsage'],
                'disklimit' => $result['usageDetails']['totalDiskUsage'],
                'bwusage' => $result['usageDetails']['bandwidth'],
                'bwlimit' => $result['usageDetails']['totalBandwidth'],
                'lastupdate' => Carbon::now()
            ]);
    }

    protected function getStoredHostingAccountId(int $serviceId): ?int
    {
        $customField = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.type', '=', 'product')
            ->where('tblcustomfields.fieldname', 'LIKE', 'hostingAccountId|%')
            ->where('tblcustomfieldsvalues.relid', '=', $serviceId)
            ->first([
                'tblcustomfieldsvalues.value'
            ]);

        return $customField && is_numeric($customField->value)
            ? (int) $customField->value
            : null;
    }
}