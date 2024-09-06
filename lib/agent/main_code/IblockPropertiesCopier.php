<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Main\Application;
use Pragma\ImportModule\Logger;

Loader::includeModule('iblock');

class IblockPropertiesCopier
{
    private $sourceIblockId;
    private $destinationIblockId;
    private $sourceProperties;
    private $destinationProperties;
    private $destinationPropertiesByCode;
    private $connection;

    public function __construct($sourceIblockId, $destinationIblockId)
    {
        $this->sourceIblockId = $sourceIblockId;
        $this->destinationIblockId = $destinationIblockId;
        $this->sourceProperties = [];
        $this->destinationProperties = [];
        $this->destinationPropertiesByCode = [];
        $this->connection = Application::getConnection();
        
        Logger::log("IblockPropertiesCopier создан. Источник: {$sourceIblockId}, Назначение: {$destinationIblockId}");
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
        // Используем API Bitrix D7 для загрузки свойств
        $this->sourceProperties = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $this->sourceIblockId],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC']
        ])->fetchAll();
        Logger::log("Загружено " . count($this->sourceProperties) . " свойств источника");

        $destinationProperties = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $this->destinationIblockId],
            'select' => ['ID', 'CODE', 'XML_ID']
        ])->fetchAll();
        Logger::log("Загружено " . count($destinationProperties) . " свойств назначения");

        foreach ($destinationProperties as $property) {
            if ($property['XML_ID']) {
                $this->destinationProperties[$property['XML_ID']] = $property;
            }
            $this->destinationPropertiesByCode[$property['CODE']][] = $property;
        }

        // Загружаем значения списков для свойств типа "Список"
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
                $destinationProperty = $this->findDestinationProperty($sourceProperty);

                if ($destinationProperty) {
                    $this->updateProperty($sourceProperty, $destinationProperty);
                } else {
                    $destinationProperty = $this->createProperty($sourceProperty);
                }

                if ($sourceProperty['PROPERTY_TYPE'] == 'L' && $destinationProperty) {
                    $this->processEnumValues($sourceProperty, $destinationProperty['ID']);
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

    private function findDestinationProperty($sourceProperty)
    {
        if ($sourceProperty['XML_ID'] && isset($this->destinationProperties[$sourceProperty['XML_ID']])) {
            return $this->destinationProperties[$sourceProperty['XML_ID']];
        }

        if (isset($this->destinationPropertiesByCode[$sourceProperty['CODE']])) {
            $properties = $this->destinationPropertiesByCode[$sourceProperty['CODE']];
            return $properties[0] ?? null;
        }

        return null;
    }

    private function updateProperty($sourceProperty, $destinationProperty)
    {
        $updateFields = $this->preparePropertyFields($sourceProperty);
        PropertyTable::update($destinationProperty['ID'], $updateFields);
    }

    private function createProperty($sourceProperty)
    {
        $newFields = $this->preparePropertyFields($sourceProperty);
        $newFields['IBLOCK_ID'] = $this->destinationIblockId;
        $newFields['XML_ID'] = $this->generateUniqueXmlId($sourceProperty['CODE']);

        $result = PropertyTable::add($newFields);
        return ['ID' => $result->getId(), 'XML_ID' => $newFields['XML_ID']];
    }

    private function generateUniqueXmlId($baseCode)
    {
        return $baseCode . '_' . uniqid();
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