<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\Logger;
use Bitrix\Iblock\Model\PropertyFeature;

Loader::includeModule('iblock');
Loader::includeModule('highloadblock');

class IblockPropertiesCopier
{
    private $moduleId;
    private $sourceIblockId;
    private $destinationIblockId;
    private $destinationOffersIblockId;
    private $properties = [];
    private $colorHlbId;
    private $sizeHlbId;
    private $typeHlbId;

    /**
     * Constructor to initialize the copier with necessary IDs.
     *
     * @param string $moduleId
     * @param int $sourceIblockId
     * @param int $destinationIblockId
     * @param int|null $destinationOffersIblockId
     */
    public function __construct($moduleId, $sourceIblockId, $destinationIblockId, $destinationOffersIblockId = null)
    {
        $this->moduleId = $moduleId;
        $this->sourceIblockId = $sourceIblockId;
        $this->destinationIblockId = $destinationIblockId;
        $this->destinationOffersIblockId = $destinationOffersIblockId;
        $this->typeHlbId = \COption::GetOptionString($this->moduleId, 'TYPE_HLB_ID');
        $this->sizeHlbId = \COption::GetOptionString($this->moduleId, 'SIZE_HLB_ID');
        $this->colorHlbId = \COption::GetOptionString($this->moduleId, 'COLOR_HLB_ID');
    }

    /**
     * Main method to start the property copying process.
     */
    public function copyProperties()
    {
        Logger::log("Starting copyProperties()");
        // Ensure directory properties are correctly set
        $this->ensureDirectoryProperty('COLOR_MODULE_REF', 'Цвета для товаров', $this->colorHlbId);
        $this->ensureDirectoryProperty('SIZE_MODULE_REF', 'Размеры для товаров', $this->sizeHlbId);
        $this->ensureDirectoryProperty('TYPE_MODULE_REF', 'Типы товаров', $this->typeHlbId);

        // Load properties from the source info block
        $this->loadProperties();

        // Process each property individually
        $this->processProperties();
        Logger::log("Finished copyProperties()");
    }

    /**
     * Loads all active properties from the source info block.
     */
    private function loadProperties()
    {
        $properties = \CIBlockProperty::GetList(
            ['sort' => 'asc', 'name' => 'asc'],
            ['ACTIVE' => 'Y', 'IBLOCK_ID' => $this->sourceIblockId]
        );

        while ($prop = $properties->GetNext()) {
            $this->properties[] = $prop;
        }
    }

    /**
     * Processes each property, copying it to the destination info blocks.
     */
    private function processProperties()
    {
        foreach ($this->properties as $property) {
            $this->copyProperty($property);
        }
    }

    /**
     * Copies a single property to the destination info blocks.
     *
     * @param array $property The property data array.
     */
    private function copyProperty($property)
    {
        Logger::log("Processing property: {$property['CODE']}");

        // Prepare property fields for addition
        $propertyFields = [
            'NAME' => $property['NAME'],
            'ACTIVE' => $property['ACTIVE'],
            'SORT' => $property['SORT'],
            'CODE' => $property['CODE'],
            'DEFAULT_VALUE' => $property['DEFAULT_VALUE'],
            'PROPERTY_TYPE' => $property['PROPERTY_TYPE'],
            'ROW_COUNT' => $property['ROW_COUNT'],
            'COL_COUNT' => $property['COL_COUNT'],
            'LIST_TYPE' => $property['LIST_TYPE'],
            'MULTIPLE' => $property['MULTIPLE'],
            'XML_ID' => $property['XML_ID'],
            'FILE_TYPE' => $property['FILE_TYPE'],
            'MULTIPLE_CNT' => $property['MULTIPLE_CNT'],
            'TMP_ID' => $property['TMP_ID'],
            'LINK_IBLOCK_ID' => $property['LINK_IBLOCK_ID'],
            'WITH_DESCRIPTION' => $property['WITH_DESCRIPTION'],
            'SEARCHABLE' => $property['SEARCHABLE'],
            'FILTRABLE' => $property['FILTRABLE'],
            'IS_REQUIRED' => $property['IS_REQUIRED'],
            'VERSION' => $property['VERSION'],
            'USER_TYPE' => $property['USER_TYPE'],
            'USER_TYPE_SETTINGS' => $property['USER_TYPE_SETTINGS'],
            'HINT' => $property['HINT'],
        ];

        $destIblockProperty = new \CIBlockProperty;

        try {
            // Copy property to the main destination info block
            $existingProperty = $this->findExistingProperty($this->destinationIblockId, $property['CODE'], $property['XML_ID']);
            if ($existingProperty) {
                Logger::log("Property {$property['CODE']} already exists in destination info block. Skipping update.");
                $propertyId = $existingProperty['ID'];
            } else {
                $propertyId = $destIblockProperty->Add(array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationIblockId]));
                if ($propertyId) {
                    Logger::log("Property {$property['CODE']} created.");
                } else {
                    throw new \Exception("Error creating property {$property['CODE']}: " . $destIblockProperty->LAST_ERROR);
                }
            }

