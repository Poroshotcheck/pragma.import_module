<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\Logger;

class OptionsHelper
{
    private static $moduleId = PRAGMA_IMPORT_MODULE_ID;

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
            // Logger::log("Начало обработки сопоставлений разделов");

            // file_put_contents(__DIR__ . "/do.txt", print_r($sectionMappings, true));
            foreach ($sectionMappings as &$mapping) { // Обратите внимание на & - передача по ссылке
                if (!empty($mapping['PROPERTIES'])) {
                    $uniqueProperties = [];
                    $newProperties = [];

                    foreach ($mapping['PROPERTIES'] as $property) {
                        $trimmedProperty = trim($property);

                        if ($trimmedProperty === '') {
                            continue; // Пропускаем пустые свойства
                        }

                        $propertyLower = mb_strtolower($trimmedProperty);

                        if (!in_array($propertyLower, $uniqueProperties)) {
                            $newProperties[] = $trimmedProperty;
                            $uniqueProperties[] = $propertyLower;
                        } else {
                            self::addDuplicatePropertyMessage($property, $mapping['SECTION_ID'], $duplicatePropertiesMessage);
                        }
                    }

                    $mapping['PROPERTIES'] = $newProperties; // Обновляем свойства в исходном массиве
                }
            }
            // file_put_contents(__DIR__ . "/posle.txt", print_r($sectionMappings, true));
            Option::set(self::$moduleId, "SECTION_MAPPINGS", serialize($sectionMappings));

            // Logger::log("Обработка сопоставлений разделов завершена");

        } catch (\Exception $e) {
            Logger::log("Ошибка в processSectionMappings: " . $e->getMessage(), "ERROR");
        }
    }

    public static function processImportMappings(&$importMappings, &$duplicateSectionsMessage)
    {
        try {
            // Logger::log("Начало обработки сопоставлений импорта");

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
            Option::set(self::$moduleId, "IMPORT_MAPPINGS", serialize($importMappings));

            // Logger::log("Обработка сопоставлений импорта завершена");

        } catch (\Exception $e) {
            Logger::log("Ошибка в processImportMappings: " . $e->getMessage(), "ERROR");
        }
    }
}