<?php

namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\Agent\ImportAgent;

class CheckAgent
{
    private static $logFile;

    private static function initLogger()
    {
        // Generate a filename with the current date and time
        $dateTime = date('dmY_Hi'); // Format: ddmmyy_HHMM
        $logFileName = "agent_{$dateTime}.log";
        self::$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/{$logFileName}";
        Logger::init(self::$logFile);
    }

    private static function getModuleVersionData()
    {
        $arModuleVersion = [];
        include __DIR__ . '/../../install/version.php';
        return $arModuleVersion;
    }
    public static function run()
    {
        // Initialize the logger
        self::initLogger();

        $moduleId = self::getModuleVersionData()['MODULE_ID'];

        $delayTime = intval(Option::get($moduleId, 'DELAY_TIME', 60)); // Delay time in seconds
        $autoMode = Option::get($moduleId, 'AUTO_MODE', 'N');

        $endOfUploadTime = intval(Option::get($moduleId, 'END_OF_1C_UPLOAD', 0));

        if ($autoMode !== 'Y' || $endOfUploadTime == 0) {
           //Logger::log("AUTO_MODE is not 'Y' or END_OF_1C_UPLOAD is zero. AUTO_MODE = '{$autoMode}', END_OF_1C_UPLOAD = '{$endOfUploadTime}'");
            // Return the agent method to schedule the next check
            return "\\Pragma\\ImportModule\\Agent\\CheckAgent::run();";
        }

        $currentTime = time();
        $timeSinceLastEvent = $currentTime - $endOfUploadTime;

        //Logger::log("Checking import completion. Time since last event: {$timeSinceLastEvent} seconds.");

        if ($timeSinceLastEvent >= $delayTime) {
            // Enough time has passed since the last event, proceed to calculate success rate
            $beforeCount = intval(Option::get($moduleId, 'ON_BEFORE_COUNT', 0));
            $successCount = intval(Option::get($moduleId, 'ON_SUCCESS_COUNT', 0));

            if ($successCount == 0) {
                // OnSuccessCatalogImport1C has zero positives
                Option::set($moduleId, '1C_UPLOAD_SUCCESS_RATE', '-1');
                Logger::log("Ошибка выгрузки, распаковано 0 пакетов", "ERROR");
                return "\\Pragma\\ImportModule\\Agent\\CheckAgent::run();";
            } else {
                $difference = $beforeCount - $successCount;
                Option::set($moduleId, '1C_UPLOAD_SUCCESS_RATE', strval($difference));

                if ($difference == 0) {
                    Logger::log("Выгрузка прошла без ошибок.");
                } else {
                    Logger::log("Часть пакетов - '{$difference}' потеряно при выгрузке.", "WARNING");
                }

                Logger::log("Итоговый счетчик. Полученные пакеты: {$beforeCount}, Обработанные пакеты: {$successCount}, Не распакованные: {$difference}");
            }

            // Reset counters
            Option::set($moduleId, 'ON_BEFORE_COUNT', 0);
            Option::set($moduleId, 'ON_SUCCESS_COUNT', 0);

            // Now you can proceed to run your ImportAgent or any other code
            try {
                ImportAgent::run();
            } catch (\Exception $e) {
                Logger::log("Error in ImportAgent::run(): " . $e->getMessage(), "ERROR");
            } finally {
                // Reset END_OF_1C_UPLOAD regardless of success or failure
                Option::set($moduleId, 'END_OF_1C_UPLOAD', 0);
            }

            // Return the agent method to schedule the next check
            return "\\Pragma\\ImportModule\\Agent\\CheckAgent::run();";
        } else {
            //Logger::log("Not enough time has passed since last event. Will check again.");
            return "\\Pragma\\ImportModule\\Agent\\CheckAgent::run();";
        }
    }
}