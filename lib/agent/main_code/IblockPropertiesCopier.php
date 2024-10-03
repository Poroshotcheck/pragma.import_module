<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Main\Application;
use Pragma\ImportModule\Logger;
use CCatalogSKU;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

class IblockPropertiesCopier
{
    private $sourceIblockId;
    private $destinationIblockId;
    private $destinationOffersIblockId;
    private $sourceProperties;
    private $destinationProperties;
    private $destinationPropertiesByCode;
    private $destinationOffersProperties;
    private $destinationOffersPropertiesByCode;
    private $connection;

    public function __construct($sourceIblockId, $destinationIblockId)
    {
        $this->sourceIblockId = $sourceIblockId;
        $this->destinationIblockId = $destinationIblockId;
        $this->sourceProperties = [];
        $this->destinationProperties = [];
        $this->destinationPropertiesByCode = [];
        $this->destinationOffersProperties = [];
        $this->destinationOffersPropertiesByCode = [];
        $this->connection = Application::getConnection();

        // Получаем ID инфоблока торговых предложений, если он существует
        $offersCatalog = CCatalogSKU::GetInfoByProductIBlock($destinationIblockId);
        if ($offersCatalog && isset($offersCatalog['IBLOCK_ID'])) {
            $this->destinationOffersIblockId = $offersCatalog['IBLOCK_ID'];
        }

        Logger::log("IblockPropertiesCopier создан. Источник: {$sourceIblockId}, Назначение: {$destinationIblockId}, ТП: " . ($this->destinationOffersIblockId ? $this->destinationOffersIblockId : "нет"));
    }

    public function copyProperties()
    {
        Logger::log("Начало copyProperties()");
        $this->loadProperties();
        $this->processProperties();
        Logger::log("Завершение copyProperties()");
    }

