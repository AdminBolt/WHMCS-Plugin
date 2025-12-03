<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Helpers\Lang;

class ClientArea extends AbstractAction
{
    public function resellerExecute(): array
    {
        return [
            'templatefile' => 'templates/reseller/index.tpl',
            'vars' => []
        ];
    }

    public function sharedExecute(): array
    {
        return [
            'templatefile' => 'templates/shared/index.tpl',
            'vars' => [
                'serviceId' => $this->params['serviceid'],
                'abLang' => new Lang()
            ]
        ];
    }
}