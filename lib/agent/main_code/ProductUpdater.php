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
        Loader::includeModule('catalog');

        $this->priceGroupId = $priceGroupId;
    }

    public function updateExistingElements($existingElements, $elementsData)
    {
        // Prepare data for updates
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

            // Prepare price update data
            $priceData = [
                'PRODUCT_ID' => $elementId,
                'CATALOG_GROUP_ID' => $this->priceGroupId,
                'PRICE' => $element['PRICE_VALUE'],
                'CURRENCY' => $element['CURRENCY_VALUE'],
            ];
            $pricesToUpdate[] = $priceData;

            // Prepare product update data (quantity)
            $productData = [
                'ID' => $elementId,
                'QUANTITY' => $element['QUANTITY_VALUE'],
            ];
            $productsToUpdate[] = $productData;

            // Prepare warehouse stock data
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
                // If there is no stock data at all for this product, we need to clear all stocks
                $productsWithoutStockData[] = $elementId;
            }
        }

        // Update prices
        $this->updatePrices($pricesToUpdate);

        // Update product data (quantity)
        $this->updateProductData($productsToUpdate);

        // Update warehouse stock data
        $this->updateWarehouseStockData($productWarehouseData, $productsWithoutStockData);

        Logger::log("Existing elements updated successfully.");
    }

    protected function updatePrices($pricesToUpdate)
    {
        // Existing implementation remains unchanged
        if (empty($pricesToUpdate)) {
            Logger::log("No prices to update.");
            return;
        }

        $baseCurrency = CurrencyManager::getBaseCurrency();

        // Collect all PRODUCT_IDs to fetch existing prices in bulk
        $productIds = array_column($pricesToUpdate, 'PRODUCT_ID');

        // Fetch existing prices for these PRODUCT_IDs
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

        // Prepare batch updates and inserts
        foreach ($pricesToUpdate as $price) {
            // Calculate PRICE_SCALE
            if ($price['CURRENCY'] != $baseCurrency) {
                $price['PRICE_SCALE'] = \CCurrencyRates::ConvertCurrency($price['PRICE'], $price['CURRENCY'], $baseCurrency);
            } else {
                $price['PRICE_SCALE'] = $price['PRICE'];
            }

            if (isset($existingPrices[$price['PRODUCT_ID']])) {
                // Update existing price
                $result = PriceTable::update($existingPrices[$price['PRODUCT_ID']], $price);
                if (!$result->isSuccess()) {
                    Logger::log("Error updating price for PRODUCT_ID {$price['PRODUCT_ID']}: " . implode(", ", $result->getErrorMessages()));
                }
            } else {
                // Add new price
                $result = PriceTable::add($price);
                if (!$result->isSuccess()) {
                    Logger::log("Error adding price for PRODUCT_ID {$price['PRODUCT_ID']}: " . implode(", ", $result->getErrorMessages()));
                }
            }
        }

        Logger::log("Prices updated successfully.");
    }

    protected function updateProductData($productsToUpdate)
    {
        // Existing implementation remains unchanged
        if (empty($productsToUpdate)) {
            Logger::log("No product data to update.");
            return;
        }

        // Collect all IDs to fetch existing products in bulk
        $productIds = array_column($productsToUpdate, 'ID');

        // Fetch existing products
        $existingProductsResult = ProductTable::getList([
            'filter' => ['ID' => $productIds],
            'select' => ['ID'],
        ]);

        $existingProductIds = [];
        while ($product = $existingProductsResult->fetch()) {
            $existingProductIds[] = $product['ID'];
        }

        // Prepare batch updates
        foreach ($productsToUpdate as $product) {
            if (in_array($product['ID'], $existingProductIds)) {
                // Update existing product
                $result = ProductTable::update($product['ID'], ['QUANTITY' => $product['QUANTITY']]);
                if (!$result->isSuccess()) {
                    Logger::log("Error updating product data for ID {$product['ID']}: " . implode(", ", $result->getErrorMessages()));
                }
            } else {
                // Add new product
                $productData = [
                    'ID' => $product['ID'],
                    'QUANTITY' => $product['QUANTITY'],
                    'AVAILABLE' => 'Y',
                    'TYPE' => $this->getProductType($product['ID']),
                ];
                $result = ProductTable::add($productData);
                if (!$result->isSuccess()) {
                    Logger::log("Error adding product data for ID {$product['ID']}: " . implode(", ", $result->getErrorMessages()));
                }
            }
        }

        Logger::log("Product data updated successfully.");
    }

    protected function updateWarehouseStockData($productWarehouseData = [], $productsWithoutStockData = [])
    {
        if (empty($productWarehouseData) && empty($productsWithoutStockData)) {
            Logger::log("No warehouse stock data to update.");
            return;
        }

        // Process products with received stock data
        foreach ($productWarehouseData as $productId => $data) {
            $receivedStoreIds = $data['RECEIVED_STORE_IDS'];

            // Fetch existing stock entries for this product
            $existingStockResult = StoreProductTable::getList([
                'filter' => ['=PRODUCT_ID' => $productId],
                'select' => ['ID', 'STORE_ID'],
            ]);

            $existingStocks = [];
            while ($stock = $existingStockResult->fetch()) {
                $existingStocks[$stock['STORE_ID']] = $stock['ID'];
            }

            // Update or add stock entries for received stores
            foreach ($data['STOCK_DATA'] as $stockData) {
                $storeId = $stockData['STORE_ID'];
                $amount = $stockData['AMOUNT'];
                if (isset($existingStocks[$storeId])) {
                    // Update existing stock
                    $result = StoreProductTable::update($existingStocks[$storeId], ['AMOUNT' => $amount]);
                    if (!$result->isSuccess()) {
                        Logger::log("Error updating stock data for PRODUCT_ID {$productId}, STORE_ID {$storeId}: " . implode(", ", $result->getErrorMessages()));
                    }
                    // Remove from existingStocks to handle non-received stores later
                    unset($existingStocks[$storeId]);
                } else {
                    // Add new stock entry
                    $newStockData = [
                        'PRODUCT_ID' => $productId,
                        'STORE_ID' => $storeId,
                        'AMOUNT' => $amount,
                    ];
                    $result = StoreProductTable::add($newStockData);
                    if (!$result->isSuccess()) {
                        Logger::log("Error adding stock data for PRODUCT_ID {$productId}, STORE_ID {$storeId}: " . implode(", ", $result->getErrorMessages()));
                    }
                }
            }

            // For existing stock entries not in receivedStoreIds, set AMOUNT to zero
            foreach ($existingStocks as $storeId => $stockId) {
                // These are stores that have existing stock but were not in the received data
                $result = StoreProductTable::update($stockId, ['AMOUNT' => 0]);
                if (!$result->isSuccess()) {
                    Logger::log("Error setting stock amount to zero for PRODUCT_ID {$productId}, STORE_ID {$storeId}: " . implode(", ", $result->getErrorMessages()));
                }
            }
        }

        // Handle products without any stock data (clear all stock amounts)
        if (!empty($productsWithoutStockData)) {
            // Fetch existing stock entries for these products
            $existingStockResult = StoreProductTable::getList([
                'filter' => ['=PRODUCT_ID' => $productsWithoutStockData],
                'select' => ['ID'],
            ]);

            $stockIdsToUpdate = [];
            while ($stock = $existingStockResult->fetch()) {
                $stockIdsToUpdate[] = $stock['ID'];
            }

            // Update each stock entry to set AMOUNT to zero
            foreach ($stockIdsToUpdate as $stockId) {
                $result = StoreProductTable::update($stockId, ['AMOUNT' => 0]);
                if (!$result->isSuccess()) {
                    Logger::log("Error setting stock amount to zero for stock ID {$stockId}: " . implode(", ", $result->getErrorMessages()));
                }
            }
        }

        Logger::log("Warehouse stock data updated successfully.");
    }

    protected function getProductType($productId)
    {
        // Determine product type based on whether it's an offer or a simple product
        // For simplicity, returning TYPE_PRODUCT
        return \Bitrix\Catalog\ProductTable::TYPE_PRODUCT;
    }
}