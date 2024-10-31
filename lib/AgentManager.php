<?php

namespace Pragma\ImportModule;

use Pragma\ImportModule\Logger;
use Bitrix\Main\Config\Option;

class AgentManager
{
    private static $moduleId = PRAGMA_IMPORT_MODULE_ID;

    public function createAgent($agentClass, $interval, $nextExec, $active = false)
    {
        try {
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
                // Logger::log("Агент " . $agentName . " создан с ID: " . $agentId);
                return $agentId;
            } else {
                Logger::log("Ошибка создания агента " . $agentName, "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Исключение в createAgent: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function updateAgent($agentId, $fields)
    {
        try {
            if (\CAgent::Update($agentId, $fields)) {
                // Logger::log("Агент с ID: " . $agentId . " обновлен");
                return true;
            } else {
                Logger::log("Ошибка обновления агента с ID: " . $agentId, "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Исключение в updateAgent: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function deleteAgent($agentId)
    {
        try {
            if (\CAgent::Delete($agentId)) {
                $agentName = $this->getAgentNameById($agentId);
                Option::delete(self::$moduleId, ["name" => $agentName . "_ID"]);
                // Logger::log("Агент " . $agentName . " (ID: " . $agentId . ") удален");
                return true;
            } else {
                Logger::log("Ошибка удаления агента с ID: " . $agentId, "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Исключение в deleteAgent: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function activateAgent($agentId)
    {
        try {
            if (\CAgent::Update($agentId, ["ACTIVE" => "Y"])) {
                // Logger::log("Агент с ID: " . $agentId . " активирован");
                return true;
            } else {
                Logger::log("Ошибка активации агента с ID: " . $agentId, "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Исключение в activateAgent: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function deactivateAgent($agentId)
    {
        try {
            if (\CAgent::Update($agentId, ["ACTIVE" => "N"])) {
                // Logger::log("Агент с ID: " . $agentId . " деактивирован");
                return true;
            } else {
                Logger::log("Ошибка деактивации агента с ID: " . $agentId, "ERROR");
                return false;
            }
        } catch (\Exception $e) {
            Logger::log("Исключение в deactivateAgent: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function getAgentInfo($agentId)
    {
        try {
            return \CAgent::GetByID($agentId)->Fetch();
        } catch (\Exception $e) {
            Logger::log("Исключение в getAgentInfo: " . $e->getMessage(), "ERROR");
            return false;
        }
    }

    public function getAgentIdByName($agentName)
    {
        try {
            return Option::get(self::$moduleId, $agentName . "_ID", 0);
        } catch (\Exception $e) {
            Logger::log("Исключение в getAgentIdByName: " . $e->getMessage(), "ERROR");
            return 0;
        }
    }

    private function getAgentName($agentClass)
    {
        $parts = explode("\\", $agentClass);
        return end($parts);
    }

    private function getAgentNameById($agentId)
    {
        try {
            $agentInfo = $this->getAgentInfo($agentId);
            if ($agentInfo) {
                return $this->getAgentName($agentInfo['NAME']);
            }
            return false;
        } catch (\Exception $e) {
            Logger::log("Исключение в getAgentNameById: " . $e->getMessage(), "ERROR");
            return false;
        }
    }
}