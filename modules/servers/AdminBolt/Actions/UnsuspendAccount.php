<?php

namespace ModulesGarden\AdminBolt\Actions;

class UnsuspendAccount extends AbstractAction
{
    /**
     * Unsuspends the reseller in the panel, together with the hosting
     * accounts suspended with it. A panel error is thrown and reported by WHMCS.
     */
    public function resellerExecute(): string
    {
        $resellerId = $this->getResellerId();

        $api = $this->getApiInstance();
        $api->post('/api/resellers/' . $resellerId . '/unsuspend');

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getHostingAccountId();

        $api = $this->getApiInstance();
        $api->post('/api/hosting-accounts/' . $hostingAccountId . '/unsuspend');

        return 'success';
    }
}