<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\Logger;

Loader::includeModule('iblock');
Loader::includeModule('highloadblock');

class IblockPropertiesCopier
{
    private $moduleId;
    private $sourceIblockId;
    private $destinationIblockId;
    private $destinationOffersIblockId;
    private $properties = [];
    private $colorHlbId;
    private $sizeHlbId;

    public function __construct($moduleId, $sourceIblockId, $destinationIblockId, $destinationOffersIblockId)
    {
        $this->moduleId = $moduleId;
        $this->sourceIblockId = $sourceIblockId;
        $this->destinationIblockId = $destinationIblockId;
        $this->destinationOffersIblockId = $destinationOffersIblockId;
        $this->colorHlbId = \COption::GetOptionString($this->moduleId, 'COLOR_HLB_ID');
        $this->sizeHlbId = \COption::GetOptionString($this->moduleId, 'SIZE_HLB_ID');
    }

    public function copyProperties()
    {
        Logger::log("Начало copyProperties()");
        $this->ensureDirectoryProperty('COLOR_MODULE_REF', 'Цвета для товаров', $this->colorHlbId);
        $this->ensureDirectoryProperty('SIZE_MODULE_REF', 'Размеры для товаров', $this->sizeHlbId);
        $this->loadProperties();
        $this->processProperties();
        Logger::log("Завершение copyProperties()");
    }

    private function loadProperties()
    {
        $properties = \CIBlockProperty::GetList(
            ['sort' => 'asc', 'name' => 'asc'],
            ['ACTIVE' => 'Y', 'IBLOCK_ID' => $this->sourceIblockId]
        );

        while ($prop = $properties->GetNext()) {
            $this->properties[] = $prop;
        }
    }

    private function processProperties()
    {
        foreach ($this->properties as $property) {
            $this->copyProperty($property);
        }
    }

