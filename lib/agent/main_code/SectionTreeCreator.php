<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Config\Option;
use Bitrix\Catalog\GroupTable;
use Pragma\ImportModule\Logger;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

class SectionTreeCreator
{
    private $moduleId;
    private $iblockId;
    private $sectionMappings;
    private $dataDir;
    private $priceGroupId;
    private $lastId;
    private $totalCount;
    private $totalProcessedCount;

    public function __construct($moduleId, $iblockId)
    {
        $this->moduleId = $moduleId;
        $this->iblockId = $iblockId;
        $this->sectionMappings = unserialize(Option::get($this->moduleId, "SECTION_MAPPINGS"));
        $this->dataDir = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/pragma.importmodule/data/';
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
        $executionTime = round($endTime - $startTime, 3); // Округляем до 3 знаков
        Logger::log("Обработка завершена. Всего обработано элементов: {$this->totalProcessedCount}");
        Logger::log("Время выполнения: {$executionTime} сек"); // Выводим в секундах

        Logger::log("Завершение createSectionTree()");
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
                'IBLOCK_ID' => $this->iblockId,
                '>ID' => $this->lastId,
                '@ID' => new SqlExpression(
                    '(SELECT PRODUCT_ID FROM b_catalog_price WHERE CATALOG_GROUP_ID = ?i AND PRICE > 0)',
                    $this->priceGroupId
                )
            ],
            'select' => [
                'ID', 'NAME', 'XML_ID',
                'PROPERTY_CML2_ARTICLE_VALUE' => 'PROPERTY_CML2_ARTICLE.VALUE',
                'PROPERTY_CML2_BAR_CODE_VALUE' => 'PROPERTY_CML2_BAR_CODE.VALUE',
            ],
            'runtime' => [
                new ReferenceField(
                    'PROPERTY_CML2_ARTICLE',
                    '\Bitrix\Iblock\ElementPropertyTable',
                    ['=this.ID' => 'ref.IBLOCK_ELEMENT_ID', '=ref.IBLOCK_PROPERTY_ID' => new SqlExpression('(SELECT ID FROM b_iblock_property WHERE IBLOCK_ID = ?i AND CODE = ?s)', $this->iblockId, 'CML2_ARTICLE')],
                    ['join_type' => 'LEFT']
                ),
                new ReferenceField(
                    'PROPERTY_CML2_BAR_CODE',
                    '\Bitrix\Iblock\ElementPropertyTable',
                    ['=this.ID' => 'ref.IBLOCK_ELEMENT_ID', '=ref.IBLOCK_PROPERTY_ID' => new SqlExpression('(SELECT ID FROM b_iblock_property WHERE IBLOCK_ID = ?i AND CODE = ?s)', $this->iblockId, 'CML2_BAR_CODE')],
                    ['join_type' => 'LEFT']
                )
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => 1000,
            'count_total' => true
        ]);

        if (!isset($this->totalCount)) {
            $this->totalCount = $result->getCount();
        }

        return $result->fetchAll();
    }

    private function processElements($elements)
    {
        $processedElements = [];
        $processedCount = 0;

        foreach ($elements as $element) {
            $assigned = false;
            foreach ($this->sectionMappings as $mapping) {
                foreach ($mapping['PROPERTIES'] as $property) {
                    if (mb_stripos($element['NAME'], $property) !== false) {
                        $processedElements[$mapping['SECTION_ID']][] = $element;
                        $assigned = true;
                        break 2;
                    }
                }
            }
            if (!$assigned) {
                $processedElements['NO_SECTION'][] = $element;
            }
            $this->lastId = $element['ID'];
            $processedCount++;
            $this->totalProcessedCount++;
        }

        $this->saveProcessedElements($processedElements);
        Logger::log("Конец обработки пачки. Обработано элементов: {$processedCount}.");
    }

    private function saveProcessedElements($processedElements)
    {
        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
        foreach ($processedElements as $sectionId => $data) {
            $filename = $this->dataDir . "section_{$sectionId}.json";
            $this->saveDataToFile($data, $filename);
        }
    }

    private function saveDataToFile($data, $filename)
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (($fp = fopen($filename, 'a')) !== false) {
            fwrite($fp, $jsonData . PHP_EOL);
            fclose($fp);
            Logger::log("Данные добавлены в файл: {$filename}");
        } else {
            Logger::log("Ошибка при открытии файла: {$filename}");
        }
    }
}