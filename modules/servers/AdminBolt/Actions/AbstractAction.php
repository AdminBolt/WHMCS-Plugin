<?php

namespace ModulesGarden\AdminBolt\Actions;

use ModulesGarden\AdminBolt\Api\AdminBolt;
use Exception;

abstract class AbstractAction
{
    protected ?AdminBolt $api = null;

    public function __construct(
        protected array $params = []
    ) {}

    public function execute(): array|string
    {
        if($this->params['producttype'] == "reselleraccount")
        {
            return $this->resellerExecute();
        }

        return $this->sharedExecute();
    }

    public function sharedExecute(): array|string
    {
        throw new Exception('sharedExecute() method not implemented');
    }

    public function resellerExecute(): array|string
    {
        throw new Exception('resellerExecute() method not implemented');
    }

    protected function getApiInstance(): AdminBolt
    {
        if(!$this->api)
        {
            $this->api = new AdminBolt(
                $this->params['serverhttpprefix'] . "://" . $this->params['serverhostname'] . ':' . $this->params['serverport'],
                $this->params['serverusername'],
                $this->params['serverpassword'],
            );
        }

        return $this->api;
    }
}