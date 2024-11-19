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

    public static function processSectionMappings(&$sectionMappings, &$duplicatePropertiesMessage)
    {
        try {
            // Logger::log("Starting to process section mappings");
            $moduleId = self::getModuleVersionData()['MODULE_ID']; 

            $mergedMappings = [];

            foreach ($sectionMappings as $mapping) {
                // Skip entries without PROPERTIES or with empty PROPERTIES
                if (empty($mapping['PROPERTIES'])) {
                    continue;
                }

                $sectionId = $mapping['SECTION_ID'];

                if (!isset($mergedMappings[$sectionId])) {
                    $mergedMappings[$sectionId] = [
                        'SECTION_ID' => $sectionId,
                        'PROPERTIES' => [],
                    ];
                }

                foreach ($mapping['PROPERTIES'] as $property) {
                    $trimmedProperty = trim($property);

                    if ($trimmedProperty === '') {
                        continue; // Skip empty properties
                    }

                    $propertyLower = mb_strtolower($trimmedProperty);

                    // Check for duplicates
                    $existingPropertiesLower = array_map('mb_strtolower', $mergedMappings[$sectionId]['PROPERTIES']);

                    if (!in_array($propertyLower, $existingPropertiesLower)) {
                        $mergedMappings[$sectionId]['PROPERTIES'][] = $trimmedProperty;
                    } else {
                        self::addDuplicatePropertyMessage($property, $sectionId, $duplicatePropertiesMessage);
                    }
                }
            }

            // Re-index the array to ensure it's sequential
            $sectionMappings = array_values($mergedMappings);

            Option::set($moduleId, "SECTION_MAPPINGS", serialize($sectionMappings));

            // Logger::log("Section mappings processing completed");

        } catch (\Exception $e) {
            Logger::log("Error in processSectionMappings: " . $e->getMessage(), "ERROR");
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