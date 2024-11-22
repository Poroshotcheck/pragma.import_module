<?php

namespace Pragma\ImportModule\Agent\MainCode;

// Use namespaces
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
    protected $moduleId; // Module ID
    protected $sourceIblockId; // Source IBlock ID
    protected $targetIblockId; // Target IBlock ID (Products)
    protected $targetOffersIblockId; // Target Offers IBlock ID
    protected $priceGroupId;
    protected $enumMapping = []; // Enum mapping
    protected $parentIdsByXmlId = []; // Mapping of ELEMENT_XML_ID to parent element IDs
    protected $targetProperties = []; // Target IBlock properties
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
            Logger::log("Ошибка в конструкторе SingleTradeOfferImporter: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Главный метод для копирования элементов из ModuleDataTable
     */
    public function copyElementsFromModuleData($batchSize = 1000)
    {
        $offset = 0;

        try {
            while (true) {
                $moduleData = $this->getModuleData($batchSize, $offset);

                if (empty($moduleData)) {
                    break;
                }

                $this->processElements($moduleData);

                $offset += $batchSize;
            }

            // После обработки всех элементов, назначаем разделы родительским продуктам
            $this->assignSectionsToParents();

        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::copyElementsFromModuleData(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
        }
    }

    /**
     * Получение данных из ModuleDataTable
     */
    protected function getModuleData($batchSize = 0, $offset = 0)
    {
        try {
            $filter = ['!TARGET_SECTION_ID' => 'a:0:{}'];

            // Элементы без CHAIN_TOGEZER
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

            //Logger::log("Получено " . count($data) . " элементов из ModuleDataTable.");

            return $data;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::getModuleData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Обработка элементов
     */
    protected function processElements($moduleData)
    {
        try {
            // Получаем все ELEMENT_ID
            $elementIds = array_column($moduleData, 'ELEMENT_ID');

            // Получаем данные элементов и свойства
            $elementsDataArray = $this->getElementsData($elementIds);
            $elementsDataByXmlId = [];
            foreach ($elementsDataArray as $element) {
                $elementsDataByXmlId[$element['XML_ID']] = $element;
            }
            $propertiesByElement = $this->getElementsProperties($elementIds);

            // Проверяем существующие элементы в целевых инфоблоках
            $existingElements = $this->getExistingElements($moduleData);

            // Создаем экземпляр ProductUpdater
            $productUpdater = new ProductUpdater($this->priceGroupId);

            Logger::log("Обновляем существующие элементы");

            if (!empty($existingElements['offers'])) {
                $productUpdater->updateExistingElements($existingElements['offers'], $elementsDataByXmlId);
            }

            if (!empty($existingElements['parents'])) {
                $productUpdater->updateExistingElements($existingElements['parents'], $elementsDataByXmlId);
            }

            // Создаем родительские продукты для элементов без существующих родителей
            $this->createParentElements($moduleData, $existingElements['parents']);

            // Собираем все торговые предложения для создания
            $tradeOffersToCreate = $this->filterExistingOffers($moduleData, $existingElements['offers']);

            if (empty($tradeOffersToCreate)) {
                return;
            }

            // Копируем торговые предложения и связываем их с родительскими элементами
            $this->copyTradeOffers($tradeOffersToCreate, $elementsDataByXmlId, $propertiesByElement);

        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::processElements(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }
    }

    /**
     * Проверка существующих элементов в целевых инфоблоках
     */
    protected function getExistingElements($moduleData)
    {
        try {
            $elementXmlIds = array_column($moduleData, 'ELEMENT_XML_ID');

            // Массивы для существующих торговых предложений и родителей
            $existingOffers = []; // Существующие торговые предложения
            $existingParents = []; // Существующие родители

            // Получаем ID свойства CML2_LINK
            $propertyCml2Link = PropertyTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'CODE' => 'CML2_LINK'
                ],
                'select' => ['ID']
            ])->fetch();

            if (!$propertyCml2Link) {
                throw new \Exception("Свойство CML2_LINK не найдено в инфоблоке торговых предложений.");
            }

            $cml2LinkPropertyId = $propertyCml2Link['ID'];

            // Получаем существующие торговые предложения и их родительские ID
            $offerElements = ElementTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'XML_ID' => $elementXmlIds
                ],
                'select' => ['ID', 'XML_ID', 'CML2_LINK_VALUE' => 'CML2_LINK_PROP.VALUE'],
                'runtime' => [
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

                // Сопоставляем ELEMENT_XML_ID с родительскими ID через CML2_LINK_VALUE
                $xmlId = $element['XML_ID'];
                if ($xmlId && $element['CML2_LINK_VALUE']) {
                    $existingParents[$xmlId] = $element['CML2_LINK_VALUE'];
                }
            }

            // Получаем существующие родительские элементы
            $parentXmlIds = array_map(function($xmlId) {
                return 'PARENT_' . $xmlId;
            }, $elementXmlIds);

            $parentElements = ElementTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetIblockId,
                    'XML_ID' => $parentXmlIds
                ],
                'select' => ['ID', 'XML_ID']
            ])->fetchAll();

            foreach ($parentElements as $element) {
                $xmlId = str_replace('PARENT_', '', $element['XML_ID']);
                $existingParents[$xmlId] = $element['ID'];
            }

            $this->pack++;
            Logger::log("Обработка " . $this->pack . " пачки элементов");
            Logger::log("Найдено " . count($existingParents) . " существующих родителей в целевом инфоблоке");
            Logger::log("Найдено " . count($existingOffers) . " существующих элементов в инфоблоке торговых предложений");

            return [
                'offers' => $existingOffers,
                'parents' => $existingParents
            ];

        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::getExistingElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Создание родительских элементов (продуктов) для элементов без существующих родителей
     */
    protected function createParentElements($moduleData, $existingParents)
    {
        try {
            $parentsToCreate = [];
            foreach ($moduleData as $item) {
                $xmlId = $item['ELEMENT_XML_ID'];
                if (!isset($existingParents[$xmlId])) {
                    // Необходимо создать родительский продукт
                    $parentsToCreate[$xmlId] = $item;
                } else {
                    // Родитель существует, сохраняем его
                    $this->parentIdsByXmlId[$xmlId] = [
                        'ID' => $existingParents[$xmlId],
                        'SECTION_IDS' => $item['TARGET_SECTION_ID']
                    ];
                }
            }

            if (empty($parentsToCreate)) {
                return;
            }

            $parentElementsData = [];
            foreach ($parentsToCreate as $xmlId => $item) {
                $productName = $item['ELEMENT_NAME'];

                // Собираем ID разделов из элемента
                $sectionIds = $item['TARGET_SECTION_ID'];
                if (!is_array($sectionIds)) {
                    $sectionIds = [$sectionIds];
                }

                // Формируем данные для нового родительского элемента
                $elementData = [
                    'IBLOCK_ID' => $this->targetIblockId,
                    'NAME' => $productName,
                    'ACTIVE' => 'Y',
                    'CODE' => \CUtil::translit($productName, 'ru'),
                    'IN_SECTIONS' => 'Y',
                    'XML_ID' => 'PARENT_' . $xmlId,
                    'INDEX_XML_ID' => $xmlId,
                ];

                $parentElementsData[] = [
                    'DATA' => $elementData,
                    'SECTION_IDS' => $sectionIds,
                    'XML_ID' => $xmlId,
                ];
            }

            // Пакетное создание родительских элементов
            $this->batchCreateParentElements($parentElementsData);

        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::createParentElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Пакетное создание родительских элементов
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
                $xmlId = $parentData['XML_ID'];

                $addResult = $targetIblockClass::add($elementData);

                if ($addResult->isSuccess()) {
                    $newId = $addResult->getId();

                    // Сохраняем ID родительского элемента и ID разделов
                    $this->parentIdsByXmlId[$xmlId] = [
                        'ID' => $newId,
                        'SECTION_IDS' => $sectionIds,
                    ];

                    // Устанавливаем тип продукта как продукт с торговыми предложениями
                    $productAddResult = \Bitrix\Catalog\ProductTable::add([
                        'ID' => $newId,
                        'TYPE' => \Bitrix\Catalog\ProductTable::TYPE_SKU,
                        'AVAILABLE' => 'Y',
                    ]);

                    if (!$productAddResult->isSuccess()) {
                        $errors = $productAddResult->getErrorMessages();
                        throw new \Exception("Ошибка при добавлении данных продукта для родительского элемента $newId: " . implode(", ", $errors));
                    }

                    //Logger::log("Создан родительский элемент с ID $newId для ELEMENT_XML_ID $xmlId с названием \"{$elementData['NAME']}\".");
                } else {
                    throw new \Exception("Ошибка при создании родительского элемента для ELEMENT_XML_ID $xmlId: " . implode(", ", $addResult->getErrorMessages()));
                }
            }

            $connection->commitTransaction();
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Ошибка при создании родительских элементов: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Фильтрация торговых предложений, которые необходимо создать
     */
    protected function filterExistingOffers($moduleData, $existingOffers)
    {
        try {
            $tradeOffersToCreate = [];
            foreach ($moduleData as $item) {
                if (!isset($existingOffers[$item['ELEMENT_XML_ID']])) {
                    $tradeOffersToCreate[] = $item;
                }
            }
            //Logger::log("Отфильтровано " . count($tradeOffersToCreate) . " торговых предложений для создания.");
            return $tradeOffersToCreate;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::filterExistingOffers(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Копирование торговых предложений и привязка их к родительским элементам
     */
    protected function copyTradeOffers($tradeOffersToCreate, $elementsDataByXmlId, $propertiesByElement)
    {
        try {
            // Подготовка данных для новых торговых предложений
            $preparedData = $this->prepareNewTradeOffersData($tradeOffersToCreate, $elementsDataByXmlId, $propertiesByElement);

            // Пакетное создание новых торговых предложений
            $newOfferIds = $this->createNewTradeOffers($preparedData);

            if (!empty($newOfferIds)) {
                Logger::log("Создано " . count($newOfferIds) . " новых торговых предложений.");
            } else {
                Logger::log("Ошибка при создании торговых предложений.", "ERROR");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::copyTradeOffers(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Подготовка данных для создания новых торговых предложений
     */
    protected function prepareNewTradeOffersData($moduleDataItems, $elementsDataByXmlId, $propertiesByElement)
    {
        try {
            $preparedData = [
                'elements' => [],
                'prices' => [],
                'products' => [],
                'properties' => [],
                'warehouseData' => [],
            ];

            foreach ($moduleDataItems as $item) {
                $elementXmlId = $item['ELEMENT_XML_ID'];
                $element = $elementsDataByXmlId[$elementXmlId];

                $parentId = $this->parentIdsByXmlId[$elementXmlId]['ID'];

                // Формируем данные для нового торгового предложения
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
                    'INDEX' => $element['ID'],
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $parentId, // Связь с родительским элементом
                    ],
                ];

                $preparedData['elements'][$element['ID']] = $newElementData;

                // Подготовка данных цен и продуктов
                $preparedData['prices'][$element['ID']] = [
                    'PRICE' => $element['PRICE_VALUE'],
                    'CURRENCY' => $element['CURRENCY_VALUE'],
                ];

                $preparedData['products'][$element['ID']] = [
                    'QUANTITY' => $element['QUANTITY_VALUE'],
                ];

                // Включаем данные складского учета
                $preparedData['warehouseData'][$element['ID']] = $element['STORE_STOCK'];
                // Обработка свойств
                if (isset($propertiesByElement[$element['ID']])) {
                    $preparedData['properties'][$element['ID']] = $this->processProperties($propertiesByElement[$element['ID']], $element['ID']);
                } else {
                    $preparedData['properties'][$element['ID']] = [];
                }

                $preparedData['properties'][$element['ID']]['SIZE_MODULE_REF'] = $item['SIZE_VALUE_ID'] ?? null;
                $preparedData['properties'][$element['ID']]['COLOR_MODULE_REF'] = $item['COLOR_VALUE_ID'] ?? null;
                $preparedData['properties'][$element['ID']]['TYPE_MODULE_REF'] = $item['TYPE_VALUE_ID'] ?? null;
            }

            //Logger::log("Подготовка данных для новых торговых предложений завершена.");

            return $preparedData;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::prepareNewTradeOffersData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Создание новых торговых предложений
     */
    protected function createNewTradeOffers($preparedData)
    {
        $elementsData = $preparedData['elements'];
        $priceData = $preparedData['prices'];
        $productData = $preparedData['products'];
        $propertiesData = $preparedData['properties'];
        $warehouseData = $preparedData['warehouseData'];

        $ciBlockElement = new \CIBlockElement;

        $connection = Application::getConnection();
        $connection->startTransaction();

        try {
            $newOfferIds = [];
            $propertyValuesList = [];

            foreach ($elementsData as $index => $elementData) {
                $oldId = $elementData['INDEX'];
                unset($elementData['INDEX']);

                // Создаем торговое предложение
                $newId = $ciBlockElement->Add($elementData);

                if (!$newId) {
                    Logger::log("Ошибка при добавлении торгового предложения: " . $ciBlockElement->LAST_ERROR, "ERROR");
                    throw new \Exception("Ошибка при добавлении торгового предложения: " . $ciBlockElement->LAST_ERROR);
                } else {
                    $newOfferIds[$oldId] = $newId;
                    $propertyValuesList[$newId] = $propertiesData[$oldId] ?? [];
                }
            }

            // Пакетное добавление свойств
            $this->addProperties($propertyValuesList);

            // Пакетное добавление данных продуктов
            $this->addProductData($newOfferIds, $productData, \Bitrix\Catalog\ProductTable::TYPE_OFFER);

            // Пакетное добавление цен
            $this->addPrices($newOfferIds, $priceData);

            // Добавление данных складского учета
            $this->addWarehouseStockData($newOfferIds, $warehouseData);

            $connection->commitTransaction();

            return $newOfferIds;
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            Logger::log("Ошибка при создании торговых предложений: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Добавление свойств к новым элементам с использованием addMulti
     */
    protected function addProperties($propertyValuesList)
    {
        try {
            $elementPropertyValues = [];

            foreach ($propertyValuesList as $elementId => $properties) {
                foreach ($properties as $propertyCode => $value) {
                    if (!isset($this->targetProperties[$propertyCode])) {
                        Logger::log("Свойство с кодом '$propertyCode' не найдено в целевых свойствах.", "WARNING");
                        continue;
                    }
                    $propertyId = $this->targetProperties[$propertyCode]['ID'];
                    $isMultiple = $this->targetProperties[$propertyCode]['MULTIPLE'] === 'Y';
                    $propertyType = $this->targetProperties[$propertyCode]['PROPERTY_TYPE'];

                    // Убеждаемся, что значения находятся в массиве для множественных свойств
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

                        // Устанавливаем соответствующее поле значения в зависимости от типа свойства
                        if ($propertyType === 'L') {
                            $propertyEntry['VALUE_ENUM'] = $val;
                            $propertyEntry['VALUE'] = $val;
                        } elseif ($propertyType === 'N') {
                            $propertyEntry['VALUE_NUM'] = $val;
                        } elseif ($propertyType === 'E') {
                            $propertyEntry['VALUE'] = (int) $val; // Убеждаемся, что значение является целочисленным ID
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
                    throw new \Exception("Ошибка при добавлении свойств: " . implode(", ", $result->getErrorMessages()));
                }

                Logger::log("Значение свойства успешно добавлены к новым торговым предложениям.");
            } else {
                Logger::log("Нет свойств для добавления.");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::addProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Добавление данных продуктов к новым элементам
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

            $result = \Bitrix\Catalog\ProductTable::addMulti($productEntries);

            if (!$result->isSuccess()) {
                throw new \Exception("Ошибка при добавлении данных продуктов: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Данные о количестве продуктов успешно добавлены к новым элементам.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::addProductData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Добавление цен к новым элементам
     */
    protected function addPrices($newElementIds, $priceData)
    {
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

            $result = \Bitrix\Catalog\PriceTable::addMulti($priceEntries);

            if (!$result->isSuccess()) {
                throw new \Exception("Ошибка при добавлении цен: " . implode(", ", $result->getErrorMessages()));
            }

            Logger::log("Цены успешно добавлены к новым элементам.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::addPrices(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Добавление данных складского учета к новым торговым предложениям
     */
    protected function addWarehouseStockData($newElementIds, $warehouseData)
    {
        try {
            $storeProductEntries = [];
            foreach ($newElementIds as $oldId => $newId) {
                if (isset($warehouseData[$oldId]) && !empty($warehouseData[$oldId])) {
                    foreach ($warehouseData[$oldId] as $stock) {
                        // Поскольку ID складов одинаковые, используем их напрямую
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
                    throw new \Exception("Ошибка при добавлении данных складского учета: " . implode(", ", $result->getErrorMessages()));
                }

                Logger::log("Данные о складах успешно добавлены к новым торговым предложениям.");
            } else {
                Logger::log("Нет данных складского учета для добавления.");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::addWarehouseStockData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Назначение разделов родительским продуктам
     */
    protected function assignSectionsToParents()
    {
        try {
            $sectionEntries = [];

            foreach ($this->parentIdsByXmlId as $xmlId => $parentData) {
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
                    Logger::log("Разделы успешно назначены родительским продуктам.");
                } else {
                    Logger::log("Ошибка при назначении разделов: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    throw new \Exception("Ошибка при назначении разделов: " . implode(", ", $result->getErrorMessages()));
                }
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::assignSectionsToParents(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получение данных элементов из исходного инфоблока
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

            // Инициализируем массив элементов
            $elements = [];

            while ($element = $elementsResult->fetch()) {
                $elementId = $element['ID'];

                if (!isset($elements[$elementId])) {
                    // Инициализируем данные элемента
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
                        'STORE_STOCK' => [], // Инициализируем как пустой массив
                    ];
                }

                // Сохраняем данные склада
                if ($element['STORE_ID'] !== null) {
                    $elements[$elementId]['STORE_STOCK'][] = [
                        'STORE_ID' => $element['STORE_ID'],
                        'AMOUNT' => $element['STORE_AMOUNT'],
                    ];
                }
            }

            //Logger::log("Получено " . count($elements) . " элементов из исходного инфоблока с данными складского учета.");

            return $elements;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::getElementsData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получение свойств элементов из исходного инфоблока
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
            //Logger::log("Получены свойства элементов.");

            // Организуем свойства по элементам
            $propertiesByElement = [];
            foreach ($properties as $property) {
                $elementId = $property['IBLOCK_ELEMENT_ID'];
                $propertiesByElement[$elementId][] = $property;
            }

            return $propertiesByElement;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::getElementsProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Обработка свойств элемента для торгового предложения
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
                    // Сопоставление значений списка
                    $sourceEnumId = $property['VALUE_ENUM'];
                    $value = $this->enumMapping[$sourceEnumId] ?? null;
                } elseif ($userType === 'directory') {
                    // Свойства типа "справочник"
                    $value = $property['VALUE'];
                } elseif ($propertyType === 'F') {
                    // Свойства типа "Файл"
                    $fileId = $property['VALUE'];
                    $fileArray = \CFile::MakeFileArray($fileId);
                    if ($fileArray) {
                        $newFileId = \CFile::SaveFile($fileArray, "iblock");
                        $value = $newFileId;
                    }
                } elseif ($propertyType === 'E') {
                    // Свойства типа "Привязка к элементу"
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

            return $propertiesData;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::processProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получение сопоставления значений списков между исходным и целевым инфоблоками
     */
    protected function getEnumMapping()
    {
        try {
            // Получаем коды свойств типа "Список" из исходного инфоблока
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

            // Группируем значения списков по коду свойства и инфоблоку
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

            //Logger::log("Сопоставление значений списков завершено.");

            return $mapping;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::getEnumMapping(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получение свойств целевого инфоблока
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

            //Logger::log("Получены свойства целевого инфоблока.");

            return $properties;
        } catch (\Exception $e) {
            Logger::log("Ошибка в SingleTradeOfferImporter::getTargetProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получение нового ID элемента по старому ID для свойств типа "E"
     */
    protected function getNewElementIdByOldId($oldId)
    {
        // Реализуйте логику сопоставления старых ID с новыми ID при копировании связанных элементов
        return $oldId; // Пока возвращаем старый ID
    }
}