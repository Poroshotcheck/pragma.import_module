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
            Logger::log("Ошибка в конструкторе TradeOfferImporter: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Главный метод для копирования элементов из ModuleDataTable
     */
    public function copyElementsFromModuleData($chainRangeSize = 50)
    {
        $currentChain = 1;

        try {
            while (true) {
                $chainRange = ['min' => $currentChain, 'max' => $currentChain + $chainRangeSize - 1];

                // Обработка элементов с текущим CHAIN_TOGEZER
                $elementsProcessed = $this->processElementsWithChain($chainRange);
                if (!$elementsProcessed) {
                    // Если больше нет элементов, выходим из цикла
                    break;
                }

                // Переходим к следующему диапазону
                $currentChain += $chainRangeSize;
            }

            // После обработки всех диапазонов, назначаем разделы родительским продуктам
            $this->assignSectionsToParents();

        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::copyElementsFromModuleData(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
        }
    }

    /**
     * Обработка элементов с указанным CHAIN_TOGEZER
     */
    protected function processElementsWithChain($chainRange)
    {
        try {
            // Получаем все элементы с указанным CHAIN_TOGEZER
            $moduleData = $this->getModuleData($chainRange);

            if (empty($moduleData)) {
                return false;
            }

            // Группируем элементы по CHAIN_TOGEZER
            $groupedElements = $this->groupElementsByChain($moduleData);

            // Проверяем существующие элементы в целевых инфоблоках
            $existingElements = $this->getExistingElements($moduleData);

            // Создаем экземпляр ProductUpdater
            $productUpdater = new ProductUpdater($this->priceGroupId);

            // Подготовка данных для обновления
            $elementIds = array_column($moduleData, 'ELEMENT_ID');
            $elementsDataArray = $this->getElementsData($elementIds);
            $elementsDataByXmlId = [];
            foreach ($elementsDataArray as $element) {
                $elementsDataByXmlId[$element['XML_ID']] = $element;
            }

            // Обновляем существующие
            if (!empty($existingElements['offers'])) {
                $productUpdater->updateExistingElements($existingElements['offers'], $elementsDataByXmlId);
            }

            if (!empty($existingElements['target'])) {
                $productUpdater->updateExistingElements($existingElements['target'], $elementsDataByXmlId);
            }

            // Создаем родительские продукты для групп без существующих родителей
            $this->createParentElements($groupedElements, $existingElements['parents']);

            // Собираем все торговые предложения для создания
            $tradeOffersToCreate = $this->filterExistingOffers($moduleData, $existingElements['offers']);

            if (empty($tradeOffersToCreate)) {
                return true;
            }

            // Копируем торговые предложения и связываем их с родительскими элементами
            $this->copyTradeOffers($tradeOffersToCreate);

            //Logger::log("Завершение выполнения TradeOfferImporter::processElementsWithChain() для диапазона CHAIN_TOGEZER {$chainRange['min']} - {$chainRange['max']}");

        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::processElementsWithChain(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

        return true;
    }

    /**
     * Получение данных из ModuleDataTable
     */
    protected function getModuleData($chainRange)
    {
        try {
            $filter = ['!TARGET_SECTION_ID' => 'a:0:{}'];

            // Фильтрация по диапазону CHAIN_TOGEZER
            $filter['>=CHAIN_TOGEZER'] = $chainRange['min'];
            $filter['<=CHAIN_TOGEZER'] = $chainRange['max'];

            $queryParams = [
                'filter' => $filter,
            ];

            $moduleDataResult = ModuleDataTable::getList($queryParams);

            $data = $moduleDataResult->fetchAll();

            //Logger::log("Получено " . count($data) . " элементов из ModuleDataTable для диапазона CHAIN_TOGEZER {$chainRange['min']} - {$chainRange['max']}.");

            return $data;
        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::getModuleData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Группировка элементов по CHAIN_TOGEZER
     */
    protected function groupElementsByChain($moduleData)
    {
        try {
            $groupedElements = [];
            foreach ($moduleData as $item) {
                $chain = $item['CHAIN_TOGEZER'];
                $groupedElements[$chain][] = $item;
            }
            //Logger::log("Элементы успешно сгруппированы по CHAIN_TOGEZER.");
            return $groupedElements;
        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::groupElementsByChain(): " . $e->getMessage(), "ERROR");
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

            // Arrays to store existing offers, targets, and parents
            $existingOffers = []; // Existing trade offers
            $existingTargets = []; // Existing products in the target products infoblock
            $existingParents = []; // Existing parents for trade offers

            // Get the property ID of CML2_LINK
            $propertyCml2Link = PropertyTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'CODE' => 'CML2_LINK'
                ],
                'select' => ['ID']
            ])->fetch();

            if (!$propertyCml2Link) {
                throw new \Exception("Property CML2_LINK not found in the offers infoblock.");
            }

            $cml2LinkPropertyId = $propertyCml2Link['ID'];

            // Fetch existing offers and their parent IDs
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

                // Map CHAIN_TOGEZER to parent IDs using CML2_LINK_VALUE
                $chain = $this->getChainByXmlId($element['XML_ID'], $moduleData);
                if ($chain && $element['CML2_LINK_VALUE']) {
                    $existingParents[$chain] = $element['CML2_LINK_VALUE'];
                }
            }

            // Fetch existing target products from the target products infoblock
            $targetXmlIds = array_column($moduleData, 'ELEMENT_XML_ID');

            $targetElements = ElementTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetIblockId,
                    'XML_ID' => $targetXmlIds
                ],
                'select' => ['ID', 'XML_ID']
            ])->fetchAll();

            foreach ($targetElements as $element) {
                $existingTargets[$element['XML_ID']] = $element['ID'];
            }

            return [
                'offers' => $existingOffers,
                'target' => $existingTargets,
                'parents' => $existingParents
            ];

        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::getExistingElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Получение CHAIN_TOGEZER по XML_ID из исходных данных
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
     * Создание родительских элементов (продуктов) для групп без существующих родителей
     */
    protected function createParentElements($groupedElements, $existingParents)
    {
        try {
            $parentsToCreate = [];
            foreach ($groupedElements as $chain => $elements) {
                if (!isset($existingParents[$chain])) {
                    // Необходимо создать родительский продукт
                    $parentsToCreate[$chain] = $elements;
                } else {
                    // Родитель существует, сохраняем его
                    $this->parentIdsByChain[$chain] = [
                        'ID' => $existingParents[$chain],
                        'SECTION_IDS' => [] // Обновим разделы позже
                    ];
                }
            }

            if (empty($parentsToCreate)) {
                return;
            }

            $parentElementsData = [];
            foreach ($parentsToCreate as $chain => $elements) {
                // Собираем названия всех торговых предложений в группе
                $elementNames = array_column($elements, 'ELEMENT_NAME');

                // Генерируем название продукта
                $productName = $this->extractCommonProductName($elementNames);

                // Собираем ID разделов из торговых предложений
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
                // Удаляем дубликаты ID разделов
                $sectionIds = array_unique($sectionIds);

                // Формируем данные для нового родительского элемента
                $elementData = [
                    'IBLOCK_ID' => $this->targetIblockId,
                    'NAME' => $productName,
                    'ACTIVE' => 'Y',
                    'CODE' => \CUtil::translit($productName, 'ru'),
                    'IN_SECTIONS' => 'Y',
                    'XML_ID' => 'PARENT_' . $chain,
                    'INDEX_CHAIN' => $chain,
                ];

                $parentElementsData[] = [
                    'DATA' => $elementData,
                    'SECTION_IDS' => $sectionIds,
                    'CHAIN' => $chain,
                ];
            }

            // Пакетное создание родительских элементов
            $this->batchCreateParentElements($parentElementsData);

        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::createParentElements(): " . $e->getMessage(), "ERROR");
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
                $chain = $parentData['CHAIN'];

                $addResult = $targetIblockClass::add($elementData);

                if ($addResult->isSuccess()) {
                    $newId = $addResult->getId();

                    // Сохраняем ID родительского элемента и ID разделов
                    $this->parentIdsByChain[$chain] = [
                        'ID' => $newId,
                        'SECTION_IDS' => $sectionIds,
                    ];

                    // Устанавливаем тип продукта как продукт с торговыми предложениями
                    $productAddResult = \Bitrix\Catalog\ProductTable::add([
                        'ID' => $newId,
                        'TYPE' => \Bitrix\Catalog\ProductTable::TYPE_SKU,
                    ]);

                    if (!$productAddResult->isSuccess()) {
                        $errors = $productAddResult->getErrorMessages();
                        throw new \Exception("Ошибка при добавлении данных продукта для родительского элемента $newId: " . implode(", ", $errors));
                    }

                    //Logger::log("Создан родительский элемент с ID $newId для CHAIN_TOGEZER $chain с названием \"{$elementData['NAME']}\".");
                } else {
                    throw new \Exception("Ошибка при создании родительского элемента для CHAIN_TOGEZER $chain: " . implode(", ", $addResult->getErrorMessages()));
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
            Logger::log("Ошибка в TradeOfferImporter::filterExistingOffers(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Копирование торговых предложений и привязка их к родительским элементам
     */
    protected function copyTradeOffers($tradeOffersToCreate)
    {
        try {
            // Собираем все elementIds торговых предложений
            $elementIds = array_column($tradeOffersToCreate, 'ELEMENT_ID');

            // Получаем данные элементов и свойства в одном запросе
            $elementsData = $this->getElementsData($elementIds);
            $properties = $this->getElementsProperties($elementIds);

            // Подготовка данных для новых торговых предложений
            $preparedData = $this->prepareNewTradeOffersData($elementsData, $properties, $tradeOffersToCreate);

            // Пакетное создание новых торговых предложений
            $newOfferIds = $this->createNewTradeOffers($preparedData);

            if (!empty($newOfferIds)) {
                Logger::log("Создано " . count($newOfferIds) . " новых торговых предложений.");
            } else {
                Logger::log("Ошибка при создании торговых предложений.", "ERROR");
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::copyTradeOffers(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Извлечение общего названия продукта из названий торговых предложений
     */
    protected function extractCommonProductName($elementNames)
    {
        try {
            if (empty($elementNames)) {
                return '';
            }

            // Очистка названий от цифр и специальных символов
            $cleanNames = [];
            foreach ($elementNames as $name) {
                $cleanName = preg_replace('/[0-9\|\-\/]/u', '', $name); // Удаляем цифры и символы | - /
                $cleanName = preg_replace('/\s+/', ' ', $cleanName); // Удаляем лишние пробелы
                $cleanNames[] = trim($cleanName);
            }

            // Разбиваем названия на массивы слов
            $namesAsWords = [];
            foreach ($cleanNames as $name) {
                $words = explode(' ', $name);
                $namesAsWords[] = $words;
            }

            // Находим общие слова во всех названиях
            $commonWords = call_user_func_array('array_intersect', $namesAsWords);

            if (!empty($commonWords)) {
                // Если есть общие слова, объединяем их в строку
                $productName = implode(' ', $commonWords);
            } else {
                // Если общих слов нет, используем очищенное название первого элемента
                $productName = $cleanNames[0];
            }

            // Удаляем лишние пробелы
            $productName = trim($productName);

            return $productName;
        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::extractCommonProductName(): " . $e->getMessage(), "ERROR");
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
                    Logger::log("Разделы успешно назначены родительским продуктам.");
                } else {
                    Logger::log("Ошибка при назначении разделов: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    throw new \Exception("Ошибка при назначении разделов: " . implode(", ", $result->getErrorMessages()));
                }
            }
        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::assignSectionsToParents(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::getElementsData(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::getElementsProperties(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Подготовка данных для создания новых торговых предложений
     */
    protected function prepareNewTradeOffersData($elementsData, $propertiesByElement, $moduleDataItems)
    {
        try {
            $preparedData = [
                'elements' => [],
                'prices' => [],
                'products' => [],
                'properties' => [],
                'sections' => [],
                'warehouseData' => [], // Добавляем warehouseData в preparedData
            ];

            foreach ($moduleDataItems as $item) {
                $elementId = $item['ELEMENT_ID'];
                $element = $elementsData[$elementId];

                $chain = $item['CHAIN_TOGEZER'];
                $parentId = $this->parentIdsByChain[$chain]['ID'];

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
                    'INDEX' => $elementId,
                    'PROPERTY_VALUES' => [
                        'CML2_LINK' => $parentId, // Связь с родительским элементом
                    ],
                ];

                $preparedData['elements'][$elementId] = $newElementData;

                // Подготовка данных цен и продуктов
                $preparedData['prices'][$elementId] = [
                    'PRICE' => $element['PRICE_VALUE'],
                    'CURRENCY' => $element['CURRENCY_VALUE'],
                ];

                $preparedData['products'][$elementId] = [
                    'QUANTITY' => $element['QUANTITY_VALUE'],
                ];

                // Включаем данные складского учета
                $preparedData['warehouseData'][$elementId] = $element['STORE_STOCK'];
                // Обработка свойств
                if (isset($propertiesByElement[$elementId])) {
                    $preparedData['properties'][$elementId] = $this->processProperties($propertiesByElement[$elementId], $elementId);
                } else {
                    $preparedData['properties'][$elementId] = [];
                }

                $preparedData['properties'][$elementId]['SIZE_MODULE_REF'] = $item['SIZE_VALUE_ID'] ?? null;
                $preparedData['properties'][$elementId]['COLOR_MODULE_REF'] = $item['COLOR_VALUE_ID'] ?? null;
                $preparedData['properties'][$elementId]['TYPE_MODULE_REF'] = $item['TYPE_VALUE_ID'] ?? null;

            }

            //Logger::log("Подготовка данных для новых торговых предложений завершена.");

            return $preparedData;
        } catch (\Exception $e) {
            Logger::log("Ошибка в TradeOfferImporter::prepareNewTradeOffersData(): " . $e->getMessage(), "ERROR");
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
        $warehouseData = $preparedData['warehouseData']; // Добавлены warehouseData

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
                    Logger::log("Ошибка при добавлении торгового предложения: " . $ciBlockElement->LAST_ERROR, "ERROR");
                    throw new \Exception("Ошибка при добавлении торгового предложения: " . $ciBlockElement->LAST_ERROR);
                } else {
                    $newOfferIds[$oldId] = $newId;
                    $propertyValuesList[$newId] = $propertiesData[$oldId] ?? [];
                    //Logger::log("Создано торговое предложение с ID $newId (старый ID $oldId).");
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
            Logger::log("Ошибка в TradeOfferImporter::processProperties(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::addProperties(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::addWarehouseStockData(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::addProductData(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::addPrices(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::getEnumMapping(): " . $e->getMessage(), "ERROR");
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
            Logger::log("Ошибка в TradeOfferImporter::getTargetProperties(): " . $e->getMessage(), "ERROR");
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