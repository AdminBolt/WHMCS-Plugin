<?php

namespace ModulesGarden\AdminBolt\Actions;

class SuspendAccount extends AbstractAction
{
    public function resellerExecute(): string
    {
        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getHostingAccountId();

        $api = $this->getApiInstance();
        $api->post('/api/hosting-accounts/' . $hostingAccountId . '/suspend');

        return 'success';
    }
}