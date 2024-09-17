<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;

class PropertyHelper
{
    public static function getIblockProperties($iblockId)
    {
        Loader::includeModule('iblock');

        $properties = [];
        $dbProperties = PropertyTable::getList([
            'filter' => [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_TYPE' => 'S',
                'MULTIPLE' => 'N'
            ],
            'select' => ['ID', 'NAME', 'CODE'],
            'order' => ['SORT' => 'ASC', 'NAME' => 'ASC']
        ]);

        while ($property = $dbProperties->fetch()) {
            $properties[$property['CODE']] = $property['NAME'] . ' [' . $property['CODE'] . ']';
        }

        return $properties;
    }

    public static function getPropertyOptionsHtml($iblockId, $selectedPropertyCode = null, $properties = null)
    {
        if (empty($iblockId)) {
            return '';
        }

        if (is_null($properties) && empty($properties)) {
            // Получаем свойства из кэша
            $cachedData = CacheHelper::getCachedProperties($iblockId);
            $properties = $cachedData ? $cachedData[0] : [];

            if (!$properties) {
                // Логируем загрузку из базы данных
                Logger::log("Свойства загружены из базы данных для инфоблока: " . $iblockId);

                // Загружаем свойства из базы данных
                $properties = self::getIblockProperties($iblockId);

                // Сохраняем свойства в кэш
                CacheHelper::saveCachedProperties($iblockId, $properties);
            }
        }

        //$html = '<option value="">'.GetMessage("PRAGMA_IMPORT_MODULE_SELECT_PROPERTY").'</option>';

        foreach ($properties as $code => $name) {
            $selected = ($code == $selectedPropertyCode) ? ' selected' : '';
            $html .= '<option value="' . $code . '"' . $selected . '>' . $name . '</option>';
        }

        return $html;
    }
}