<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/SectionHelper.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/CacheHelper.php"); 
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/Logger.php"); 
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/PropertyHelper.php");

use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\CacheHelper;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\PropertyHelper; // Добавляем use

if (!Loader::includeModule('iblock')) {
    die('IBlock module is not installed');
}

// Инициализация логгера
$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/import.log"; 
Logger::init($logFile);

$request = Application::getInstance()->getContext()->getRequest();
$iblockId = intval($request->getQuery('IBLOCK_ID'));
$getProperty = $request->getQuery('PROPERTY');

if ($iblockId > 0) {
    if ($getProperty === 'Y') {
        // Возвращаем список свойств
        echo PropertyHelper::getPropertyOptionsHtml($iblockId);
    } else {
        // Возвращаем список разделов
        echo SectionHelper::getSectionOptionsHtml($iblockId); 
    }
}

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");
?>