<?php
namespace Pragma\ImportModule;

use Bitrix\Main\Loader;
use Bitrix\Iblock\PropertyTable;
use Pragma\ImportModule\Logger;

class PropertyHelper
{
    public static function getIblockProperties($iblockId)
    {
        try {
            // Logger::log("Начало получения свойств инфоблока с ID: $iblockId");

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

            // Logger::log("Свойства инфоблока успешно получены");

            return $properties;

        } catch (\Exception $e) {
            Logger::log("Ошибка в getIblockProperties: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public static function getPropertyOptionsHtml($iblockId, $selectedPropertyCode = null, $properties = null)
    {
        try {
            // Logger::log("Формирование HTML опций для инфоблока с ID: $iblockId");

            if (empty($iblockId)) {
                return '';
            }

            if (is_null($properties) || empty($properties)) {
                $properties = CacheHelper::getCachedProperties($iblockId);

                if (empty($properties)) {
                    // Logger::log("Свойства не найдены в кэше для инфоблока: $iblockId");

                    // Загружаем свойства из базы данных
                    $properties = self::getIblockProperties($iblockId);

                    // Проверяем, успешно ли получены свойства
                    if ($properties === false) {
                        Logger::log("Не удалось получить свойства для инфоблока: $iblockId", "ERROR");
                        return '';
                    }

                    // Сохраняем свойства в кэш
                    CacheHelper::saveCachedProperties($iblockId, $properties);
                }
            }

            // Формируем HTML для опций select
            $html = '';
            foreach ($properties as $code => $name) {
                $selected = ($code == $selectedPropertyCode) ? ' selected' : '';
                $html .= '<option value="' . $code . '"' . $selected . '>' . $name . '</option>';
            }

            // Logger::log("HTML опции успешно сформированы");

            return $html;

        } catch (\Exception $e) {
            Logger::log("Ошибка в getPropertyOptionsHtml: " . $e->getMessage(), "ERROR");
            return '';
        }
    }
}