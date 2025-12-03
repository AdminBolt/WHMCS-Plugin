<?php


namespace ModulesGarden\AdminBolt\Actions;

class AdminSingleSignOn extends AbstractAction
{
    public function execute(): array
    {
        $api = $this->getApiInstance();
        $result = $api->post('/api/admin/generate-sso-token');

        return [
            'success' => true,
            'redirectTo' => $result['sso_url']
        ];
    }
}