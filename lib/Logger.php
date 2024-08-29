<?php

namespace Pragma\ImportModule;

class Logger
{
    private static $logFile;

    public static function init($logFile)
    {
        self::$logFile = $logFile;
    }

    public static function log($message, $severity = "INFO")
    {
        if (!self::$logFile) {
            return; // Логирование не инициализировано
        }

        $logMessage = date("Y-m-d H:i:s") . " [" . $severity . "] " . $message . PHP_EOL;
        file_put_contents(self::$logFile, $logMessage, FILE_APPEND);
    }
}