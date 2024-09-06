<?php

namespace Pragma\ImportModule\Agent;

use Bitrix\Main\Config\Option;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\Agent\MainCode\IblockPropertiesCopier;

class ImportAgent
{
    private static $logFile;

    private static function initLogger()
    {
        if (!self::$logFile) {
            self::$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/agent.log";
            Logger::init(self::$logFile);
        }
    }

    public static function run()
    {
        self::initLogger();
        
        $moduleId = 'pragma.importmodule';
        
        Logger::log("Начало выполнения ImportAgent::run()");
        
        try {
            // Получаем ID инфоблоков из настроек модуля
            $sourceIblockId = Option::get($moduleId, 'IBLOCK_ID_IMPORT');
            $destinationIblockId = Option::get($moduleId, 'IBLOCK_ID_CATALOG');
            
            Logger::log("Получены ID инфоблоков: источник = {$sourceIblockId}, назначение = {$destinationIblockId}");
            
            if (empty($sourceIblockId) || empty($destinationIblockId)) {
                throw new \Exception("ID инфоблоков не настроены в модуле");
            }
            
            // Запускаем первый этап - копирование свойств
            Logger::log("Начало копирования свойств");
            $propertiesCopier = new IblockPropertiesCopier($sourceIblockId, $destinationIblockId);
            $propertiesCopier->copyProperties();
            Logger::log("Завершено копирование свойств");
            
            // Код для остальных этапов (пока не реализовано)
            // ...
            
            Logger::log("Успешное завершение ImportAgent::run()");
        } catch (\Exception $e) {
            Logger::log("Ошибка в ImportAgent::run(): " . $e->getMessage());
        }

        return "Pragma\\ImportModule\\Agent\\ImportAgent::run();";
    }
}