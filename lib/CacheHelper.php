<?php

namespace Pragma\ImportModule;

require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.import_module/lib/Logger.php");

use Pragma\ImportModule\Logger;
use Bitrix\Main\Data\Cache;
use Bitrix\Iblock\SectionTable;

class CacheHelper
{
    private static $cacheDir = "/pragma.import_module/module_cache/";

    public static function getCachedSections($iblockId)
    {
        Logger::log("Попытка получить разделы из кэша для инфоблока: " . $iblockId);

        $cache = Cache::createInstance();
        $cacheId = "sections_cache_iblock_" . $iblockId;

        Logger::log("Cache ID: " . $cacheId);
        Logger::log("Cache Dir: " . self::$cacheDir);

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
        self::clearCache($iblockId);

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

    public static function clearCache($iblockId)
    {
        $cache = Cache::createInstance();
        $cacheId = "sections_cache_iblock_" . $iblockId;
        $cache->clean($cacheId, self::$cacheDir);
        Logger::log("Кэш разделов очищен для инфоблока: " . $iblockId);
    }
}