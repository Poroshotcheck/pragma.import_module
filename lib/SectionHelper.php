<?php

namespace Pragma\ImportModule;

use Pragma\ImportModule\Logger;
use Bitrix\Iblock\SectionTable;

class SectionHelper
{
    public static function getSectionOptionsHtml($iblockId, $selectedId = null, $sections = null)
    {
        if (empty($iblockId)) {
            return '';
        }

        // Используем переданный $sections, если он не пустой
        if (is_null($sections) && empty($sections)) {
            // Получаем разделы из кэша (уже отсортированные)
            $cachedData = CacheHelper::getCachedSections($iblockId);
            $sections = $cachedData ? $cachedData[0] : [];

            if (!$sections) {
                // Логируем загрузку из базы данных
                Logger::log("Разделы загружены из базы данных для инфоблока: " . $iblockId);

                // Загружаем разделы из базы данных
                $rsSections = SectionTable::getList([
                    'filter' => ['IBLOCK_ID' => $iblockId],
                    'select' => ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'LEFT_MARGIN'],
                    'order' => ['LEFT_MARGIN' => 'ASC']
                ]);

                $sections = [];
                while ($section = $rsSections->fetch()) {
                    $sections[$section['ID']] = $section;
                }

                // Сохраняем разделы в кэш (уже отсортированные)
                CacheHelper::saveCachedSections($iblockId, $sections);
            }
        }

        // Строим дерево разделов
        $tree = self::buildSectionTree($sections);

        // Выводим опции select с учетом уровней вложенности
        return self::buildSectionOptions($tree, $selectedId);
    }

    // Вспомогательный метод для построения дерева разделов
    protected static function buildSectionTree($sections)
    {
        $tree = [];
        foreach ($sections as &$section) {
            if ($section['IBLOCK_SECTION_ID'] && isset($sections[$section['IBLOCK_SECTION_ID']])) {
                $sections[$section['IBLOCK_SECTION_ID']]['CHILDREN'][] = &$section;
            } else {
                $tree[] = &$section;
            }
        }
        return $tree;
    }

    public static function buildSectionOptions($sections, $selectedId = null, $level = 0)
    {
        $result = '';
        foreach ($sections as $section) {
            $selected = ($section['ID'] == $selectedId) ? 'selected' : '';
            $result .= '<option value="' . $section['ID'] . '" ' . $selected . '>'
                . str_repeat("   ", $level) . htmlspecialcharsbx($section['NAME']) . " [ID =" . $section['ID'] . "]"
                . '</option>';
            if (isset($section['CHILDREN']) && is_array($section['CHILDREN'])) {
                $result .= self::buildSectionOptions($section['CHILDREN'], $selectedId, $level + 1);
            }
        }
        return $result;
    }
    public static function getSectionNameById($sectionId)
    {
        $section = \Bitrix\Iblock\SectionTable::getRowById($sectionId);
        return $section ? $section['NAME'] : '';
    }
}