    private function copyProperty($property)
    {
        Logger::log("Обработка свойства: {$property['CODE']}");

        $propertyFields = [
            'NAME' => $property['NAME'],
            'ACTIVE' => $property['ACTIVE'],
            'SORT' => $property['SORT'],
            'CODE' => $property['CODE'],
            'DEFAULT_VALUE' => $property['DEFAULT_VALUE'],
            'PROPERTY_TYPE' => $property['PROPERTY_TYPE'],
            'ROW_COUNT' => $property['ROW_COUNT'],
            'COL_COUNT' => $property['COL_COUNT'],
            'LIST_TYPE' => $property['LIST_TYPE'],
            'MULTIPLE' => $property['MULTIPLE'],
            'XML_ID' => $property['XML_ID'],
            'FILE_TYPE' => $property['FILE_TYPE'],
            'MULTIPLE_CNT' => $property['MULTIPLE_CNT'],
            'TMP_ID' => $property['TMP_ID'],
            'LINK_IBLOCK_ID' => $property['LINK_IBLOCK_ID'],
            'WITH_DESCRIPTION' => $property['WITH_DESCRIPTION'],
            'SEARCHABLE' => $property['SEARCHABLE'],
            'FILTRABLE' => $property['FILTRABLE'],
            'IS_REQUIRED' => $property['IS_REQUIRED'],
            'VERSION' => $property['VERSION'],
            'USER_TYPE' => $property['USER_TYPE'],
            'USER_TYPE_SETTINGS' => $property['USER_TYPE_SETTINGS'],
            'HINT' => $property['HINT'],
        ];

        $destIblockProperty = new \CIBlockProperty;

        // Функция для поиска существующего свойства
        $findExistingProperty = function($iblockId) use ($property) {
            $existingProperty = null;

            // Поиск по XML_ID, если оно не пустое
            if (!empty($property['XML_ID'])) {
                $existingProperty = \CIBlockProperty::GetList([], [
                    'IBLOCK_ID' => $iblockId,
                    'XML_ID' => $property['XML_ID']
                ])->Fetch();
            }

            // Если не найдено по XML_ID, ищем по CODE
            if (!$existingProperty) {
                $existingProperty = \CIBlockProperty::GetList([], [
                    'IBLOCK_ID' => $iblockId,
                    'CODE' => $property['CODE']
                ])->Fetch();
            }

            return $existingProperty;
        };

        try {
            // Обработка для основного инфоблока
            $existingProperty = $findExistingProperty($this->destinationIblockId);
            if ($existingProperty) {
                Logger::log("Свойство {$property['CODE']} (XML_ID: {$property['XML_ID']}) уже существует в целевом инфоблоке.");
                $propertyId = $existingProperty['ID'];
                // Подготовка полей для обновления
                $updateFields = array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationIblockId]);
                // Попытка обновления свойства
                if ($destIblockProperty->Update($propertyId, $updateFields)) {
                    Logger::log("Свойство {$property['CODE']} обновлено.");
                } else {
                    throw new \Exception("Ошибка при обновлении свойства {$property['CODE']}: " . $destIblockProperty->LAST_ERROR);
                }
            } else {
                $propertyId = $destIblockProperty->Add(array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationIblockId]));
                if ($propertyId) {
                    Logger::log("Свойство {$property['CODE']} создано.");
                } else {
                    throw new \Exception("Ошибка при создании свойства {$property['CODE']}: " . $destIblockProperty->LAST_ERROR);
                }
            }

            if ($propertyId && $property['PROPERTY_TYPE'] == 'L') {
                $this->copyPropertyEnumValues($property['ID'], $propertyId);
            }

            // Обработка для инфоблока торговых предложений
            if ($this->destinationOffersIblockId) {
                Logger::log("Обработка свойства {$property['CODE']} для инфоблока торговых предложений (ID: {$this->destinationOffersIblockId})");

                $existingOfferProperty = $findExistingProperty($this->destinationOffersIblockId);
                if ($existingOfferProperty) {
                    Logger::log("Свойство {$property['CODE']} (XML_ID: {$property['XML_ID']}) уже существует в инфоблоке торговых предложений.");
                    $offerPropertyId = $existingOfferProperty['ID'];
            
                    // Подготовка полей для обновления ТП
                    $updateOfferFields = array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationOffersIblockId]);

                    // Попытка обновления свойства ТП
                    if ($destIblockProperty->Update($offerPropertyId, $updateOfferFields)) {
                        Logger::log("Свойство {$property['CODE']} обновлено в ТП.");
                    } else {
                        throw new \Exception("Ошибка при обновлении свойства {$property['CODE']} в ТП: " . $destIblockProperty->LAST_ERROR);
                    }
                } else {
                    Logger::log("Создание нового свойства {$property['CODE']} в инфоблоке торговых предложений.");
                    $offerPropertyId = $destIblockProperty->Add(array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationOffersIblockId]));
                    if ($offerPropertyId) {
                        Logger::log("Свойство {$property['CODE']} создано в ТП.");
                    } else {
                        throw new \Exception("Ошибка при создании свойства {$property['CODE']} в ТП: " . $destIblockProperty->LAST_ERROR);
                    }
                }

                if (isset($offerPropertyId) && $offerPropertyId && $property['PROPERTY_TYPE'] == 'L') {
                    $this->copyPropertyEnumValues($property['ID'], $offerPropertyId);
                }
            } else {
                Logger::log("Инфоблок торговых предложений не задан.");
            }

        } catch (\Exception $e) {
            Logger::log("Исключение: " . $e->getMessage() . "");
            Logger::log("Трассировка стека:");
            Logger::log("<pre>" . $e->getTraceAsString() . "</pre>");
        }

        // Дополнительная проверка на ошибки
        global $DB;
        if ($DB->GetErrorMessage()) {
            Logger::log("Final Query Error: " . $DB->GetErrorMessage() . "");
            Logger::log("Final Last SQL Query: " . $DB->LastQuery() . "");
        }
    }

    private function copyPropertyEnumValues($sourcePropertyId, $destPropertyId)
    {
        $enumValues = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['PROPERTY_ID' => $sourcePropertyId]
        );

        $obEnum = new \CIBlockPropertyEnum;
        while ($enumValue = $enumValues->GetNext()) {
            // Проверяем, есть ли исходный XML_ID. Если нет, используем значение VALUE как XML_ID
            $sourceXmlId = !empty($enumValue['XML_ID']) ? $enumValue['XML_ID'] : strtolower($enumValue['VALUE']);

            // Генерация уникального XML_ID путем объединения ID свойства и исходного XML_ID
            $newXmlId = $destPropertyId . '_' . $sourceXmlId;

            // Проверяем, существует ли уже такое значение по XML_ID
            $existingEnum = \CIBlockPropertyEnum::GetList(
                [],
                [
                    'PROPERTY_ID' => $destPropertyId,
                    'XML_ID' => $newXmlId
                ]
            )->Fetch();

            if ($existingEnum) {
                Logger::log("Значение списка '{$enumValue['VALUE']}' уже существует для свойства {$destPropertyId} с XML_ID '{$newXmlId}'.");
                continue;
            }

            $fields = [
                'PROPERTY_ID' => $destPropertyId,
                'VALUE' => $enumValue['VALUE'],
                'DEF' => $enumValue['DEF'],
                'SORT' => $enumValue['SORT'],
                'XML_ID' => $newXmlId,
            ];

            if ($obEnum->Add($fields)) {
                Logger::log("Значение списка '{$enumValue['VALUE']}' скопировано для свойства {$destPropertyId} с XML_ID '{$newXmlId}'.");
            } else {
                Logger::log("Ошибка при копировании значения списка '{$enumValue['VALUE']}' для свойства {$destPropertyId}: " . $obEnum->LAST_ERROR . "");
            }
        }
    }

    private function ensureDirectoryProperty($code, $name, $hlbId)
    {
        Logger::log("Начало ensureDirectoryProperty для {$code}");

        $iblockIds = [$this->destinationIblockId];
        if ($this->destinationOffersIblockId) {
            $iblockIds[] = $this->destinationOffersIblockId;
        }

        // Получаем информацию о Highload-блоке по ID
        $hlblock = HighloadBlockTable::getById($hlbId)->fetch();
        if (!$hlblock) {
            Logger::log("Ошибка: Highload-блок с ID {$hlbId} не найден");
            return;
        }

        $hlbName = $hlblock['NAME'];
        Logger::log("Найден Highload-блок: {$hlbName} (ID: {$hlbId})");

        foreach ($iblockIds as $iblockId) {
            $property = \CIBlockProperty::GetList([], [
                'IBLOCK_ID' => $iblockId,
                'CODE' => $code
            ])->Fetch();

            $userTypeSettings = [
                'TABLE_NAME' => $hlblock['TABLE_NAME'],
                'DIRECTORY_TABLE_NAME' => $hlblock['TABLE_NAME'],
                'size' => 1,
                'width' => 0,
                'group' => 'N',
                'multiple' => 'N',
                'directoryId' => $hlbId
            ];

            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'ACTIVE' => 'Y',
                'SORT' => '500',
                'CODE' => $code,
                'PROPERTY_TYPE' => 'S',
                'USER_TYPE' => 'directory',
                'LIST_TYPE' => 'L',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SEARCHABLE' => 'N',
                'FILTRABLE' => 'Y',
                'WITH_DESCRIPTION' => 'N',
                'MULTIPLE_CNT' => '5',
                'HINT' => '',
                'USER_TYPE_SETTINGS' => $userTypeSettings,
                'FEATURES' => [
                    [
                        'MODULE_ID' => 'catalog',
                        'FEATURE_ID' => 'IN_BASKET',
                        'IS_ENABLED' => 'Y'
                    ]
                ]
            ];

            $ibp = new \CIBlockProperty;

            if (!$property) {
                $propertyId = $ibp->Add($fields);
                if ($propertyId) {
                    Logger::log("Свойство {$code} создано для инфоблока {$iblockId}");
                } else {
                    Logger::log("Ошибка при создании свойства {$code} для инфоблока {$iblockId}: " . $ibp->LAST_ERROR . "");
                    continue;
                }
            } else {
                $propertyId = $property['ID'];
                if ($ibp->Update($propertyId, $fields)) {
                    Logger::log("Свойство {$code} обновлено для инфоблока {$iblockId}");
                } else {
                    Logger::log("Ошибка при обновлении свойства {$code} для инфоблока {$iblockId}: " . $ibp->LAST_ERROR . "");
                    continue;
                }
            }

            // Проверяем, правильно ли установлена привязка
            $updatedProperty = \CIBlockProperty::GetByID($propertyId)->Fetch();
            $updatedUserTypeSettings = $updatedProperty['USER_TYPE_SETTINGS'];
            
            if (is_string($updatedUserTypeSettings)) {
                $updatedUserTypeSettings = unserialize($updatedUserTypeSettings);
            }
            
            if (!is_array($updatedUserTypeSettings) || $updatedUserTypeSettings['TABLE_NAME'] !== $hlblock['TABLE_NAME']) {
                Logger::log("Ошибка: Привязка к Highload-блоку не установлена корректно для свойства {$code} (ID: {$propertyId})");
                Logger::log("Текущие настройки: " . Logger::log($updatedUserTypeSettings, true) . "");
            } else {
                Logger::log("Привязка к Highload-блоку {$hlbName} успешно установлена для свойства {$code} (ID: {$propertyId})");
            }
        }

        Logger::log("Завершение ensureDirectoryProperty для {$code}");
    }
}
