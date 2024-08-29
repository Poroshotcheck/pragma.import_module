<?php

namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;
use Pragma\ImportModule\ImportHelper;

class ImportAgent
{
    public static function run()
    {
        $moduleId = 'pragma.import_module';
        
        // Запускаем обработку инфоблока
        ImportHelper::processCatalog();

        // Обновляем время последнего запуска
        Option::set($moduleId, 'last_run_time', time());

        // Получаем настройки агента
        $interval = Option::get($moduleId, 'AGENT_INTERVAL', 86400);
        $nextExec = Option::get($moduleId, 'AGENT_NEXT_EXEC', '');

        if (empty($nextExec)) {
            $nextExec = date("d.m.Y H:i:s", time() + $interval);
        }

        // Обновляем агент
        $importAgentId = Option::get($moduleId, "IMPORT_AGENT_ID", 0);
        if ($importAgentId > 0) {
            \CAgent::Update($importAgentId, array(
                "NEXT_EXEC" => $nextExec,
                "AGENT_INTERVAL" => $interval,
                "ACTIVE" => "Y"
            ));
        }

        return "Pragma\\ImportModule\\Agent\\ImportAgent::run();";
    }
}