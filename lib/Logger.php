<?php

namespace Pragma\ImportModule;

use Bitrix\Main\Config\Option;

class Logger
{
    private static $logFile;

    private static function getModuleVersionData()
    {
        $arModuleVersion = [];
        include __DIR__ . '/../install/version.php';
        return $arModuleVersion;
    }

    public static function init($logFile)
    {
        self::$logFile = $logFile;
    }

    public static function log($message, $severity = "INFO")
    {
        $moduleId = self::getModuleVersionData()['MODULE_ID'];

        if (!self::$logFile) {
            return; // Логирование не инициализировано
        }

        $enableLogging = Option::get($moduleId, "ENABLE_LOGGING", "N");
        if ($enableLogging != "Y") {
            return; // Логирование отключено
        }

        $logMessage = date("Y-m-d H:i:s") . " [" . $severity . "] " . $message . PHP_EOL;
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND);
    }
}