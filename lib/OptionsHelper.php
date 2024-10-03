<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Pragma\ImportModule\SectionHelper;

class OptionsHelper
{
    private static $moduleId = PRAGMA_IMPORT_MODULE_ID;
    // Функция для добавления сообщения об ошибке дублирования свойства
    private static function addDuplicatePropertyMessage($property, $sectionId, &$duplicatePropertiesMessage)
    {
        $sectionName = SectionHelper::getSectionNameById($sectionId);
        $duplicatePropertiesMessage .= Loc::getMessage("PRAGMA_IMPORT_MODULE_DUPLICATE_PROPERTY_MESSAGE", [
            "#PROPERTY#" => $property,
            "#SECTION_NAME#" => $sectionName,
            "#SECTION_ID#" => $sectionId
        ]) . "\n";
    }

    public static function processSectionMappings(&$sectionMappings, &$duplicatePropertiesMessage)
    {

        file_put_contents(__DIR__ . "/do.txt", print_r($sectionMappings, true));
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
        file_put_contents(__DIR__ . "/posle.txt", print_r($sectionMappings, true));
        Option::set(self::$moduleId, "SECTION_MAPPINGS", serialize($sectionMappings));
    }

    public static function processImportMappings(&$importMappings, &$duplicateSectionsMessage)
    {
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
    }
}