    private function loadProperties()
    {
        Logger::log("Начало loadProperties()");

        // Загружаем свойства исходного инфоблока
        $this->sourceProperties = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $this->sourceIblockId],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC']
        ])->fetchAll();
        Logger::log("Загружено " . count($this->sourceProperties) . " свойств источника");

        // Загружаем свойства целевого инфоблока
        $destinationProperties = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $this->destinationIblockId],
            'select' => ['ID', 'CODE', 'XML_ID', 'PROPERTY_TYPE', 'MULTIPLE']
        ])->fetchAll();
        Logger::log("Загружено " . count($destinationProperties) . " свойств назначения");

        // Создаем массивы для быстрого доступа к свойствам целевого инфоблока по XML_ID и коду
        foreach ($destinationProperties as $property) {
            if ($property['XML_ID']) {
                $this->destinationProperties[$property['XML_ID']] = $property;
            }
            $this->destinationPropertiesByCode[$property['CODE']][] = $property;
        }

        // Загружаем свойства инфоблока торговых предложений, если он существует
        if ($this->destinationOffersIblockId) {
            $destinationOffersProperties = PropertyTable::getList([
                'filter' => ['IBLOCK_ID' => $this->destinationOffersIblockId],
                'select' => ['ID', 'CODE', 'XML_ID', 'PROPERTY_TYPE', 'MULTIPLE']
            ])->fetchAll();
            Logger::log("Загружено " . count($destinationOffersProperties) . " свойств назначения ТП");

            foreach ($destinationOffersProperties as $property) {
                if ($property['XML_ID']) {
                    $this->destinationOffersProperties[$property['XML_ID']] = $property;
                }
                $this->destinationOffersPropertiesByCode[$property['CODE']][] = $property;
            }
        }

        // Загружаем значения списков для свойств типа "Список" в исходном инфоблоке
        foreach ($this->sourceProperties as &$property) {
            if ($property['PROPERTY_TYPE'] == 'L') {
                $property['ENUM_VALUES'] = $this->getEnumValues($property['ID']);
                Logger::log("Загружено " . count($property['ENUM_VALUES']) . " значений списка для свойства {$property['CODE']}");
            }
        }
        unset($property); // Очищаем ссылку

        Logger::log("Завершение loadProperties()");
    }

    private function processProperties()
    {
        Logger::log("Начало processProperties()");
        $this->connection->startTransaction();

        try {
            foreach ($this->sourceProperties as $sourceProperty) {
                Logger::log("Обработка свойства: {$sourceProperty['CODE']}");

                // 1. Поиск свойства в целевом инфоблоке по XML_ID
                if ($sourceProperty['XML_ID'] && isset($this->destinationProperties[$sourceProperty['XML_ID']])) {
                    $destinationProperty = $this->destinationProperties[$sourceProperty['XML_ID']];

                    // Сравнение типов свойств
                    if ($destinationProperty['PROPERTY_TYPE'] == $sourceProperty['PROPERTY_TYPE']) {
                        $this->updateProperty($sourceProperty, $destinationProperty);
                        Logger::log("Свойство {$sourceProperty['CODE']} (XML_ID: {$sourceProperty['XML_ID']}) обновлено.");
                    } else {
                        Logger::log("Свойство {$sourceProperty['CODE']} (XML_ID: {$sourceProperty['XML_ID']}) пропущено: типы свойств не совпадают.");
                    }
                }
                // 2. Поиск свойства в целевом инфоблоке по коду, если не найдено по XML_ID
                else if (isset($this->destinationPropertiesByCode[$sourceProperty['CODE']])) {
                    $properties = $this->destinationPropertiesByCode[$sourceProperty['CODE']];

                    foreach ($properties as $property) {
                        // Сравнение типов свойств
                        if ($property['PROPERTY_TYPE'] == $sourceProperty['PROPERTY_TYPE']) {
                            $this->updateProperty($sourceProperty, $property);
                            Logger::log("Свойство {$sourceProperty['CODE']} (CODE: {$sourceProperty['CODE']}) обновлено.");
                            break; // Выходим из цикла после обновления
                        }
                    }

                    if (!$property) { // Если ни одно свойство с совпадающим типом не найдено
                        Logger::log("Свойство {$sourceProperty['CODE']} (CODE: {$sourceProperty['CODE']}) пропущено: типы свойств не совпадают.");
                    }
                }
                // 3. Если свойство не найдено ни по XML_ID, ни по коду в целевом инфоблоке, создаем новое
                else {
                    $destinationProperty = $this->createProperty($sourceProperty, $this->destinationIblockId);
                    Logger::log("Свойство {$sourceProperty['CODE']} создано.");

                    if ($sourceProperty['PROPERTY_TYPE'] == 'L' && $destinationProperty) {
                        $this->processEnumValues($sourceProperty, $destinationProperty['ID']);
                    }
                }

                // Обработка для инфоблока торговых предложений, если он существует
                if ($this->destinationOffersIblockId) {
                    // 1. Поиск свойства в инфоблоке ТП по XML_ID
                    if ($sourceProperty['XML_ID'] && isset($this->destinationOffersProperties[$sourceProperty['XML_ID']])) {
                        $destinationOffersProperty = $this->destinationOffersProperties[$sourceProperty['XML_ID']];

                        // Сравнение типов свойств
                        if ($destinationOffersProperty['PROPERTY_TYPE'] == $sourceProperty['PROPERTY_TYPE']) {
                            $this->updateProperty($sourceProperty, $destinationOffersProperty);
                            Logger::log("Свойство {$sourceProperty['CODE']} (XML_ID: {$sourceProperty['XML_ID']}) обновлено в ТП.");
                        } else {
                            Logger::log("Свойство {$sourceProperty['CODE']} (XML_ID: {$sourceProperty['XML_ID']}) пропущено в ТП: типы свойств не совпадают.");
                        }
                    }
                    // 2. Поиск свойства в инфоблоке ТП по коду, если не найдено по XML_ID
                    else if (isset($this->destinationOffersPropertiesByCode[$sourceProperty['CODE']])) {
                        $properties = $this->destinationOffersPropertiesByCode[$sourceProperty['CODE']];

                        foreach ($properties as $property) {
                            // Сравнение типов свойств
                            if ($property['PROPERTY_TYPE'] == $sourceProperty['PROPERTY_TYPE']) {
                                $this->updateProperty($sourceProperty, $property);
                                Logger::log("Свойство {$sourceProperty['CODE']} (CODE: {$sourceProperty['CODE']}) обновлено в ТП.");
                                break; // Выходим из цикла после обновления
                            }
                        }

                        if (!$property) { // Если ни одно свойство с совпадающим типом не найдено
                            Logger::log("Свойство {$sourceProperty['CODE']} (CODE: {$sourceProperty['CODE']}) пропущено в ТП: типы свойств не совпадают.");
                        }
                    }
                    // 3. Если свойство не найдено ни по XML_ID, ни по коду в инфоблоке ТП, создаем новое
                    else {
                        $destinationOffersProperty = $this->createProperty($sourceProperty, $this->destinationOffersIblockId);
                        Logger::log("Свойство {$sourceProperty['CODE']} создано в ТП.");

                        if ($sourceProperty['PROPERTY_TYPE'] == 'L' && $destinationOffersProperty) {
                            $this->processEnumValues($sourceProperty, $destinationOffersProperty['ID']);
                        }
                    }
                }
            }

            $this->connection->commitTransaction();
            Logger::log("Транзакция успешно завершена");
        } catch (\Exception $e) {
            $this->connection->rollbackTransaction();
            Logger::log("Ошибка при обработке свойств: " . $e->getMessage());
            throw $e;
        }

        Logger::log("Завершение processProperties()");
    }


    private function findDestinationProperty($sourceProperty, $destinationPropertiesByCode)
    {
        if ($sourceProperty['XML_ID'] && isset($destinationPropertiesByCode[$sourceProperty['XML_ID']])) {
            return $destinationPropertiesByCode[$sourceProperty['XML_ID']];
        }

        if (isset($destinationPropertiesByCode[$sourceProperty['CODE']])) {
            $properties = $destinationPropertiesByCode[$sourceProperty['CODE']];
            foreach ($properties as $property) {
                // Добавьте проверку на другие атрибуты, чтобы выбрать правильное свойство
                if ($property['PROPERTY_TYPE'] == $sourceProperty['PROPERTY_TYPE'] &&
                    $property['MULTIPLE'] == $sourceProperty['MULTIPLE']) {
                    return $property;
                }
            }
        }

        return null;
    }

    private function updateProperty($sourceProperty, $destinationProperty)
    {
        $updateFields = $this->preparePropertyFields($sourceProperty);
        PropertyTable::update($destinationProperty['ID'], $updateFields);
    }

    private function createProperty($sourceProperty, $iblockId)
    {
        // Проверяем, существует ли уже свойство с таким XML_ID в целевом инфоблоке
        $existingProperty = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $iblockId, 'XML_ID' => $sourceProperty['XML_ID']]
        ])->fetch();

        if ($existingProperty) {
            Logger::log("Свойство {$sourceProperty['CODE']} (XML_ID: {$sourceProperty['XML_ID']}) уже существует в целевом инфоблоке.");
            return ['ID' => $existingProperty['ID'], 'XML_ID' => $sourceProperty['XML_ID']];
        } else {
            // Создаем новое свойство с исходным XML_ID
            $newFields = $this->preparePropertyFields($sourceProperty);
            $newFields['IBLOCK_ID'] = $iblockId;

            $result = PropertyTable::add($newFields);
            return ['ID' => $result->getId(), 'XML_ID' => $sourceProperty['XML_ID']];
        }
    }

    private function generateUniqueXmlId($baseCode, $iblockId)
    {
        return $baseCode . '_' . $iblockId . '_' . uniqid();
    }

    private function preparePropertyFields($property)
    {
        $allowedKeys = [
            "NAME",
            "ACTIVE",
            "SORT",
            "CODE",
            "DEFAULT_VALUE",
            "PROPERTY_TYPE",
            "ROW_COUNT",
            "COL_COUNT",
            "LIST_TYPE",
            "MULTIPLE",
            "XML_ID",
            "FILE_TYPE",
            "MULTIPLE_CNT",
            "LINK_IBLOCK_ID",
            "WITH_DESCRIPTION",
            "SEARCHABLE",
            "FILTRABLE",
            "IS_REQUIRED",
            "USER_TYPE",
            "USER_TYPE_SETTINGS",
            "HINT",
            "SMART_FILTER"
        ];
        return array_intersect_key($property, array_flip($allowedKeys));
    }

    private function getEnumValues($propertyId)
    {
        return PropertyEnumerationTable::getList([
            'filter' => ['PROPERTY_ID' => $propertyId],
            'order' => ['DEF' => 'DESC', 'SORT' => 'ASC']
        ])->fetchAll();
    }

    private function processEnumValues($sourceProperty, $destinationPropertyId)
    {
        $existingEnumValues = PropertyEnumerationTable::getList([
            'filter' => ['PROPERTY_ID' => $destinationPropertyId]
        ])->fetchAll();

        $existingEnumValuesMap = array_column($existingEnumValues, null, 'XML_ID');
        $sourceEnumValuesMap = array_column($sourceProperty['ENUM_VALUES'], null, 'XML_ID');

        foreach ($sourceEnumValuesMap as $xmlId => $sourceEnumValue) {
            $newXmlId = $this->addIdSuffix($xmlId, $destinationPropertyId);

            if (isset($existingEnumValuesMap[$newXmlId])) {
                $this->updateEnumValue($sourceEnumValue, $existingEnumValuesMap[$newXmlId], $newXmlId, $destinationPropertyId);
            } else {
                $this->createEnumValue($sourceEnumValue, $destinationPropertyId, $newXmlId);
            }

            unset($existingEnumValuesMap[$newXmlId]);
        }

        foreach ($existingEnumValuesMap as $enumValue) {
            if (!$this->isCustomXmlId($enumValue['XML_ID'])) {
                PropertyEnumerationTable::delete(['ID' => $enumValue['ID'], 'PROPERTY_ID' => $destinationPropertyId]);
            }
        }
    }

    private function addIdSuffix($xmlId, $propertyId)
    {
        return $xmlId . '_' . $propertyId;
    }

    private function isCustomXmlId($xmlId)
    {
        return !preg_match('/_\d+$/', $xmlId);
    }

    private function updateEnumValue($sourceEnumValue, $existingEnumValue, $newXmlId, $propertyId)
    {
        PropertyEnumerationTable::update(
            ['ID' => $existingEnumValue['ID'], 'PROPERTY_ID' => $propertyId],
            [
                'VALUE' => $sourceEnumValue['VALUE'],
                'SORT' => $sourceEnumValue['SORT'],
                'DEF' => $sourceEnumValue['DEF'],
                'XML_ID' => $newXmlId
            ]
        );
    }

    private function createEnumValue($enumValue, $propertyId, $xmlId)
    {
        PropertyEnumerationTable::add([
            'PROPERTY_ID' => $propertyId,
            'VALUE' => $enumValue['VALUE'],
            'SORT' => $enumValue['SORT'],
            'DEF' => $enumValue['DEF'],
            'XML_ID' => $xmlId
        ]);
    }
}