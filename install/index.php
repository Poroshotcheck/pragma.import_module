<?php
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/AgentManager.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/Logger.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/ModuleDataTable.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/EventHandlers.php");

use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\AgentManager;
use Pragma\ImportModule\Agent\CheckAgent;
use Pragma\ImportModule\Agent\ImportAgent;
use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Main\Loader;

Loader::includeModule("highloadblock");

class pragma_importmodule extends CModule
{
    public $MODULE_ID;
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $arModuleVersion = [];
        include(__DIR__ . "/version.php");

        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        $this->MODULE_ID = $arModuleVersion["MODULE_ID"];
        $this->MODULE_NAME = GetMessage("PRAGMA_IMPORT_MODULE_NAME");
        $this->MODULE_DESCRIPTION = GetMessage("PRAGMA_IMPORT_MODULE_DESCRIPTION");

        define('PRAGMA_IMPORT_MODULE_ID', $arModuleVersion["MODULE_ID"]);
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallEvents();

        // Create agents
        $agentManager = new AgentManager();
        $agentManager->createAgent(CheckAgent::class, 300, date("d.m.Y H:i:s"), true);
        $agentManager->createAgent(ImportAgent::class, 86400, date("d.m.Y H:i:s", time() + 86400), false);

        // Highload block creation/check
        global $DB;

