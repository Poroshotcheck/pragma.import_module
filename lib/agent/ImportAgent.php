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
    private static function getBasePriceGroupId()
    {
        $priceGroup = GroupTable::getList([
            'filter' => ['BASE' => 'Y'],
            'select' => ['ID'],
            'limit' => 1
        ])->fetch();

        if (!$priceGroup) {
            throw new \Exception('Базовая группа цен не найдена.');
        }

        return $priceGroup['ID'];
    }

    public static function run()
    {
        self::initLogger();
        $startTime = microtime(true);
        Logger::log("Начало выполнения ImportAgent::run()");

        $basePriceGroupId = self::getBasePriceGroupId();
        Logger::log("Получен ID базовой цены: {$basePriceGroupId}");

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

            $sectionTreeCreator = new SectionTreeCreator($moduleId, $sourceIblockId, $basePriceGroupId);
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

            // Сопоставление цветов (4 Этап)
            $colorStartTime = microtime(true);
            Logger::log("Начало сопоставления цветов");

            $colorMatcher = new ColorMatcher($moduleId);
            $colorMatcher->matchColors();
            $colorMatcher->updateDatabase();
            $colorEndTime = microtime(true);
            $colorDuration = self::getExecutionTimeMs($colorStartTime, $colorEndTime);
            Logger::log("Завершено сопоставление цветов. Время выполнения: {$colorDuration}");

            // Сопоставление размеров (5 Этап)
            $sizeStartTime = microtime(true);
            Logger::log("Начало сопоставления размеров");

            $sizeMatcher = new SizeMatcher($moduleId);
            $sizeMatcher->matchSizes();
            $sizeMatcher->updateDatabase();
            $sizeEndTime = microtime(true);
            $sizeDuration = self::getExecutionTimeMs($sizeStartTime, $sizeEndTime);
            Logger::log("Завершено сопоставление размеров. Время выполнения: {$sizeDuration}");


            // Сопоставление типов (6 Этап)
            $typeStartTime = microtime(true);
            Logger::log("Начало сопоставления типов");

            $typeMatcher = new TypeMatcher($moduleId);
            $typeMatcher->matchTypes();
            $typeMatcher->createMissingTypes();
            $typeMatcher->updateDatabase();
            $typeEndTime = microtime(true);
            $typeDuration = self::getExecutionTimeMs($typeStartTime, $typeEndTime);
            Logger::log("Завершено сопоставление размеров. Время выполнения: {$typeDuration}");


            // Копирование ДАННЫХ
            $targetOffersIblockId = \CCatalogSKU::GetInfoByProductIBlock($destinationIblockId)['IBLOCK_ID'];

            // Копирование свойств (7 Этап)
            $propertiesStartTime = microtime(true);
            Logger::log("Начало копирования свойств");

            $propertiesCopier = new IblockPropertiesCopier($moduleId, $sourceIblockId, $destinationIblockId, $targetOffersIblockId);
            $propertiesCopier->copyProperties();
            $propertiesEndTime = microtime(true);
            $propertiesDuration = self::getExecutionTimeMs($propertiesStartTime, $propertiesEndTime);
            Logger::log("Завершено копирование свойств. Время выполнения: {$propertiesDuration}");


            // Импорт простых продуктов (7 Этап)
            $importStartTime = microtime(true);
            Logger::log("Начало импорта простых продуктов");

            $simpleProductImporter = new SimpleProductImporter($moduleId, $sourceIblockId, $destinationIblockId, $targetOffersIblockId, $basePriceGroupId);
            $simpleProductImporter->copyElementsFromModuleData(1000); // Specify batch size
            $importEndTime = microtime(true);
            $importDuration = self::getExecutionTimeMs($importStartTime, $importEndTime);
            Logger::log("Завершен импорт простых продуктов. Время выполнения: {$importDuration}");

            // Импорт торговых предложений (8 Этап)
            $offerImportStartTime = microtime(true);
            Logger::log("Начало импорта торговых предложений");

            $tradeOfferImporter = new TradeOfferImporter($moduleId, $sourceIblockId, $destinationIblockId, $targetOffersIblockId, $basePriceGroupId);
            $tradeOfferImporter->copyElementsFromModuleData(30); // Specify chain range size
            $offerImportEndTime = microtime(true);
            $offerImportDuration = self::getExecutionTimeMs($offerImportStartTime, $offerImportEndTime);
            Logger::log("Завершен импорт торговых предложений. Время выполнения: {$offerImportDuration}");


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