<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . "vendor" . DIRECTORY_SEPARATOR . "autoload.php";

use WHMCS\View\Menu\Item;
use WHMCS\Database\Capsule;
use ModulesGarden\AdminBolt\Helpers\Lang;

add_hook('ClientAreaPrimarySidebar', 1, function (Item $primarySidebar): void {
    try
    {
        $serviceDetailsActions = $primarySidebar->getChild('Service Details Actions');

        if(!$serviceDetailsActions || empty($_GET['id']))
        {
            return;
        }

        $service = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.id', '=', $_GET['id'])
            ->where('tblproducts.type', '!=', 'reselleraccount')
            ->where('tblproducts.servertype', '=', 'AdminBolt')
            ->first(['tblhosting.id']);

        if(!$service)
        {
            return;
        }

        $lang = new Lang();

        $serviceDetailsActions->addChild($lang->get('logInToControlPanel'), [
            'uri' => 'clientarea.php?action=productdetails&id=' . $_GET['id'] . '&dosinglesignon=1',
            'icon' => 'fa-external-link-alt',
            'order' => 1,
            'attributes' => [
                'target' => '_blank'
            ]
        ]);
    }
    catch (Exception $e)
    {
        \logModuleCall('AdminBolt', 'ClientAreaPrimarySidebar', "", $e->getMessage(), $e->getMessage());
    }
});

add_hook('ClientAreaFooterOutput', 1, function (array $params): string {
    if($params['templatefile'] != 'clientareaproductdetails' || $_GET['action'] != 'productdetails')
    {
        return "";
    }

    return <<<HTML
<script>
    $('#Primary_Sidebar-Service_Details_Overview-Information').on('click', e => {
        $('#Primary_Sidebar-Service_Details_Actions-Change_Password').removeClass('active');
    });
    
    $('#Primary_Sidebar-Service_Details_Actions-Change_Password').on('click', e => {
        $('#Primary_Sidebar-Service_Details_Overview-Information').removeClass('active');
    });
</script>
HTML;
});