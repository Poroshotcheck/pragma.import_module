<?php
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/AgentManager.php");// Рот того автоподключения


use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Pragma\ImportModule\AgentManager;
use Pragma\ImportModule\Agent\CheckAgent;
use Pragma\ImportModule\Agent\ImportAgent;

class pragma_importmodule extends CModule
{
    public $MODULE_ID = "pragma.importmodule";
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

        // Создаем агенты при установке модуля
        $agentManager = new AgentManager($this->MODULE_ID);
        $agentManager->createAgent(CheckAgent::class, 300, date("d.m.Y H:i:s"), false);
        $agentManager->createAgent(ImportAgent::class, 86400, date("d.m.Y H:i:s", time() + 86400), false);
    }

    public function DoUninstall()
    {
        $this->UnInstallEvents();

        // Удаляем агенты при удалении модуля
        $agentManager = new AgentManager($this->MODULE_ID);
        $agentManager->deleteAgent($agentManager->getAgentIdByName('CheckAgent'));
        $agentManager->deleteAgent($agentManager->getAgentIdByName('ImportAgent'));

        // Удаляем все параметры модуля из таблицы b_option
        $this->deleteModuleOptions();

        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    private function deleteModuleOptions()
    {
        // Получаем список всех настроек для модуля
        $options = Option::getForModule($this->MODULE_ID);

        // Удаляем каждую настройку
        foreach ($options as $optionName => $optionValue) {
            Option::delete($this->MODULE_ID, ['name' => $optionName]);
        }
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
}