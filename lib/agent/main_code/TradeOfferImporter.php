<?php

namespace Pragma\ImportModule\Agent\MainCode;

// Use namespaces
use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Pragma\ImportModule\ModuleDataTable;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\Query;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Iblock\SectionElementTable;
use Pragma\ImportModule\Logger;
use Bitrix\Catalog\StoreProductTable;

class TradeOfferImporter
{
    protected $moduleId; // Module ID
    protected $sourceIblockId; // Source IBlock ID
    protected $targetIblockId; // Target IBlock ID (Products)
    protected $targetOffersIblockId; // Target Offers IBlock ID
    protected $priceGroupId;
    protected $enumMapping = []; // Enum mapping
    protected $parentIdsByChain = []; // Mapping of CHAIN_TOGEZER to parent element IDs
    protected $targetProperties = []; // Target IBlock properties

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

    /**
     * Main method to copy elements from ModuleDataTable
     */
    public function copyElementsFromModuleData($chainRangeSize = 50)
    {
        $startTime = microtime(true);

        $currentChain = 1;

        while (true) {
            $chainRange = ['min' => $currentChain, 'max' => $currentChain + $chainRangeSize - 1];

            Logger::log("Starting processing elements with CHAIN_TOGEZER in the range {$chainRange['min']} - {$chainRange['max']}.");

            // Process elements with CHAIN_TOGEZER in the current range
            $elementsProcessed = $this->processElementsWithChain($chainRange);

            Logger::log("Processing elements with CHAIN_TOGEZER in the range {$chainRange['min']} - {$chainRange['max']} completed.");

            if (!$elementsProcessed) {
                // If no elements in the current range, exit the loop
                Logger::log("No further elements with CHAIN_TOGEZER. Processing completed.");
                break;
            }

            // Move to the next range
            $currentChain += $chainRangeSize;
        }

        // After processing all ranges, assign sections to parent products
        $this->assignSectionsToParents();

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        Logger::log("Script completed. Execution time: " . round($executionTime, 2) . " seconds.");
    }

    /**
     * Process elements with CHAIN_TOGEZER
     */
    protected function processElementsWithChain($chainRange)
    {
        // Get all elements with CHAIN_TOGEZER in the specified range
        $moduleData = $this->getModuleData($chainRange);

        if (empty($moduleData)) {
            Logger::log("No elements with CHAIN_TOGEZER in the range {$chainRange['min']} - {$chainRange['max']}.");
            flush();
            // No data to process in the current range
            return false;
        }

        // Group elements by CHAIN_TOGEZER
        $groupedElements = $this->groupElementsByChain($moduleData);

        // Check existing elements in target infoblocks
        $existingElements = $this->getExistingElements($moduleData);

        // Create parent products for groups without existing parents
        $this->createParentElements($groupedElements, $existingElements['parents']);

        // Collect all trade offers to create
        $tradeOffersToCreate = $this->filterExistingOffers($moduleData, $existingElements['offers']);

        if (empty($tradeOffersToCreate)) {
            Logger::log("All trade offers in this batch already exist.");
            flush();
            return true;
        }

        // Copy trade offers and link them to the parent elements
        $this->copyTradeOffers($tradeOffersToCreate);

        return true;
    }

    /**
     * Get data from ModuleDataTable
     */
    protected function getModuleData($chainRange)
    {
        $filter = ['!TARGET_SECTION_ID' => 'a:0:{}'];

        // Filter by CHAIN_TOGEZER range
        $filter['>=CHAIN_TOGEZER'] = $chainRange['min'];
        $filter['<=CHAIN_TOGEZER'] = $chainRange['max'];

        $queryParams = [
            'filter' => $filter,
        ];

        $moduleDataResult = ModuleDataTable::getList($queryParams);

        return $moduleDataResult->fetchAll();
    }

    /**
     * Group elements by CHAIN_TOGEZER
     */
    protected function groupElementsByChain($moduleData)
    {
        $groupedElements = [];
        foreach ($moduleData as $item) {
            $chain = $item['CHAIN_TOGEZER'];
            $groupedElements[$chain][] = $item;
        }
        return $groupedElements;
    }

