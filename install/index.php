<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;

Loc::loadMessages(__FILE__);

class pragma_import_module extends CModule
{
    public function __construct()
    {
        $arModuleVersion = array();
        include(__DIR__.'/version.php');

        $this->MODULE_ID = 'pragma.import_module';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('PRAGMA_IMPORT_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('PRAGMA_IMPORT_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = Loc::getMessage('PRAGMA_IMPORT_MODULE_PARTNER_NAME');
        $this->PARTNER_URI = Loc::getMessage('PRAGMA_IMPORT_MODULE_PARTNER_URI');
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallEvents();
        $this->InstallDB();
        $this->InstallAgents();
    }

    public function DoUninstall()
    {
        $this->UnInstallAgents();
        $this->UnInstallDB();
        $this->UnInstallEvents();
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    function InstallDB()
    {
        // Здесь больше не нужно устанавливать значение по умолчанию для IMPORT_TIMEOUT
    }

    function UnInstallDB()
    {
        Option::delete($this->MODULE_ID);
    }

    function InstallEvents()
    {
        RegisterModuleDependences("catalog", "OnBeforeCatalogImport1C", $this->MODULE_ID, "Pragma\\ImportModule\\EventHandlers", "onBeforeCatalogImport1CHandler");
        RegisterModuleDependences("catalog", "OnSuccessCatalogImport1C", $this->MODULE_ID, "Pragma\\ImportModule\\EventHandlers", "onSuccessCatalogImport1CHandler");
    }

    function UnInstallEvents()
    {
        UnRegisterModuleDependences("catalog", "OnBeforeCatalogImport1C", $this->MODULE_ID, "Pragma\\ImportModule\\EventHandlers", "onBeforeCatalogImport1CHandler");
        UnRegisterModuleDependences("catalog", "OnSuccessCatalogImport1C", $this->MODULE_ID, "Pragma\\ImportModule\\EventHandlers", "onSuccessCatalogImport1CHandler");
    }

    function InstallAgents()
    {
        CAgent::AddAgent(
            "Pragma\\ImportModule\\Agent\\ImportAgent::run();", // Функция агента
            $this->MODULE_ID, // ID модуля
            "N", // Периодичность (N - не периодический)
            86400, // Интервал (в секундах)
            "", // Дата первой проверки
            "Y", // Активность
            "", // Дата первого запуска
            100 // Сортировка
        );
    }

    function UnInstallAgents()
    {
        CAgent::RemoveModuleAgents($this->MODULE_ID);
    }
}