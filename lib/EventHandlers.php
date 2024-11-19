<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Config\Option;

class EventHandlers
{
    private static function getModuleVersionData()
    {
        $arModuleVersion = [];
        include __DIR__ . '/../install/version.php';
        return $arModuleVersion;
    }

    public static function onBeforeCatalogImport1CHandler(&$arParams, &$File = null)
    {
        // Increment the OnBeforeCatalogImport1C counter
        $moduleId = self::getModuleVersionData()['MODULE_ID']; 
        $beforeCount = Option::get($moduleId, 'ON_BEFORE_COUNT', 0);
        $beforeCount++;
        Option::set($moduleId, 'ON_BEFORE_COUNT', $beforeCount);

        // Update END_OF_1C_UPLOAD time
        $currentTime = time();
        Option::set($moduleId, 'END_OF_1C_UPLOAD', $currentTime);
    }

    public static function onSuccessCatalogImport1CHandler(&$arParams, &$File)
    {
        // Increment the OnSuccessCatalogImport1C counter
        $moduleId = self::getModuleVersionData()['MODULE_ID']; 
        $successCount = Option::get($moduleId, 'ON_SUCCESS_COUNT', 0);
        $successCount++;
        Option::set($moduleId, 'ON_SUCCESS_COUNT', $successCount);

        // Update END_OF_1C_UPLOAD time
        $currentTime = time();
        Option::set($moduleId, 'END_OF_1C_UPLOAD', $currentTime);
    }
}