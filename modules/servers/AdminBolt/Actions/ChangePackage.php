<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Helpers\ResellerLimits;

class ChangePackage extends AbstractAction
{
    public function resellerExecute(): string
    {
        $limits = ResellerLimits::fromParams($this->params);

        if(empty($limits))
        {
            return 'success';
        }

        $resellerId = $this->getResellerId();

        $api = $this->getApiInstance();
        $this->withPanelReason(fn() => $api->put('/api/resellers/' . $resellerId, $limits));

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getHostingAccountId();

        $api = $this->getApiInstance();
        $api->put('/api/hosting-accounts/' . $hostingAccountId, [
            'hosting_plan_id' => $this->params['configoption1'],
            'ssh_access' => $this->params['configoption2'] == 'on'
        ]);

        return 'success';
    }
}