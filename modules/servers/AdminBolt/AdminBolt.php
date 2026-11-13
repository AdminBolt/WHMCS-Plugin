<?php

if(!defined('WHMCS'))
{
    die('This file cannot be accessed directly');
}

require_once __DIR__ . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";

use ModulesGarden\AdminBolt\Actions\ChangePackage;
use ModulesGarden\AdminBolt\Actions\ChangePassword;
use ModulesGarden\AdminBolt\Actions\ConfigOptions;
use ModulesGarden\AdminBolt\Actions\CreateAccount;
use ModulesGarden\AdminBolt\Actions\MetaData;
use ModulesGarden\AdminBolt\Actions\SuspendAccount;
use ModulesGarden\AdminBolt\Actions\TerminateAccount;
use ModulesGarden\AdminBolt\Actions\TestConnection;
use ModulesGarden\AdminBolt\Actions\UnsuspendAccount;
use ModulesGarden\AdminBolt\Actions\UsageUpdate;
use ModulesGarden\AdminBolt\Actions\ServiceSingleSignOn;
use ModulesGarden\AdminBolt\Actions\AdminSingleSignOn;
use ModulesGarden\AdminBolt\Actions\ClientArea;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;

function AdminBolt_MetaData(): array
{
    $action = new MetaData();
    return $action->execute();
}

function AdminBolt_ConfigOptions(array $params): array
{
    try
    {
        $action = new ConfigOptions($params);
        return $action->execute();
    }
    catch (Exception $e)
    {
        ModuleLog::error('ConfigOptions', $params, $e->getMessage());

        return [];
    }
}

function AdminBolt_CreateAccount(array $params): string
{
    try
    {
        $action = new CreateAccount($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('CreateAccount', $params, $e->getMessage());

        return $e->getMessage();
    }
}

function AdminBolt_SuspendAccount(array $params): string
{
    try
    {
        $action = new SuspendAccount($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('SuspendAccount', $params, $e->getMessage());

        return $e->getMessage();
    }
}

function AdminBolt_UnsuspendAccount(array $params): string
{
    try
    {
        $action = new UnsuspendAccount($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('UnsuspendAccount', $params, $e->getMessage());

        return $e->getMessage();
    }
}

function AdminBolt_TerminateAccount(array $params): string
{
    try
    {
        $action = new TerminateAccount($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('TerminateAccount', $params, $e->getMessage());

        return $e->getMessage();
    }
}

function AdminBolt_ChangePassword(array $params): string
{
    try
    {
        $action = new ChangePassword($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('ChangePassword', $params, $e->getMessage());

        return $e->getMessage();
    }
}

function AdminBolt_ChangePackage(array $params): string
{
    try
    {
        $action = new ChangePackage($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('ChangePackage', $params, $e->getMessage());

        return $e->getMessage();
    }
}

function AdminBolt_TestConnection(array $params): array
{
    try
    {
        $action = new TestConnection($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('TestConnection', $params, $e->getMessage());

        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function AdminBolt_AdminSingleSignOn(array $params): array
{
    try
    {
        $action = new AdminSingleSignOn($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('AdminSingleSignOn', $params, $e->getMessage());

        return [
            'success' => false,
            'errorMsg' => $e->getMessage()
        ];
    }
}

function AdminBolt_ServiceSingleSignOn(array $params): array
{
    try
    {
        $action = new ServiceSingleSignOn($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('ServiceSingleSignOn', $params, $e->getMessage());

        return [
            'success' => false,
            'errorMsg' => $e->getMessage()
        ];
    }
}

function AdminBolt_UsageUpdate(array $params): void
{
    try
    {
        $action = new UsageUpdate($params);
        $action->execute();
    }
    catch(\Throwable $e)
    {
        ModuleLog::error('UsageUpdate', $params, $e->getMessage());
    }
}

function AdminBolt_ClientArea(array $params): array
{
    try
    {
        $action = new ClientArea($params);
        return $action->execute();
    }
    catch(Exception $e)
    {
        ModuleLog::error('ClientArea', $params, $e->getMessage());

        return [];
    }
}