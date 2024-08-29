<?php

namespace Pragma\ImportModule;

use Bitrix\Main\Config\Option;

class EventHandlers
{
    public static function onBeforeCatalogImport1CHandler()
    {
        $moduleId = 'pragma.import_module';
        $currentTime = time();

        // Сохраняем время начала импорта в опцию модуля
        Option::set($moduleId, 'import_start_time', $currentTime);

        // Инкрементируем счетчик запусков импорта
        $importStartCount = Option::get($moduleId, 'import_start_count', 0);
        Option::set($moduleId, 'import_start_count', $importStartCount + 1);

        // Активируем CheckAgent, если он не активен
        $checkAgentId = Option::get($moduleId, "CHECK_AGENT_ID", 0);
        if ($checkAgentId > 0) {
            \CAgent::Update($checkAgentId, array("ACTIVE" => "Y"));
        }
    }

    public static function onSuccessCatalogImport1CHandler()
    {
        $moduleId = 'pragma.import_module';

        // Инкрементируем счетчик успешных завершений импорта
        $importSuccessCount = Option::get($moduleId, 'import_success_count', 0);
        Option::set($moduleId, 'import_success_count', $importSuccessCount + 1);
    }
}