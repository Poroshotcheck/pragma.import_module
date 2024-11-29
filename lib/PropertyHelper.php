<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;
use Pragma\ImportModule\Logger;

class PropertyHelper
{
    /**
     * Получает свойства инфоблока с заданными типами и множественностью.
     * @param int $iblockId ID инфоблока
     * @param array|null $propertyTypes Типы свойств
     * @param bool|null $multiple Множественность свойств
     * @return array|false
     */
    public static function getIblockProperties($iblockId, $propertyTypes = null, $multiple = null)
    {
        try {
            Loader::includeModule('iblock');

            $filter = [
                'IBLOCK_ID' => $iblockId,
            ];

            if (!empty($propertyTypes)) {
                $filter['PROPERTY_TYPE'] = $propertyTypes;
            }

            if (!is_null($multiple)) {
                $filter['MULTIPLE'] = $multiple;
            }

            $properties = [];
            $dbProperties = PropertyTable::getList([
                'filter' => $filter,
                'select' => ['ID', 'NAME', 'CODE', 'PROPERTY_TYPE', 'MULTIPLE'],
                'order' => ['SORT' => 'ASC', 'NAME' => 'ASC']
            ]);

            while ($property = $dbProperties->fetch()) {
                $properties[$property['CODE']] = $property['NAME'] . ' [' . $property['CODE'] . ']';
            }

            return $properties;

        } catch (\Exception $e) {
            Logger::log("Ошибка в getIblockProperties: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Генерирует HTML для опций свойств инфоблока с использованием кэша.
     * @param int $iblockId ID инфоблока
     * @param string|null $selectedPropertyCode Выбранный код свойства
     * @param array|null $properties Существующие свойства
     * @param array|null $propertyTypes Типы свойств
     * @param bool|null $multiple Множественность свойств
     * @return string
     */
    public static function getPropertyOptionsHtml($iblockId, $selectedPropertyCode = null, $properties = null, $propertyTypes = null, $multiple = null)
    {
        try {
            if (empty($iblockId)) {
                return '';
            }

            if (is_null($properties) || empty($properties)) {
                $properties = CacheHelper::getCachedProperties($iblockId, $propertyTypes, $multiple);

                if (empty($properties)) {
                    $properties = self::getIblockProperties($iblockId, $propertyTypes, $multiple);

                    if ($properties === false) {
                        Logger::log("Не удалось получить свойства для инфоблока: $iblockId", "ERROR");
                        return '';
                    }

                    CacheHelper::saveCachedProperties($iblockId, $properties, $propertyTypes, $multiple);
                }
            }

            $html = '';
            foreach ($properties as $code => $name) {
                $selected = ($code == $selectedPropertyCode) ? ' selected' : '';
                $html .= '<option value="' . htmlspecialcharsbx($code) . '"' . $selected . '>' . htmlspecialcharsbx($name) . '</option>';
            }

            return $html;

        } catch (\Exception $e) {
            Logger::log("Ошибка в getPropertyOptionsHtml: " . $e->getMessage(), "ERROR");
            return '';
        }
    }

    /**
     * Получает значения перечислений для свойства типа "Лист" с использованием кэша.
     * @param int $iblockId ID инфоблока
     * @param string $propertyCode Код свойства
     * @return array|false
     */
    public static function getPropertyEnumValues($iblockId, $propertyCode)
    {
        try {
            Loader::includeModule('iblock');

            // Логирование для отладки
            Logger::log("Получение значений перечислений для свойства {$propertyCode} в инфоблоке {$iblockId}", "DEBUG");

            // Получение ID свойства
            $propertyRes = PropertyTable::getList([
                'filter' => ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode],
                'select' => ['ID', 'PROPERTY_TYPE']
            ]);

            if ($property = $propertyRes->fetch()) {
                Logger::log("Найдено свойство: " . print_r($property, true), "DEBUG");

                if ($property['PROPERTY_TYPE'] == 'L') {
                    $propertyId = $property['ID'];

                    // Проверяем кэш на наличие значений перечислений
                    $enumValues = CacheHelper::getCachedEnumValues($propertyId);
                    if ($enumValues === false) {
                        // Получение значений перечислений из базы данных
                        $enumRes = \CIBlockPropertyEnum::GetList(
                            ['SORT' => 'ASC', 'VALUE' => 'ASC'],
                            ['PROPERTY_ID' => $propertyId]
                        );

                        $enumValues = [];
                        while ($enum = $enumRes->Fetch()) {
                            $enumValues[$enum['ID']] = [
                                'VALUE' => $enum['VALUE'],
                                'XML_ID' => $enum['XML_ID'],
                            ];
                        }

                        // Сохранение в кэш
                        CacheHelper::saveCachedEnumValues($propertyId, $enumValues);
                    } else {
                        Logger::log("Значения перечислений получены из кэша для свойства ID {$propertyId}", "DEBUG");
                    }

                    Logger::log("Найдено значений перечислений: " . print_r($enumValues, true), "DEBUG");
                    return $enumValues;
                } else {
                    Logger::log("Свойство {$propertyCode} не является типом 'Лист' в инфоблоке {$iblockId}", "ERROR");
                    return false;
                }
            } else {
                Logger::log("Свойство {$propertyCode} не найдено в инфоблоке {$iblockId}", "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в getPropertyEnumValues: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Получает все свойства для каталога и торговых предложений с использованием кэша.
     * @param int $iblockIdCatalog ID инфоблока каталога
     * @param array $selectedCatalogProperties Выбранные свойства каталога
     * @param array $selectedOffersProperties Выбранные свойства торговых предложений
     * @return array
     */
    public static function getAllProperties($iblockIdCatalog, $selectedCatalogProperties = [], $selectedOffersProperties = [])
    {
        try {
            Loader::includeModule('iblock');
            Loader::includeModule('catalog');

            $result = [
                'catalogListProperties' => [],
                'offersListProperties' => [],
                'catalogProperties' => [],
                'offerProperties' => []
            ];

            // Получаем свойства каталога с использованием кэша
            if ($iblockIdCatalog > 0) {
                $result['catalogListProperties'] = CacheHelper::getCachedProperties($iblockIdCatalog, ['L'], null);
                if (!$result['catalogListProperties']) {
                    $result['catalogListProperties'] = self::getIblockProperties($iblockIdCatalog, ['L']);
                    CacheHelper::saveCachedProperties($iblockIdCatalog, $result['catalogListProperties'], ['L'], null);
                }

                // Получаем значения для выбранных свойств каталога
                foreach ($selectedCatalogProperties as $propertyCode) {
                    $enumValues = self::getPropertyEnumValues($iblockIdCatalog, $propertyCode);
                    if ($enumValues !== false) {
                        $uniqueKey = 'CATALOG_' . $propertyCode;
                        $result['catalogProperties'][$uniqueKey] = [
                            'NAME' => $result['catalogListProperties'][$propertyCode],
                            'VALUES' => $enumValues,
                            'IS_OFFER' => false,
                            'ORIGINAL_CODE' => $propertyCode
                        ];
                    }
                }
            }

            // Получаем свойства торговых предложений с использованием кэша
            $offersIblockId = \CCatalogSKU::GetInfoByProductIBlock($iblockIdCatalog)['IBLOCK_ID'];
            if ($offersIblockId) {
                $result['offersListProperties'] = CacheHelper::getCachedProperties($offersIblockId, ['L'], null);
                if (!$result['offersListProperties']) {
                    $result['offersListProperties'] = self::getIblockProperties($offersIblockId, ['L']);
                    CacheHelper::saveCachedProperties($offersIblockId, $result['offersListProperties'], ['L'], null);
                }

                // Получаем значения для выбранных свойств торговых предложений
                foreach ($selectedOffersProperties as $propertyCode) {
                    $enumValues = self::getPropertyEnumValues($offersIblockId, $propertyCode);
                    if ($enumValues !== false) {
                        $uniqueKey = 'OFFER_' . $propertyCode;
                        $result['offerProperties'][$uniqueKey] = [
                            'NAME' => $result['offersListProperties'][$propertyCode],
                            'VALUES' => $enumValues,
                            'IS_OFFER' => true,
                            'ORIGINAL_CODE' => $propertyCode
                        ];
                    }
                }
            }

            return $result;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getAllProperties: " . $e->getMessage(), "ERROR");
            return [];
        }
    }

    /**
     * Получает свойства типа "Список" для каталога.
     * @param int $iblockIdCatalog ID инфоблока каталога
     * @return array
     */
    public static function getCatalogListProperties($iblockIdCatalog)
    {
        if ($iblockIdCatalog > 0) {
            return self::getIblockProperties($iblockIdCatalog, ['L'], null) ?: [];
        }
        return [];
    }

    /**
     * Получает свойства типа "Список" для торговых предложений.
     * @param int $iblockIdCatalog ID инфоблока каталога
     * @return array
     */
    public static function getOffersListProperties($iblockIdCatalog)
    {
        try {
            Loader::includeModule('catalog');
            $offersIblockId = \CCatalogSKU::GetInfoByProductIBlock($iblockIdCatalog)['IBLOCK_ID'];
            
            if ($offersIblockId) {
                return self::getIblockProperties($offersIblockId, ['L'], null) ?: [];
            }
            return [];
        } catch (\Exception $e) {
            Logger::log("Ошибка в getOffersListProperties: " . $e->getMessage(), "ERROR");
            return [];
        }
    }
}
