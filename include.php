<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
    'pragma.import_module',
    [
        'Pragma\\ImportModule\\Agent\\CheckAgent' => 'lib/agent/CheckAgent.php',
        'Pragma\\ImportModule\\Agent\\ImportAgent' => 'lib/agent/ImportAgent.php',
        'Pragma\\ImportModule\\EventHandlers' => 'lib/EventHandlers.php',
        'Pragma\\ImportModule\\Logger' => 'lib/Logger.php',
        'Pragma\\ImportModule\\ImportHelper' => 'lib/ImportHelper.php',
        'Pragma\\ImportModule\\CacheHelper' => 'lib/CacheHelper.php',
        'Pragma\\ImportModule\\SectionHelper' => 'lib/SectionHelper.php'
    ]
);