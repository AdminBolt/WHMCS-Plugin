<?php


namespace ModulesGarden\AdminBolt\Actions;

use Exception;

class ServiceSingleSignOn extends AbstractAction
{
    public function resellerExecute(): array
    {
        $resellerId = $this->getVerifiedResellerId();

        $api = $this->getApiInstance();
        $result = $this->withPanelReason(fn() => $api->post('/api/resellers/' . $resellerId . '/generate-sso-token'));

        if(empty($result['sso_url']) || !is_string($result['sso_url']))
        {
            throw new Exception('The panel did not return a reseller sign-in link.');
        }

        return [
            'success' => true,
            'redirectTo' => $result['sso_url']
        ];
    }

    public function sharedExecute(): array
    {
        $hostingAccountId = $this->getVerifiedHostingAccountId();

        $api = $this->getApiInstance();
        $result = $api->post('/api/hosting-accounts/' . $hostingAccountId . '/generate-sso-token');

        return [
            'success' => true,
            'redirectTo' => $result['sso_url']
        ];
    }
}