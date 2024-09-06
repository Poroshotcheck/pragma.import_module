<?php

namespace Pragma\ImportModule;

use Bitrix\Iblock\IblockTable;

class IblockHelper
{
    public static function getIblocks()
    {
        // Получаем список инфоблоков из кэша
        $iblocks = CacheHelper::getCachedIblocks();

        // Если кэш пуст, получаем инфоблоки из базы данных и сохраняем в кэш
        if (!$iblocks) {
            $iblocks = [];
            $rsIblocks = IblockTable::getList([
                'select' => ['ID', 'NAME'],
                'order' => ['NAME' => 'ASC']
            ]);
            while ($arIblock = $rsIblocks->fetch()) {
                $iblocks[$arIblock["ID"]] = $arIblock["NAME"];
            }
            CacheHelper::saveIblocksCache($iblocks);
        }

        return $iblocks;
    }
}