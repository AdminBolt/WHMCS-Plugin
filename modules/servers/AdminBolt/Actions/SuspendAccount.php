<?php

namespace ModulesGarden\AdminBolt\Actions;

class SuspendAccount extends AbstractAction
{
    /**
     * Suspends the reseller in the panel, which also suspends its hosting
     * accounts. A panel error is thrown and reported by WHMCS.
     */
    public function resellerExecute(): string
    {
        $resellerId = $this->getVerifiedResellerId();

        $reason = trim((string) ($this->params['suspendreason'] ?? ''));

        $api = $this->getApiInstance();
        $api->post('/api/resellers/' . $resellerId . '/suspend', $reason !== '' ? ['reason' => $reason] : null);

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getVerifiedHostingAccountId();

        $api = $this->getApiInstance();
        $api->post('/api/hosting-accounts/' . $hostingAccountId . '/suspend');

        return 'success';
    }
}