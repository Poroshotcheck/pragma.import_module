<?php

namespace Pragma\ImportModule;

use Pragma\ImportModule\Logger;
use Bitrix\Main\Data\Cache;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\IblockTable;

class CacheHelper
{
    private static $cacheDir = "/pragma.importmodule/module_cache/";

    /**
     * Получает разделы инфоблока из кэша.
     * @param int $iblockId ID инфоблока
     * @return array|false
     */
    public static function getCachedSections($iblockId)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "sections_cache_iblock_" . $iblockId;

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $cachedData = $cache->getVars();
                $sections = $cachedData['sections'] ?? [];
                return $sections;
            }

            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedSections: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Сохраняет разделы инфоблока в кэш.
     * @param int $iblockId ID инфоблока
     * @param array $sections Разделы инфоблока
     * @return bool
     */
    public static function saveCachedSections($iblockId, $sections)
    {
        try {
            // Сортировка по LEFT_MARGIN перед сохранением
            uasort($sections, function ($a, $b) {
                return $a['LEFT_MARGIN'] - $b['LEFT_MARGIN'];
            });

            $cache = Cache::createInstance();
            $cacheId = "sections_cache_iblock_" . $iblockId;

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache(['sections' => $sections]); // Сохраняем с ключом 'sections'
                return true;
            }

            Logger::log("Ошибка сохранения данных в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveCachedSections: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Обновляет кэш разделов инфоблока.
     * @param int $iblockId ID инфоблока
     */
    public static function updateSectionsCache($iblockId)
    {
        try {
            self::clearSectionsCache($iblockId);

            $sections = [];
            $rsSections = SectionTable::getList([
                'filter' => ['IBLOCK_ID' => $iblockId],
                'select' => ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'LEFT_MARGIN'],
                'order' => ['LEFT_MARGIN' => 'ASC']
            ]);
            while ($section = $rsSections->fetch()) {
                $sections[$section['ID']] = $section;
            }

            self::saveCachedSections($iblockId, $sections);
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateSectionsCache: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * Очищает кэш разделов инфоблока.
     * @param int $iblockId ID инфоблока
     */
    public static function clearSectionsCache($iblockId)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "sections_cache_iblock_" . $iblockId;
            $cache->clean($cacheId, self::$cacheDir);
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearSectionsCache: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * Получает список инфоблоков из кэша.
     * @return array|false
     */
    public static function getCachedIblocks()
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "iblocks_cache";

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $iblocks = $cache->getVars();
                return $iblocks;
            }

            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedIblocks: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Сохраняет список инфоблоков в кэш.
     * @param array $iblocks Список инфоблоков
     * @return bool
     */
    public static function saveIblocksCache($iblocks)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "iblocks_cache";

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache($iblocks);
                return true;
            }

            Logger::log("Ошибка сохранения списка инфоблоков в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveIblocksCache: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Обновляет кэш списка инфоблоков.
     */
    public static function updateIblocksCache()
    {
        try {
            self::clearIblocksCache();

            $iblocks = [];
            $rsIblocks = IblockTable::getList([
                'select' => ['ID', 'NAME'],
                'order' => ['NAME' => 'ASC']
            ]);
            while ($arIblock = $rsIblocks->fetch()) {
                $iblocks[$arIblock["ID"]] = $arIblock["NAME"];
            }

            self::saveIblocksCache($iblocks);
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateIblocksCache: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * Очищает кэш списка инфоблоков.
     */
    public static function clearIblocksCache()
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "iblocks_cache";
            $cache->clean($cacheId, self::$cacheDir);
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearIblocksCache: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * Получает свойства инфоблока из кэша.
     * @param int $iblockId ID инфоблока
     * @param array|null $propertyTypes Типы свойств
     * @param bool|null $multiple Множественность свойств
     * @return array|false
     */
    public static function getCachedProperties($iblockId, $propertyTypes = null, $multiple = null)
    {
        try {
            $cache = Cache::createInstance();

            $propertyTypesKey = is_array($propertyTypes) ? implode('_', $propertyTypes) : 'all_types';
            $multipleKey = is_null($multiple) ? 'both' : $multiple;

            $cacheId = "properties_cache_iblock_{$iblockId}_type_{$propertyTypesKey}_multiple_{$multipleKey}";

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $cachedData = $cache->getVars();
                $properties = $cachedData['properties'] ?? [];
                return $properties;
            }

            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedProperties: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Сохраняет свойства инфоблока в кэш.
     * @param int $iblockId ID инфоблока
     * @param array $properties Свойства инфоблока
     * @param array|null $propertyTypes Типы свойств
     * @param bool|null $multiple Множественность свойств
     * @return bool
     */
    public static function saveCachedProperties($iblockId, $properties, $propertyTypes = null, $multiple = null)
    {
        try {
            $cache = Cache::createInstance();

            $propertyTypesKey = is_array($propertyTypes) ? implode('_', $propertyTypes) : 'all_types';
            $multipleKey = is_null($multiple) ? 'both' : $multiple;

            $cacheId = "properties_cache_iblock_{$iblockId}_type_{$propertyTypesKey}_multiple_{$multipleKey}";

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache(['properties' => $properties]);
                return true;
            }

            Logger::log("Ошибка сохранения свойств в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveCachedProperties: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Обновляет кэш свойств инфоблока.
     * @param int $iblockId ID инфоблока
     * @param array|null $propertyTypes Типы свойств
     * @param bool|null $multiple Множественность свойств
     */
    public static function updatePropertiesCache($iblockId, $propertyTypes = null, $multiple = null)
    {
        try {
            self::clearPropertiesCache($iblockId, $propertyTypes, $multiple);
            $properties = PropertyHelper::getIblockProperties($iblockId, $propertyTypes, $multiple);
            if ($properties !== false) {
                self::saveCachedProperties($iblockId, $properties, $propertyTypes, $multiple);
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в updatePropertiesCache: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * Очищает кэш свойств инфоблока.
     * @param int $iblockId ID инфоблока
     * @param array|null $propertyTypes Типы свойств
     * @param bool|null $multiple Множественность свойств
     */
    public static function clearPropertiesCache($iblockId, $propertyTypes = null, $multiple = null)
    {
        try {
            $cache = Cache::createInstance();

            $propertyTypesKey = is_array($propertyTypes) ? implode('_', $propertyTypes) : 'all_types';
            $multipleKey = is_null($multiple) ? 'both' : $multiple;

            $cacheId = "properties_cache_iblock_{$iblockId}_type_{$propertyTypesKey}_multiple_{$multipleKey}";

            $cache->clean($cacheId, self::$cacheDir);
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearPropertiesCache: " . $e->getMessage(), "ERROR");
        }
    }

    /**
     * Получает значения перечислений из кэша.
     * @param int $propertyId ID свойства
     * @return array|false
     */
    public static function getCachedEnumValues($propertyId)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "enum_values_property_{$propertyId}";

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $cachedData = $cache->getVars();
                $enumValues = $cachedData['enumValues'] ?? [];
                return $enumValues;
            }

            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedEnumValues: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Сохраняет значения перечислений в кэш.
     * @param int $propertyId ID свойства
     * @param array $enumValues Значения перечислений
     * @return bool
     */
    public static function saveCachedEnumValues($propertyId, $enumValues)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "enum_values_property_{$propertyId}";

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache(['enumValues' => $enumValues]);
                return true;
            }

            Logger::log("Ошибка сохранения enum значений в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveCachedEnumValues: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    /**
     * Очищает кэш значений перечислений.
     * @param int $propertyId ID свойства
     */
    public static function clearEnumValuesCache($propertyId)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "enum_values_property_{$propertyId}";
            $cache->clean($cacheId, self::$cacheDir);
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearEnumValuesCache: " . $e->getMessage(), "ERROR");
        }
    }
}