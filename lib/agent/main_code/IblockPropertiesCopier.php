<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\Logger;
use Bitrix\Iblock\Model\PropertyFeature;

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
    private $typeHlbId;

    /**
     * Конструктор для инициализации копировщика с необходимыми ID.
     *
     * @param string $moduleId
     * @param int $sourceIblockId
     * @param int $destinationIblockId
     * @param int|null $destinationOffersIblockId
     */
    public function __construct($moduleId, $sourceIblockId, $destinationIblockId, $destinationOffersIblockId = null)
    {
        $this->moduleId = $moduleId;
        $this->sourceIblockId = $sourceIblockId;
        $this->destinationIblockId = $destinationIblockId;
        $this->destinationOffersIblockId = $destinationOffersIblockId;
        $this->typeHlbId = \COption::GetOptionString($this->moduleId, 'TYPE_HLB_ID');
        $this->sizeHlbId = \COption::GetOptionString($this->moduleId, 'SIZE_HLB_ID');
        $this->colorHlbId = \COption::GetOptionString($this->moduleId, 'COLOR_HLB_ID');
    }

    /**
     * Основной метод для запуска процесса копирования свойств.
     */
    public function copyProperties()
    {
        //Logger::log("Начало выполнения copyProperties()");

        try {
            // Убеждаемся, что свойства типа справочник корректно настроены
            $this->ensureDirectoryProperty('COLOR_MODULE_REF', 'Цвета для товаров', $this->colorHlbId);
            $this->ensureDirectoryProperty('SIZE_MODULE_REF', 'Размеры для товаров', $this->sizeHlbId);
            $this->ensureDirectoryProperty('TYPE_MODULE_REF', 'Типы товаров', $this->typeHlbId);

            // Загружаем свойства из исходного инфоблока
            $this->loadProperties();

            // Обрабатываем каждое свойство
            $this->processProperties();
        } catch (\Exception $e) {
            Logger::log("Ошибка в copyProperties(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    /**
     * Загружает все активные свойства из исходного инфоблока.
     */
    private function loadProperties()
    {
        //Logger::log("Начало загрузки свойств из инфоблока ID: {$this->sourceIblockId}");

        try {
            $properties = \CIBlockProperty::GetList(
                ['sort' => 'asc', 'name' => 'asc'],
                ['ACTIVE' => 'Y', 'IBLOCK_ID' => $this->sourceIblockId]
            );

            while ($prop = $properties->GetNext()) {
                $this->properties[] = $prop;
            }
            //Logger::log("Загружено свойств: " . count($this->properties));
        } catch (\Exception $e) {
            Logger::log("Ошибка в loadProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    /**
     * Обрабатывает каждое свойство, копируя его в целевые инфоблоки.
     */
    private function processProperties()
    {

        try {
            foreach ($this->properties as $property) {
                $this->copyProperty($property);
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в processProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Копирует одно свойство в целевые инфоблоки.
     *
     * @param array $property Массив данных свойства.
     */
    private function copyProperty($property)
    {
        //Logger::log("Начало обработки свойства: {$property['CODE']}");

        // Подготовка полей свойства для добавления
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

        try {
            // Копируем свойство в основной целевой инфоблок
            $existingProperty = $this->findExistingProperty($this->destinationIblockId, $property['CODE'], $property['XML_ID']);
            if ($existingProperty) {
                Logger::log("Свойство {$property['CODE']} уже существует в целевом инфоблоке. Пропускаем обновление.");
                $propertyId = $existingProperty['ID'];
            } else {
                $propertyId = $destIblockProperty->Add(array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationIblockId]));
                if ($propertyId) {
                    Logger::log("Свойство {$property['CODE']} создано в инфоблоке ID: {$this->destinationIblockId}");
                } else {
                    throw new \Exception("Ошибка при создании свойства {$property['CODE']}: " . $destIblockProperty->LAST_ERROR);
                }
            }

            // Если тип свойства - список, копируем значения
            if ($propertyId && $property['PROPERTY_TYPE'] == 'L') {
                $this->copyPropertyEnumValues($property['ID'], $propertyId);
            }

            // Если есть инфоблок торговых предложений, обрабатываем его также
            if ($this->destinationOffersIblockId) {
                Logger::log("Обработка свойства {$property['CODE']} для инфоблока предложений (ID: {$this->destinationOffersIblockId})");

                $existingOfferProperty = $this->findExistingProperty($this->destinationOffersIblockId, $property['CODE'], $property['XML_ID']);
                if ($existingOfferProperty) {
                    Logger::log("Свойство {$property['CODE']} уже существует в инфоблоке предложений. Пропускаем обновление.");
                    $offerPropertyId = $existingOfferProperty['ID'];
                } else {
                    Logger::log("Создание свойства {$property['CODE']} в инфоблоке предложений.");
                    $offerPropertyId = $destIblockProperty->Add(array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationOffersIblockId]));
                    if ($offerPropertyId) {
                        Logger::log("Свойство {$property['CODE']} создано в инфоблоке предложений.");
                    } else {
                        throw new \Exception("Ошибка при создании свойства {$property['CODE']} в инфоблоке предложений: " . $destIblockProperty->LAST_ERROR);
                    }
                }

                // Если тип свойства - список, копируем значения
                if (isset($offerPropertyId) && $offerPropertyId && $property['PROPERTY_TYPE'] == 'L') {
                    $this->copyPropertyEnumValues($property['ID'], $offerPropertyId);
                }
            } else {
                Logger::log("Инфоблок предложений не задан.","WARRING");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в copyProperty(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

        // Дополнительная проверка на ошибки
        global $DB;
        if ($DB->GetErrorMessage()) {
            Logger::log("Ошибка запроса: " . $DB->GetErrorMessage(), "ERROR");
            Logger::log("Последний SQL запрос: " . $DB->LastQuery(), "ERROR");
        }
    }

    /**
     * Копирует значения спискового свойства из исходного свойства в целевое свойство.
     *
     * @param int $sourcePropertyId
     * @param int $destPropertyId
     */
    private function copyPropertyEnumValues($sourcePropertyId, $destPropertyId)
    {
       // Logger::log("Начало копирования значений списка для свойства ID: {$destPropertyId}");

        try {
            $enumValues = \CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC'],
                ['PROPERTY_ID' => $sourcePropertyId]
            );

            $obEnum = new \CIBlockPropertyEnum;
            while ($enumValue = $enumValues->GetNext()) {
                // Проверяем существование XML_ID или используем VALUE в качестве запасного варианта
                $sourceXmlId = !empty($enumValue['XML_ID']) ? $enumValue['XML_ID'] : strtolower($enumValue['VALUE']);

                // Генерируем уникальный XML_ID, объединяя ID свойства и исходный XML_ID
                $newXmlId = $destPropertyId . '_' . $sourceXmlId;

                // Проверяем, существует ли уже такое значение
                $existingEnum = \CIBlockPropertyEnum::GetList(
                    [],
                    [
                        'PROPERTY_ID' => $destPropertyId,
                        'XML_ID' => $newXmlId
                    ]
                )->Fetch();

                if ($existingEnum) {
                    //Logger::log("Значение списка '{$enumValue['VALUE']}' уже существует для свойства {$destPropertyId} с XML_ID '{$newXmlId}'.");
                    continue;
                }

                // Подготовка полей для нового значения
                $fields = [
                    'PROPERTY_ID' => $destPropertyId,
                    'VALUE' => $enumValue['VALUE'],
                    'DEF' => $enumValue['DEF'],
                    'SORT' => $enumValue['SORT'],
                    'XML_ID' => $newXmlId,
                ];

                if ($obEnum->Add($fields)) {
                    //Logger::log("Значение списка '{$enumValue['VALUE']}' скопировано для свойства {$destPropertyId} с XML_ID '{$newXmlId}'.");
                } else {
                    Logger::log("Ошибка при копировании значения списка '{$enumValue['VALUE']}' для свойства {$destPropertyId}: " . $obEnum->LAST_ERROR, "ERROR");
                }
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в copyPropertyEnumValues(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Убеждается, что свойство типа справочник существует и устанавливает необходимые настройки.
     *
     * @param string $code
     * @param string $name
     * @param int $hlbId
     */
    private function ensureDirectoryProperty($code, $name, $hlbId)
    {
        //Logger::log("Начало проверки и создания свойства справочника {$code}");

        try {
            // IDs инфоблоков для обработки
            $iblockIds = [$this->destinationIblockId];
            if ($this->destinationOffersIblockId) {
                $iblockIds[] = $this->destinationOffersIblockId;
            }

            // Получаем информацию о Highload-блоке по ID
            $hlblock = HighloadBlockTable::getById($hlbId)->fetch();
            if (!$hlblock) {
                throw new \Exception("Highload-блок с ID {$hlbId} не найден");
            }

            $hlbName = $hlblock['NAME'];
            //Logger::log("Найден Highload-блок: {$hlbName} (ID: {$hlbId})");

            foreach ($iblockIds as $iblockId) {
                // Поиск существующего свойства
                $property = $this->findExistingProperty($iblockId, $code);

                // Подготовка настроек типа пользователя для справочника
                $userTypeSettings = [
                    'TABLE_NAME' => $hlblock['TABLE_NAME'],
                    'size' => 1,
                    'width' => 0,
                    'group' => 'N',
                    'multiple' => 'N',
                    'directoryId' => $hlbId
                ];

                // Получаем настройки Features для инфоблока
                $features = $this->getFeaturesForIblock($iblockId);

                // Подготовка полей для свойства
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
                    'FEATURES' => $features
                ];

                $ibp = new \CIBlockProperty;

                if (!$property) {
                    // Добавляем новое свойство
                    $propertyId = $ibp->Add($fields);
                    if ($propertyId) {
                        //Logger::log("Свойство {$code} создано для инфоблока {$iblockId}");
                        // Устанавливаем свойства Features явно
                        $this->setPropertyFeatures($propertyId, $features);
                    } else {
                        throw new \Exception("Ошибка при создании свойства {$code} для инфоблока {$iblockId}: " . $ibp->LAST_ERROR);
                    }
                } else {
                    $propertyId = $property['ID'];

                    // Проверяем, является ли свойство типа 'directory' и связано ли с правильным HLB
                    $currentUserType = $property['USER_TYPE'];
                    $currentUserTypeSettings = $property['USER_TYPE_SETTINGS'];

                    if (is_string($currentUserTypeSettings)) {
                        $currentUserTypeSettings = unserialize($currentUserTypeSettings);
                    }

                    $isCorrectDirectory = (
                        $currentUserType === 'directory' &&
                        is_array($currentUserTypeSettings) &&
                        isset($currentUserTypeSettings['TABLE_NAME']) &&
                        $currentUserTypeSettings['TABLE_NAME'] === $hlblock['TABLE_NAME']
                    );

                    if ($isCorrectDirectory) {
                        //Logger::log("Свойство {$code} уже существует в инфоблоке {$iblockId} и корректно связано с Highload-блоком. Пропускаем обновление.");
                    } else {
                        // Обновляем свойство
                        if ($ibp->Update($propertyId, $fields)) {
                            //Logger::log("Свойство {$code} обновлено для инфоблока {$iblockId}");
                            // Устанавливаем свойства Features явно
                            $this->setPropertyFeatures($propertyId, $features);
                        } else {
                            throw new \Exception("Ошибка при обновлении свойства {$code} для инфоблока {$iblockId}: " . $ibp->LAST_ERROR);
                        }
                    }
                }

                //Logger::log("Завершена обработка свойства {$code} для инфоблока {$iblockId}");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в ensureDirectoryProperty(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    /**
     * Ищет существующее свойство в инфоблоке по коду или XML_ID.
     *
     * @param int $iblockId
     * @param string $code
     * @param string|null $xmlId
     * @return array|null
     */
    private function findExistingProperty($iblockId, $code, $xmlId = null)
    {
        $filter = ['IBLOCK_ID' => $iblockId];

        if (!empty($xmlId)) {
            $filter['XML_ID'] = $xmlId;
        } else {
            $filter['CODE'] = $code;
        }

        $existingProperty = \CIBlockProperty::GetList([], $filter)->Fetch();

        return $existingProperty ?: null;
    }

    /**
     * Генерирует массив features для данного инфоблока.
     *
     * @param int $iblockId
     * @return array
     */
    private function getFeaturesForIblock($iblockId)
    {
        $features = [];

        if ($iblockId == $this->destinationIblockId) {
            // Для основного инфоблока устанавливаем LIST_PAGE_SHOW и DETAIL_PAGE_SHOW
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'LIST_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'DETAIL_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
        } elseif ($iblockId == $this->destinationOffersIblockId) {
            // Для инфоблока предложений устанавливаем все необходимые features
            $features[] = [
                'MODULE_ID' => 'catalog',
                'FEATURE_ID' => 'OFFER_TREE',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'catalog',
                'FEATURE_ID' => 'IN_BASKET',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'LIST_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'DETAIL_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
        }

        return $features;
    }

    /**
     * Устанавливает свойства features для свойства в базе данных.
     *
     * @param int $propertyId
     * @param array $features
     */
    private function setPropertyFeatures($propertyId, $features)
    {
        // Используем модель PropertyFeature из Bitrix для установки features
        try {
            PropertyFeature::SetFeatures($propertyId, $features);
            //Logger::log("Features для свойства ID {$propertyId} установлены.");
        } catch (\Exception $e) {
            Logger::log("Ошибка при установке features для свойства ID {$propertyId}: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }
}
