<?php

namespace ModulesGarden\AdminBolt\Actions;

class TestConnection extends AbstractAction
{
    public function execute(): array
    {
        $api = $this->getApiInstance();
        $api->get('/api/hosting-plans');

        return [
            'success' => true
        ];
    }
}