<?php

namespace ModulesGarden\AdminBolt\Actions;

class UnsuspendAccount extends AbstractAction
{
    public function resellerExecute(): string
    {
        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->params['customfields']['hostingAccountId'];

        $api = $this->getApiInstance();
        $api->post('/api/hosting-accounts/' . $hostingAccountId . '/unsuspend');

        return 'success';
    }
}