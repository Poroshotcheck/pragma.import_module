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
        Logger::log("Попытка получить разделы из кэша для инфоблока: " . $iblockId);

        $cache = Cache::createInstance();
        $cacheId = "sections_cache_iblock_" . $iblockId;

        if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
            $sections = $cache->getVars();
            Logger::log("Получены разделы из кэша для инфоблока: " . $iblockId);
            return $sections;
        }

        Logger::log("Разделы не найдены в кэше");
        return false;
    }

    public static function saveCachedSections($iblockId, $sections)
    {
        Logger::log("Попытка сохранить разделы в кэш для инфоблока: " . $iblockId);

        // Сортировка по LEFT_MARGIN перед сохранением
        uasort($sections, function ($a, $b) {
            return $a['LEFT_MARGIN'] - $b['LEFT_MARGIN'];
        });

        $cache = Cache::createInstance();
        $cacheId = "sections_cache_iblock_" . $iblockId;

        if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
            $cache->endDataCache([$sections]); // Сохраняем отсортированный массив
            Logger::log("Данные успешно сохранены в кэш");
            return true;
        }

        Logger::log("Ошибка сохранения данных в кэш");
        return false;
    }

    public static function updateSectionsCache($iblockId)
    {
        self::clearSectionsCache($iblockId);

        $sections = [];
        $rsSections = SectionTable::getList([
            'filter' => ['IBLOCK_ID' => $iblockId],
            'select' => ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL'],
            'order' => ['LEFT_MARGIN' => 'ASC']
        ]);
        while ($section = $rsSections->fetch()) {
            $sections[$section['ID']] = $section;
        }

        self::saveCachedSections($iblockId, $sections);
        Logger::log("Кэш разделов обновлён для инфоблока: " . $iblockId);
    }

    public static function clearSectionsCache($iblockId)
    {
        $cache = Cache::createInstance();
        $cacheId = "sections_cache_iblock_" . $iblockId;
        $cache->clean($cacheId, self::$cacheDir);
        Logger::log("Кэш разделов очищен для инфоблока: " . $iblockId);
    }

    public static function getCachedIblocks()
    {
        Logger::log("Попытка получить список инфоблоков из кэша");

        $cache = Cache::createInstance();
        $cacheId = "iblocks_cache";

        if ($cache->initCache(604800, $cacheId, self::$cacheDir)) {
            $iblocks = $cache->getVars();
            Logger::log("Список инфоблоков получен из кэша");
            return $iblocks;
        }

        Logger::log("Список инфоблоков не найден в кэше");
        return false;
    }

    public static function saveIblocksCache($iblocks)
    {
        Logger::log("Попытка сохранить список инфоблоков в кэш");

        $cache = Cache::createInstance();
        $cacheId = "iblocks_cache";

        if ($cache->startDataCache(604800, $cacheId, self::$cacheDir)) {
            $cache->endDataCache($iblocks);
            Logger::log("Список инфоблоков сохранен в кэш");
            return true;
        }

        Logger::log("Ошибка сохранения списка инфоблоков в кэш", "ERROR");
        return false;
    }

    public static function updateIblocksCache()
    {
        Logger::log("Обновление кэша инфоблоков");

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
        Logger::log("Кэш инфоблоков обновлён");
    }

    public static function clearIblocksCache()
    {
        $cache = Cache::createInstance();
        $cacheId = "iblocks_cache";
        $cache->clean($cacheId, self::$cacheDir);
        Logger::log("Кэш инфоблоков очищен");
    }
}