        // Start transaction to ensure data integrity
        try {
            $DB->StartTransaction();

            // HLBs for colors and sizes
            $colorHlbId = $this->getOrCreateHLB('PragmaColorReference', $this->getColorFields());
            $sizeHlbId = $this->getOrCreateHLB('PragmaSizeReference', $this->getSizeFields());
            $typeHlbId = $this->getOrCreateHLB('PragmaTypeReference', $this->getTypeFields());

            // Save HLB IDs in module options if both were found/created successfully
            if ($colorHlbId && $sizeHlbId) {
                Option::set($this->MODULE_ID, "COLOR_HLB_ID", $colorHlbId);
                Option::set($this->MODULE_ID, "SIZE_HLB_ID", $sizeHlbId);
                Option::set($this->MODULE_ID, "TYPE_HLB_ID", $typeHlbId);
                echo "Highload blocks created/found and IDs saved successfully.";
            } else {
                throw new \Exception("Error creating or finding Highload blocks.");
            }

            $DB->Commit();

            // Clear cache for HighloadBlock to force re-generation of entity classes
            Loader::clearModuleCache("highloadblock");

        } catch (\Exception $e) {
            $DB->Rollback();
            echo "Error: " . $e->getMessage();
        }
    }

    // Function to check or create HLB
    private function getOrCreateHLB($hlblockName, $fields)
    {
        // Check if the HLB already exists
        $hlblock = HighloadBlockTable::getList([
            'filter' => ['=NAME' => $hlblockName]
        ])->fetch();

        if ($hlblock) {
            return $hlblock['ID'];
        } else {
            // Create the HLB if it doesn't exist
            return $this->createHighloadBlock($hlblockName, $fields);
        }
    }

    // Function to create an HLB
    private function createHighloadBlock($hlblockName, $fields)
    {
        $result = HighloadBlockTable::add([
            'NAME' => $hlblockName,
            'TABLE_NAME' => strtolower($hlblockName),
        ]);

        if ($result->isSuccess()) {
            $id = $result->getId();

            $userTypeEntity = new \CUserTypeEntity();
            foreach ($fields as $fieldName => $fieldParams) {
                $userTypeData = [
                    'ENTITY_ID' => 'HLBLOCK_' . $id,
                    'FIELD_NAME' => $fieldName,
                    'USER_TYPE_ID' => $fieldParams['USER_TYPE_ID'],
                    'SORT' => $fieldParams['SORT'],
                    'MULTIPLE' => $fieldParams['MULTIPLE'] ? 'Y' : 'N',
                    'MANDATORY' => $fieldParams['MANDATORY'] ? 'Y' : 'N',
                    'SETTINGS' => $fieldParams['SETTINGS'] ?? [],
                ];
                $userTypeEntity->Add($userTypeData);
            }

            return $id;
        } else {
            throw new \Exception("Failed to create Highload Block: " . implode(", ", $result->getErrorMessages()));
        }
    }

    // Define the fields for colors HLB
    private function getColorFields()
    {
        return [
            'UF_SIMILAR' => ['USER_TYPE_ID' => 'string', 'SORT' => 100, 'MULTIPLE' => true],
            'UF_NAME' => ['USER_TYPE_ID' => 'string', 'SORT' => 100, 'MULTIPLE' => false, 'MANDATORY' => true],
            'UF_SORT' => ['USER_TYPE_ID' => 'integer', 'SORT' => 200, 'MULTIPLE' => false],
            'UF_XML_ID' => ['USER_TYPE_ID' => 'string', 'SORT' => 300, 'MULTIPLE' => false, 'MANDATORY' => true],
            'UF_LINK' => ['USER_TYPE_ID' => 'string', 'SORT' => 400, 'MULTIPLE' => false],
            'UF_DESCRIPTION' => ['USER_TYPE_ID' => 'string', 'SORT' => 500, 'MULTIPLE' => false],
            'UF_FULL_DESCRIPTION' => ['USER_TYPE_ID' => 'string', 'SORT' => 600, 'MULTIPLE' => false],
            'UF_DEF' => ['USER_TYPE_ID' => 'boolean', 'SORT' => 700, 'MULTIPLE' => false],
            'UF_FILE' => ['USER_TYPE_ID' => 'file', 'SORT' => 800, 'MULTIPLE' => false],
        ];
    }

    // Define the fields for sizes HLB
    private function getSizeFields()
    {
        return [
            'UF_SIMILAR' => ['USER_TYPE_ID' => 'string', 'SORT' => 100, 'MULTIPLE' => true],
            'UF_NAME' => ['USER_TYPE_ID' => 'string', 'SORT' => 100, 'MULTIPLE' => false, 'MANDATORY' => true],
            'UF_SORT' => ['USER_TYPE_ID' => 'integer', 'SORT' => 200, 'MULTIPLE' => false],
            'UF_XML_ID' => ['USER_TYPE_ID' => 'string', 'SORT' => 300, 'MULTIPLE' => false, 'MANDATORY' => true],
            'UF_LINK' => ['USER_TYPE_ID' => 'string', 'SORT' => 400, 'MULTIPLE' => false],
            'UF_DESCRIPTION' => ['USER_TYPE_ID' => 'string', 'SORT' => 500, 'MULTIPLE' => false],
            'UF_FULL_DESCRIPTION' => ['USER_TYPE_ID' => 'string', 'SORT' => 600, 'MULTIPLE' => false],
            'UF_DEF' => ['USER_TYPE_ID' => 'boolean', 'SORT' => 700, 'MULTIPLE' => false],
        ];
    }

    private function getTypeFields()
    {
        return [
            'UF_SIMILAR' => ['USER_TYPE_ID' => 'string', 'SORT' => 100, 'MULTIPLE' => true],
            'UF_NAME' => ['USER_TYPE_ID' => 'string', 'SORT' => 100, 'MULTIPLE' => false, 'MANDATORY' => true],
            'UF_SORT' => ['USER_TYPE_ID' => 'integer', 'SORT' => 200, 'MULTIPLE' => false],
            'UF_XML_ID' => ['USER_TYPE_ID' => 'string', 'SORT' => 300, 'MULTIPLE' => false, 'MANDATORY' => true],
            'UF_LINK' => ['USER_TYPE_ID' => 'string', 'SORT' => 400, 'MULTIPLE' => false],
            'UF_DESCRIPTION' => ['USER_TYPE_ID' => 'string', 'SORT' => 500, 'MULTIPLE' => false],
            'UF_FULL_DESCRIPTION' => ['USER_TYPE_ID' => 'string', 'SORT' => 600, 'MULTIPLE' => false],
            'UF_DEF' => ['USER_TYPE_ID' => 'boolean', 'SORT' => 700, 'MULTIPLE' => false],
            'UF_FILE' => ['USER_TYPE_ID' => 'file', 'SORT' => 800, 'MULTIPLE' => false],
        ];
    }

    public function DoUninstall()
    {
        $this->UnInstallEvents();

        // Удаляем агенты
        $agentManager = new AgentManager();
        $agentManager->deleteAgent($agentManager->getAgentIdByName('CheckAgent'));
        $agentManager->deleteAgent($agentManager->getAgentIdByName('ImportAgent'));

        // Удаляем все параметры модуля из таблицы b_option
        $this->deleteModuleOptions();

        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    private function deleteModuleOptions()
    {
        $options = Option::getForModule($this->MODULE_ID);
        foreach ($options as $optionName => $optionValue) {
            Option::delete($this->MODULE_ID, ['name' => $optionName]);
        }
    }

    public function InstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $moduleId = $this->MODULE_ID;

        $eventManager->registerEventHandler(
            'catalog',
            'OnBeforeCatalogImport1C',
            $moduleId,
            '\\Pragma\\ImportModule\\EventHandlers',
            'onBeforeCatalogImport1CHandler'
        );

        $eventManager->registerEventHandler(
            'catalog',
            'OnSuccessCatalogImport1C',
            $moduleId,
            '\\Pragma\\ImportModule\\EventHandlers',
            'onSuccessCatalogImport1CHandler'
        );

        $eventManager->registerEventHandler(
            'catalog',
            'OnCompleteCatalogImport1C',
            $moduleId,
            '\\Pragma\\ImportModule\\EventHandlers',
            'onCompleteCatalogImport1CHandler'
        );
    }

    public function UnInstallEvents()
    {
        $eventManager = EventManager::getInstance();
        $moduleId = $this->MODULE_ID;

        $eventManager->unRegisterEventHandler(
            'catalog',
            'OnBeforeCatalogImport1C',
            $moduleId,
            '\\Pragma\\ImportModule\\EventHandlers',
            'onBeforeCatalogImport1CHandler'
        );

        $eventManager->unRegisterEventHandler(
            'catalog',
            'OnSuccessCatalogImport1C',
            $moduleId,
            '\\Pragma\\ImportModule\\EventHandlers',
            'onSuccessCatalogImport1CHandler'
        );

        $eventManager->unRegisterEventHandler(
            'catalog',
            'OnCompleteCatalogImport1C',
            $moduleId,
            '\\Pragma\\ImportModule\\EventHandlers',
            'onCompleteCatalogImport1CHandler'
        );
    }
}