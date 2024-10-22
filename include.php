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
        'Pragma\\ImportModule\\Agent\\MainCode\\SizeMatcher' => 'lib/agent/main_code/SizeMatcher.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\ColorMatcher' => 'lib/agent/main_code/ColorMatcher.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\TypeMatcher' => 'lib/agent/main_code/TypeMatcher.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\ProductUpdater' => 'lib/agent/main_code/ProductUpdater.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\SimpleProductImporter' => 'lib/agent/main_code/SimpleProductImporter.php',
        'Pragma\\ImportModule\\Agent\\MainCode\\TradeOfferImporter' => 'lib/agent/main_code/TradeOfferImporter.php',
    ]
);