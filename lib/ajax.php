<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/SectionHelper.php");//Без этого не работает 
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/CacheHelper.php");//Без этого не работает 
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/Logger.php");//Без этого не работает 

use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\CacheHelper;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Pragma\ImportModule\Logger;

if (!Loader::includeModule('iblock')) {
    die('IBlock module is not installed');
}

// // Инициализация логгера
$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/import.log"; 
Logger::init($logFile);

$request = Application::getInstance()->getContext()->getRequest();
$iblockId = intval($request->getQuery('IBLOCK_ID'));

if ($iblockId > 0) {
    echo SectionHelper::getSectionOptionsHtml($iblockId);
}

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");
?>