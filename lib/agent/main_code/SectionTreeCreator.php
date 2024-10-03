<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Config\Option;
use Bitrix\Catalog\GroupTable;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\ModuleDataTable;
use Bitrix\Main\Application;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

class SectionTreeCreator
{
    private $moduleId;
    private $sourceIblockId;
    private $sectionMappings;
    private $priceGroupId;
    private $lastId;
    private $totalCount;
    private $totalProcessedCount;
    private $batchSize = 1000;

    public function __construct($moduleId, $sourceIblockId)
    {
        $this->moduleId = $moduleId;
        $this->sourceIblockId = $sourceIblockId;
        $this->sectionMappings = unserialize(Option::get($this->moduleId, "SECTION_MAPPINGS"));
        $this->lastId = 0;
        $this->totalProcessedCount = 0;
        $this->setPriceGroupId();
        Option::delete($this->moduleId, ['name' => 'LAST_ID_TEST']);
    }

    public function createSectionTree()
    {
        Logger::log("Начало createSectionTree()");
        $startTime = microtime(true);

        while (true) {
            $elements = $this->getElements();

            if (empty($elements)) {
                Logger::log("Больше нет элементов для обработки.");
                Option::set($this->moduleId, "LAST_ID_TEST", 'last');
                break;
            }

            $this->processElements($elements);
        }

        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 3);
        Logger::log("Обработка завершена. Всего обработано элементов: {$this->totalProcessedCount}");
        Logger::log("Время выполнения: {$executionTime} сек");
        Logger::log("Завершение createSectionTree()");

        return $executionTime; // Возвращаем время выполнения
    }

    private function setPriceGroupId()
    {
        $priceGroups = GroupTable::getList([
            'filter' => ['BASE' => 'Y'],
            'select' => ['ID']
        ])->fetchAll();

        if (empty($priceGroups)) {
            throw new \Exception('Базовая группа цен не найдена.');
        }

        $this->priceGroupId = $priceGroups[0]['ID'];
    }

    private function getElements()
    {
        $result = ElementTable::getList([
            'filter' => [
                'IBLOCK_ID' => $this->sourceIblockId,
                '>ID' => $this->lastId,
                '@ID' => new SqlExpression(
                    '(SELECT PRODUCT_ID FROM b_catalog_price WHERE CATALOG_GROUP_ID = ?i AND PRICE > 0)',
                    $this->priceGroupId
                )
            ],
            'select' => [
                'ID',
                'NAME',
                'XML_ID',
                'IBLOCK_SECTION_ID',
                'PROPERTY_CML2_ARTICLE_VALUE' => 'PROPERTY_CML2_ARTICLE.VALUE',
                'PROPERTY_CML2_BAR_CODE_VALUE' => 'PROPERTY_CML2_BAR_CODE.VALUE',
            ],
            'runtime' => [
                new ReferenceField(
                    'PROPERTY_CML2_ARTICLE',
                    '\Bitrix\Iblock\ElementPropertyTable',
                    ['=this.ID' => 'ref.IBLOCK_ELEMENT_ID', '=ref.IBLOCK_PROPERTY_ID' => new SqlExpression('(SELECT ID FROM b_iblock_property WHERE IBLOCK_ID = ?i AND CODE = ?s)', $this->sourceIblockId, 'CML2_ARTICLE')],
                    ['join_type' => 'LEFT']
                ),
                new ReferenceField(
                    'PROPERTY_CML2_BAR_CODE',
                    '\Bitrix\Iblock\ElementPropertyTable',
                    ['=this.ID' => 'ref.IBLOCK_ELEMENT_ID', '=ref.IBLOCK_PROPERTY_ID' => new SqlExpression('(SELECT ID FROM b_iblock_property WHERE IBLOCK_ID = ?i AND CODE = ?s)', $this->sourceIblockId, 'CML2_BAR_CODE')],
                    ['join_type' => 'LEFT']
                )
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => $this->batchSize,
            'count_total' => true
        ]);

        if (!isset($this->totalCount)) {
            $this->totalCount = $result->getCount();
        }

        return $result->fetchAll();
    }

    private function processElements($elements)
    {
        $batchData = [];
        $moduleId = $this->moduleId;

        foreach ($elements as $element) {
            $targetSectionIds = $this->getTargetSectionIds($element);

            // Получаем динамические поля из настроек модуля
            $importMappings = unserialize(Option::get($moduleId, "IMPORT_MAPPINGS"));
            $dynamicFields = [];
            if (!empty($importMappings)) {
                foreach ($importMappings as $mapping) {
                    foreach ($mapping['PROPERTIES'] as $property) {
                        $code = $property['CODE'];
                        $dynamicFields[$code] = $element['PROPERTY_' . $code . '_VALUE'] ?: '';
                    }
                }
            }

            $batchData[] = array_merge([
                'TARGET_SECTION_ID' => $targetSectionIds,
                'SOURCE_SECTION_ID' => $element['IBLOCK_SECTION_ID'],
                'ELEMENT_ID' => $element['ID'],
                'ELEMENT_NAME' => $element['NAME'],
                'ELEMENT_XML_ID' => $element['XML_ID'],
                'CHAIN_TOGEZER' => ''
            ], $dynamicFields);

            $this->lastId = $element['ID'];
            $this->totalProcessedCount++;
        }

        $this->saveBatchData($batchData);
    }

    private function getTargetSectionIds($element) // Изменено имя метода
    {
        $targetSectionIds = []; // Создаем массив для хранения ID разделов

        foreach ($this->sectionMappings as $mapping) {
            foreach ($mapping['PROPERTIES'] as $property) {
                if (mb_stripos($element['NAME'], $property) !== false) {
                    if (!in_array($mapping['SECTION_ID'], $targetSectionIds)) {
                        $targetSectionIds[] = $mapping['SECTION_ID']; // Добавляем ID, если его еще нет
                    }
                }
            }
        }

        return $targetSectionIds; // Возвращаем массив ID разделов (может быть пустым)
    }

    private function saveBatchData($batchData)// Пачки возможно лучше передаелать за один запрос в БД
    {
        if (empty($batchData)) {
            return;
        }

        $connection = Application::getConnection();
        $connection->startTransaction();

        try {
            $result = ModuleDataTable::addMulti($batchData);

            if ($result->isSuccess()) {
                $connection->commitTransaction();
                Logger::log("Успешно добавлено " . count($batchData) . " записей в таблицу " . ModuleDataTable::getTableName() . " одним запросом.");
            } else {
                throw new \Exception(implode(", ", $result->getErrorMessages()));
            }
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Ошибка при пакетном добавлении данных в таблицу " . ModuleDataTable::getTableName() . ": " . $e->getMessage());
            Logger::log("Детали ошибки: " . $e->getTraceAsString());
        }
    }

    private function checkTableStructure()
    {
        $tableName = ModuleDataTable::getTableName();
        $connection = Application::getConnection();
        $tableStructure = $connection->getTableFields($tableName);
        Logger::log("Структура таблицы {$tableName}: " . print_r($tableStructure, true));

        $count = ModuleDataTable::getCount();
        Logger::log("Количество записей в таблице {$tableName}: {$count}");

        $testSelect = ModuleDataTable::getList(['limit' => 1])->fetch();
        Logger::log("Тестовая выборка из таблицы {$tableName}: " . ($testSelect ? "успешно" : "неудачно"));
    }
}