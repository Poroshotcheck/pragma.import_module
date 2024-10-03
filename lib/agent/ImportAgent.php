<?php
namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\Application;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Agent\MainCode\SectionTreeCreator;
use Pragma\ImportModule\Agent\MainCode\TradeOfferSorter;
use Pragma\ImportModule\Agent\MainCode\IblockPropertiesCopier;

class ImportAgent
{
    private static $logFile;

    private static function initLogger()
    {
        if (!self::$logFile) {
            self::$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/agent.log";
            Logger::init(self::$logFile);
        }
    }

    private static function getModuleVersionData()
    {
        $arModuleVersion = [];
        include __DIR__ . '/../../install/version.php';
        return $arModuleVersion;
    }

    private static function getExecutionTimeMs($startTime, $endTime)
    {
        $duration = $endTime - $startTime;
        return round($duration, 3) . " сек";
    }

    public static function run()
    {
        self::initLogger();
        $startTime = microtime(true);
        Logger::log("Начало выполнения ImportAgent::run()");

        try {
            // Получаем MODULE_ID из version.php
            $moduleId = self::getModuleVersionData()['MODULE_ID'];

            $sourceIblockId = Option::get($moduleId, 'IBLOCK_ID_IMPORT');
            $destinationIblockId = Option::get($moduleId, 'IBLOCK_ID_CATALOG');

            Logger::log("Получены ID инфоблоков: источник = {$sourceIblockId}, назначение = {$destinationIblockId}");

            if (empty($sourceIblockId) || empty($destinationIblockId)) {
                throw new \Exception("ID инфоблоков не настроены в модуле");
            }

            // Создаем таблицу для импорта, если ее нет
            if (!Application::getConnection(ModuleDataTable::getConnectionName())->isTableExists(ModuleDataTable::getTableName())) {
                Base::getInstance(ModuleDataTable::class)->createDbTable();
                Logger::log("Таблица создана");
            }

            // Создание дерева разделов (1 Этап)
            $sectionStartTime = microtime(true);
            Logger::log("Начало создания дерева разделов");

            $sectionTreeCreator = new SectionTreeCreator($moduleId, $sourceIblockId);
            $sectionEndTime = microtime(true);
            $sectionDuration = $sectionTreeCreator->createSectionTree();
            Logger::log("Завершено создание дерева разделов. Время выполнения: {$sectionDuration} сек");

            // Сортировка торговых предложений (2 Этап)
            $sorterStartTime = microtime(true);
            Logger::log("Начало сортировки торговых предложений");

            $tradeOfferSorter = new TradeOfferSorter($moduleId);
            $tradeOfferSorter->sortTradeOffers();
            $sorterEndTime = microtime(true);
            $sorterDuration = self::getExecutionTimeMs($sorterStartTime, $sorterEndTime);
            Logger::log("Завершена сортировка торговых предложений. Время выполнения: {$sorterDuration}");

            // Копирование свойств (3 Этап)
            $propertiesStartTime = microtime(true);
            Logger::log("Начало копирования свойств");

            $propertiesCopier = new IblockPropertiesCopier($sourceIblockId, $destinationIblockId);
            $propertiesCopier->copyProperties();
            $propertiesEndTime = microtime(true);
            $propertiesDuration = self::getExecutionTimeMs($propertiesStartTime, $propertiesEndTime);
            Logger::log("Завершено копирование свойств. Время выполнения: {$propertiesDuration}");

            // Удаляем таблицу импорта
            Application::getConnection(ModuleDataTable::getConnectionName())
                ->queryExecute('DROP TABLE IF EXISTS ' . ModuleDataTable::getTableName());
            Logger::log("Таблица удалена");

            Logger::log("Успешное завершение ImportAgent::run()");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ImportAgent::run(): " . $e->getMessage(), "ERROR");
        }

        $endTime = microtime(true);
        $totalDuration = self::getExecutionTimeMs($startTime, $endTime); // Исправленный расчет общего времени
        Logger::log("Общее время выполнения ImportAgent::run(): {$totalDuration}");

        return "Pragma\\ImportModule\\Agent\\ImportAgent::run();";
    }
}