<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;
use Pragma\ImportModule\Logger;

class PropertyHelper
{
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
                $html .= '<option value="' . $code . '"' . $selected . '>' . $name . '</option>';
            }

            return $html;

        } catch (\Exception $e) {
            Logger::log("Ошибка в getPropertyOptionsHtml: " . $e->getMessage(), "ERROR");
            return '';
        }
    }

    public static function getPropertyEnumValues($iblockId, $propertyCode)
    {
        try {
            Loader::includeModule('iblock');

            // Debug output
            Logger::log("Getting enum values for property {$propertyCode} in iblock {$iblockId}", "DEBUG");

            // Get property ID
            $propertyRes = PropertyTable::getList([
                'filter' => ['IBLOCK_ID' => $iblockId, 'CODE' => $propertyCode],
                'select' => ['ID', 'PROPERTY_TYPE']
            ]);

            if ($property = $propertyRes->fetch()) {
                Logger::log("Found property: " . print_r($property, true), "DEBUG");

                if ($property['PROPERTY_TYPE'] == 'L') {
                    $propertyId = $property['ID'];

                    // Get enum values
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

                    Logger::log("Found enum values: " . print_r($enumValues, true), "DEBUG");
                    return $enumValues;
                } else {
                    Logger::log("Property {$propertyCode} is not a list property in iblock {$iblockId}", "ERROR");
                    return false;
                }
            } else {
                Logger::log("Property {$propertyCode} not found in iblock {$iblockId}", "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Error in getPropertyEnumValues: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Получает все свойства для каталога и торговых предложений
     * @param int $iblockIdCatalog ID инфоблока каталога
     * @return array Массив со свойствами каталога и ТП
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

            // Получаем свойства каталога
            if ($iblockIdCatalog > 0) {
                $result['catalogListProperties'] = self::getIblockProperties($iblockIdCatalog, ['L']);
                
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

            // Получаем свойства торговых предложений
            $offersIblockId = \CCatalogSKU::GetInfoByProductIBlock($iblockIdCatalog)['IBLOCK_ID'];
            if ($offersIblockId) {
                $result['offersListProperties'] = self::getIblockProperties($offersIblockId, ['L']);
                
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
            Logger::log("Error in getAllProperties: " . $e->getMessage(), "ERROR");
            return [];
        }
    }

    /**
     * Получает свойства типа "Список" для каталога
     * @param int $iblockIdCatalog ID инфоблока каталога
     * @return array Массив свойств каталога
     */
    public static function getCatalogListProperties($iblockIdCatalog)
    {
        if ($iblockIdCatalog > 0) {
            return self::getIblockProperties($iblockIdCatalog, ['L'], null) ?: [];
        }
        return [];
    }

    /**
     * Получает свойства типа "Список" для торговых предложений
     * @param int $iblockIdCatalog ID инфоблока каталога
     * @return array Массив свойств торговых предложений
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
            Logger::log("Error in getOffersListProperties: " . $e->getMessage(), "ERROR");
            return [];
        }
    }
}
