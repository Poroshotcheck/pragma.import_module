<?php

namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\AgentManager;
use Pragma\ImportModule\Agent\MainCode\IblockPropertiesCopier;
use Pragma\ImportModule\Agent\MainCode\SectionTreeCreator;

class ImportAgent
{
    private static $logFile;
    private static $moduleId = 'pragma.importmodule';

    private static function initLogger()
    {
        if (!self::$logFile) {
            self::$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/agent.log";
            Logger::init(self::$logFile);
        }
    }

    private static function getExecutionTimeMs($startTime, $endTime)
    {
        $duration = $endTime - $startTime;
        return round($duration, 3) . " сек";  // Изменено: вывод в секундах, 3 знака после запятой
    }

    public static function run()
    {
        self::initLogger();
        $startTime = microtime(true);
        Logger::log("Начало выполнения ImportAgent::run()");

        try {
            $sourceIblockId = Option::get(self::$moduleId, 'IBLOCK_ID_IMPORT');
            $destinationIblockId = Option::get(self::$moduleId, 'IBLOCK_ID_CATALOG');

            Logger::log("Получены ID инфоблоков: источник = {$sourceIblockId}, назначение = {$destinationIblockId}");

            if (empty($sourceIblockId) || empty($destinationIblockId)) {
                throw new \Exception("ID инфоблоков не настроены в модуле");
            }

            // Создание дерева разделов (1 Этап)
            $sectionStartTime = microtime(true);
            Logger::log("Начало создания дерева разделов");
            $sectionTreeCreator = new SectionTreeCreator(self::$moduleId, $sourceIblockId);
            $sectionTreeCreator->createSectionTree();
            $sectionEndTime = microtime(true);
            $sectionDuration = self::getExecutionTimeMs($sectionStartTime, $sectionEndTime);
            Logger::log("Завершено создание дерева разделов. Время выполнения: {$sectionDuration}"); // Изменено: без "мс"





            // Копирование свойств (3 Этап)
            $propertiesStartTime = microtime(true);
            Logger::log("Начало копирования свойств");
            $propertiesCopier = new IblockPropertiesCopier($sourceIblockId, $destinationIblockId);
            $propertiesCopier->copyProperties();
            $propertiesEndTime = microtime(true);
            $propertiesDuration = self::getExecutionTimeMs($propertiesStartTime, $propertiesEndTime);
            Logger::log("Завершено копирование свойств. Время выполнения: {$propertiesDuration}"); // Изменено: без "мс"

            Logger::log("Успешное завершение ImportAgent::run()");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ImportAgent::run(): " . $e->getMessage());
        }

        $endTime = microtime(true);
        $totalDuration = self::getExecutionTimeMs($startTime, $endTime);
        Logger::log("Общее время выполнения ImportAgent::run(): {$totalDuration}"); // Изменено: без "мс"

        return "Pragma\\ImportModule\\Agent\\ImportAgent::run();";
    }
}