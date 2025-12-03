<?php


namespace ModulesGarden\AdminBolt\Actions;

class ServiceSingleSignOn extends AbstractAction
{
    public function sharedExecute(): array
    {
        $hostingAccountId = $this->params['customfields']['hostingAccountId'];

        $api = $this->getApiInstance();
        $result = $api->post('/api/hosting-accounts/' . $hostingAccountId . '/generate-sso-token');

        return [
            'success' => true,
            'redirectTo' => $result['sso_url']
        ];
    }
}