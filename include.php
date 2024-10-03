<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
    'pragma.importmodule',
    [
        'Pragma\\ImportModule\\Agent\\ImportAgent' => 'lib/agent/ImportAgent.php',
        'Pragma\\ImportModule\\Logger' => 'lib/Logger.php',
        'Pragma\\ImportModule\\PropertyHelper' => 'lib/PropertyHelper.php',
        'Pragma\\ImportModule\\CacheHelper' => 'lib/CacheHelper.php',
        'Pragma\\ImportModule\\SectionHelper' => 'lib/SectionHelper.php',
        'Pragma\\ImportModule\\IblockHelper' => 'lib/IblockHelper.php',
        'Pragma\\ImportModule\\AgentManager' => 'lib/AgentManager.php',
        'Pragma\\ImportModule\\OptionsHelper' => 'lib/OptionsHelper.php',
        'Pragma\\ImportModule\\ModuleDataTable' => 'lib/ModuleDataTable.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\SectionTreeCreator' => 'lib/agent/main_code/SectionTreeCreator.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\TradeOfferSorter' => 'lib/agent/main_code/TradeOfferSorter.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\IblockPropertiesCopier' => 'lib/agent/main_code/IblockPropertiesCopier.php',
    ]
);