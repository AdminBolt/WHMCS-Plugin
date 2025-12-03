<?php

namespace ModulesGarden\AdminBolt\Actions;

class ChangePackage extends AbstractAction
{
    public function resellerExecute(): string
    {
        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->params['customfields']['hostingAccountId'];

        $api = $this->getApiInstance();
        $api->put('/api/hosting-accounts/' . $hostingAccountId, [
            'hosting_plan_id' => $this->params['configoption1'],
            'ssh_access' => $this->params['configoption2'] == 'on'
        ]);

        return 'success';
    }
}