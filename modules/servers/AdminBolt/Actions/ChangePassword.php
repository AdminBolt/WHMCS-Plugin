<?php

namespace ModulesGarden\AdminBolt\Actions;

class ChangePassword extends AbstractAction
{
    public function resellerExecute(): string
    {
        $resellerId = $this->params['customfields']['resellerId'];

        $api = $this->getApiInstance();
        $api->put('/api/resellers/' . $resellerId, [
            'password' => $this->params['password']
        ]);

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->params['customfields']['hostingAccountId'];

        $api = $this->getApiInstance();
        $api->post('/api/hosting-accounts/' . $hostingAccountId . '/change-password', [
            'password' => $this->params['password']
        ]);

        return 'success';
    }
}