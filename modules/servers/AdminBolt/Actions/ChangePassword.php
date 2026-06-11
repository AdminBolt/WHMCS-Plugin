<?php

namespace ModulesGarden\AdminBolt\Actions;

class ChangePassword extends AbstractAction
{
    public function resellerExecute(): string
    {
        $resellerId = $this->getResellerId();

        $api = $this->getApiInstance();

        $existing = $api->get('/api/resellers/' . $resellerId) ?? [];

        $api->put('/api/resellers/' . $resellerId, [
            'name' => $this->params['clientsdetails']['fullname'] ?? ($existing['name'] ?? ''),
            'username' => $this->params['username'] ?? ($existing['username'] ?? ''),
            'email' => $this->params['clientsdetails']['email'] ?? ($existing['email'] ?? ''),
            'password' => $this->params['password'],
        ]);

        return 'success';
    }

    public function sharedExecute(): string
    {
        $hostingAccountId = $this->getHostingAccountId();

        $api = $this->getApiInstance();
        $api->post('/api/hosting-accounts/' . $hostingAccountId . '/change-password', [
            'password' => $this->params['password']
        ]);

        return 'success';
    }
}