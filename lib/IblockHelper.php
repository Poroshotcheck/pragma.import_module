<?php

namespace Pragma\ImportModule;

use Bitrix\Iblock\IblockTable;
use Pragma\ImportModule\Logger;

class IblockHelper
{
    public static function getIblocks()
    {
        try {
            // Logger::log("Попытка получить список инфоблоков");

            // Получаем список инфоблоков из кэша
            $iblocks = CacheHelper::getCachedIblocks();

            // Если кэш пуст, получаем инфоблоки из базы данных и сохраняем в кэш
            if (!$iblocks) {
                // Logger::log("Кэш инфоблоков пуст, получение из базы данных");

                $iblocks = [];
                $rsIblocks = IblockTable::getList([
                    'select' => ['ID', 'NAME'],
                    'order' => ['NAME' => 'ASC']
                ]);
                while ($arIblock = $rsIblocks->fetch()) {
                    $iblocks[$arIblock["ID"]] = $arIblock["NAME"];
                }
                CacheHelper::saveIblocksCache($iblocks);
                // Logger::log("Кэш инфоблоков обновлён");
            }

            // Logger::log("Список инфоблоков успешно получен");
            return $iblocks;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getIblocks: " . $e->getMessage(), "ERROR");
            return false;
        }
    }
}