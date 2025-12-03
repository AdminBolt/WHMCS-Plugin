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
        \logModuleCall('AdminBolt', 'ConfigOptions', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'CreateAccount', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'SuspendAccount', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'UnsuspendAccount', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'TerminateAccount', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'ChangePassword', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'ChangePackage', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'TestConnection', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'AdminSingleSignOn', print_r($params, true), $e->getMessage(), $e->getMessage());

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
        \logModuleCall('AdminBolt', 'ServiceSingleSignOn', print_r($params, true), $e->getMessage(), $e->getMessage());

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
    catch(Exception $e)
    {
        \logModuleCall('AdminBolt', 'UsageUpdate', print_r($params, true), $e->getMessage(), $e->getMessage());
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
        \logModuleCall('AdminBolt', 'ClientArea', print_r($params, true), $e->getMessage(), $e->getMessage());

        return [];
    }
}