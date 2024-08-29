<?php

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;

class pragma_import_module extends CModule
{
    public $MODULE_ID = "pragma.import_module";
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $arModuleVersion = array();
        include(__DIR__ . "/version.php");
        
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        
        $this->MODULE_NAME = GetMessage("PRAGMA_IMPORT_MODULE_NAME");
        $this->MODULE_DESCRIPTION = GetMessage("PRAGMA_IMPORT_MODULE_DESCRIPTION");
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallEvents();
        $this->InstallAgents();
    }

    public function DoUninstall()
    {
        $this->UnInstallEvents();
        $this->UnInstallAgents();
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler(
            "catalog",
            "OnBeforeIBlockImport1C",
            $this->MODULE_ID,
            "Pragma\\ImportModule\\EventHandlers",
            "onBeforeCatalogImport1CHandler"
        );

        EventManager::getInstance()->registerEventHandler(
            "catalog",
            "OnSuccessCatalogImport1C",
            $this->MODULE_ID,
            "Pragma\\ImportModule\\EventHandlers",
            "onSuccessCatalogImport1CHandler"
        );
    }

    public function UnInstallEvents()
    {
        EventManager::getInstance()->unRegisterEventHandler(
            "catalog",
            "OnBeforeIBlockImport1C",
            $this->MODULE_ID,
            "Pragma\\ImportModule\\EventHandlers",
            "onBeforeCatalogImport1CHandler"
        );

        EventManager::getInstance()->unRegisterEventHandler(
            "catalog",
            "OnSuccessCatalogImport1C",
            $this->MODULE_ID,
            "Pragma\\ImportModule\\EventHandlers",
            "onSuccessCatalogImport1CHandler"
        );
    }

    public function InstallAgents()
    {
        // Создаем агенты при установке модуля (неактивные)
        $checkAgentId = \CAgent::AddAgent(
            "Pragma\\ImportModule\\Agent\\CheckAgent::run();", 
            $this->MODULE_ID,
            "N", // Агент неактивен
            300, 
            "", 
            "Y", 
            date("d.m.Y H:i:s"), 
            100 
        );
        Option::set($this->MODULE_ID, "CHECK_AGENT_ID", $checkAgentId); 

        $importAgentId = \CAgent::AddAgent(
            "Pragma\\ImportModule\\Agent\\ImportAgent::run();", 
            $this->MODULE_ID,
            "N", // Агент неактивен
            86400, 
            "", 
            "Y", 
            date("d.m.Y H:i:s", time() + 86400),
            100 
        );
        Option::set($this->MODULE_ID, "IMPORT_AGENT_ID", $importAgentId);

        // Обходное решение: сразу деактивируем агенты после создания
        \CAgent::Update($checkAgentId, array("ACTIVE" => "N")); 
        \CAgent::Update($importAgentId, array("ACTIVE" => "N"));
    }

    public function UnInstallAgents()
    {
        // Удаляем агенты при удалении модуля
        $checkAgentId = Option::get($this->MODULE_ID, "CHECK_AGENT_ID", 0);
        $importAgentId = Option::get($this->MODULE_ID, "IMPORT_AGENT_ID", 0);

        if ($checkAgentId > 0) {
            \CAgent::Delete($checkAgentId);
        }
        if ($importAgentId > 0) {
            \CAgent::Delete($importAgentId);
        }

        Option::delete($this->MODULE_ID, array("name" => "CHECK_AGENT_ID"));
        Option::delete($this->MODULE_ID, array("name" => "IMPORT_AGENT_ID"));
    }
}