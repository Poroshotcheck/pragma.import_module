<?php
namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\ModuleDataTable;

Loader::includeModule('highloadblock');

class TypeMatcher
{
    private $elements;
    private $mainSeparators = ['|'];
    private $additionalSeparators = ['/'];
    private $updateCollection = [];
    private $moduleId;
    private $hlblockId;
    private $existingTypes = [];

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->hlblockId = Option::get($this->moduleId, 'TYPE_HLB_ID');

        // Optimized data loading
        $this->loadElements();
        $this->loadExistingTypes();
    }

    /**
     * Load elements in an optimized way to reduce database queries.
     */
    private function loadElements()
    {
        // Load elements with necessary fields only
        $elementsResult = ModuleDataTable::getList([
            'select' => ['ID', 'ELEMENT_NAME', 'SIZE_VALUE_ID'],
            'filter' => [
                '!TARGET_SECTION_ID' => 'a:0:{}',
            ],
        ]);

        $this->elements = [];

        while ($element = $elementsResult->fetch()) {
            $this->elements[] = $element;
        }
    }

    /**
     * Load existing types from the Highload Block to check for duplicates.
     */
    private function loadExistingTypes()
    {
        $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
        if (!$hlblock) {
            throw new \Exception("Highload Block with ID {$this->hlblockId} not found.");
        }

        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();

        $rsData = $entityDataClass::getList([
            'select' => ['UF_NAME', 'UF_XML_ID'],
        ]);

        $this->existingTypes = [];

        while ($item = $rsData->fetch()) {
            $typeValue = $item['UF_NAME']; // Assuming UF_NAME and UF_XML_ID are the same
            $this->existingTypes[$typeValue] = true;
        }
    }

    /**
     * Match types and prepare data for database update.
     */
    public function matchTypes()
    {
        foreach ($this->elements as $element) {
            $typeValue = $this->extractType($element['ELEMENT_NAME'], $element['SIZE_VALUE_ID']);

            if (!empty($typeValue)) {
                $this->updateCollection[] = [
                    'ID' => $element['ID'],
                    'TYPE_VALUE_ID' => $typeValue,
                ];
            }
        }
    }

    /**
     * Create missing TYPE_VALUE_ID entries in the Highload Block.
     */
    public function createMissingTypes()
    {
        // Collect all unique TYPE_VALUE_IDs from the update collection
        $typeValues = array_unique(array_column($this->updateCollection, 'TYPE_VALUE_ID'));

        // Identify which TYPE_VALUE_IDs are missing in the HLB
        $missingTypes = [];
        foreach ($typeValues as $typeValue) {
            if (!isset($this->existingTypes[$typeValue])) {
                $missingTypes[] = $typeValue;
            }
        }

        if (!empty($missingTypes)) {
            // Get the entity data class for the HLB
            $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
            $entity = HighloadBlockTable::compileEntity($hlblock);
            $entityDataClass = $entity->getDataClass();

            foreach ($missingTypes as $typeValue) {
                $result = $entityDataClass::add([
                    'UF_NAME' => $typeValue,
                    'UF_XML_ID' => $typeValue,
                ]);

                if ($result->isSuccess()) {
                    // Add to existing types to avoid re-adding
                    $this->existingTypes[$typeValue] = true;
                } else {
                    // Handle errors as needed
                    $errors = $result->getErrorMessages();
                    // Log or handle the errors appropriately
                }
            }
        }
    }

    /**
     * Extract the type from the element name using the same separators and the SIZE_VALUE_ID.
     *
     * @param string $elementName The name of the element.
     * @param string $sizeValueId The SIZE_VALUE_ID of the element.
     * @return string The extracted type.
     */
    private function extractType($elementName, $sizeValueId)
    {
        // If SIZE_VALUE_ID is available, extract the type before the size
        if (!empty($sizeValueId)) {
            // Use main separators first
            foreach ($this->mainSeparators as $separator) {
                if (strpos($elementName, $separator) !== false) {
                    $parts = explode($separator, $elementName);
                    // Remove the size part(s) from the end
                    $typeParts = array_slice($parts, 0, -1);
                    $type = implode($separator, $typeParts);
                    return trim($type);
                }
            }

            // Use additional separators if main separators are not found
            foreach ($this->additionalSeparators as $separator) {
                if (strpos($elementName, $separator) !== false) {
                    $parts = explode($separator, $elementName);
                    // Remove the size part(s) from the end
                    $typeParts = array_slice($parts, 0, -1);
                    $type = implode($separator, $typeParts);
                    return trim($type);
                }
            }

            // If no separators are found, return the full name as type
            return trim($elementName);
        }

        // If SIZE_VALUE_ID is not available, use the full ELEMENT_NAME as TYPE_VALUE_ID
        return trim($elementName);
    }

    /**
     * Update the database with the matched types.
     */
    public function updateDatabase()
    {
        if (empty($this->updateCollection)) {
            return;
        }

        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $tableName = ModuleDataTable::getTableName();

        // Prepare the bulk update using CASE WHEN
        $updateCases = [];
        foreach ($this->updateCollection as $element) {
            $id = intval($element['ID']);
            $typeValueId = $sqlHelper->forSql($element['TYPE_VALUE_ID']);
            $updateCases[] = "WHEN {$id} THEN '{$typeValueId}'";
        }

        $ids = array_map('intval', array_column($this->updateCollection, 'ID'));

        $updateSql = "
            UPDATE {$tableName}
            SET TYPE_VALUE_ID = CASE ID
                " . implode(' ', $updateCases) . "
            END
            WHERE ID IN (" . implode(',', $ids) . ")
        ";

        $connection->queryExecute($updateSql);
    }
}