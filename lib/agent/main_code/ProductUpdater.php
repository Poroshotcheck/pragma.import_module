<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Currency\CurrencyManager;
use Pragma\ImportModule\Logger;

class ProductUpdater
{
    protected $priceGroupId;

    public function __construct($priceGroupId)
    {
        try {
            Loader::includeModule('catalog');
            $this->priceGroupId = $priceGroupId;
            //Logger::log("Инициализация ProductUpdater с группой цен ID: {$priceGroupId}");
        } catch (\Exception $e) {
            //Logger::log("Ошибка в конструкторе ProductUpdater: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public function updateExistingElements($existingElements, $elementsData)
    {
        //Logger::log("Начало выполнения ProductUpdater::updateExistingElements()");

        try {
            // Подготовка данных для обновления
            $pricesToUpdate = [];
            $productsToUpdate = [];
            $warehouseDataToUpdate = [];

            $productWarehouseData = [];
            $productsWithoutStockData = [];

            foreach ($existingElements as $xmlId => $elementId) {
                if (!isset($elementsData[$xmlId])) {
                    continue;
                }

                $element = $elementsData[$xmlId];

                // Подготовка данных для обновления цены
                $priceData = [
                    'PRODUCT_ID' => $elementId,
                    'CATALOG_GROUP_ID' => $this->priceGroupId,
                    'PRICE' => $element['PRICE_VALUE'],
                    'CURRENCY' => $element['CURRENCY_VALUE'],
                ];
                $pricesToUpdate[] = $priceData;

                // Подготовка данных продукта (количество)
                $productData = [
                    'ID' => $elementId,
                    'QUANTITY' => $element['QUANTITY_VALUE'],
                ];
                $productsToUpdate[] = $productData;

                // Подготовка данных о запасах на складе
                if (isset($element['STORE_STOCK']) && !empty($element['STORE_STOCK'])) {
                    $productWarehouseData[$elementId] = [
                        'STOCK_DATA' => [],
                        'RECEIVED_STORE_IDS' => [],
                    ];

                    foreach ($element['STORE_STOCK'] as $stock) {
                        $warehouseDataToUpdate[] = [
                            'PRODUCT_ID' => $elementId,
                            'STORE_ID' => $stock['STORE_ID'],
                            'AMOUNT' => $stock['AMOUNT'] ?? null,
                        ];
                        $productWarehouseData[$elementId]['STOCK_DATA'][] = [
                            'STORE_ID' => $stock['STORE_ID'],
                            'AMOUNT' => $stock['AMOUNT'] ?? null,
                        ];
                        $productWarehouseData[$elementId]['RECEIVED_STORE_IDS'][] = $stock['STORE_ID'];
                    }
                } else {
                    // Если нет данных о запасах для этого продукта, необходимо обнулить все запасы
                    $productsWithoutStockData[] = $elementId;
                }
            }

            // Обновление цен
            $this->updatePrices($pricesToUpdate);

            // Обновление данных продукта (количество)
            $this->updateProductData($productsToUpdate);

            // Обновление данных о запасах на складе
            $this->updateWarehouseStockData($productWarehouseData, $productsWithoutStockData);

            //Logger::log("Существующие элементы успешно обновлены");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ProductUpdater::updateExistingElements(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

    }

    protected function updatePrices($pricesToUpdate)
    {
        //Logger::log("Начало выполнения ProductUpdater::updatePrices()");

        try {
            if (empty($pricesToUpdate)) {
                Logger::log("Нет цен для обновления.", "WARNING");
                return;
            }

            $baseCurrency = CurrencyManager::getBaseCurrency();

            // Собираем все PRODUCT_ID для массового получения существующих цен
            $productIds = array_column($pricesToUpdate, 'PRODUCT_ID');

            // Получаем существующие цены для этих PRODUCT_ID
            $existingPricesResult = PriceTable::getList([
                'filter' => [
                    'PRODUCT_ID' => $productIds,
                    'CATALOG_GROUP_ID' => $this->priceGroupId,
                ],
                'select' => ['ID', 'PRODUCT_ID'],
            ]);

            $existingPrices = [];
            while ($price = $existingPricesResult->fetch()) {
                $existingPrices[$price['PRODUCT_ID']] = $price['ID'];
            }

            // Подготовка массовых обновлений и вставок
            foreach ($pricesToUpdate as $price) {
                // Вычисляем PRICE_SCALE
                if ($price['CURRENCY'] != $baseCurrency) {
                    $price['PRICE_SCALE'] = \CCurrencyRates::ConvertCurrency($price['PRICE'], $price['CURRENCY'], $baseCurrency);
                } else {
                    $price['PRICE_SCALE'] = $price['PRICE'];
                }

                if (isset($existingPrices[$price['PRODUCT_ID']])) {
                    // Обновляем существующую цену
                    $result = PriceTable::update($existingPrices[$price['PRODUCT_ID']], $price);
                    if (!$result->isSuccess()) {
                        Logger::log("Ошибка обновления цены для PRODUCT_ID {$price['PRODUCT_ID']}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    }
                } else {
                    // Добавляем новую цену
                    $result = PriceTable::add($price);
                    if (!$result->isSuccess()) {
                        Logger::log("Ошибка добавления цены для PRODUCT_ID {$price['PRODUCT_ID']}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    }
                }
            }

            //Logger::log("Цены успешно обработаны.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ProductUpdater::updatePrices(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    protected function updateProductData($productsToUpdate)
    {
        //Logger::log("Начало выполнения ProductUpdater::updateProductData()");

        try {
            if (empty($productsToUpdate)) {
                Logger::log("Нет данных о продуктах для обновления.", "WARNING");
                return;
            }

            // Собираем все ID для массового получения существующих продуктов
            $productIds = array_column($productsToUpdate, 'ID');

            // Получаем существующие продукты
            $existingProductsResult = ProductTable::getList([
                'filter' => ['ID' => $productIds],
                'select' => ['ID'],
            ]);

            $existingProductIds = [];
            while ($product = $existingProductsResult->fetch()) {
                $existingProductIds[] = $product['ID'];
            }

            // Подготовка массовых обновлений
            foreach ($productsToUpdate as $product) {
                if (in_array($product['ID'], $existingProductIds)) {
                    // Обновляем существующий продукт
                    $result = ProductTable::update($product['ID'], ['QUANTITY' => $product['QUANTITY']]);
                    if (!$result->isSuccess()) {
                        Logger::log("Ошибка обновления данных продукта для ID {$product['ID']}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    }
                } else {
                    // Добавляем новый продукт
                    $productData = [
                        'ID' => $product['ID'],
                        'QUANTITY' => $product['QUANTITY'],
                        'AVAILABLE' => 'Y',
                        'TYPE' => $this->getProductType($product['ID']),
                    ];
                    $result = ProductTable::add($productData);
                    if (!$result->isSuccess()) {
                        Logger::log("Ошибка добавления данных продукта для ID {$product['ID']}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    }
                }
            }

            //Logger::log("Данные о продуктах успешно обработаны.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ProductUpdater::updateProductData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    protected function updateWarehouseStockData($productWarehouseData = [], $productsWithoutStockData = [])
    {
        //Logger::log("Начало выполнения ProductUpdater::updateWarehouseStockData()");

        try {
            if (empty($productWarehouseData) && empty($productsWithoutStockData)) {
                Logger::log("Нет данных о складских запасах для обновления.", "WARNING");
                return;
            }

            // Обработка продуктов с полученными данными о запасах
            foreach ($productWarehouseData as $productId => $data) {
                $receivedStoreIds = $data['RECEIVED_STORE_IDS'];

                // Получаем существующие записи о запасах для этого продукта
                $existingStockResult = StoreProductTable::getList([
                    'filter' => ['=PRODUCT_ID' => $productId],
                    'select' => ['ID', 'STORE_ID'],
                ]);

                $existingStocks = [];
                while ($stock = $existingStockResult->fetch()) {
                    $existingStocks[$stock['STORE_ID']] = $stock['ID'];
                }

                // Обновляем или добавляем записи о запасах для полученных складов
                foreach ($data['STOCK_DATA'] as $stockData) {
                    $storeId = $stockData['STORE_ID'];
                    $amount = $stockData['AMOUNT'];
                    if (isset($existingStocks[$storeId])) {
                        // Обновляем существующий запас
                        $result = StoreProductTable::update($existingStocks[$storeId], ['AMOUNT' => $amount]);
                        if (!$result->isSuccess()) {
                            Logger::log("Ошибка обновления данных склада для PRODUCT_ID {$productId}, STORE_ID {$storeId}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                        }
                        // Удаляем из existingStocks для последующей обработки не полученных складов
                        unset($existingStocks[$storeId]);
                    } else {
                        // Добавляем новую запись о запасах
                        $newStockData = [
                            'PRODUCT_ID' => $productId,
                            'STORE_ID' => $storeId,
                            'AMOUNT' => $amount,
                        ];
                        $result = StoreProductTable::add($newStockData);
                        if (!$result->isSuccess()) {
                            Logger::log("Ошибка добавления данных склада для PRODUCT_ID {$productId}, STORE_ID {$storeId}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                        }
                    }
                }

                // Для существующих записей о запасах, не входящих в receivedStoreIds, устанавливаем AMOUNT в ноль
                foreach ($existingStocks as $storeId => $stockId) {
                    // Это склады, которые имеют существующие запасы, но не были в полученных данных
                    $result = StoreProductTable::update($stockId, ['AMOUNT' => 0]);
                    if (!$result->isSuccess()) {
                        Logger::log("Ошибка обнуления запасов на складе для PRODUCT_ID {$productId}, STORE_ID {$storeId}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    }
                }
            }

            // Обработка продуктов без каких-либо данных о запасах (обнуление всех количеств)
            if (!empty($productsWithoutStockData)) {
                // Получаем существующие записи о запасах для этих продуктов
                $existingStockResult = StoreProductTable::getList([
                    'filter' => ['=PRODUCT_ID' => $productsWithoutStockData],
                    'select' => ['ID', 'PRODUCT_ID', 'STORE_ID'],
                ]);

                while ($stock = $existingStockResult->fetch()) {
                    $result = StoreProductTable::update($stock['ID'], ['AMOUNT' => 0]);
                    if (!$result->isSuccess()) {
                        Logger::log("Ошибка обнуления запасов на складе для PRODUCT_ID {$stock['PRODUCT_ID']}, STORE_ID {$stock['STORE_ID']}: " . implode(", ", $result->getErrorMessages()), "ERROR");
                    }
                }
            }

            //Logger::log("Данные о складских запасах успешно обработаны.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ProductUpdater::updateWarehouseStockData(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    protected function getProductType($productId)
    {
        // Определяем тип продукта на основе того, является ли он предложением или простым продуктом
        // Для простоты возвращаем TYPE_PRODUCT
        return \Bitrix\Catalog\ProductTable::TYPE_PRODUCT;
    }
}