    /**
     * Check existing elements in target infoblocks
     */
    protected function getExistingElements($moduleData)
    {
        $elementXmlIds = array_column($moduleData, 'ELEMENT_XML_ID');

        // Arrays to store existing elements
        $existingOffers = []; // Existing trade offers
        $existingParents = []; // Existing parent products

        // Get CML2_LINK property ID
        $propertyCml2Link = PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => $this->targetOffersIblockId,
                'CODE' => 'CML2_LINK'
            ],
            'select' => ['ID']
        ])->fetch();

        if (!$propertyCml2Link) {
            throw new \Exception("CML2_LINK property not found in target offers IBlock.");
        }

        $cml2LinkPropertyId = $propertyCml2Link['ID'];

        // Get existing trade offers and their parent IDs
        $offerElements = ElementTable::getList([
            'filter' => [
                'IBLOCK_ID' => $this->targetOffersIblockId,
                'XML_ID' => $elementXmlIds
            ],
            'select' => ['ID', 'XML_ID', 'CML2_LINK_VALUE' => 'CML2_LINK_PROP.VALUE'],
            'runtime' => [
                // Join the ElementPropertyTable to get the CML2_LINK property value
                new \Bitrix\Main\Entity\ReferenceField(
                    'CML2_LINK_PROP',
                    ElementPropertyTable::getEntity(),
                    [
                        '=this.ID' => 'ref.IBLOCK_ELEMENT_ID',
                        '=ref.IBLOCK_PROPERTY_ID' => new \Bitrix\Main\DB\SqlExpression('?', $cml2LinkPropertyId)
                    ],
                    ['join_type' => 'LEFT']
                ),
            ]
        ])->fetchAll();

        foreach ($offerElements as $element) {
            $existingOffers[$element['XML_ID']] = $element['ID'];
            // Map CHAIN_TOGEZER to parent IDs
            $chain = $this->getChainByXmlId($element['XML_ID'], $moduleData);
            if ($chain && $element['CML2_LINK_VALUE']) {
                $existingParents[$chain] = $element['CML2_LINK_VALUE'];
            }
        }

        // For existing parents, ensure we have their IDs
        foreach ($moduleData as $item) {
            $chain = $item['CHAIN_TOGEZER'];
            if (!isset($existingParents[$chain])) {
                // Check if parent exists in target products IBlock
                $parentElement = ElementTable::getList([
                    'filter' => [
                        'IBLOCK_ID' => $this->targetIblockId,
                        'XML_ID' => 'PARENT_' . $chain // Adjust according to your actual parent XML_ID generation logic
                    ],
                    'select' => ['ID']
                ])->fetch();

                if ($parentElement) {
                    $existingParents[$chain] = $parentElement['ID'];
                }
            }
        }

        return [
            'offers' => $existingOffers,
            'parents' => $existingParents
        ];
    }

    /**
     * Get CHAIN_TOGEZER by XML_ID from source data
     */
    protected function getChainByXmlId($xmlId, $moduleData)
    {
        foreach ($moduleData as $item) {
            if ($item['ELEMENT_XML_ID'] == $xmlId) {
                return $item['CHAIN_TOGEZER'];
            }
        }
        return null;
    }

    /**
     * Create parent elements (products) for groups without existing parents
     */
    protected function createParentElements($groupedElements, $existingParents)
    {
        $parentsToCreate = [];
        foreach ($groupedElements as $chain => $elements) {
            if (!isset($existingParents[$chain])) {
                // Need to create parent product
                $parentsToCreate[$chain] = $elements;
            } else {
                // Parent exists, save it
                $this->parentIdsByChain[$chain] = [
                    'ID' => $existingParents[$chain],
                    'SECTION_IDS' => [] // Will update sections later
                ];
            }
        }

        if (empty($parentsToCreate)) {
            return;
        }

        $parentElementsData = [];
        foreach ($parentsToCreate as $chain => $elements) {
            // Collect names of all trade offers in the group
            $elementNames = array_column($elements, 'ELEMENT_NAME');

            // Generate product name
            $productName = $this->extractCommonProductName($elementNames);

            // Collect section IDs from trade offers
            $sectionIds = [];
            foreach ($elements as $item) {
                $targetSectionIds = $item['TARGET_SECTION_ID'];
                if (!is_array($targetSectionIds)) {
                    $targetSectionIds = [$targetSectionIds];
                }
                if (is_array($targetSectionIds)) {
                    $sectionIds = array_merge($sectionIds, $targetSectionIds);
                }
            }
            // Remove duplicate section IDs
            $sectionIds = array_unique($sectionIds);

            // Form data for new parent element
            $elementData = [
                'IBLOCK_ID' => $this->targetIblockId,
                'NAME' => $productName,
                'ACTIVE' => 'Y',
                'CODE' => \CUtil::translit($productName, 'ru'),
                'IN_SECTIONS' => 'Y',
                'XML_ID' => 'PARENT_' . $chain, // Assign a unique XML_ID for the parent
                'INDEX_CHAIN' => $chain, // Custom field to map back after creation
            ];

            $parentElementsData[] = [
                'DATA' => $elementData,
                'SECTION_IDS' => $sectionIds,
                'CHAIN' => $chain,
            ];
        }

        // Batch create parent elements
        $this->batchCreateParentElements($parentElementsData);
    }

    /**
     * Batch create parent elements
     */
    protected function batchCreateParentElements($parentElementsData)
    {
        $targetIblock = \Bitrix\Iblock\Iblock::wakeUp($this->targetIblockId);
        $targetIblockClass = $targetIblock->getEntityDataClass();

        $connection = Application::getConnection();
        $connection->startTransaction();

        try {
            foreach ($parentElementsData as $parentData) {
                $elementData = $parentData['DATA'];
                $sectionIds = $parentData['SECTION_IDS'];
                $chain = $parentData['CHAIN'];

                $addResult = $targetIblockClass::add($elementData);

                if ($addResult->isSuccess()) {
                    $newId = $addResult->getId();

                    // Save parent element ID and section IDs
                    $this->parentIdsByChain[$chain] = [
                        'ID' => $newId,
                        'SECTION_IDS' => $sectionIds,
                    ];

                    // Set product type as product with trade offers
                    $productAddResult = \Bitrix\Catalog\ProductTable::add([
                        'ID' => $newId,
                        'TYPE' => \Bitrix\Catalog\ProductTable::TYPE_SKU,
                    ]);

                    if (!$productAddResult->isSuccess()) {
                        $errors = $productAddResult->getErrorMessages();
                        throw new \Exception("Error adding product data for parent element $newId: " . implode(", ", $errors));
                    }

                    Logger::log("Created parent element with ID $newId for CHAIN_TOGEZER $chain with name \"{$elementData['NAME']}\".");
                    flush();
                } else {
                    throw new \Exception("Error creating parent element for CHAIN_TOGEZER $chain: " . implode(", ", $addResult->getErrorMessages()));
                }
            }

            $connection->commitTransaction();
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Error creating parent elements: " . $e->getMessage() . "");
            flush();
        }
    }

    /**
     * Filter trade offers that need to be created
     */
    protected function filterExistingOffers($moduleData, $existingOffers)
    {
        $tradeOffersToCreate = [];
        foreach ($moduleData as $item) {
            if (!isset($existingOffers[$item['ELEMENT_XML_ID']])) {
                $tradeOffersToCreate[] = $item;
            }
        }
        return $tradeOffersToCreate;
    }

    /**
     * Copy trade offers and link them to the parent elements
     */
    protected function copyTradeOffers($tradeOffersToCreate)
    {
        // Collect all elementIds of trade offers
        $elementIds = array_column($tradeOffersToCreate, 'ELEMENT_ID');

        // Get element data and properties in one query
        $elementsData = $this->getElementsData($elementIds);
        $properties = $this->getElementsProperties($elementIds);

        // Prepare data for new trade offers
        $preparedData = $this->prepareNewTradeOffersData($elementsData, $properties, $tradeOffersToCreate);

        // Batch create new trade offers
        $newOfferIds = $this->createNewTradeOffers($preparedData);

        if (!empty($newOfferIds)) {
            Logger::log("Created " . count($newOfferIds) . " new trade offers.");
            flush();
        } else {
            Logger::log("Error creating trade offers.");
            flush();
        }
    }

    /**
     * Extract common product name from trade offer names
     */
    protected function extractCommonProductName($elementNames)
    {
        if (empty($elementNames)) {
            return '';
        }

        // Clean names of numbers and special characters
        $cleanNames = [];
        foreach ($elementNames as $name) {
            $cleanName = preg_replace('/[0-9\|\-\/]/u', '', $name); // Remove numbers and symbols | - /
            $cleanName = preg_replace('/\s+/', ' ', $cleanName); // Remove extra spaces
            $cleanNames[] = trim($cleanName);
        }

        // Split names into arrays of words
        $namesAsWords = [];
        foreach ($cleanNames as $name) {
            $words = explode(' ', $name);
            $namesAsWords[] = $words;
        }

        // Find common words in all names
        $commonWords = call_user_func_array('array_intersect', $namesAsWords);

        if (!empty($commonWords)) {
            // If there are common words, join them into a string
            $productName = implode(' ', $commonWords);
        } else {
            // If no common words, use the cleaned name of the first element
            $productName = $cleanNames[0];
        }

        // Remove extra spaces
        $productName = trim($productName);

        return $productName;
    }

    /**
     * Assign sections to parent products in batch
     */
    protected function assignSectionsToParents()
    {
        $sectionEntries = [];

        foreach ($this->parentIdsByChain as $chain => $parentData) {
            $parentId = $parentData['ID'];
            $sectionIds = $parentData['SECTION_IDS'];

            foreach ($sectionIds as $sectionId) {
                $sectionEntries[] = [
                    'IBLOCK_ELEMENT_ID' => $parentId,
                    'IBLOCK_SECTION_ID' => $sectionId,
                ];
            }
        }

        if (!empty($sectionEntries)) {
            $result = SectionElementTable::addMulti($sectionEntries, true);

            if ($result->isSuccess()) {
                Logger::log("Assigned sections to parent products successfully.");
            } else {
                Logger::log("Error assigning sections: " . implode(", ", $result->getErrorMessages()) . "");
            }
        }
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

        Logger::log("Retrieved " . count($elements) . " elements from source IBlock with warehouse stock data.");
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
     * Prepare data for creating new trade offers
     */
    protected function prepareNewTradeOffersData($elementsData, $propertiesByElement, $moduleDataItems)
    {
        $preparedData = [
            'elements' => [],
            'prices' => [],
            'products' => [],
            'properties' => [],
            'sections' => [],
            'warehouseData' => [], // Add warehouseData to preparedData
        ];

        foreach ($moduleDataItems as $item) {
            $elementId = $item['ELEMENT_ID'];
            $element = $elementsData[$elementId];

            $chain = $item['CHAIN_TOGEZER'];
            $parentId = $this->parentIdsByChain[$chain]['ID'];

            // Form data for the new trade offer
            $newElementData = [
                'IBLOCK_ID' => $this->targetOffersIblockId,
                'NAME' => $element['NAME'],
                'XML_ID' => $element['XML_ID'],
                'CODE' => $element['CODE'] ?: \CUtil::translit($element['NAME'], 'ru'),
                'DETAIL_TEXT' => $element['DETAIL_TEXT'],
                'DETAIL_TEXT_TYPE' => $element['DETAIL_TEXT_TYPE'],
                'PREVIEW_TEXT' => $element['PREVIEW_TEXT'],
                'PREVIEW_TEXT_TYPE' => $element['PREVIEW_TEXT_TYPE'],
                'ACTIVE' => 'Y',
                'INDEX' => $elementId,
                'PROPERTY_VALUES' => [
                    'CML2_LINK' => $parentId, // Link to parent element
                ],
            ];

            $preparedData['elements'][$elementId] = $newElementData;

            // Prepare price and product data
            $preparedData['prices'][$elementId] = [
                'PRICE' => $element['PRICE_VALUE'],
                'CURRENCY' => $element['CURRENCY_VALUE'],
            ];

            $preparedData['products'][$elementId] = [
                'QUANTITY' => $element['QUANTITY_VALUE'],
            ];

            // Include warehouse stock data
            $preparedData['warehouseData'][$elementId] = $element['STORE_STOCK'];

            // Process properties
            if (isset($propertiesByElement[$elementId])) {
                $preparedData['properties'][$elementId] = $this->processProperties($propertiesByElement[$elementId], $elementId);
            } else {
                $preparedData['properties'][$elementId] = [];
            }

            // Assign SIZE_MODULE_REF and COLOR_MODULE_REF properties directly
            $preparedData['properties'][$elementId]['SIZE_MODULE_REF'] = $item['SIZE_VALUE_ID'] ?? null;
            $preparedData['properties'][$elementId]['COLOR_MODULE_REF'] = $item['COLOR_VALUE_ID'] ?? null;
        }

        return $preparedData;
    }
    /**
     * Create new trade offers
     */
    protected function createNewTradeOffers($preparedData)
    {
        $elementsData = $preparedData['elements'];
        $priceData = $preparedData['prices'];
        $productData = $preparedData['products'];
        $propertiesData = $preparedData['properties'];
        $warehouseData = $preparedData['warehouseData']; // Added warehouseData

        $ciBlockElement = new \CIBlockElement;

        $connection = Application::getConnection();
        $connection->startTransaction();

        try {
            $newOfferIds = [];

            foreach ($elementsData as $index => $elementData) {
                $oldId = $elementData['INDEX'];
                unset($elementData['INDEX']);

                $newId = $ciBlockElement->Add($elementData);

                if (!empty($ciBlockElement->LAST_ERROR)) {
                    Logger::log("Error " . $ciBlockElement->LAST_ERROR);
                } else {
                    $newOfferIds[$oldId] = $newId;
                    $propertyValuesList[$newId] = $propertiesData[$oldId] ?? [];
                    Logger::log("Created trade offer with ID $newId (old ID $oldId).");
                }
            }

            // Batch add properties
            $this->addProperties($propertyValuesList);

            // Batch add product data
            $this->addProductData($newOfferIds, $productData, \Bitrix\Catalog\ProductTable::TYPE_OFFER);

            // Batch add prices
            $this->addPrices($newOfferIds, $priceData);

            // Add warehouse stock data
            $this->addWarehouseStockData($newOfferIds, $warehouseData);

            $connection->commitTransaction();

            return $newOfferIds;
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Error creating trade offers: " . $e->getMessage());
            flush();
            return [];
        }
    }

    /**
     * Process properties of an element for trade offer
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
            $targetProperty = $this->targetProperties[$propertyCode];

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
     * Add properties to new elements using addMulti
     */
    protected function addProperties($propertyValuesList)
    {
        $elementPropertyValues = [];

        foreach ($propertyValuesList as $elementId => $properties) {
            foreach ($properties as $propertyCode => $value) {
                if (!isset($this->targetProperties[$propertyCode])) {
                    Logger::log("Property code '$propertyCode' not found in target properties.");
                    continue;
                }
                $propertyId = $this->targetProperties[$propertyCode]['ID'];
                $isMultiple = $this->targetProperties[$propertyCode]['MULTIPLE'] === 'Y';
                $propertyType = $this->targetProperties[$propertyCode]['PROPERTY_TYPE'];

                // Ensure values are in an array for multiple properties
                $values = $isMultiple ? (array) $value : [(is_array($value) ? reset($value) : $value)];

                foreach ($values as $val) {
                    $propertyEntry = [
                        'IBLOCK_PROPERTY_ID' => $propertyId,
                        'IBLOCK_ELEMENT_ID' => $elementId,
                        'VALUE' => null,
                        'VALUE_ENUM' => null,
                        'VALUE_NUM' => null,
                        'DESCRIPTION' => null,
                    ];

                    // Set the appropriate value field based on property type
                    if ($propertyType === 'L') {
                        $propertyEntry['VALUE_ENUM'] = $val;
                        $propertyEntry['VALUE'] = $val;
                    } elseif ($propertyType === 'N') {
                        $propertyEntry['VALUE_NUM'] = $val;
                    } elseif ($propertyType === 'E') {
                        $propertyEntry['VALUE'] = (int) $val; // Ensure the value is an integer ID
                    } else {
                        $propertyEntry['VALUE'] = $val;
                    }

                    $elementPropertyValues[] = $propertyEntry;


                }
            }
        }

        if (!empty($elementPropertyValues)) {
            $result = ElementPropertyTable::addMulti($elementPropertyValues, true);

            if (!$result->isSuccess()) {
                throw new \Exception("Error adding properties: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Properties successfully added to new trade offers.");
            flush();
        } else {
            Logger::log("No properties to add.");
            flush();
        }
    }

    /**
     * Add warehouse stock data to new trade offers
     */
    protected function addWarehouseStockData($newElementIds, $warehouseData)
    {
        $storeProductEntries = [];
        foreach ($newElementIds as $oldId => $newId) {
            if (isset($warehouseData[$oldId]) && !empty($warehouseData[$oldId])) {
                foreach ($warehouseData[$oldId] as $stock) {
                    // Since store IDs are the same, use them directly
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
                throw new \Exception("Error adding warehouse stock data: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Warehouse stock data successfully added to new trade offers.");
            flush();
        } else {
            Logger::log("No warehouse stock data to add.");
            flush();
        }
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
                'PROPERTY.IBLOCK_ID' => [$this->sourceIblockId, $this->targetOffersIblockId],
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
     * Get target IBlock properties
     */
    protected function getTargetProperties()
    {
        $propertyList = PropertyTable::getList([
            'filter' => ['IBLOCK_ID' => $this->targetOffersIblockId],
            'select' => ['ID', 'CODE', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE'],
        ]);

        $properties = [];
        while ($property = $propertyList->fetch()) {
            $properties[$property['CODE']] = $property;
        }

        return $properties;
    }

    /**
     * Get new element ID by old ID for "E" type properties
     */
    protected function getNewElementIdByOldId($oldId)
    {
        // Implement logic to map old IDs to new IDs if copying linked elements
        return $oldId; // For now, return old ID
    }
}