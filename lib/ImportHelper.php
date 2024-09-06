<?php

namespace Pragma\ImportModule;

use Bitrix\Main\Config\Option;

class ImportHelper
{
    public static function processCatalog()
    {
        $moduleId = 'pragma.importmodule';
        $iblockIdImport = Option::get($moduleId, "IBLOCK_ID_IMPORT", 0);
        $iblockIdCatalog = Option::get($moduleId, "IBLOCK_ID_CATALOG", 0);
        $sectionMappings = unserialize(Option::get($moduleId, "SECTION_MAPPINGS", "a:0:{}"));

        $importStartCount = Option::get($moduleId, 'import_start_count', 0);
        $importSuccessCount = Option::get($moduleId, 'import_success_count', 0);

        $logMessage = "Catalog processing started. Import IBLOCK_ID: {$iblockIdImport}, Catalog IBLOCK_ID: {$iblockIdCatalog}\n";
        $logMessage .= "Section mappings: " . print_r($sectionMappings, true) . "\n";

        if ($importStartCount == $importSuccessCount) {
            $logMessage .= "Import status: Full success\n";
        } elseif ($importSuccessCount > 0) {
            $logMessage .= "Import status: Partial success\n";
        } else {
            $logMessage .= "Import status: Full failure\n";
        }

        $logMessage .= "Import start count: {$importStartCount}\n";
        $logMessage .= "Import success count: {$importSuccessCount}\n";

        self::log($logMessage);

        // Сбрасываем счетчики
        Option::set($moduleId, 'import_start_count', 0);
        Option::set($moduleId, 'import_success_count', 0);
    }

    private static function log($message)
    {
        $logFile = $_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/import_log.txt";
        $logMessage = date("[Y-m-d H:i:s] ") . $message . "\n";
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
}