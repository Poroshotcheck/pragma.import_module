<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\Logger;

class OptionsHelper
{
    //private static $moduleId = PRAGMA_IMPORT_MODULE_ID;
    private static function getModuleVersionData()
    {
        $arModuleVersion = [];
        include __DIR__ . '/../install/version.php';
        return $arModuleVersion;
    }

    // Функция для добавления сообщения об ошибке дублирования свойства
    private static function addDuplicatePropertyMessage($property, $sectionId, &$duplicatePropertiesMessage)
    {
        try {
            // Logger::log("Попытка добавить сообщение о дублировании свойства: $property в разделе ID: $sectionId");

            $sectionName = SectionHelper::getSectionNameById($sectionId);
            $duplicatePropertiesMessage .= Loc::getMessage("PRAGMA_IMPORT_MODULE_DUPLICATE_PROPERTY_MESSAGE", [
                "#PROPERTY#" => $property,
                "#SECTION_NAME#" => $sectionName,
                "#SECTION_ID#" => $sectionId
            ]) . "\n";

            // Logger::log("Сообщение о дублировании свойства успешно добавлено");

        } catch (\Exception $e) {
            Logger::log("Ошибка в addDuplicatePropertyMessage: " . $e->getMessage(), "ERROR");
        }
    }

    public static function processSectionMappings($sectionMappings, &$duplicatePropertiesMessage) 
    {
        global $module_id;
        
        if (!is_array($sectionMappings)) {
            return;
        }

        $processedMappings = [];
        foreach ($sectionMappings as $index => $mapping) {
            if (empty($mapping['SECTION_ID'])) {
                continue;
            }

            $sectionId = $mapping['SECTION_ID'];
            $properties = isset($mapping['PROPERTIES']) ? array_filter($mapping['PROPERTIES']) : [];
            
            // Обработка filter properties
            $filterProperties = [];
            if (isset($mapping['FILTER_PROPERTIES']) && is_array($mapping['FILTER_PROPERTIES'])) {
                foreach ($mapping['FILTER_PROPERTIES'] as $propertyCode => $selectedValues) {
                    if (!empty($selectedValues)) {
                        $filterProperties[$propertyCode] = array_map('intval', $selectedValues);
                        // Сортируем значения для корректного сравнения
                        sort($filterProperties[$propertyCode]);
                    }
                }
            }

            // Проверка на дубликаты
            if (isset($processedMappings[$sectionId])) {
                // Проверяем, идентичны ли FILTER_PROPERTIES
                $existingFilters = $processedMappings[$sectionId]['FILTER_PROPERTIES'];
                $areFiltersIdentical = true;

                // Проверяем каждое свойство фильтра
                foreach ($filterProperties as $propertyCode => $values) {
                    if (!isset($existingFilters[$propertyCode]) || 
                        count(array_diff($values, $existingFilters[$propertyCode])) > 0 || 
                        count(array_diff($existingFilters[$propertyCode], $values)) > 0) {
                        $areFiltersIdentical = false;
                        break;
                    }
                }

                // Проверяем обратное направление (свойства, которые есть в существующем, но нет в новом)
                foreach ($existingFilters as $propertyCode => $values) {
                    if (!isset($filterProperties[$propertyCode])) {
                        $areFiltersIdentical = false;
                        break;
                    }
                }

                if ($areFiltersIdentical) {
                    // Объединяем PROPERTIES без дубликатов
                    $processedMappings[$sectionId]['PROPERTIES'] = array_unique(
                        array_merge($processedMappings[$sectionId]['PROPERTIES'], $properties)
                    );
                    continue;
                } else {
                    // Если фильтры различаются, создаем новый уникальный ключ
                    $newKey = $sectionId . '_' . uniqid();
                    $processedMappings[$newKey] = [
                        'SECTION_ID' => $sectionId,
                        'PROPERTIES' => $properties,
                        'FILTER_PROPERTIES' => $filterProperties
                    ];
                    continue;
                }
            }

            // Если это первое появление секции
            $processedMappings[$sectionId] = [
                'SECTION_ID' => $sectionId,
                'PROPERTIES' => $properties,
                'FILTER_PROPERTIES' => $filterProperties
            ];
        }

        if (!empty($processedMappings)) {
            Option::set($module_id, "SECTION_MAPPINGS", serialize($processedMappings));
        }
    }

    public static function processImportMappings(&$importMappings, &$duplicateSectionsMessage)
    {
        try {
            // Logger::log("Начало обработки сопоставлений импорта");
            $moduleId = self::getModuleVersionData()['MODULE_ID']; 

            $uniqueSections = [];
            $newImportMappings = [];

            foreach ($importMappings as $mapping) {
                $sectionId = intval($mapping['SECTION_ID']);
                if (!isset($uniqueSections[$sectionId])) {
                    $uniqueSections[$sectionId] = true;
                    $newImportMappings[] = $mapping;
                } else {
                    self::addDuplicatePropertyMessage($mapping['SECTION_ID'], $sectionId, $duplicateSectionsMessage);
                }
            }

            $importMappings = $newImportMappings;
            Option::set($moduleId, "IMPORT_MAPPINGS", serialize($importMappings));

            // Logger::log("Обработка сопоставлений импорта завершена");

        } catch (\Exception $e) {
            Logger::log("Ошибка в processImportMappings: " . $e->getMessage(), "ERROR");
        }
    }
}