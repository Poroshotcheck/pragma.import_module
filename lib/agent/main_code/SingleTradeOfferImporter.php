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

use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Logger;

class SingleTradeOfferImporter
{
    protected $moduleId;
    protected $sourceIblockId;
    protected $targetIblockId;
    protected $targetOffersIblockId;
    protected $priceGroupId;
    protected $enumMapping = [];
    protected $targetProperties = [];
    protected $pack = 0;

    public function __construct($moduleId, $sourceIblockId, $targetIblockId, $targetOffersIblockId, $priceGroupId)
    {
        try {
            Loader::includeModule('iblock');
            Loader::includeModule('catalog');

            $this->moduleId = $moduleId;
            $this->sourceIblockId = $sourceIblockId;
            $this->targetIblockId = $targetIblockId;
            $this->priceGroupId = $priceGroupId;
            $this->targetOffersIblockId = $targetOffersIblockId;

            $this->enumMapping = $this->getEnumMapping();
            $this->targetProperties = $this->getTargetProperties();
        } catch (\Exception $e) {
            Logger::log("Error in SingleTradeOfferImporter constructor: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public function copyElementsFromModuleData($batchSize = 1000)
    {
        try {
            $this->processElementsWithoutChain($batchSize);
        } catch (\Exception $e) {
            Logger::log("Error in copyElementsFromModuleData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Process elements without CHAIN_TOGEZER
     */
    protected function processElementsWithoutChain($batchSize)
    {
        $offset = 0;

        try {
            while (true) {
                $moduleData = $this->getModuleData($batchSize, $offset);

                if (empty($moduleData)) {
                    break;
                }

                foreach ($moduleData as $item) {
                    $this->processSingleElement($item);
                }

                $offset += $batchSize;
            }
        } catch (\Exception $e) {
            Logger::log("Error in processElementsWithoutChain(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Process a single element to create parent and trade offer
     */
    protected function processSingleElement($moduleDataItem)
    {
        try {
            $elementId = $moduleDataItem['ELEMENT_ID'];
            $elementXmlId = $moduleDataItem['ELEMENT_XML_ID'];

            // Check if the parent product already exists
            $parentExists = $this->checkIfParentExists($elementXmlId);

            if ($parentExists) {
                Logger::log("Parent product for XML_ID {$elementXmlId} already exists. Skipping.");
                return;
            }

            // Get element data
            $elementsData = $this->getElementsData([$elementId]);
            if (empty($elementsData)) {
                Logger::log("No data found for element ID {$elementId}. Skipping.");
                return;
            }
            $elementData = $elementsData[$elementId];

            // Get element properties
            $propertiesByElement = $this->getElementsProperties([$elementId]);
            $properties = $propertiesByElement[$elementId] ?? [];

            // Create parent product
            $parentId = $this->createParentProduct($elementData, $moduleDataItem);

            // Create trade offer
            $this->createTradeOffer($elementData, $properties, $moduleDataItem, $parentId);

            Logger::log("Successfully processed element ID {$elementId}.");

        } catch (\Exception $e) {
            Logger::log("Error in processSingleElement(): " . $e->getMessage(), "ERROR");
            Logger::log("Trace: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }
    }

    /**
     * Check if a parent product already exists based on XML_ID
     */
    protected function checkIfParentExists($elementXmlId)
    {
        try {
            $parentXmlId = 'PARENT_' . $elementXmlId;

            $existingParent = ElementTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetIblockId,
                    'XML_ID' => $parentXmlId,
                ],
                'select' => ['ID'],
            ])->fetch();

            return $existingParent ? true : false;
        } catch (\Exception $e) {
            Logger::log("Error in checkIfParentExists(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Create parent product
     */
    protected function createParentProduct($elementData, $moduleDataItem)
    {
        try {
            $parentXmlId = 'PARENT_' . $elementData['XML_ID'];
            $productName = $elementData['NAME'];

            $elementFields = [
                'IBLOCK_ID' => $this->targetIblockId,
                'NAME' => $productName,
                'ACTIVE' => 'Y',
                'CODE' => $elementData['CODE'] ?: \CUtil::translit($productName, 'ru'),
                'IN_SECTIONS' => 'Y',
                'XML_ID' => $parentXmlId,
            ];

            $targetIblock = \Bitrix\Iblock\Iblock::wakeUp($this->targetIblockId);
            $targetIblockClass = $targetIblock->getEntityDataClass();

            $addResult = $targetIblockClass::add($elementFields);

            if ($addResult->isSuccess()) {
                $newId = $addResult->getId();

                // Set product type to TYPE_SKU
                $productAddResult = ProductTable::add([
                    'ID' => $newId,
                    'TYPE' => ProductTable::TYPE_SKU,
                ]);

                if (!$productAddResult->isSuccess()) {
                    throw new \Exception("Error adding product data: " . implode(', ', $productAddResult->getErrorMessages()));
                }

                // Assign sections
                $sectionIds = $moduleDataItem['TARGET_SECTION_ID'];
                if (!is_array($sectionIds)) {
                    $sectionIds = [$sectionIds];
                }
                $this->addSectionsToElements([$newId], [$newId => $sectionIds]);

                return $newId;
            } else {
                throw new \Exception("Error adding parent product: " . implode(', ', $addResult->getErrorMessages()));
            }
        } catch (\Exception $e) {
            Logger::log("Error in createParentProduct(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Create trade offer
     */
    protected function createTradeOffer($elementData, $properties, $moduleDataItem, $parentId)
    {
        try {
            $elementFields = [
                'IBLOCK_ID' => $this->targetOffersIblockId,
                'NAME' => $elementData['NAME'],
                'XML_ID' => $elementData['XML_ID'],
                'CODE' => $elementData['CODE'] ?: \CUtil::translit($elementData['NAME'], 'ru'),
                'DETAIL_TEXT' => $elementData['DETAIL_TEXT'],
                'DETAIL_TEXT_TYPE' => $elementData['DETAIL_TEXT_TYPE'],
                'PREVIEW_TEXT' => $elementData['PREVIEW_TEXT'],
                'PREVIEW_TEXT_TYPE' => $elementData['PREVIEW_TEXT_TYPE'],
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => [
                    'CML2_LINK' => $parentId,
                    // Add other properties
                ],
            ];

            // Process properties
            $processedProperties = $this->processProperties($properties, $elementData['ID']);
            $elementFields['PROPERTY_VALUES'] = array_merge($elementFields['PROPERTY_VALUES'], $processedProperties);

            // Add SIZE_MODULE_REF and COLOR_MODULE_REF from module data
            $elementFields['PROPERTY_VALUES']['SIZE_MODULE_REF'] = $moduleDataItem['SIZE_VALUE_ID'] ?? null;
            $elementFields['PROPERTY_VALUES']['COLOR_MODULE_REF'] = $moduleDataItem['COLOR_VALUE_ID'] ?? null;
            $elementFields['PROPERTY_VALUES']['TYPE_MODULE_REF'] = $moduleDataItem['TYPE_VALUE_ID'] ?? null;

            $ciBlockElement = new \CIBlockElement;
            $newId = $ciBlockElement->Add($elementFields);

            if ($newId) {
                // Add product data
                $this->addProductData([$elementData['ID'] => $newId], [$elementData['ID'] => ['QUANTITY' => $elementData['QUANTITY_VALUE']]], ProductTable::TYPE_OFFER);

                // Add prices
                $this->addPrices([$elementData['ID'] => $newId], [$elementData['ID'] => ['PRICE' => $elementData['PRICE_VALUE'], 'CURRENCY' => $elementData['CURRENCY_VALUE']]]);

                // Add warehouse stock data
                $this->addWarehouseStockData([$elementData['ID'] => $newId], [$elementData['ID'] => $elementData['STORE_STOCK']]);

                return $newId;
            } else {
                throw new \Exception("Error adding trade offer: " . $ciBlockElement->LAST_ERROR);
            }
        } catch (\Exception $e) {
            Logger::log("Error in createTradeOffer(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Get module data
     */
    protected function getModuleData($batchSize = 0, $offset = 0)
    {
        try {
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

            $data = $moduleDataResult->fetchAll();
        } catch (\Exception $e) {
            Logger::log("Error in getModuleData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $data;
    }

    /**
     * Get elements data
     */
    protected function getElementsData($elementIds)
    {
        try {
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
        } catch (\Exception $e) {
            Logger::log("Error in getElementsData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $elements;
    }

    /**
     * Get elements properties
     */
    protected function getElementsProperties($elementIds)
    {
        try {
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

            // Organize properties by element
            $propertiesByElement = [];
            foreach ($properties as $property) {
                $elementId = $property['IBLOCK_ELEMENT_ID'];
                $propertiesByElement[$elementId][] = $property;
            }
        } catch (\Exception $e) {
            Logger::log("Error in getElementsProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $propertiesByElement;
    }

    /**
     * Process properties
     */
    protected function processProperties($properties, $elementId)
    {
        try {
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
                    // Map list values
                    $sourceEnumId = $property['VALUE_ENUM'];
                    $value = $this->enumMapping[$sourceEnumId] ?? null;
                } elseif ($userType === 'directory') {
                    // Properties of type "directory"
                    $value = $property['VALUE'];
                } elseif ($propertyType === 'F') {
                    // Properties of type "file"
                    $fileId = $property['VALUE'];
                    $fileArray = \CFile::MakeFileArray($fileId);
                    if ($fileArray) {
                        $newFileId = \CFile::SaveFile($fileArray, "iblock");
                        $value = $newFileId;
                    }
                } elseif ($propertyType === 'E') {
                    // Properties of type "element binding"
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
        } catch (\Exception $e) {
            Logger::log("Error in processProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Add product data
     */
    protected function addProductData($newElementIds, $productData, $productType)
    {
        try {
            $productEntries = [];
            foreach ($newElementIds as $oldId => $newId) {
                $productEntry = $productData[$oldId];
                $productEntry['ID'] = $newId;
                $productEntry['TYPE'] = $productType;
                $productEntry['AVAILABLE'] = 'Y';
                $productEntries[] = $productEntry;
            }

            $result = ProductTable::addMulti($productEntries);

            if (!$result->isSuccess()) {
                throw new \Exception("Error adding product data: " . implode(", ", $result->getErrorMessages()));
            }
        } catch (\Exception $e) {
            Logger::log("Error in addProductData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Add prices
     */
    protected function addPrices($newElementIds, $priceData)
    {
        try {
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

            $result = PriceTable::addMulti($priceEntries);

            if (!$result->isSuccess()) {
                throw new \Exception("Error adding prices: " . implode(", ", $result->getErrorMessages()));
            }
        } catch (\Exception $e) {
            Logger::log("Error in addPrices(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Add warehouse stock data
     */
    protected function addWarehouseStockData($newElementIds, $warehouseData)
    {
        try {
            $storeProductEntries = [];
            foreach ($newElementIds as $oldId => $newId) {
                if (isset($warehouseData[$oldId]) && !empty($warehouseData[$oldId])) {
                    foreach ($warehouseData[$oldId] as $stock) {
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
            }
        } catch (\Exception $e) {
            Logger::log("Error in addWarehouseStockData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Add sections to elements
     */
    protected function addSectionsToElements($newElementIds, $sectionData)
    {
        try {
            $sectionEntries = [];
            foreach ($newElementIds as $newId) {
                $targetSectionIds = $sectionData[$newId] ?? [];
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

                if (!$result->isSuccess()) {
                    throw new \Exception("Error adding section bindings: " . implode(", ", $result->getErrorMessages()));
                }
            }
        } catch (\Exception $e) {
            Logger::log("Error in addSectionsToElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Get enum mapping
     */
    protected function getEnumMapping()
    {
        try {
            // Get property codes of type "List" from source IBlock
            $propertyCodes = PropertyTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->sourceIblockId,
                    'PROPERTY_TYPE' => 'L'
                ],
                'select' => ['CODE']
            ])->fetchAll();

            $propertyCodes = array_column($propertyCodes, 'CODE');

            // Get enums from both IBlocks
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

            // Map enums
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

            return $mapping;
        } catch (\Exception $e) {
            Logger::log("Error in getEnumMapping(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Get target properties
     */
    protected function getTargetProperties()
    {
        try {
            $propertyList = PropertyTable::getList([
                'filter' => ['IBLOCK_ID' => $this->targetOffersIblockId],
                'select' => ['ID', 'CODE', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE'],
            ]);

            $properties = [];
            while ($property = $propertyList->fetch()) {
                $properties[$property['CODE']] = $property;
            }

            return $properties;
        } catch (\Exception $e) {
            Logger::log("Error in getTargetProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Get new element ID by old ID for properties of type "E"
     */
    protected function getNewElementIdByOldId($oldId)
    {
        // Implement logic if needed
        return $oldId; // For now, return old ID
    }
}
