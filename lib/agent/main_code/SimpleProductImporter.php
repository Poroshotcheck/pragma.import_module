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
            Logger::log("Ошибка в конструкторе SimpleProductImporter: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public function copyElementsFromModuleData($batchSize = 1000)
    {
        try {
            $this->processElementsWithoutChain($batchSize);
        } catch (\Exception $e) {
            Logger::log("Ошибка в copyElementsFromModuleData(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }
    }

    /**
     * Обработка элементов без CHAIN_TOGEZER
     */
    protected function processElementsWithoutChain($batchSize)
    {
        $offset = 0;

        try {
            while (true) {
                $moduleData = $this->getModuleData($batchSize, $offset);

                if (empty($moduleData)) {
                    //Logger::log("Обработка элементов без CHAIN_TOGEZER завершена");
                    break;
                }

                $existingElements = $this->getExistingElements($moduleData);
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

                if (!empty($existingElements['offers'])) {
                    $productUpdater->updateExistingElements($existingElements['offers'], $elementsDataByXmlId);
                }

                $newModuleData = $this->filterExistingElements($moduleData, $existingElements);

                if (empty($newModuleData)) {
                    //Logger::log("Все элементы в этом пакете уже существуют в целевых инфоблоках");
                    $offset += $batchSize;
                    continue;
                }

                $elementIdsToCopy = array_column($newModuleData, 'ELEMENT_ID');
                $newElementIds = $this->copyIblockElements($elementIdsToCopy, $newModuleData);

                if (!empty($newElementIds)) {
                    Logger::log("Создано " . count($newElementIds) . ' новых "простых" товаров');
                } else {
                    Logger::log("Ошибка при создании простых товаров", "ERROR");
                }

                $offset += $batchSize;
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в processElementsWithoutChain(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получает данные из ModuleDataTable
     */
    protected function getModuleData($batchSize = 0, $offset = 0)
    {
        //Logger::log("Получение данных из ModuleDataTable с offset: {$offset}, batchSize: {$batchSize}");
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
            //Logger::log("Получено " . count($data) . " записей из ModuleDataTable");
        } catch (\Exception $e) {
            Logger::log("Ошибка в getModuleData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $data;
    }

    /**
     * Проверка существующих элементов в целевых инфоблоках
     */
    protected function getExistingElements($moduleData)
    {
        //Logger::log("Проверка существующих элементов в целевых инфоблоках");

        try {
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

            Logger::log("Найдено " . count($existingElementsInTarget) . " существующих элементов в целевом инфоблоке");
            Logger::log("Найдено " . count($existingElementsInOffers) . " существующих элементов в инфоблоке торговых предложений");
        } catch (\Exception $e) {
            Logger::log("Ошибка в getExistingElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        //Logger::log("Завершена проверка существующих элементов. Время выполнения: {$duration} секунд");

        return [
            'target' => $existingElementsInTarget,
            'offers' => $existingElementsInOffers
        ];
    }

    /**
     * Фильтрация существующих элементов из текущего пакета
     */
    protected function filterExistingElements($moduleData, $existingElements)
    {
        //Logger::log("Фильтрация существующих элементов из текущего пакета");

        try {
            $existingXmlIds = array_merge(
                array_keys($existingElements['target']),
                array_keys($existingElements['offers'])
            );

            $filteredData = array_filter($moduleData, function ($item) use ($existingXmlIds) {
                return !in_array($item['ELEMENT_XML_ID'], $existingXmlIds);
            });

            //Logger::log("После фильтрации осталось " . count($filteredData) . " новых элементов для обработки");
        } catch (\Exception $e) {
            Logger::log("Ошибка в filterExistingElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        //Logger::log("Завершена фильтрация существующих элементов. Время выполнения: {$duration} секунд");

        return $filteredData;
    }

    /**
     * Копирование элементов в целевой инфоблок
     */
    protected function copyIblockElements($elementIds, $newModuleData)
    {
        //Logger::log("Начало копирования элементов: " . implode(', ', $elementIds));

        try {
            $elementsData = $this->getElementsData($elementIds);
            $propertiesByElement = $this->getElementsProperties($elementIds);

            $preparedData = $this->prepareNewElementsData($elementsData, $propertiesByElement, $newModuleData);

            $newElementIds = $this->createNewElements($preparedData['elements'], $preparedData['sections'], $preparedData['properties']);

            if (!empty($newElementIds)) {
                Logger::log("Создано " . count($newElementIds) . " новых простых продуктов");
                $this->addPrices($newElementIds, $preparedData['prices']);
                $this->addProductData($newElementIds, $preparedData['products'], \Bitrix\Catalog\ProductTable::TYPE_PRODUCT);
                $this->addWarehouseStockData($newElementIds, $preparedData['warehouseData']);
            } else {
                Logger::log("Ошибка при создании простых продуктов", "ERROR");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в copyIblockElements(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }
        return $newElementIds;
    }

    /**
     * Получение данных элементов из исходного инфоблока
     */
    protected function getElementsData($elementIds)
    {
        //Logger::log("Получение данных элементов из исходного инфоблока");

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

            //Logger::log("Получено " . count($elements) . " элементов из исходного инфоблока");
        } catch (\Exception $e) {
            Logger::log("Ошибка в getElementsData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $elements;
    }

    /**
     * Получение свойств элементов из исходного инфоблока
     */
    protected function getElementsProperties($elementIds)
    {
        //Logger::log("Получение свойств элементов из исходного инфоблока");

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
            // Logger::log("Получено свойств: " . count($properties));

            // Organize properties by element
            $propertiesByElement = [];
            foreach ($properties as $property) {
                $elementId = $property['IBLOCK_ELEMENT_ID'];
                $propertiesByElement[$elementId][] = $property;
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в getElementsProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $propertiesByElement;
    }

    /**
     * Подготовка данных для новых элементов
     */
    protected function prepareNewElementsData($elementsData, $propertiesByElement, $newModuleData)
    {
        //Logger::log("Подготовка данных для новых элементов");

        try {
            $newElements = [];
            $priceData = [];
            $productData = [];
            $sectionData = [];
            $propertiesData = [];
            $warehouseData = [];

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

            //Logger::log("Данные для новых элементов подготовлены");
        } catch (\Exception $e) {
            Logger::log("Ошибка в prepareNewElementsData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

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
     * Создание новых элементов в целевом инфоблоке
     */
    protected function createNewElements($elementsData, $sectionData, $propertiesData)
    {
        //Logger::log("Начало создания новых элементов в целевом инфоблоке");

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

                    //Logger::log("Создан элемент с ID {$newId} (старый ID {$oldId})");
                } else {
                    Logger::log("Ошибка при добавлении элемента с индексом {$index}: " . implode(", ", $addResult->getErrorMessages()), "ERROR");
                }
            }

            // Update properties with new element IDs
            $this->addProperties($newElementIds, $propertiesData);

            // Add section bindings
            $this->addSectionsToElements($newElementIds, $sectionData);

            $connection->commitTransaction();
            Logger::log("Успешно добавлено " . count($newElementIds) . " новых элементов");
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Ошибка при создании элементов: " . $e->getMessage(), "ERROR");
            throw $e;
        }

        return $newElementIds;
    }

    /**
     * Добавляет данные об остатках на складах к новым элементам
     */
    protected function addWarehouseStockData($newElementIds, $warehouseData)
    {
        //Logger::log("Начало выполнения addWarehouseStockData()");

        try {
            $storeProductEntries = [];
            foreach ($newElementIds as $oldId => $newId) {
                if (isset($warehouseData[$oldId]) && !empty($warehouseData[$oldId])) {
                    foreach ($warehouseData[$oldId] as $stock) {
                        // Используем ID складов напрямую
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
            } else {
                Logger::log("Нет данных об остатках на складах для добавления.");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в addWarehouseStockData(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }
    }

    /**
     * Добавляет свойства к новым элементам
     */
    protected function addProperties($newElementIds, $propertiesData)
    {
        //Logger::log("Начало выполнения addProperties()");

        try {
            $propertyEntries = [];

            foreach ($newElementIds as $oldId => $newId) {
                if (isset($propertiesData[$oldId])) {
                    foreach ($propertiesData[$oldId] as $propertyCode => $values) {
                        if (!isset($this->targetProperties[$propertyCode])) {
                            continue; // Пропускаем свойства, которых нет в целевом инфоблоке
                        }

                        $propertyInfo = $this->targetProperties[$propertyCode];
                        $propertyId = $propertyInfo['ID'];
                        $propertyType = $propertyInfo['PROPERTY_TYPE'];
                        $isMultiple = $propertyInfo['MULTIPLE'] === 'Y';

                        // Убеждаемся, что значения представлены в виде массива для множественных свойств
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

                            // Устанавливаем соответствующее поле значения в зависимости от типа свойства
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
                    throw new \Exception("Ошибка при добавлении свойств: " . implode(", ", $result->getErrorMessages()));
                }

                Logger::log("Значения свойства успешно добавлены к новым элементам.");
            } else {
                Logger::log("Нет свойств для добавления.");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в addProperties(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    /**
     * Добавляет секции к элементам
     */
    protected function addSectionsToElements($newElementIds, $sectionData)
    {
        //Logger::log("Начало выполнения addSectionsToElements()");

        try {
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
                    Logger::log("Привязка к разделам успешно добавлены к новым элементам.");
                } else {
                    throw new \Exception("Ошибка при добавлении привязки к разделам: " . implode(", ", $result->getErrorMessages()));
                }
            } else {
                Logger::log("Нет данных для добавления привязки к разделам.");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в addSectionsToElements(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    /**
     * Добавляет цены к новым элементам
     */
    protected function addPrices($newElementIds, $priceData)
    {
        //Logger::log("Начало выполнения addPrices()");

        try {
            $priceEntries = [];
            foreach ($newElementIds as $oldId => $newId) {
                $priceEntry = $priceData[$oldId];
                $priceEntry['PRODUCT_ID'] = $newId;
                $priceEntry['CATALOG_GROUP_ID'] = $this->priceGroupId;

                // Конвертируем цену в базовую валюту для PRICE_SCALE
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
                throw new \Exception("Ошибка при добавлении цен: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Цены успешно добавлены к новым элементам.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в addPrices(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    /**
     * Добавляет данные о продуктах к новым элементам
     */
    protected function addProductData($newElementIds, $productData, $productType)
    {
        //Logger::log("Начало выполнения addProductData()");

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
                throw new \Exception("Ошибка при добавлении данных о количестве продукта: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Данные о количестве продукта успешно добавлены к новым элементам.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в addProductData(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    /**
     * Получает целевые ID разделов для элемента
     */
    protected function getTargetSectionIdsForElement($oldElementId, $newModuleData)
    {
        //Logger::log("Получение целевых ID разделов для элемента ID: {$oldElementId}");

        try {
            foreach ($newModuleData as $dataItem) {
                if ($dataItem['ELEMENT_ID'] == $oldElementId) {
                    $sectionIds = $dataItem['TARGET_SECTION_ID'];
                    if (!is_array($sectionIds)) {
                        $sectionIds = [$sectionIds];
                    }
                    //Logger::log("Найдены целевые разделы: " . implode(', ', $sectionIds));
                    return $sectionIds;
                }
            }
            //Logger::log("Целевые разделы не найдены для элемента ID: {$oldElementId}");
            return [];
        } catch (\Exception $e) {
            Logger::log("Ошибка в getTargetSectionIdsForElement(): " . $e->getMessage(), "ERROR");
            throw $e;
        } 
    }

    /**
     * Обрабатывает свойства элемента
     */
    protected function processProperties($properties, $elementId)
    {
        //Logger::log("Начало обработки свойств для элемента ID: {$elementId}");

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

                $value = null;
                if ($propertyType === 'L') {
                    // Сопоставляем значения списков
                    $sourceEnumId = $property['VALUE_ENUM'];
                    $value = $this->enumMapping[$sourceEnumId] ?? null;
                } elseif ($userType === 'directory') {
                    // Свойства типа "справочник"
                    $value = $property['VALUE'];
                } elseif ($propertyType === 'F') {
                    // Свойства типа "файл"
                    $fileId = $property['VALUE'];
                    $fileArray = \CFile::MakeFileArray($fileId);
                    if ($fileArray) {
                        $newFileId = \CFile::SaveFile($fileArray, "iblock");
                        $value = $newFileId;
                    }
                } elseif ($propertyType === 'E') {
                    // Свойства типа "привязка к элементу"
                    $value = $this->getNewElementIdByOldId($property['VALUE']);
                } elseif ($propertyType === 'S' || $propertyType === 'N') {
                    $value = $property['VALUE'] ?: $property['VALUE_NUM'];
                } else {
                    // Другие типы свойств
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

            //Logger::log("Обработка свойств для элемента ID: {$elementId} завершена");
            return $propertiesData;
        } catch (\Exception $e) {
            Logger::log("Ошибка в processProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получает сопоставление значений свойств типа "список" между исходным и целевым инфоблоками
     */
    protected function getEnumMapping()
    {
       // Logger::log("Начало получения сопоставления значений свойств типа 'список'");

        try {
            // Получаем коды свойств типа "список" из исходного инфоблока
            $propertyCodes = PropertyTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->sourceIblockId,
                    'PROPERTY_TYPE' => 'L'
                ],
                'select' => ['CODE']
            ])->fetchAll();

            $propertyCodes = array_column($propertyCodes, 'CODE');

            // Получаем значения списков из обоих инфоблоков
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

            // Группируем значения по коду свойства и инфоблоку
            $sourceEnums = [];
            $targetEnums = [];
            foreach ($enums as $enum) {
                if ($enum['IBLOCK_ID'] == $this->sourceIblockId) {
                    $sourceEnums[$enum['PROPERTY_CODE']][] = $enum;
                } else {
                    $targetEnums[$enum['PROPERTY_CODE']][] = $enum;
                }
            }

            // Сопоставляем значения
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

            //Logger::log("Сопоставление значений свойств типа 'список' завершено");
            return $mapping;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getEnumMapping(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получает новый ID элемента по старому ID для свойств типа "E"
     */
    protected function getNewElementIdByOldId($oldId)
    {
        // Этот метод может быть реализован по мере необходимости.
        //Logger::log("Вызов getNewElementIdByOldId() для старого ID: {$oldId}");
        return $oldId; // Пока возвращаем старый ID
    }

    /**
     * Получает свойства целевого инфоблока
     */
    protected function getTargetProperties()
    {
        //Logger::log("Начало получения свойств целевого инфоблока");

        try {
            $propertyList = PropertyTable::getList([
                'filter' => ['IBLOCK_ID' => $this->targetIblockId],
                'select' => ['ID', 'CODE', 'PROPERTY_TYPE', 'USER_TYPE', 'MULTIPLE'],
            ]);

            $properties = [];
            while ($property = $propertyList->fetch()) {
                $properties[$property['CODE']] = $property;
            }

            //Logger::log("Получено свойств целевого инфоблока: " . count($properties));
            return $properties;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getTargetProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }
}