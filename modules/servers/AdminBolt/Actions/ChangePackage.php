<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Helpers\HostingPlanSetting;
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

        $resellerId = $this->getVerifiedResellerId();

        $api = $this->getApiInstance();
        $this->withPanelReason(fn() => $api->put('/api/resellers/' . $resellerId, $limits));

        return 'success';
    }

    public function sharedExecute(): string
    {
        $planName = HostingPlanSetting::nameFrom($this->params['configoption1'] ?? '');
        $hostingAccountId = $this->getVerifiedHostingAccountId();

        $api = $this->getApiInstance();
        $answer = $this->withPlanReason(fn() => $api->put('/api/hosting-accounts/' . $hostingAccountId, [
            'hosting_plan' => $planName,
            'ssh_access' => $this->params['configoption2'] == 'on'
        ]), 'Nothing was changed.');

        $this->confirmAccountPlan($hostingAccountId, $planName, $answer);

        return 'success';
    }
}