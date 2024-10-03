<?php

namespace Pragma\ImportModule;

use Pragma\ImportModule\Logger;
use Bitrix\Main\Config\Option;

class AgentManager
{
    private static $moduleId = PRAGMA_IMPORT_MODULE_ID;
    public function createAgent($agentClass, $interval, $nextExec, $active = false)
    {
        $agentName = $this->getAgentName($agentClass);
        $agentId = \CAgent::AddAgent(
            "\\" . $agentClass . "::run();",
            self::$moduleId,
            "N",
            $interval,
            "",
            "Y",
            $nextExec,
            100
        );

        if ($agentId) {
            Option::set(self::$moduleId, $agentName . "_ID", $agentId);
            if (!$active) {
                $this->deactivateAgent($agentId);
            }
            Logger::log("Агент " . $agentName . " создан с ID: " . $agentId);
            return $agentId;
        } else {
            Logger::log("Ошибка создания агента " . $agentName, "ERROR");
            return false;
        }
    }

    public function updateAgent($agentId, $fields)
    {
        if (\CAgent::Update($agentId, $fields)) {
            Logger::log("Агент с ID: " . $agentId . " обновлен");
            return true;
        } else {
            Logger::log("Ошибка обновления агента с ID: " . $agentId, "ERROR");
            return false;
        }
    }

    public function deleteAgent($agentId)
    {
        if (\CAgent::Delete($agentId)) {
            $agentName = $this->getAgentNameById($agentId);
            Option::delete(self::$moduleId, array("name" => $agentName . "_ID"));
            Logger::log("Агент " . $agentName . " (ID: " . $agentId . ") удален");
            return true;
        } else {
            Logger::log("Ошибка удаления агента с ID: " . $agentId, "ERROR");
            return false;
        }
    }

    public function activateAgent($agentId)
    {
        if (\CAgent::Update($agentId, array("ACTIVE" => "Y"))) {
            Logger::log("Агент с ID: " . $agentId . " активирован");
            return true;
        } else {
            Logger::log("Ошибка активации агента с ID: " . $agentId, "ERROR");
            return false;
        }
    }

    public function deactivateAgent($agentId)
    {
        if (\CAgent::Update($agentId, array("ACTIVE" => "N"))) {
            Logger::log("Агент с ID: " . $agentId . " деактивирован");
            return true;
        } else {
            Logger::log("Ошибка деактивации агента с ID: " . $agentId, "ERROR");
            return false;
        }
    }

    public function getAgentInfo($agentId)
    {
        return \CAgent::GetByID($agentId)->Fetch();
    }

    public function getAgentIdByName($agentName)
    {
        return Option::get(self::$moduleId, $agentName . "_ID", 0);
    }

    private function getAgentName($agentClass)
    {
        $parts = explode("\\", $agentClass);
        return end($parts);
    }

    private function getAgentNameById($agentId)
    {
        $agentInfo = $this->getAgentInfo($agentId);
        if ($agentInfo) {
            return $this->getAgentName($agentInfo['NAME']);
        }
        return false;
    }
}