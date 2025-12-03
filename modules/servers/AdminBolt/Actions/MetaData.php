<?php

namespace ModulesGarden\AdminBolt\Actions;

class MetaData extends AbstractAction
{
    public function execute(): array
    {
        return [
            'DisplayName' => 'AdminBolt',
            'APIVersion' => '0.0.1',
            'RequiresServer' => true,
            'DefaultNonSSLPort' => '8443',
            'DefaultSSLPort' => '8443'
        ];
    }
}