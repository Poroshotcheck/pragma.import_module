<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\Query;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Iblock\SectionElementTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;

use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Logger;

class SimpleProductImporter
{
    protected $moduleId;
    protected $sourceIblockId;
    protected $targetIblockId;
    protected $targetOffersIblockId;
    protected $priceGroupId;
    protected $enumMapping = [];
    protected $targetProperties = [];

    public function __construct($moduleId, $sourceIblockId, $targetIblockId, $targetOffersIblockId, $priceGroupId)
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        $this->moduleId = $moduleId;
        $this->sourceIblockId = $sourceIblockId;
        $this->targetIblockId = $targetIblockId;
        $this->priceGroupId = $priceGroupId;
        $this->targetOffersIblockId = $targetOffersIblockId;

        $this->enumMapping = $this->getEnumMapping();
        $this->targetProperties = $this->getTargetProperties();
    }

    public function copyElementsFromModuleData($batchSize = 1000)
    {
        $startTime = microtime(true);
        $this->processElementsWithoutChain($batchSize);
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        Logger::log("Импорт простых продуктов завершен. Время выполнения: " . round($executionTime, 2) . " секунд.");
    }

    /**
     * Process elements without CHAIN_TOGEZER
     */
    protected function processElementsWithoutChain($batchSize)
    {
        $offset = 0;

        while (true) {
            $moduleData = $this->getModuleData($batchSize, $offset);

            if (empty($moduleData)) {
                Logger::log("Обработка элементов без CHAIN_TOGEZER завершена.");
                break;
            }

            $existingElements = $this->getExistingElements($moduleData);
            // Создаем экземпляр ProductUpdater
            $productUpdater = new ProductUpdater($this->priceGroupId);

            // Подготовка данных для обновления
            $elementsData = $this->getElementsData(array_column($moduleData, 'ELEMENT_ID'));
            $elementsDataByXmlId = [];
            foreach ($elementsData as $element) {
                $elementsDataByXmlId[$element['XML_ID']] = $element;
            }

            // Обновляем существующие элементы
            if (!empty($existingElements['target'])) {
                $productUpdater->updateExistingElements($existingElements['target'], $elementsDataByXmlId);
            }

            $newModuleData = $this->filterExistingElements($moduleData, $existingElements);

            if (empty($newModuleData)) {
                Logger::log("Все элементы в этом пакете уже существуют в целевых инфоблоках.");
                $offset += $batchSize;
                continue;
            }

            $elementIdsToCopy = array_column($newModuleData, 'ELEMENT_ID');
            $newElementIds = $this->copyIblockElements($elementIdsToCopy, $newModuleData);

            if (!empty($newElementIds)) {
                Logger::log("Создано " . count($newElementIds) . " новых простых продуктов.");
            } else {
                Logger::log("Ошибка при создании простых продуктов.");
            }

            $offset += $batchSize;
        }
    }

    /**
     * Get data from ModuleDataTable
     */
    protected function getModuleData($batchSize = 0, $offset = 0)
    {
        $filter = ['!TARGET_SECTION_ID' => 'a:0:{}'];

        // Elements without CHAIN_TOGEZER
        $filter[] = [
            'LOGIC' => 'OR',
            ['CHAIN_TOGEZER' => false],
            ['CHAIN_TOGEZER' => 0],
            ['CHAIN_TOGEZER' => null],
        ];

        $queryParams = [
            'filter' => $filter,
            'offset' => $offset,
        ];

        if ($batchSize > 0) {
            $queryParams['limit'] = $batchSize;
        }

        $moduleDataResult = ModuleDataTable::getList($queryParams);

        return $moduleDataResult->fetchAll();
    }

    /**
     * Check existing elements in target IBlock and sales offers IBlock
     */
    protected function getExistingElements($moduleData)
    {
        $elementXmlIds = array_column($moduleData, 'ELEMENT_XML_ID');

        // Arrays to store existing elements
        $existingElementsInTarget = [];
        $existingElementsInOffers = [];

        // Check for elements in target IBlock
        $targetElements = ElementTable::getList([
            'filter' => [
                'IBLOCK_ID' => $this->targetIblockId,
                'XML_ID' => $elementXmlIds
            ],
            'select' => ['ID', 'XML_ID']
        ])->fetchAll();

        foreach ($targetElements as $element) {
            $existingElementsInTarget[$element['XML_ID']] = $element['ID'];
        }

        // Check for elements in trade offers IBlock
        if ($this->targetOffersIblockId) {
            $offerElements = ElementTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'XML_ID' => $elementXmlIds
                ],
                'select' => ['ID', 'XML_ID']
            ])->fetchAll();

            foreach ($offerElements as $element) {
                $existingElementsInOffers[$element['XML_ID']] = $element['ID'];
            }
        }

        return [
            'target' => $existingElementsInTarget,
            'offers' => $existingElementsInOffers
        ];
    }

    /**
     * Filter existing elements from current batch
     */
    protected function filterExistingElements($moduleData, $existingElements)
    {
        $existingXmlIds = array_merge(
            array_keys($existingElements['target']),
            array_keys($existingElements['offers'])
        );

        return array_filter($moduleData, function ($item) use ($existingXmlIds) {
            return !in_array($item['ELEMENT_XML_ID'], $existingXmlIds);
        });
    }

    /**
     * Copy elements to target IBlock
     */
    protected function copyIblockElements($elementIds, $newModuleData)
    {
        Logger::log("Копирование элементов: " . implode(', ', $elementIds));
        flush();

        $elementsData = $this->getElementsData($elementIds);
        $propertiesByElement = $this->getElementsProperties($elementIds);

        $preparedData = $this->prepareNewElementsData($elementsData, $propertiesByElement, $newModuleData);

        $newElementIds = $this->createNewElements($preparedData['elements'], $preparedData['sections'], $preparedData['properties']);

        if (!empty($newElementIds)) {
            Logger::log("Создано " . count($newElementIds) . " новых простых продуктов.");
            flush();

            $this->addPrices($newElementIds, $preparedData['prices']);
            $this->addProductData($newElementIds, $preparedData['products'], \Bitrix\Catalog\ProductTable::TYPE_PRODUCT);

            // Add warehouse stock data
            $this->addWarehouseStockData($newElementIds, $preparedData['warehouseData']);
        } else {
            Logger::log("Ошибка при создании простых продуктов.");
            flush();
        }

        return $newElementIds;
    }

    /**
     * Get elements data from source IBlock
     */
    protected function getElementsData($elementIds)
    {
        $query = new Query(ElementTable::getEntity());
        $query->setSelect([
            'ID',
            'NAME',
            'XML_ID',
            'CODE',
            'DETAIL_TEXT',
            'DETAIL_TEXT_TYPE',
            'PREVIEW_TEXT',
            'PREVIEW_TEXT_TYPE',
            'PRICE_VALUE' => 'PRICE.PRICE',
            'CURRENCY_VALUE' => 'PRICE.CURRENCY',
            'QUANTITY_VALUE' => 'PRODUCT.QUANTITY',
            'STORE_ID' => 'STORE_PRODUCT.STORE_ID',
            'STORE_AMOUNT' => 'STORE_PRODUCT.AMOUNT',
        ]);
        $query->setFilter(['ID' => $elementIds, 'IBLOCK_ID' => $this->sourceIblockId]);
        $query->registerRuntimeField(
            'PRICE',
            [
                'data_type' => PriceTable::getEntity(),
                'reference' => [
                    '=this.ID' => 'ref.PRODUCT_ID',
                ],
                'join_type' => 'LEFT'
            ]
        );
        $query->registerRuntimeField(
            'PRODUCT',
            [
                'data_type' => ProductTable::getEntity(),
                'reference' => ['=this.ID' => 'ref.ID'],
                'join_type' => 'LEFT'
            ]
        );
        $query->registerRuntimeField(
            'STORE_PRODUCT',
            [
                'data_type' => StoreProductTable::getEntity(),
                'reference' => ['=this.ID' => 'ref.PRODUCT_ID'],
                'join_type' => 'LEFT'
            ]
        );

        $elementsResult = $query->exec();

        // Initialize elements array
        $elements = [];

        while ($element = $elementsResult->fetch()) {
            $elementId = $element['ID'];

            if (!isset($elements[$elementId])) {
                // Initialize element data
                $elements[$elementId] = [
                    'ID' => $element['ID'],
                    'NAME' => $element['NAME'],
                    'XML_ID' => $element['XML_ID'],
                    'CODE' => $element['CODE'],
                    'DETAIL_TEXT' => $element['DETAIL_TEXT'],
                    'DETAIL_TEXT_TYPE' => $element['DETAIL_TEXT_TYPE'],
                    'PREVIEW_TEXT' => $element['PREVIEW_TEXT'],
                    'PREVIEW_TEXT_TYPE' => $element['PREVIEW_TEXT_TYPE'],
                    'PRICE_VALUE' => $element['PRICE_VALUE'],
                    'CURRENCY_VALUE' => $element['CURRENCY_VALUE'],
                    'QUANTITY_VALUE' => $element['QUANTITY_VALUE'],
                    'STORE_STOCK' => [], // Initialize as empty array
                ];
            }

            // Save warehouse stock data
            if ($element['STORE_ID'] !== null) {
                $elements[$elementId]['STORE_STOCK'][] = [
                    'STORE_ID' => $element['STORE_ID'],
                    'AMOUNT' => $element['STORE_AMOUNT'],
                ];
            }
        }

        Logger::log("Получено " . count($elements) . " элементов из исходного инфоблока с данными об остатках на складах.");
        flush();

        return $elements;
    }

    /**
     * Get properties of elements from source IBlock
     */
    protected function getElementsProperties($elementIds)
    {
        $propertiesQuery = new Query(ElementPropertyTable::getEntity());
        $propertiesQuery->setSelect([
            'IBLOCK_PROPERTY_ID',
            'IBLOCK_ELEMENT_ID',
            'VALUE',
            'VALUE_ENUM',
            'VALUE_NUM',
            'DESCRIPTION',
            'PROPERTY_ID' => 'PROPERTY.ID',
            'CODE' => 'PROPERTY.CODE',
            'PROPERTY_TYPE' => 'PROPERTY.PROPERTY_TYPE',
            'MULTIPLE' => 'PROPERTY.MULTIPLE',
            'USER_TYPE' => 'PROPERTY.USER_TYPE',
        ]);
        $propertiesQuery->setFilter(['IBLOCK_ELEMENT_ID' => $elementIds]);
        $propertiesQuery->registerRuntimeField(
            'PROPERTY',
            [
                'data_type' => PropertyTable::getEntity(),
                'reference' => ['=this.IBLOCK_PROPERTY_ID' => 'ref.ID'],
                'join_type' => 'INNER'
            ]
        );

        $propertiesResult = $propertiesQuery->exec();
        $properties = $propertiesResult->fetchAll();
        Logger::log("Retrieved element properties.");
        flush();

        // Organize properties by element
        $propertiesByElement = [];
        foreach ($properties as $property) {
            $elementId = $property['IBLOCK_ELEMENT_ID'];
            $propertiesByElement[$elementId][] = $property;
        }

        return $propertiesByElement;
    }

    /**
     * Prepare data for new elements
     */
    protected function prepareNewElementsData($elementsData, $propertiesByElement, $newModuleData)
    {
        $newElements = [];
        $priceData = [];
        $productData = [];
        $sectionData = [];
        $propertiesData = [];
        $warehouseData = []; // Array to store warehouse stock data

        // Build mapping of elementId to module data item
        $itemByElementId = [];
        foreach ($newModuleData as $item) {
            $itemByElementId[$item['ELEMENT_ID']] = $item;
        }

        foreach ($elementsData as $elementId => $element) {
            $newElements[$elementId] = [
                'IBLOCK_ID' => $this->targetIblockId,
                'NAME' => $element['NAME'],
                'XML_ID' => $element['XML_ID'],
                'CODE' => $element['CODE'] ?: \CUtil::translit($element['NAME'], 'ru'),
                'DETAIL_TEXT' => $element['DETAIL_TEXT'],
                'DETAIL_TEXT_TYPE' => $element['DETAIL_TEXT_TYPE'],
                'PREVIEW_TEXT' => $element['PREVIEW_TEXT'],
                'PREVIEW_TEXT_TYPE' => $element['PREVIEW_TEXT_TYPE'],
                'ACTIVE' => 'Y',
                'INDEX' => $elementId,
                'IN_SECTIONS' => 'Y'
            ];

            $priceData[$elementId] = [
                'PRICE' => $element['PRICE_VALUE'],
                'CURRENCY' => $element['CURRENCY_VALUE'],
            ];

            $productData[$elementId] = [
                'QUANTITY' => $element['QUANTITY_VALUE'],
            ];

            $sectionData[$elementId] = $this->getTargetSectionIdsForElement($elementId, $newModuleData);

            if (isset($propertiesByElement[$elementId])) {
                $propertiesData[$elementId] = $this->processProperties($propertiesByElement[$elementId], $elementId);
            } else {
                $propertiesData[$elementId] = [];
            }

            // Add SIZE_MODULE_REF and COLOR_MODULE_REF from module data
            if (isset($itemByElementId[$elementId])) {
                $item = $itemByElementId[$elementId];
                $propertiesData[$elementId]['SIZE_MODULE_REF'] = $item['SIZE_VALUE_ID'] ?? null;
                $propertiesData[$elementId]['COLOR_MODULE_REF'] = $item['COLOR_VALUE_ID'] ?? null;
            }

            // Include warehouse stock data
            $warehouseData[$elementId] = $element['STORE_STOCK'];
        }

        Logger::log("Подготовлены данные для новых элементов.");
        flush();

        return [
            'elements' => $newElements,
            'prices' => $priceData,
            'products' => $productData,
            'sections' => $sectionData,
            'properties' => $propertiesData,
            'warehouseData' => $warehouseData,
        ];
    }

    /**
     * Create new elements in target IBlock
     */
    protected function createNewElements($elementsData, $sectionData, $propertiesData)
    {
        $targetIblock = \Bitrix\Iblock\Iblock::wakeUp($this->targetIblockId);
        $targetIblockClass = $targetIblock->getEntityDataClass();

        $connection = Application::getConnection();
        $connection->startTransaction();

        try {
            $newElementIds = [];

            foreach ($elementsData as $index => $elementData) {
                $oldId = $elementData['INDEX'];
                unset($elementData['INDEX']);

                $addResult = $targetIblockClass::add($elementData);

                if ($addResult->isSuccess()) {
                    $newId = $addResult->getId();
                    $newElementIds[$oldId] = $newId;

                    Logger::log("Created element with ID $newId (old ID $oldId).");
                    flush();
                } else {
                    Logger::log("Error adding element with index $index: " . implode(", ", $addResult->getErrorMessages()) . "");
                    flush();
                }
            }

            // Update properties with new element IDs
            $this->addProperties($newElementIds, $propertiesData);

            // Add section bindings
            $this->addSectionsToElements($newElementIds, $sectionData);

            $connection->commitTransaction();
            Logger::log("Successfully added " . count($newElementIds) . " new elements.");
            flush();

            return $newElementIds;
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Error creating elements: " . $e->getMessage() . "");
            flush();
            return [];
        }
    }

    /**
     * Add warehouse stock data to new elements
     */
    protected function addWarehouseStockData($newElementIds, $warehouseData)
    {
        $storeProductEntries = [];
        foreach ($newElementIds as $oldId => $newId) {
            if (isset($warehouseData[$oldId]) && !empty($warehouseData[$oldId])) {
                foreach ($warehouseData[$oldId] as $stock) {
                    // Use store IDs directly
                    $storeProductEntries[] = [
                        'PRODUCT_ID' => $newId,
                        'STORE_ID' => $stock['STORE_ID'],
                        'AMOUNT' => $stock['AMOUNT']
                    ];
                }
            }
        }

        if (!empty($storeProductEntries)) {
            $result = StoreProductTable::addMulti($storeProductEntries);

            if (!$result->isSuccess()) {
                throw new \Exception("Ошибка при добавлении данных об остатках на складах: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Данные об остатках на складах успешно добавлены к новым элементам.");
            flush();
        } else {
            Logger::log("Нет данных об остатках на складах для добавления.");
            flush();
        }
    }

    /**
     * Add properties to new elements using addMulti
     */
    protected function addProperties($newElementIds, $propertiesData)
    {
        $propertyEntries = [];

        foreach ($newElementIds as $oldId => $newId) {
            if (isset($propertiesData[$oldId])) {
                foreach ($propertiesData[$oldId] as $propertyCode => $values) {
                    if (!isset($this->targetProperties[$propertyCode])) {
                        continue; // Skip properties that do not exist in the target IBlock
                    }

                    $propertyInfo = $this->targetProperties[$propertyCode];
                    $propertyId = $propertyInfo['ID'];
                    $propertyType = $propertyInfo['PROPERTY_TYPE'];
                    $isMultiple = $propertyInfo['MULTIPLE'] === 'Y';

                    // Ensure values are in an array for multiple properties
                    $values = $isMultiple ? (array) $values : [(is_array($values) ? reset($values) : $values)];

                    foreach ($values as $value) {
                        $propertyEntry = [
                            'IBLOCK_PROPERTY_ID' => $propertyId,
                            'IBLOCK_ELEMENT_ID' => $newId,
                            'VALUE' => null,
                            'VALUE_ENUM' => null,
                            'VALUE_NUM' => null,
                            'DESCRIPTION' => null,
                        ];

                        // Set the appropriate value field based on property type
                        if ($propertyType === 'L') {
                            $propertyEntry['VALUE_ENUM'] = $value;
                            $propertyEntry['VALUE'] = $value;
                        } elseif ($propertyType === 'N') {
                            $propertyEntry['VALUE_NUM'] = $value;
                        } else {
                            $propertyEntry['VALUE'] = $value;
                        }

                        $propertyEntries[] = $propertyEntry;
                    }
                }
            }
        }

        if (!empty($propertyEntries)) {

            $result = ElementPropertyTable::addMulti($propertyEntries, true);

            if (!$result->isSuccess()) {
                throw new \Exception("Error adding properties: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Properties successfully added to new elements.");
            flush();
        } else {
            Logger::log("No properties to add.");
            flush();
        }
    }

    /**
     * Add sections to new elements using addMulti
     */
    protected function addSectionsToElements($newElementIds, $sectionData)
    {
        $sectionEntries = [];
        foreach ($newElementIds as $oldId => $newId) {
            $targetSectionIds = $sectionData[$oldId] ?? [];
            if (!is_array($targetSectionIds)) {
                $targetSectionIds = [$targetSectionIds];
            }

            foreach ($targetSectionIds as $sectionId) {
                $sectionEntries[] = [
                    'IBLOCK_ELEMENT_ID' => $newId,
                    'IBLOCK_SECTION_ID' => $sectionId,
                ];
            }
        }

        if (!empty($sectionEntries)) {
            $result = SectionElementTable::addMulti($sectionEntries, true);

            if ($result->isSuccess()) {
                Logger::log("Sections successfully added to new elements.");
            } else {
                Logger::log("Error adding sections: " . implode(", ", $result->getErrorMessages()) . "");
            }

            flush();
        } else {
            Logger::log("No data for adding sections.");
            flush();
        }
    }

    /**
     * Add prices to new elements
     */
    protected function addPrices($newElementIds, $priceData)
    {
        $priceEntries = [];
        foreach ($newElementIds as $oldId => $newId) {
            $priceEntry = $priceData[$oldId];
            $priceEntry['PRODUCT_ID'] = $newId;
            $priceEntry['CATALOG_GROUP_ID'] = $this->priceGroupId;

            // Convert price to base currency for PRICE_SCALE
            $baseCurrency = CurrencyManager::getBaseCurrency();

            if ($priceEntry['CURRENCY'] != $baseCurrency) {
                $priceEntry['PRICE_SCALE'] = \CCurrencyRates::ConvertCurrency($priceEntry['PRICE'], $priceEntry['CURRENCY'], $baseCurrency);
            } else {
                $priceEntry['PRICE_SCALE'] = $priceEntry['PRICE'];
            }

            $priceEntries[] = $priceEntry;
        }

        $result = \Bitrix\Catalog\PriceTable::addMulti($priceEntries);

        if (!$result->isSuccess()) {
            throw new \Exception("Error adding prices: " . implode(", ", $result->getErrorMessages()));
        }

        Logger::log("Prices successfully added to new elements.");
        flush();
    }

    /**
     * Add product data to new elements
     */
    protected function addProductData($newElementIds, $productData, $productType)
    {
        $productEntries = [];
        foreach ($newElementIds as $oldId => $newId) {
            $productEntry = $productData[$oldId];
            $productEntry['ID'] = $newId;
            $productEntry['TYPE'] = $productType;
            $productEntry['AVAILABLE'] = 'Y';
            $productEntries[] = $productEntry;
        }

        $result = \Bitrix\Catalog\ProductTable::addMulti($productEntries);

        if (!$result->isSuccess()) {
            throw new \Exception("Error adding product data: " . implode(", ", $result->getErrorMessages()));
        }

        Logger::log("Product data successfully added to new elements.");
        flush();
    }

    /**
     * Get target section IDs for an element
     */
    protected function getTargetSectionIdsForElement($oldElementId, $newModuleData)
    {
        foreach ($newModuleData as $dataItem) {
            if ($dataItem['ELEMENT_ID'] == $oldElementId) {
                $sectionIds = $dataItem['TARGET_SECTION_ID'];
                if (!is_array($sectionIds)) {
                    $sectionIds = [$sectionIds];
                }
                return $sectionIds;
            }
        }

        return [];
    }

    /**
     * Process properties of an element
     */
    protected function processProperties($properties, $elementId)
    {
        $propertiesData = [];

        foreach ($properties as $property) {
            $propertyCode = $property['CODE'];
            $isMultiple = $property['MULTIPLE'] === 'Y';
            $propertyType = $property['PROPERTY_TYPE'];
            $userType = $property['USER_TYPE'];

            if (!isset($this->targetProperties[$propertyCode])) {
                continue;
            }

            $value = null;
            if ($propertyType === 'L') {
                // Map list value
                $sourceEnumId = $property['VALUE_ENUM'];
                $value = $this->enumMapping[$sourceEnumId] ?? null;
            } elseif ($userType === 'directory') {
                // "Directory" type properties
                $value = $property['VALUE'];
            } elseif ($propertyType === 'F') {
                // "File" type properties
                $fileId = $property['VALUE'];
                $fileArray = \CFile::MakeFileArray($fileId);
                if ($fileArray) {
                    $newFileId = \CFile::SaveFile($fileArray, "iblock");
                    $value = $newFileId;
                }
            } elseif ($propertyType === 'E') {
                // "Link to element" type properties
                $value = $this->getNewElementIdByOldId($property['VALUE']);
            } elseif ($propertyType === 'S' || $propertyType === 'N') {
                $value = $property['VALUE'] ?: $property['VALUE_NUM'];
            } else {
                // Other property types
                $value = $property['VALUE'];
            }

            if ($value !== null) {
                if ($isMultiple) {
                    $propertiesData[$propertyCode][] = $value;
                } else {
                    $propertiesData[$propertyCode] = $value;
                }
            }
        }
        return $propertiesData;
    }

    /**
     * Get enum mapping between source and target IBlocks
     */
    protected function getEnumMapping()
    {
        // Get property codes of "List" type from source IBlock
        $propertyCodes = PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => $this->sourceIblockId,
                'PROPERTY_TYPE' => 'L'
            ],
            'select' => ['CODE']
        ])->fetchAll();

        $propertyCodes = array_column($propertyCodes, 'CODE');

        // Get enum values from both IBlocks
        $enums = PropertyEnumerationTable::getList([
            'filter' => [
                'PROPERTY.IBLOCK_ID' => [$this->sourceIblockId, $this->targetIblockId],
                'PROPERTY.CODE' => $propertyCodes
            ],
            'select' => ['ID', 'PROPERTY_ID', 'VALUE', 'XML_ID', 'PROPERTY_CODE' => 'PROPERTY.CODE', 'IBLOCK_ID' => 'PROPERTY.IBLOCK_ID'],
            'runtime' => [
                new \Bitrix\Main\Entity\ReferenceField(
                    'PROPERTY',
                    PropertyTable::getEntity(),
                    ['=this.PROPERTY_ID' => 'ref.ID'],
                    ['join_type' => 'INNER']
                )
            ]
        ])->fetchAll();

        // Group enums by property code and IBlock
        $sourceEnums = [];
        $targetEnums = [];
        foreach ($enums as $enum) {
            if ($enum['IBLOCK_ID'] == $this->sourceIblockId) {
                $sourceEnums[$enum['PROPERTY_CODE']][] = $enum;
            } else {
                $targetEnums[$enum['PROPERTY_CODE']][] = $enum;
            }
        }

        // Map values
        $mapping = [];
        foreach ($sourceEnums as $propertyCode => $sourcePropertyEnums) {
            if (isset($targetEnums[$propertyCode])) {
                $targetPropertyEnums = $targetEnums[$propertyCode];
                foreach ($sourcePropertyEnums as $sourceEnum) {
                    foreach ($targetPropertyEnums as $targetEnum) {
                        if ($sourceEnum['VALUE'] == $targetEnum['VALUE']) {
                            $mapping[$sourceEnum['ID']] = $targetEnum['ID'];
                            break;
                        }
                    }
                }
            }
        }

        Logger::log("Enum mapping completed.<br>");
        flush();

        return $mapping;
    }

    /**
     * Get new element ID by old ID for "E" type properties
     */
    protected function getNewElementIdByOldId($oldId)
    {
        // Implement logic to map old IDs to new IDs if copying linked elements
        return $oldId; // For now, return old ID
    }

    /**
     * Get target IBlock properties
     */
    protected function getTargetProperties()
    {
        $propertyList = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $this->targetIblockId],
            'select' => ['ID', 'CODE', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE'],
        ]);

        $properties = [];
        while ($property = $propertyList->fetch()) {
            $properties[$property['CODE']] = $property;
        }

        return $properties;
    }
}