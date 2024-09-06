<?php

namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;

class CheckAgent
{
    public static function run()
    {
        $moduleId = 'pragma.importmodule';
        $autoMode = Option::get($moduleId, "AUTO_MODE", "N");
        $importStartTime = Option::get($moduleId, 'import_start_time', 0);
        $delayTime = Option::get($moduleId, "DELAY_TIME", 60);

        if ($autoMode === "Y" && $importStartTime > 0) {
            $currentTime = time();
            $timePassed = $currentTime - $importStartTime;

            if ($timePassed >= $delayTime * 60) {
                // Активируем ImportAgent
                $importAgentId = Option::get($moduleId, "IMPORT_AGENT_ID", 0);
                if ($importAgentId > 0) {
                    \CAgent::Update($importAgentId, array(
                        "NEXT_EXEC" => date("d.m.Y H:i:s", $currentTime + 300),
                        "ACTIVE" => "Y"
                    ));
                }

                // Деактивируем CheckAgent
                $checkAgentId = Option::get($moduleId, "CHECK_AGENT_ID", 0);
                if ($checkAgentId > 0) {
                    \CAgent::Update($checkAgentId, array("ACTIVE" => "N"));
                }

                // Сбрасываем время начала импорта
                Option::set($moduleId, 'import_start_time', 0);
            }
        }

        return "Pragma\\ImportModule\\Agent\\CheckAgent::run();";
    }
}