<?php

namespace ModulesGarden\AdminBolt\Actions;

class TerminateAccount extends AbstractAction
{
    public function resellerExecute(): string
    {
        $resellerId = $this->getResellerId();

        $api = $this->getApiInstance();
        $api->delete('/api/resellers/' . $resellerId);

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getHostingAccountId();

        $api = $this->getApiInstance();
        $api->delete('/api/hosting-accounts/' . $hostingAccountId);

        return 'success';
    }
}