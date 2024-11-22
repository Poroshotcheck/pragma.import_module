<?php
define("NO_KEEP_STATISTIC", true);
define("NO_AGENT_CHECK", true);
define('PUBLIC_AJAX_MODE', true);

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\CacheHelper;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\PropertyHelper;

Loc::loadMessages(__FILE__);

// Security checks
$request = Application::getInstance()->getContext()->getRequest();

// Include necessary modules
if (!Loader::includeModule('iblock')) {
    die('IBlock module is not installed');
}

require_once __DIR__ . '/../install/version.php';
$moduleId = $arModuleVersion['MODULE_ID'];

if (!Loader::includeModule($moduleId)) {
    die('Pragma Import Module is not installed');
}

// Initialize logger
$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/import.log";
Logger::init($logFile);

// Check user access rights
global $APPLICATION;

if ($APPLICATION->GetGroupRight($moduleId) < "W") {
    echo 'Access denied';
    die();
}

$action = $request->getPost('action');

if ($action === 'delete_log') {
    // Implement the delete_log action
    $file = basename($request->getPost('file'));
    $filePath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/pragma.importmodule/logs/' . $file;

    if (file_exists($filePath)) {
        if (unlink($filePath)) {
            echo 'success';
        } else {
            echo 'Error deleting file';
        }
    } else {
        echo 'File not found';
    }
} elseif ($action === 'get_table_data') {
    // Existing code for handling table data
    require_once(__DIR__ . '/ModuleDataTable.php');

    // Get filter and sort parameters from POST
    $filterParams = $request->getPost('filter') ?? [];
    $sortField = $request->getPost('sort_field');
    $sortOrder = $request->getPost('sort_order');

    // Process data as needed
    ob_start();
    include 'data_table_content.php';
    $tableHtml = ob_get_clean();
    echo $tableHtml;
} else {
    // Handle section and property lists
    $iblockId = intval($request->getQuery('IBLOCK_ID'));
    $getProperty = $request->getQuery('PROPERTY');

    if ($iblockId > 0) {
        if ($getProperty === 'Y') {
            // Return list of properties
            echo PropertyHelper::getPropertyOptionsHtml($iblockId, null, null, "S");
        } else {
            // Return list of sections
            echo SectionHelper::getSectionOptionsHtml($iblockId);
        }
    }
}

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/epilog_after.php");
?>