<?php

namespace ModulesGarden\AdminBolt\Actions;

class TerminateAccount extends AbstractAction
{
    public function resellerExecute(): string
    {
        $resellerId = $this->params['customfields']['resellerId'];

        $api = $this->getApiInstance();
        $api->delete('/api/resellers/' . $resellerId);

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->params['customfields']['hostingAccountId'];

        $api = $this->getApiInstance();
        $api->delete('/api/hosting-accounts/' . $hostingAccountId);

        return 'success';
    }
}