            // If property type is list, copy enum values
            if ($propertyId && $property['PROPERTY_TYPE'] == 'L') {
                $this->copyPropertyEnumValues($property['ID'], $propertyId);
            }

            // If there's a destination offers info block, process it as well
            if ($this->destinationOffersIblockId) {
                Logger::log("Processing property {$property['CODE']} for offers info block (ID: {$this->destinationOffersIblockId})");

                $existingOfferProperty = $this->findExistingProperty($this->destinationOffersIblockId, $property['CODE'], $property['XML_ID']);
                if ($existingOfferProperty) {
                    Logger::log("Property {$property['CODE']} already exists in offers info block. Skipping update.");
                    $offerPropertyId = $existingOfferProperty['ID'];
                } else {
                    Logger::log("Creating new property {$property['CODE']} in offers info block.");
                    $offerPropertyId = $destIblockProperty->Add(array_merge($propertyFields, ['IBLOCK_ID' => $this->destinationOffersIblockId]));
                    if ($offerPropertyId) {
                        Logger::log("Property {$property['CODE']} created in offers info block.");
                    } else {
                        throw new \Exception("Error creating property {$property['CODE']} in offers info block: " . $destIblockProperty->LAST_ERROR);
                    }
                }

                // If property type is list, copy enum values
                if (isset($offerPropertyId) && $offerPropertyId && $property['PROPERTY_TYPE'] == 'L') {
                    $this->copyPropertyEnumValues($property['ID'], $offerPropertyId);
                }
            } else {
                Logger::log("Offers info block is not set.");
            }
        } catch (\Exception $e) {
            Logger::log("Exception: " . $e->getMessage());
            Logger::log("Stack trace:");
            Logger::log($e->getTraceAsString());
        }

        // Additional error checking
        global $DB;
        if ($DB->GetErrorMessage()) {
            Logger::log("Final Query Error: " . $DB->GetErrorMessage());
            Logger::log("Final Last SQL Query: " . $DB->LastQuery());
        }
    }

    /**
     * Copies enum values from the source property to the destination property.
     *
     * @param int $sourcePropertyId
     * @param int $destPropertyId
     */
    private function copyPropertyEnumValues($sourcePropertyId, $destPropertyId)
    {
        $enumValues = \CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['PROPERTY_ID' => $sourcePropertyId]
        );

        $obEnum = new \CIBlockPropertyEnum;
        while ($enumValue = $enumValues->GetNext()) {
            // Check for existing XML_ID or use VALUE as fallback
            $sourceXmlId = !empty($enumValue['XML_ID']) ? $enumValue['XML_ID'] : strtolower($enumValue['VALUE']);

            // Generate a unique XML_ID by combining property ID and source XML_ID
            $newXmlId = $destPropertyId . '_' . $sourceXmlId;

            // Check if the enum value already exists
            $existingEnum = \CIBlockPropertyEnum::GetList(
                [],
                [
                    'PROPERTY_ID' => $destPropertyId,
                    'XML_ID' => $newXmlId
                ]
            )->Fetch();

            if ($existingEnum) {
                Logger::log("Enum value '{$enumValue['VALUE']}' already exists for property {$destPropertyId} with XML_ID '{$newXmlId}'.");
                continue;
            }

            // Prepare fields for the new enum value
            $fields = [
                'PROPERTY_ID' => $destPropertyId,
                'VALUE' => $enumValue['VALUE'],
                'DEF' => $enumValue['DEF'],
                'SORT' => $enumValue['SORT'],
                'XML_ID' => $newXmlId,
            ];

            if ($obEnum->Add($fields)) {
                Logger::log("Enum value '{$enumValue['VALUE']}' copied for property {$destPropertyId} with XML_ID '{$newXmlId}'.");
            } else {
                Logger::log("Error copying enum value '{$enumValue['VALUE']}' for property {$destPropertyId}: " . $obEnum->LAST_ERROR);
            }
        }
    }

    /**
     * Ensures that a directory property exists and sets the required features.
     *
     * @param string $code
     * @param string $name
     * @param int $hlbId
     */
    private function ensureDirectoryProperty($code, $name, $hlbId)
    {
        Logger::log("Starting ensureDirectoryProperty for {$code}");

        // Prepare info block IDs to process
        $iblockIds = [$this->destinationIblockId];
        if ($this->destinationOffersIblockId) {
            $iblockIds[] = $this->destinationOffersIblockId;
        }

        // Get Highload block information by ID
        $hlblock = HighloadBlockTable::getById($hlbId)->fetch();
        if (!$hlblock) {
            Logger::log("Error: Highload block with ID {$hlbId} not found");
            return; 
        }

        $hlbName = $hlblock['NAME'];
        Logger::log("Found Highload block: {$hlbName} (ID: {$hlbId})");

        foreach ($iblockIds as $iblockId) {
            // Find existing property
            $property = $this->findExistingProperty($iblockId, $code);

            // Prepare user type settings for the directory
            $userTypeSettings = [
                'TABLE_NAME' => $hlblock['TABLE_NAME'],
                'size' => 1,
                'width' => 0,
                'group' => 'N',
                'multiple' => 'N',
                'directoryId' => $hlbId
            ];

            // Get features based on the info block ID
            $features = $this->getFeaturesForIblock($iblockId);

            // Prepare fields for the property
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'ACTIVE' => 'Y',
                'SORT' => '500',
                'CODE' => $code,
                'PROPERTY_TYPE' => 'S',
                'USER_TYPE' => 'directory',
                'LIST_TYPE' => 'L',
                'MULTIPLE' => 'N',
                'IS_REQUIRED' => 'N',
                'SEARCHABLE' => 'N',
                'FILTRABLE' => 'Y',
                'WITH_DESCRIPTION' => 'N',
                'MULTIPLE_CNT' => '5',
                'HINT' => '',
                'USER_TYPE_SETTINGS' => $userTypeSettings,
                'FEATURES' => $features
            ];

            $ibp = new \CIBlockProperty;

            if (!$property) {
                // Add new property
                $propertyId = $ibp->Add($fields);
                if ($propertyId) {
                    Logger::log("Property {$code} created for info block {$iblockId}");
                    // Set property features explicitly
                    $this->setPropertyFeatures($propertyId, $features);
                } else {
                    Logger::log("Error creating property {$code} for info block {$iblockId}: " . $ibp->LAST_ERROR);
                    continue;
                }
            } else {
                $propertyId = $property['ID'];

                // Check if the property is of type 'directory' and linked to the correct HLB
                $currentUserType = $property['USER_TYPE'];
                $currentUserTypeSettings = $property['USER_TYPE_SETTINGS'];

                if (is_string($currentUserTypeSettings)) {
                    $currentUserTypeSettings = unserialize($currentUserTypeSettings);
                }

                $isCorrectDirectory = (
                    $currentUserType === 'directory' &&
                    is_array($currentUserTypeSettings) &&
                    isset($currentUserTypeSettings['TABLE_NAME']) &&
                    $currentUserTypeSettings['TABLE_NAME'] === $hlblock['TABLE_NAME']
                );

                if ($isCorrectDirectory) {
                    Logger::log("Property {$code} already exists in info block {$iblockId} and is correctly linked to the Highload block. Skipping update.");
                } else {
                    // Update the property
                    if ($ibp->Update($propertyId, $fields)) {
                        Logger::log("Property {$code} updated for info block {$iblockId}");
                        // Set property features explicitly
                        $this->setPropertyFeatures($propertyId, $features);
                    } else {
                        Logger::log("Error updating property {$code} for info block {$iblockId}: " . $ibp->LAST_ERROR);
                        continue;
                    }
                }
            }

            Logger::log("Finished processing property {$code} for info block {$iblockId}");
        }

        Logger::log("Finished ensureDirectoryProperty for {$code}");
    }

    /**
     * Finds an existing property in an info block by code or XML_ID.
     *
     * @param int $iblockId
     * @param string $code
     * @param string|null $xmlId
     * @return array|null
     */
    private function findExistingProperty($iblockId, $code, $xmlId = null)
    {
        $filter = ['IBLOCK_ID' => $iblockId];

        if (!empty($xmlId)) {
            $filter['XML_ID'] = $xmlId;
        } else {
            $filter['CODE'] = $code;
        }

        $existingProperty = \CIBlockProperty::GetList([], $filter)->Fetch();

        return $existingProperty ?: null;
    }

    /**
     * Generates the features array for a given info block ID.
     *
     * @param int $iblockId
     * @return array
     */
    private function getFeaturesForIblock($iblockId)
    {
        $features = [];

        if ($iblockId == $this->destinationIblockId) {
            // For main info block, set LIST_PAGE_SHOW and DETAIL_PAGE_SHOW
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'LIST_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'DETAIL_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
        } elseif ($iblockId == $this->destinationOffersIblockId) {
            // For offers info block, set all required features
            $features[] = [
                'MODULE_ID' => 'catalog',
                'FEATURE_ID' => 'OFFER_TREE',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'catalog',
                'FEATURE_ID' => 'IN_BASKET',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'LIST_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
            $features[] = [
                'MODULE_ID' => 'iblock',
                'FEATURE_ID' => 'DETAIL_PAGE_SHOW',
                'IS_ENABLED' => 'Y'
            ];
        }

        return $features;
    }

    /**
     * Sets the property features in the database.
     *
     * @param int $propertyId
     * @param array $features
     */
    private function setPropertyFeatures($propertyId, $features)
    {
        // Use Bitrix's PropertyFeature model to set features
        PropertyFeature::SetFeatures($propertyId, $features);
        Logger::log("Property features set for property ID {$propertyId}");
    }
}
