<?php

namespace Pragma\ImportModule;

use Bitrix\Main\Config\Option;

class ImportAgent
{
    public static function run()
    {
        // Получаем настройки модуля
        $agentActive = Option::get('pragma.import_module', 'AGENT_ACTIVE', 'N');
        $launchMode = Option::get('pragma.import_module', 'LAUNCH_MODE', 'auto');
        $autoModeDelay = Option::get('pragma.import_module', 'AUTO_MODE_DELAY', 30);
        $scheduleModeTime = Option::get('pragma.import_module', 'SCHEDULE_MODE_TIME', '00:00');
        $scheduleModeInterval = Option::get('pragma.import_module', 'SCHEDULE_MODE_INTERVAL', 86400);

        // Проверяем активность агента
        if ($agentActive !== 'Y') {
            return "Pragma\\ImportModule\\ImportAgent::run();";
        }

        // Определяем режим запуска
        if ($launchMode === 'auto') {
            // Автоматический режим

            // Получаем время последнего запуска импорта
            $importStartTime = Option::get('pragma.import_module', 'import_start_time', 0);

            // Проверяем, был ли запущен импорт
            if ($importStartTime > 0) {
                // Вычисляем время, прошедшее с момента запуска импорта
                $timeSinceImport = time() - $importStartTime;

                // Проверяем, прошло ли достаточно времени
                if ($timeSinceImport >= $autoModeDelay * 60) {
                    // Запускаем обработку инфоблока
                    ImportHelper::processCatalog();

                    // Сбрасываем время начала импорта
                    Option::set('pragma.import_module', 'import_start_time', 0);
                }
            }
        } elseif ($launchMode === 'schedule') {
            // Запуск по расписанию

            // ... (реализация логики запуска по расписанию)
        }

        return "Pragma\\ImportModule\\ImportAgent::run();";
    }
}

?>