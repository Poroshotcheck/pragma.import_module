<?php
// Подключение пролога Bitrix
require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

// Подключение необходимого модуля и таблицы ModuleDataTable
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/ModuleDataTable.php");

// Используемые пространства имен
use Bitrix\Main\Loader;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Config\Option;
use Pragma\ImportModule\ModuleDataTable;
use Bitrix\Main\Application;
use Bitrix\Main\Entity\Query;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\SectionElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Currency\CurrencyManager;
use Bitrix\Currency\CurrencyTable;

// Подключение модулей iblock и catalog
Loader::includeModule('iblock');
Loader::includeModule('catalog');

class IblockElementCopier
{
    protected $moduleId; // Идентификатор модуля
    protected $sourceIblockId; // ID исходного инфоблока
    protected $targetIblockId; // ID целевого инфоблока товаров
    protected $targetOffersIblockId; // ID целевого инфоблока торговых предложений
    protected $enumMapping = []; // Сопоставление значений списков
    protected $targetSectionIdsByElement = []; // Привязки элементов к разделам
    protected $parentIdsByChain = []; // Сопоставление CHAIN_TOGEZER с ID родительских элементов

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->sourceIblockId = Option::get($moduleId, 'IBLOCK_ID_IMPORT'); // Получаем ID исходного инфоблока из настроек модуля
        $this->targetIblockId = Option::get($moduleId, 'IBLOCK_ID_CATALOG'); // Получаем ID целевого инфоблока товаров из настроек модуля
        // Получаем ID инфоблока торговых предложений, связанного с целевым инфоблоком товаров
        $this->targetOffersIblockId = \CCatalogSKU::GetInfoByProductIBlock($this->targetIblockId)['IBLOCK_ID'];
        $this->enumMapping = $this->getEnumMapping(); // Получаем сопоставление значений списков между инфоблоками
    }

    /**
     * Основной метод для копирования элементов из ModuleDataTable
     */
    public function copyElementsFromModuleData($batchSizeWithoutChain = 1000, $chainRangeSize = 50)
    {
        $startTime = microtime(true);
        // Обработка элементов без CHAIN_TOGEZER
        $this->processElementsWithoutChain($batchSizeWithoutChain);

        $currentChain = 1;

        while (true) {
            $chainRange = ['min' => $currentChain, 'max' => $currentChain + $chainRangeSize - 1];

            echo "Начало обработки элементов с CHAIN_TOGEZER в диапазоне {$chainRange['min']} - {$chainRange['max']}.<br/>";

            // Обработка элементов с CHAIN_TOGEZER в текущем диапазоне
            $elementsProcessed = $this->processElementsWithChain($chainRange);

            echo "Обработка элементов с CHAIN_TOGEZER в диапазоне {$chainRange['min']} - {$chainRange['max']} завершена.<br/>";

            if (!$elementsProcessed) {
                // Если в текущем диапазоне нет элементов, выходим из цикла
                echo "Дальнейших элементов с CHAIN_TOGEZER нет. Обработка завершена.<br/>";
                break;
            }

            // Переходим к следующему диапазону
            $currentChain += $chainRangeSize;
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        echo "Скрипт завершен. Время выполнения: " . round($executionTime, 2) . " секунд.<br/>";
    }

    /**
     * Обработка элементов без CHAIN_TOGEZER
     */
    protected function processElementsWithoutChain($batchSize)
    {
        $offset = 0;

        while (true) {
            // Получаем пачку элементов без CHAIN_TOGEZER с учетом смещения
            $moduleData = $this->getModuleData($batchSize, false, [], $offset);

            if (empty($moduleData)) {
                echo "Обработка элементов без CHAIN_TOGEZER завершена.<br/>";
                flush();
                break;
            }

            // Проверяем существующие элементы в целевых инфоблоках
            $existingElements = $this->getExistingElements($moduleData);

            // Фильтруем элементы, которые уже существуют
            $newModuleData = $this->filterExistingElements($moduleData, $existingElements);

            if (empty($newModuleData)) {
                echo "Все элементы в этой пачке уже существуют в целевых инфоблоках.<br/>";
                flush();
                $offset += $batchSize;
                continue;
            }

            // Получаем IDs элементов для копирования
            $elementIdsToCopy = array_column($newModuleData, 'ELEMENT_ID');

            // Копируем элементы в целевой инфоблок товаров
            $newElementIds = $this->copyIblockElements($elementIdsToCopy, $newModuleData);

            if (!empty($newElementIds)) {
                echo "Создано " . count($newElementIds) . " новых элементов без CHAIN_TOGEZER.<br/>";
                flush();
            } else {
                echo "Ошибка при создании элементов без CHAIN_TOGEZER.<br/>";
                flush();
            }

            $offset += $batchSize;
        }
    }

    /**
     * Обработка элементов с CHAIN_TOGEZER
     */
    protected function processElementsWithChain($chainRange)
    {
        // Получаем все элементы с CHAIN_TOGEZER в заданном диапазоне
        $moduleData = $this->getModuleData(0, true, $chainRange);

        if (empty($moduleData)) {
            echo "Нет элементов с CHAIN_TOGEZER в диапазоне {$chainRange['min']} - {$chainRange['max']}.<br/>";
            flush();
            // Нет данных для обработки в текущем диапазоне
            return false;
        }

        // Проверяем существующие элементы в целевых инфоблоках
        $existingElements = $this->getExistingElements($moduleData, true);

        // Фильтруем элементы, которые уже существуют
        $newModuleData = $this->filterExistingElements($moduleData, $existingElements);

        if (empty($newModuleData)) {
            echo "Все элементы в этой пачке уже существуют.<br/>";
            flush();
            // Возвращаем true, чтобы продолжить обработку следующих диапазонов
            return true;
        }

        // Группируем элементы по CHAIN_TOGEZER
        $groupedElements = $this->groupElementsByChain($newModuleData);

        // Обработка каждой группы элементов с одинаковым CHAIN_TOGEZER
        foreach ($groupedElements as $chain => $elements) {
            // Проверяем, существует ли родительский элемент для текущего CHAIN_TOGEZER
            $parentId = $this->getParentIdForChain($chain, $existingElements['parents']);

            if (!$parentId) {
                // Если родитель не найден, создаем новый родительский элемент
                $parentId = $this->createParentElement($chain, $elements);
            }

            // Копируем торговые предложения и связываем их с родительским элементом
            $this->copyTradeOffers($elements, $parentId);
        }

        // Возвращаем true, так как в текущем диапазоне были обработаны элементы
        return true;
    }

    /**
     * Получение максимального значения CHAIN_TOGEZER из ModuleDataTable
     */
    protected function getMaxChainTogezers()
    {
        $result = ModuleDataTable::getList([
            'select' => ['MAX_CHAIN'],
            'runtime' => [
                new \Bitrix\Main\Entity\ExpressionField('MAX_CHAIN', 'MAX(CHAIN_TOGEZER)')
            ]
        ])->fetch();

        $maxChain = $result['MAX_CHAIN'];
        echo "Максимальный CHAIN_TOGEZER из базы данных: $maxChain<br/>";

        return $maxChain;
    }

    /**
     * Получение данных из ModuleDataTable
     */
    protected function getModuleData($batchSize = 0, $withChain = false, $chainRange = [], $offset = 0)
    {
        $filter = ['!TARGET_SECTION_ID' => 'a:0:{}'];

        if ($withChain) {
            // Фильтруем по диапазону CHAIN_TOGEZER
            $filter['>=CHAIN_TOGEZER'] = $chainRange['min'];
            $filter['<=CHAIN_TOGEZER'] = $chainRange['max'];
        } else {
            // Элементы без CHAIN_TOGEZER
            $filter[] = [
                'LOGIC' => 'OR',
                ['CHAIN_TOGEZER' => false],
                ['CHAIN_TOGEZER' => 0],
                ['CHAIN_TOGEZER' => null],
            ];
        }

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
     * Проверка существующих элементов в целевых инфоблоках
     */
    protected function getExistingElements($moduleData, $withParents = false)
    {
        $elementXmlIds = array_column($moduleData, 'ELEMENT_XML_ID');

        // Массив для хранения существующих элементов
        $existingElementsInTarget = [];
        $existingElementsInOffers = [];
        $existingParents = [];

        // Проверяем наличие элементов в целевом инфоблоке товаров
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

        // Проверяем наличие элементов в инфоблоке торговых предложений
        if ($this->targetOffersIblockId) {
            // Получаем ID свойства CML2_LINK
            $propertyCml2Link = PropertyTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'CODE' => 'CML2_LINK'
                ],
                'select' => ['ID']
            ])->fetch();

            $cml2LinkPropertyId = $propertyCml2Link['ID'];

            // Если требуется получить родителей, присоединяем свойство CML2_LINK
            $offerElements = ElementTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'XML_ID' => $elementXmlIds
                ],
                'select' => ['ID', 'XML_ID', 'CML2_LINK_VALUE'],
                'runtime' => [
                    // Присоединяем таблицу свойств элементов для свойства CML2_LINK
                    new ReferenceField(
                        'CML2_LINK_PROP',
                        ElementPropertyTable::getEntity(),
                        [
                            '=this.ID' => 'ref.IBLOCK_ELEMENT_ID',
                            '=ref.IBLOCK_PROPERTY_ID' => new \Bitrix\Main\DB\SqlExpression('?', $cml2LinkPropertyId)
                        ],
                        ['join_type' => 'left']
                    ),
                    // Добавляем поле для значения свойства
                    new ExpressionField(
                        'CML2_LINK_VALUE',
                        '%s',
                        ['CML2_LINK_PROP.VALUE']
                    ),
                ]
            ])->fetchAll();

            foreach ($offerElements as $element) {
                $existingElementsInOffers[$element['XML_ID']] = $element['ID'];

                if ($withParents && $element['CML2_LINK_VALUE']) {
                    // Получаем CHAIN_TOGEZER по XML_ID
                    $chain = $this->getChainByXmlId($element['XML_ID'], $moduleData);
                    if ($chain) {
                        $existingParents[$chain] = $element['CML2_LINK_VALUE'];
                    }
                }
            }
        }

        return [
            'target' => $existingElementsInTarget,
            'offers' => $existingElementsInOffers,
            'parents' => $existingParents
        ];
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
     * Фильтрация существующих элементов из текущей пачки
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
     * Группировка элементов по CHAIN_TOGEZER
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
     * Получение ID родительского элемента для заданного CHAIN_TOGEZER
     */
    protected function getParentIdForChain($chain, $parentIds)
    {
        return $parentIds[$chain] ?? null;
    }

    /**
     * Создание родительского элемента (товара)
     */
    protected function createParentElement($chain, $elements)
    {
        // Собираем названия всех торговых предложений в группе
        $elementNames = array_column($elements, 'ELEMENT_NAME');

        // Получаем общее название товара
        $productName = $this->extractCommonProductName($elementNames);

        // Собираем IDs разделов из торговых предложений
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
        // Убираем дублирующиеся IDs разделов
        $sectionIds = array_unique($sectionIds);

        // Формируем данные для нового родительского элемента (товара с торговыми предложениями)
        $elementData = [
            'IBLOCK_ID' => $this->targetIblockId,
            'NAME' => $productName,
            'ACTIVE' => 'Y',
            // Не указываем 'IBLOCK_SECTION' здесь
        ];

        // Создаем элемент в целевом инфоблоке товаров
        $targetIblock = \Bitrix\Iblock\Iblock::wakeUp($this->targetIblockId);
        $targetIblockClass = $targetIblock->getEntityDataClass();

        $addResult = $targetIblockClass::add($elementData);

        if ($addResult->isSuccess()) {
            $newId = $addResult->getId();

            // Устанавливаем привязку к разделам после создания элемента
            if (!empty($sectionIds)) {
                $el = new \CIBlockElement;
                $setSectionsResult = $el->SetElementSection($newId, $sectionIds);

                if ($setSectionsResult) {
                    echo "Привязки к разделам " . implode(', ', $sectionIds) . " для родительского элемента $newId установлены.<br/>";
                    flush();
                } else {
                    global $APPLICATION;
                    if ($exception = $APPLICATION->GetException()) {
                        echo "Ошибка при установке привязок к разделам для родительского элемента $newId: " . $exception->GetString() . "<br/>";
                    } else {
                        echo "Неизвестная ошибка при установке привязок к разделам для родительского элемента $newId.<br/>";
                    }
                    flush();
                }
            }

            // Устанавливаем тип товара как товар с торговыми предложениями
            $productAddResult = \Bitrix\Catalog\ProductTable::add([
                'ID' => $newId,
                'TYPE' => \Bitrix\Catalog\ProductTable::TYPE_SKU,
            ]);

            if (!$productAddResult->isSuccess()) {
                $errors = $productAddResult->getErrorMessages();
                echo "Ошибка при добавлении данных о продукте для родительского элемента $newId: " . implode(", ", $errors) . "<br/>";
                flush();
            } else {
                echo "Данные о продукте для родительского элемента $newId успешно добавлены.<br/>";
                flush();
            }

            // Сохраняем ID родительского элемента
            $this->parentIdsByChain[$chain] = $newId;
            echo "Создан родительский элемент с ID $newId для CHAIN_TOGEZER $chain с названием \"$productName\".<br/>";
            flush();
            return $newId;
        } else {
            echo "Ошибка при создании родительского элемента для CHAIN_TOGEZER $chain: " . implode(", ", $addResult->getErrorMessages()) . "<br/>";
            flush();
            return null;
        }
    }

    /**
     * Извлечение общего названия товара из названий торговых предложений
     */
    protected function extractCommonProductName($elementNames)
    {
        if (empty($elementNames)) {
            return '';
        }

        // Очищаем названия от цифр и специальных символов
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
            // Если нет общих слов, используем очищенное название первого элемента
            $productName = $cleanNames[0];
        }

        // Удаляем лишние пробелы
        $productName = trim($productName);

        return $productName;
    }

    /**
     * Копирование торговых предложений и привязка к родительскому элементу
     */
    protected function copyTradeOffers($elements, $parentId)
    {
        foreach ($elements as $item) {
            $elementId = $item['ELEMENT_ID'];

            // Получаем данные элемента и свойства
            $elementsData = $this->getElementsData([$elementId]);
            $properties = $this->getElementsProperties([$elementId]);

            // Подготовка данных для торгового предложения
            $preparedData = $this->prepareNewTradeOfferData($elementsData, $properties, $item, $parentId);

            // Создаем торговое предложение
            $newOfferId = $this->createNewTradeOffer($preparedData);

            if ($newOfferId) {
                echo "Создано торговое предложение с ID $newOfferId, связанное с родителем $parentId.<br/>";
                flush();
            } else {
                echo "Ошибка при создании торгового предложения для элемента $elementId.<br/>";
                flush();
            }
        }
    }

    /**
     * Получение данных элементов из исходного инфоблока
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

        $elementsResult = $query->exec();
        $elements = $elementsResult->fetchAll();
        echo "Получено " . count($elements) . " элементов из исходного инфоблока.<br/>";
        flush();

        return $elements;
    }

    /**
     * Получение свойств элементов из исходного инфоблока
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
        echo "Получены свойства элементов.<br/>";
        flush();

        return $properties;
    }

    /**
     * Подготовка данных для создания торгового предложения
     */
    protected function prepareNewTradeOfferData($elements, $properties, $moduleDataItem, $parentId)
    {
        $element = reset($elements);
        $elementId = $element['ID'];

        // Формируем данные для нового торгового предложения
        $newElementData = [
            'IBLOCK_ID' => $this->targetOffersIblockId,
            'NAME' => $element['NAME'],
            'XML_ID' => $element['XML_ID'],
            'CODE' => $element['CODE'] ?: \CUtil::translit($element['NAME'], 'ru'),
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'CML2_LINK' => $parentId, // Связь с родительским элементом
            ],
        ];

        // Обработка свойств
        $propertiesData = $this->processProperties($properties, $elementId);

        // Объединяем свойства
        $newElementData['PROPERTY_VALUES'] = array_merge(
            $newElementData['PROPERTY_VALUES'],
            $propertiesData
        );

        // Подготовка данных о цене и продукте
        $price = $element['PRICE_VALUE'];
        $currency = $element['CURRENCY_VALUE'];

        return [
            'element' => $newElementData,
            'price' => [
                'PRICE' => $price,
                'CURRENCY' => $currency,
            ],
            'product' => [
                'QUANTITY' => $element['QUANTITY_VALUE']
            ]
        ];
    }

    /**
     * Обработка свойств элемента для торгового предложения
     */
    protected function processProperties($properties, $elementId)
    {
        $propertiesData = [];
        foreach ($properties as $property) {
            if ($property['IBLOCK_ELEMENT_ID'] != $elementId) {
                continue;
            }
            $propertyCode = $property['CODE'];
            $isMultiple = $property['MULTIPLE'] === 'Y';
            $propertyType = $property['PROPERTY_TYPE'];
            $userType = $property['USER_TYPE'];

            // Получаем ID свойства в целевом инфоблоке торговых предложений
            $targetProperty = PropertyTable::getList([
                'filter' => [
                    'IBLOCK_ID' => $this->targetOffersIblockId,
                    'CODE' => $propertyCode,
                ],
                'select' => ['ID', 'PROPERTY_TYPE', 'USER_TYPE'],
            ])->fetch();

            if (!$targetProperty) {
                continue;
            }

            $value = null;
            if ($propertyType === 'L') {
                // Сопоставляем значение списка
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
                    $value = \CFile::SaveFile($fileArray, "iblock");
                }
            } elseif ($propertyType === 'E') {
                // Свойства типа "привязка к элементу"
                $value = $this->getNewElementIdByOldId($property['VALUE']);
            } elseif ($propertyType === 'S' || $propertyType === 'N') {
                $value = $property['VALUE'] ?: $property['VALUE_NUM'];
            } else {
                // Обработка других типов свойств
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
     * Создание нового торгового предложения
     */
    protected function createNewTradeOffer($preparedData)
    {
        $elementData = $preparedData['element'];
        $priceData = $preparedData['price'];
        $productData = $preparedData['product'];

        // Убираем PROPERTY_VALUES из данных для создания элемента
        $propertyValues = $elementData['PROPERTY_VALUES'];
        unset($elementData['PROPERTY_VALUES']);

        // Создаем элемент в инфоблоке торговых предложений
        $targetIblock = \Bitrix\Iblock\Iblock::wakeUp($this->targetOffersIblockId);
        $targetIblockClass = $targetIblock->getEntityDataClass();

        $addResult = $targetIblockClass::add($elementData);

        if ($addResult->isSuccess()) {
            $newId = $addResult->getId();

            // Устанавливаем свойства после создания элемента
            \CIBlockElement::SetPropertyValuesEx($newId, $this->targetOffersIblockId, $propertyValues);

            // Добавляем данные о продукте
            $productData['ID'] = $newId;
            $productData['TYPE'] = \Bitrix\Catalog\ProductTable::TYPE_OFFER; // Устанавливаем тип продукта как торговое предложение
            $productAddResult = \Bitrix\Catalog\ProductTable::add($productData);

            if (!$productAddResult->isSuccess()) {
                $errors = $productAddResult->getErrorMessages();
                echo "Ошибка при добавлении данных о продукте для торгового предложения $newId: " . implode(", ", $errors) . "<br/>";
                flush();
            } else {
                echo "Данные о продукте для торгового предложения $newId успешно добавлены.<br/>";
                flush();
            }

            // Добавляем цену
            $priceData['PRODUCT_ID'] = $newId;
            $priceData['CATALOG_GROUP_ID'] = 1; // Проверьте, что эта ценовая группа существует

            // Конвертируем цену в базовую валюту для заполнения PRICE_SCALE
            $baseCurrency = CurrencyManager::getBaseCurrency();

            if ($priceData['CURRENCY'] != $baseCurrency) {
                $priceData['PRICE_SCALE'] = \CCurrencyRates::ConvertCurrency($priceData['PRICE'], $priceData['CURRENCY'], $baseCurrency);
            } else {
                $priceData['PRICE_SCALE'] = $priceData['PRICE'];
            }

            $addPriceResult = \Bitrix\Catalog\PriceTable::add($priceData);

            if (!$addPriceResult->isSuccess()) {
                $errors = $addPriceResult->getErrorMessages();
                echo "Ошибка при добавлении цены для торгового предложения $newId: " . implode(", ", $errors) . "<br/>";
                flush();
            } else {
                echo "Цена для торгового предложения $newId успешно добавлена.<br/>";
                flush();
            }

            return $newId;
        } else {
            echo "Ошибка при создании торгового предложения: " . implode(", ", $addResult->getErrorMessages()) . "<br/>";
            flush();
            return null;
        }
    }

    /**
     * Сопоставление значений списков между инфоблоками
     */
    protected function getEnumMapping()
    {
        // Получаем значения списков и коды свойств из исходного инфоблока
        $sourceEnums = PropertyEnumerationTable::getList([
            'filter' => ['PROPERTY.IBLOCK_ID' => $this->sourceIblockId],
            'select' => ['ID', 'PROPERTY_ID', 'VALUE', 'XML_ID', 'PROPERTY_CODE' => 'PROPERTY.CODE'],
            'runtime' => [
                new ReferenceField(
                    'PROPERTY',
                    PropertyTable::getEntity(),
                    ['=this.PROPERTY_ID' => 'ref.ID'],
                    ['join_type' => 'INNER']
                )
            ]
        ])->fetchAll();

        // Получаем уникальные коды свойств из исходного инфоблока
        $sourcePropertyCodes = array_unique(array_column($sourceEnums, 'PROPERTY_CODE'));

        // Получаем значения списков из целевого инфоблока, фильтруя по кодам свойств из исходного
        $targetEnums = PropertyEnumerationTable::getList([
            'filter' => [
                'PROPERTY.IBLOCK_ID' => [$this->targetIblockId, $this->targetOffersIblockId],
                'PROPERTY.CODE' => $sourcePropertyCodes
            ],
            'select' => ['ID', 'PROPERTY_ID', 'VALUE', 'XML_ID', 'PROPERTY_CODE' => 'PROPERTY.CODE'],
            'runtime' => [
                new ReferenceField(
                    'PROPERTY',
                    PropertyTable::getEntity(),
                    ['=this.PROPERTY_ID' => 'ref.ID'],
                    ['join_type' => 'INNER']
                )
            ]
        ])->fetchAll();

        // Группируем значения по коду свойства
        $sourceEnumsByCode = $this->groupEnumsByPropertyCode($sourceEnums);
        $targetEnumsByCode = $this->groupEnumsByPropertyCode($targetEnums);

        $mapping = [];

        // Сопоставляем значения
        foreach ($sourceEnumsByCode as $propertyCode => $sourcePropertyEnums) {
            if (isset($targetEnumsByCode[$propertyCode])) {
                $targetPropertyEnums = $targetEnumsByCode[$propertyCode];
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

        echo "Сопоставление значений списков выполнено.<br>";
        flush();

        return $mapping;
    }

    /**
     * Группировка значений списков по коду свойства
     */
    protected function groupEnumsByPropertyCode($enums)
    {
        $groupedEnums = [];
        foreach ($enums as $enum) {
            $groupedEnums[$enum['PROPERTY_CODE']][] = $enum;
        }
        return $groupedEnums;
    }

    /**
     * Получение новых ID элементов по старым ID для свойств типа "E"
     */
    protected function getNewElementIdByOldId($oldId)
    {
        // Реализуйте логику соответствия старых и новых ID, если копируете связанные элементы
        return $oldId; // Пока возвращаем старый ID
    }

    /**
     * Копирование элементов в целевой инфоблок товаров
     */
    protected function copyIblockElements($elementIds, $newModuleData)
    {
        echo "Копируем элементы: " . implode(', ', $elementIds) . "<br/>";
        flush();

        $elements = $this->getElementsData($elementIds);
        $properties = $this->getElementsProperties($elementIds);

        $preparedData = $this->prepareNewElementsData($elements, $properties, $newModuleData);

        $newElementIds = $this->createNewElements($preparedData['elements'], $preparedData['sections'], $preparedData['properties']);

        if (empty($newElementIds)) {
            echo "Ошибка при создании новых элементов.<br/>";
            flush();
            return [];
        }

        $this->addPrices($newElementIds, $preparedData['prices']);
        $this->addProductData($newElementIds, $preparedData['products']);

        return $newElementIds;
    }

    /**
     * Подготовка данных для новых элементов
     */
    protected function prepareNewElementsData($elements, $properties, $newModuleData)
    {
        $newElements = [];
        $priceData = [];
        $productData = [];
        $sectionData = [];
        $propertiesData = [];

        $propertiesByElement = [];
        foreach ($properties as $property) {
            $elementId = $property['IBLOCK_ELEMENT_ID'];
            $propertiesByElement[$elementId][] = $property;
        }

        foreach ($elements as $element) {
            $elementId = $element['ID'];

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
            ];

            $priceData[$elementId] = [
                'PRODUCT_ID' => $elementId,
                'CATALOG_GROUP_ID' => 1,
                'PRICE' => $element['PRICE_VALUE'],
                'CURRENCY' => $element['CURRENCY_VALUE'],
            ];

            $productData[$elementId] = [
                'QUANTITY' => $element['QUANTITY_VALUE'],
            ];

            $sectionData[$elementId] = $this->getTargetSectionIdsForElement($elementId, $newModuleData);

            if (isset($propertiesByElement[$elementId])) {
                foreach ($propertiesByElement[$elementId] as $property) {
                    $propertyCode = $property['CODE'];
                    $isMultiple = $property['MULTIPLE'] === 'Y';
                    $propertyType = $property['PROPERTY_TYPE'];
                    $userType = $property['USER_TYPE'];

                    // Получаем ID свойства в целевом инфоблоке
                    $targetProperty = PropertyTable::getList([
                        'filter' => [
                            'IBLOCK_ID' => $this->targetIblockId,
                            'CODE' => $propertyCode,
                        ],
                        'select' => ['ID', 'PROPERTY_TYPE', 'USER_TYPE'],
                    ])->fetch();

                    if (!$targetProperty) {
                        continue;
                    }

                    $value = null;
                    if ($propertyType === 'L') {
                        // Сопоставляем значение списка
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
                            $value = \CFile::SaveFile($fileArray, "iblock");
                        }
                    } elseif ($propertyType === 'E') {
                        // Свойства типа "привязка к элементу"
                        $value = $this->getNewElementIdByOldId($property['VALUE']);
                    } elseif ($propertyType === 'S' || $propertyType === 'N') {
                        $value = $property['VALUE'] ?: $property['VALUE_NUM'];
                    } else {
                        // Обработка других типов свойств
                        $value = $property['VALUE'];
                    }

                    if ($value !== null) {
                        if ($isMultiple) {
                            $propertiesData[$elementId][$targetProperty['ID']][] = [
                                'VALUE' => $value,
                                'DESCRIPTION' => $property['DESCRIPTION'],
                            ];
                        } else {
                            $propertiesData[$elementId][$targetProperty['ID']] = [
                                'VALUE' => $value,
                                'DESCRIPTION' => $property['DESCRIPTION'],
                            ];
                        }
                    }
                }
            }
        }

        echo "Данные для новых элементов подготовлены.<br/>";
        flush();

        return [
            'elements' => $newElements,
            'prices' => $priceData,
            'products' => $productData,
            'sections' => $sectionData,
            'properties' => $propertiesData,
        ];
    }

    /**
     * Создание новых элементов в целевом инфоблоке товаров
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

                    echo "Создан элемент с ID $newId (старый ID $oldId).<br/>";
                    flush();
                } else {
                    echo "Ошибка при добавлении элемента с индексом $index: " . implode(", ", $addResult->getErrorMessages()) . "<br/>";
                    flush();
                }
            }

            // Обновляем свойства с новыми ID элементов
            $this->addProperties($newElementIds, $propertiesData);

            // Добавляем привязки к разделам
            $this->addSectionsToElements($newElementIds, $sectionData);

            $connection->commitTransaction();
            echo "Успешно добавлено " . count($newElementIds) . " новых элементов.<br/>";
            flush();

            return $newElementIds;
        } catch (\Exception $e) {
            $connection->rollbackTransaction();
            echo "Ошибка при создании элементов: " . $e->getMessage() . "<br/>";
            flush();
            return [];
        }
    }

    protected function addSectionsToElements($newElementIds, $sectionData)
    {
        foreach ($newElementIds as $oldId => $newId) {
            $targetSectionIds = $sectionData[$oldId] ?? [];
            if (!is_array($targetSectionIds)) {
                $targetSectionIds = [$targetSectionIds];
            }

            // Используем SetElementSection для установки привязок
            \CIBlockElement::SetElementSection($newId, $targetSectionIds);

            echo "Привязки к разделам " . implode(', ', $targetSectionIds) . " для элемента $newId установлены.<br>";
        }
    }

    protected function addProperties($newElementIds, $propertiesData)
    {
        foreach ($newElementIds as $oldId => $newId) {
            if (isset($propertiesData[$oldId])) {
                foreach ($propertiesData[$oldId] as $propertyId => $values) {
                    if (is_array($values) && isset($values[0])) {
                        // Множественное свойство
                        foreach ($values as $value) {
                            \CIBlockElement::SetPropertyValuesEx($newId, $this->targetIblockId, [$propertyId => $value]);
                        }
                    } else {
                        // Одиночное свойство
                        \CIBlockElement::SetPropertyValuesEx($newId, $this->targetIblockId, [$propertyId => $values]);
                    }
                }
            }
        }

        echo "Свойства успешно добавлены к новым элементам.<br/>";
        flush();
    }

    /**
     * Добавление цен к новым элементам
     */
    protected function addPrices($newElementIds, $priceData)
    {
        foreach ($newElementIds as $oldId => $newId) {
            if (!empty($priceData[$oldId]['PRICE'])) {
                $priceEntry = $priceData[$oldId];
                $priceEntry['PRODUCT_ID'] = $newId;
                $priceEntry['CATALOG_GROUP_ID'] = 1;

                // Конвертируем цену в базовую валюту для заполнения PRICE_SCALE
                $baseCurrency = CurrencyManager::getBaseCurrency();

                if ($priceEntry['CURRENCY'] != $baseCurrency) {
                    $priceEntry['PRICE_SCALE'] = \CCurrencyRates::ConvertCurrency($priceEntry['PRICE'], $priceEntry['CURRENCY'], $baseCurrency);
                } else {
                    $priceEntry['PRICE_SCALE'] = $priceEntry['PRICE'];
                }

                $priceResult = \Bitrix\Catalog\PriceTable::add($priceEntry);

                if ($priceResult->isSuccess()) {
                    echo "Цена добавлена к элементу $newId.<br/>";
                } else {
                    echo "Ошибка при добавлении цены к элементу $newId: " . implode(", ", $priceResult->getErrorMessages()) . "<br/>";
                }
            }
        }
    }

    /**
     * Добавление данных о продуктах к новым элементам
     */
    protected function addProductData($newElementIds, $productData)
    {
        foreach ($newElementIds as $oldId => $newId) {
            $productEntry = $productData[$oldId];
            $productEntry['ID'] = $newId;

            $productResult = \Bitrix\Catalog\ProductTable::add($productEntry);

            if ($productResult->isSuccess()) {
                echo "Данные о продукте добавлены к элементу $newId.<br/>";
            } else {
                echo "Ошибка при добавлении данных о продукте к элементу $newId: " . implode(", ", $productResult->getErrorMessages()) . "<br/>";
            }
        }
    }

    /**
     * Получение ID целевых разделов для элемента
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
}

// Использование класса
$moduleId = 'pragma.importmodule';
$iblockElementCopier = new IblockElementCopier($moduleId);
$iblockElementCopier->copyElementsFromModuleData(1000, 30); // Указываем размер батчей

// Подключение эпилога Bitrix
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');