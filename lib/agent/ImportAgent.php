<?php
namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\Application;
use Bitrix\Catalog\GroupTable;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Agent\MainCode\SectionTreeCreator;
use Pragma\ImportModule\Agent\MainCode\TradeOfferSorter;
use Pragma\ImportModule\Agent\MainCode\IblockPropertiesCopier;
use Pragma\ImportModule\Agent\MainCode\ColorMatcher;
use Pragma\ImportModule\Agent\MainCode\SizeMatcher;
use Pragma\ImportModule\Agent\MainCode\TypeMatcher;
use Pragma\ImportModule\Agent\MainCode\SimpleProductImporter;
use Pragma\ImportModule\Agent\MainCode\TradeOfferImporter;

class ImportAgent
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

    private static function getExecutionTimeMs($startTime, $endTime)
    {
        $duration = $endTime - $startTime;
        return round($duration, 3) . " сек";
    }

    private static function getBasePriceGroupId()
    {
        try {
            $priceGroup = GroupTable::getList([
                'filter' => ['BASE' => 'Y'],
                'select' => ['ID'],
                'limit' => 1
            ])->fetch();

            if (!$priceGroup) {
                throw new \Exception('Базовая группа цен не найдена.');
            }

            Logger::log("Получен ID базовой цены: {$priceGroup['ID']}");
            return $priceGroup['ID'];
        } catch (\Exception $e) {
            Logger::log("Ошибка в getBasePriceGroupId(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public static function run()
    {
        self::initLogger();
        $startTime = microtime(true);
        Logger::log("Начало выполнения агента импорта: ImportAgent::run()");

        try {
            $basePriceGroupId = self::getBasePriceGroupId();

            // Получаем MODULE_ID из version.php
            $moduleId = self::getModuleVersionData()['MODULE_ID'];

            $sourceIblockId = Option::get($moduleId, 'IBLOCK_ID_IMPORT');
            $destinationIblockId = Option::get($moduleId, 'IBLOCK_ID_CATALOG');

            if (empty($sourceIblockId) || empty($destinationIblockId)) {
                throw new \Exception("ID инфоблоков не настроены в модуле");
            } else {
                Logger::log("Получены ID инфоблоков: источник = {$sourceIblockId}, назначение = {$destinationIblockId}");
            }

            try {
                // Создаем таблицу для импорта, если ее нет
                if (!Application::getConnection(ModuleDataTable::getConnectionName())->isTableExists(ModuleDataTable::getTableName())) {
                    Base::getInstance(ModuleDataTable::class)->createDbTable();
                    Logger::log("Таблица " . ModuleDataTable::getTableName() . " создана");
                } else {
                    //Очищаем таблицу
                    Application::getConnection()->truncateTable(ModuleDataTable::getTableName());
                    Logger::log("Таблица " . ModuleDataTable::getTableName() . " очищена");
                }
            } catch (\Exception $e) {
                Logger::log("Ошибка при обновлении таблицы: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Создание дерева разделов (1 Этап)
            $sectionStartTime = microtime(true);
            Logger::log("Начало создания дерева разделов");

            try {
                $sectionTreeCreator = new SectionTreeCreator($moduleId, $sourceIblockId, $basePriceGroupId);
                $sectionTreeCreator->createSectionTree();
                $sectionEndTime = microtime(true);
                $sectionDuration = self::getExecutionTimeMs($sectionStartTime, $sectionEndTime);
                Logger::log("Завершено создание дерева разделов. Время выполнения: {$sectionDuration} сек");
            } catch (\Exception $e) {
                Logger::log("Ошибка при создании дерева разделов: " . $e->getMessage(), "ERROR");
                throw $e;
            }


            // Сортировка торговых предложений (2 Этап)
            $sorterStartTime = microtime(true);
            Logger::log("Начало сортировки торговых предложений");

            try {
                $tradeOfferSorter = new TradeOfferSorter($moduleId);
                $tradeOfferSorter->sortTradeOffers();
                $sorterEndTime = microtime(true);
                $sorterDuration = self::getExecutionTimeMs($sorterStartTime, $sorterEndTime);
                Logger::log("Завершена сортировка торговых предложений. Время выполнения: {$sorterDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при сортировке торговых предложений: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Сопоставление цветов (4 Этап)
            $colorStartTime = microtime(true);
            Logger::log("Начало сопоставления цветов");

            try {
                $colorMatcher = new ColorMatcher($moduleId);
                $colorMatcher->matchColors();
                $colorMatcher->updateDatabase();
                $colorEndTime = microtime(true);
                $colorDuration = self::getExecutionTimeMs($colorStartTime, $colorEndTime);
                Logger::log("Завершено сопоставление цветов. Время выполнения: {$colorDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при сопоставлении цветов: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Сопоставление размеров (5 Этап)
            $sizeStartTime = microtime(true);
            Logger::log("Начало сопоставления размеров");

            try {
                $sizeMatcher = new SizeMatcher($moduleId);
                $sizeMatcher->matchSizes();
                $sizeMatcher->updateDatabase();
                $sizeEndTime = microtime(true);
                $sizeDuration = self::getExecutionTimeMs($sizeStartTime, $sizeEndTime);
                Logger::log("Завершено сопоставление размеров. Время выполнения: {$sizeDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при сопоставлении размеров: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Сопоставление типов (6 Этап)
            $typeStartTime = microtime(true);
            Logger::log("Начало сопоставления типов");

            try {
                $typeMatcher = new TypeMatcher($moduleId);
                $typeMatcher->matchTypes();
                $typeMatcher->createMissingTypes();
                $typeMatcher->updateDatabase();
                $typeEndTime = microtime(true);
                $typeDuration = self::getExecutionTimeMs($typeStartTime, $typeEndTime);
                Logger::log("Завершено сопоставление типов. Время выполнения: {$typeDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при сопоставлении типов: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Копирование свойств (7 Этап)
            $targetOffersIblockId = \CCatalogSKU::GetInfoByProductIBlock($destinationIblockId)['IBLOCK_ID'];
            $propertiesStartTime = microtime(true);
            Logger::log("Начало копирования свойств");

            try {
                $propertiesCopier = new IblockPropertiesCopier($moduleId, $sourceIblockId, $destinationIblockId, $targetOffersIblockId);
                $propertiesCopier->copyProperties();
                $propertiesEndTime = microtime(true);
                $propertiesDuration = self::getExecutionTimeMs($propertiesStartTime, $propertiesEndTime);
                Logger::log("Завершено копирование свойств. Время выполнения: {$propertiesDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при копировании свойств: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Импорт простых продуктов (8 Этап)
            $importStartTime = microtime(true);
            Logger::log("Начало импорта простых продуктов");

            try {
                $simpleProductImporter = new SimpleProductImporter($moduleId, $sourceIblockId, $destinationIblockId, $targetOffersIblockId, $basePriceGroupId);
                $simpleProductImporter->copyElementsFromModuleData(1000); // Размер пачки
                $importEndTime = microtime(true);
                $importDuration = self::getExecutionTimeMs($importStartTime, $importEndTime);
                Logger::log("Завершен импорт простых продуктов. Время выполнения: {$importDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при импорте простых продуктов: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Импорт торговых предложений (9 Этап)
            $offerImportStartTime = microtime(true);
            Logger::log("Начало импорта торговых предложений");

            try {
                $tradeOfferImporter = new TradeOfferImporter($moduleId, $sourceIblockId, $destinationIblockId, $targetOffersIblockId, $basePriceGroupId);
                $tradeOfferImporter->copyElementsFromModuleData(30); // Размер цепочки
                $offerImportEndTime = microtime(true);
                $offerImportDuration = self::getExecutionTimeMs($offerImportStartTime, $offerImportEndTime);
                Logger::log("Завершен импорт торговых предложений. Время выполнения: {$offerImportDuration}");
            } catch (\Exception $e) {
                Logger::log("Ошибка при импорте торговых предложений: " . $e->getMessage(), "ERROR");
                throw $e;
            }

            // Удаляем таблицу импорта
            // try {
            //     Application::getConnection(ModuleDataTable::getConnectionName())
            //         ->queryExecute('DROP TABLE IF EXISTS ' . ModuleDataTable::getTableName());
            //     Logger::log("Таблица " . ModuleDataTable::getTableName() . " удалена");
            // } catch (\Exception $e) {
            //     Logger::log("Ошибка при удалении таблицы: " . $e->getMessage(), "ERROR");
            //     throw $e;
            // }

            Logger::log("Успешное завершение ImportAgent::run()");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ImportAgent::run(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
        }

        $endTime = microtime(true);
        $totalDuration = self::getExecutionTimeMs($startTime, $endTime);
        Logger::log("Общее время выполнения ImportAgent::run(): {$totalDuration}");

        return "Pragma\\ImportModule\\Agent\\ImportAgent::run();";
    }
}
?>