<?php

namespace Pragma\ImportModule;

use Bitrix\Main\Config\Option;

class EventHandlers
{
    public static function onBeforeCatalogImport1CHandler()
    {
        // Получаем текущее время
        $currentTime = time();

        // Сохраняем время начала импорта в опцию модуля
        Option::set('pragma.import_module', 'import_start_time', $currentTime);

        // Инкрементируем счетчик запусков импорта
        $importStartCount = Option::get('pragma.import_module', 'import_start_count', 0);
        Option::set('pragma.import_module', 'import_start_count', $importStartCount + 1);
    }

    public static function onSuccessCatalogImport1CHandler()
    {
        // Инкрементируем счетчик успешных завершений импорта
        $importSuccessCount = Option::get('pragma.import_module', 'import_success_count', 0);
        Option::set('pragma.import_module', 'import_success_count', $importSuccessCount + 1);
    }
}

?>