<?php

namespace Pragma\ImportModule;

use Pragma\ImportModule\Logger;
use Bitrix\Main\Data\Cache;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\IblockTable;

class CacheHelper
{
    private static $cacheDir = "/pragma.importmodule/module_cache/";

    public static function getCachedSections($iblockId)
    {
        try {
            // Logger::log("Попытка получить разделы из кэша для инфоблока: " . $iblockId);

            $cache = Cache::createInstance();
            $cacheId = "sections_cache_iblock_" . $iblockId;

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $cachedData = $cache->getVars();
                $sections = $cachedData['sections'] ?? [];
                // Logger::log("Получены разделы из кэша для инфоблока: " . $iblockId . ", количество разделов: " . count($sections));
                return $sections;
            }

            // Logger::log("Разделы не найдены в кэше");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedSections: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public static function saveCachedSections($iblockId, $sections)
    {
        try {
            // Logger::log("Попытка сохранить разделы в кэш для инфоблока: " . $iblockId);

            // Сортировка по LEFT_MARGIN перед сохранением
            uasort($sections, function ($a, $b) {
                return $a['LEFT_MARGIN'] - $b['LEFT_MARGIN'];
            });

            $cache = Cache::createInstance();
            $cacheId = "sections_cache_iblock_" . $iblockId;

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache(['sections' => $sections]); // Сохраняем с ключом 'sections'
                // Logger::log("Данные успешно сохранены в кэш");
                return true;
            }

            Logger::log("Ошибка сохранения данных в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveCachedSections: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

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
            // Logger::log("Кэш разделов обновлён для инфоблока: " . $iblockId);
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateSectionsCache: " . $e->getMessage(), "ERROR");
        }
    }

    public static function clearSectionsCache($iblockId)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "sections_cache_iblock_" . $iblockId;
            $cache->clean($cacheId, self::$cacheDir);
            // Logger::log("Кэш разделов очищен для инфоблока: " . $iblockId);
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearSectionsCache: " . $e->getMessage(), "ERROR");
        }
    }

    public static function getCachedIblocks()
    {
        try {
            // Logger::log("Попытка получить список инфоблоков из кэша");

            $cache = Cache::createInstance();
            $cacheId = "iblocks_cache";

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $iblocks = $cache->getVars();
                // Logger::log("Список инфоблоков получен из кэша");
                return $iblocks;
            }

            // Logger::log("Список инфоблоков не найден в кэше");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedIblocks: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public static function saveIblocksCache($iblocks)
    {
        try {
            // Logger::log("Попытка сохранить список инфоблоков в кэш");

            $cache = Cache::createInstance();
            $cacheId = "iblocks_cache";

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache($iblocks);
                // Logger::log("Список инфоблоков сохранен в кэш");
                return true;
            }

            Logger::log("Ошибка сохранения списка инфоблоков в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveIblocksCache: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public static function updateIblocksCache()
    {
        try {
            // Logger::log("Обновление кэша инфоблоков");

            // Очистка кэша перед заполнением
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
            // Logger::log("Кэш инфоблоков обновлён");
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateIblocksCache: " . $e->getMessage(), "ERROR");
        }
    }

    public static function clearIblocksCache()
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "iblocks_cache";
            $cache->clean($cacheId, self::$cacheDir);
            // Logger::log("Кэш инфоблоков очищен");
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearIblocksCache: " . $e->getMessage(), "ERROR");
        }
    }

    public static function getCachedProperties($iblockId)
    {
        try {
            // Logger::log("Попытка получить свойства из кэша для инфоблока: " . $iblockId);

            $cache = Cache::createInstance();
            $cacheId = "properties_cache_iblock_" . $iblockId;

            if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
                $cachedData = $cache->getVars();
                $properties = $cachedData['properties'] ?? [];
                // Logger::log("Получены свойства из кэша для инфоблока: " . $iblockId . ", количество свойств: " . count($properties));
                return $properties;
            }

            // Logger::log("Свойства не найдены в кэше");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getCachedProperties: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public static function saveCachedProperties($iblockId, $properties)
    {
        try {
            // Logger::log("Попытка сохранить свойства в кэш для инфоблока: " . $iblockId);

            $cache = Cache::createInstance();
            $cacheId = "properties_cache_iblock_" . $iblockId;

            if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
                $cache->endDataCache(['properties' => $properties]); // Сохраняем с ключом 'properties'
                // Logger::log("Свойства успешно сохранены в кэш");
                return true;
            }

            Logger::log("Ошибка сохранения свойств в кэш", "ERROR");
            return false;
        } catch (\Exception $e) {
            Logger::log("Ошибка в saveCachedProperties: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public static function updatePropertiesCache($iblockId)
    {
        try {
            self::clearPropertiesCache($iblockId);
            $properties = PropertyHelper::getIblockProperties($iblockId);
            self::saveCachedProperties($iblockId, $properties);
            // Logger::log("Кэш свойств обновлён для инфоблока: " . $iblockId);
        } catch (\Exception $e) {
            Logger::log("Ошибка в updatePropertiesCache: " . $e->getMessage(), "ERROR");
        }
    }

    public static function clearPropertiesCache($iblockId)
    {
        try {
            $cache = Cache::createInstance();
            $cacheId = "properties_cache_iblock_" . $iblockId;
            $cache->clean($cacheId, self::$cacheDir);
            // Logger::log("Кэш свойств очищен для инфоблока: " . $iblockId);
        } catch (\Exception $e) {
            Logger::log("Ошибка в clearPropertiesCache: " . $e->getMessage(), "ERROR");
        }
    }
}