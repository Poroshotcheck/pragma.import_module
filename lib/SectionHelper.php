<?php

namespace Pragma\ImportModule;

use Pragma\ImportModule\Logger;
use Bitrix\Iblock\SectionTable;

class SectionHelper
{
    public static function getSectionOptionsHtml($iblockId, $selectedId = null, $sections = null)
    {
        try {
            // Logger::log("Начало получения HTML опций разделов для инфоблока: $iblockId");

            if (empty($iblockId)) {
                return '';
            }

            if (is_null($sections) || empty($sections)) {
                $sections = CacheHelper::getCachedSections($iblockId);

                if (empty($sections)) {
                    // Logger::log("Разделы не найдены в кэше, загрузка из базы данных для инфоблока: $iblockId");

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

                    // Сохраняем разделы в кэш
                    CacheHelper::saveCachedSections($iblockId, $sections);
                }
            }

            // Строим дерево разделов
            $tree = self::buildSectionTree($sections);

            // Выводим опции select с учетом уровней вложенности
            return self::buildSectionOptions($tree, $selectedId);

            // Logger::log("HTML опции разделов успешно получены");

        } catch (\Exception $e) {
            Logger::log("Ошибка в getSectionOptionsHtml: " . $e->getMessage(), "ERROR");
            return '';
        }
    }

    // Вспомогательный метод для построения дерева разделов
    protected static function buildSectionTree($sections)
    {
        try {
            // Logger::log("Начало построения дерева разделов");

            $tree = [];
            foreach ($sections as &$section) {
                if ($section['IBLOCK_SECTION_ID'] && isset($sections[$section['IBLOCK_SECTION_ID']])) {
                    $sections[$section['IBLOCK_SECTION_ID']]['CHILDREN'][] = &$section;
                } else {
                    $tree[] = &$section;
                }
            }
            return $tree;

            // Logger::log("Дерево разделов успешно построено");

        } catch (\Exception $e) {
            Logger::log("Ошибка в buildSectionTree: " . $e->getMessage(), "ERROR");
            return [];
        }
    }

    public static function buildSectionOptions($sections, $selectedId = null, $level = 0)
    {
        try {
            // Logger::log("Начало построения HTML опций разделов на уровне: $level");

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

            // Logger::log("HTML опции разделов успешно построены");

        } catch (\Exception $e) {
            Logger::log("Ошибка в buildSectionOptions: " . $e->getMessage(), "ERROR");
            return '';
        }
    }

    public static function getSectionNameById($sectionId)
    {
        try {
            // Logger::log("Получение имени раздела по ID: $sectionId");

            $section = \Bitrix\Iblock\SectionTable::getRowById($sectionId);
            return $section ? $section['NAME'] : '';

            // Logger::log("Имя раздела получено: " . $section['NAME']);

        } catch (\Exception $e) {
            Logger::log("Ошибка в getSectionNameById: " . $e->getMessage(), "ERROR");
            return '';
        }
    }
}