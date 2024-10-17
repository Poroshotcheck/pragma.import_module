<?php
namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\ModuleDataTable;

Loader::includeModule('highloadblock');

class SizeMatcher
{
    private $elements;
    private $sizes;
    private $mainSeparators = ['|'];
    private $additionalSeparators = ['/'];
    private $updateCollection = [];
    private $hlblockId;
    private $moduleId;

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->hlblockId = Option::get($this->moduleId, 'SIZE_HLB_ID');
        $this->loadElements();
        $this->loadSizes();
    }

    private function loadElements()
    {
        $this->elements = ModuleDataTable::getList([
            'filter' => [
                '!TARGET_SECTION_ID' => 'a:0:{}',
            ],
        ])->fetchAll();
    }

    private function loadSizes()
    {
        $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();

        $rsData = $entityDataClass::getList([
            'select' => ['ID', 'UF_NAME', 'UF_XML_ID'],
        ]);

        $this->sizes = [];
        while ($item = $rsData->fetch()) {
            $this->sizes[] = $item;
        }
    }

    public function matchSizes()
    {
        foreach ($this->elements as $element) {
            $sizeValueId = $this->findBestMatchingSize($element['ELEMENT_NAME']);
            if ($sizeValueId !== null) {
                $this->updateCollection[] = [
                    'ID' => $element['ID'],
                    'SIZE_VALUE_ID' => $sizeValueId
                ];
            }
        }
    }

    private function findBestMatchingSize($elementName)
    {
        $bestMatch = null;
        $bestMatchLength = 0;

        $sizePart = $this->extractSizePart($elementName);
        if (empty($sizePart)) {
            return null;
        }

        foreach ($this->sizes as $dbSize) {
            if (strpos($sizePart, $dbSize['UF_NAME']) !== false) {
                $currentMatchLength = strlen($dbSize['UF_NAME']);
                if ($currentMatchLength > $bestMatchLength) {
                    $bestMatchLength = $currentMatchLength;
                    $bestMatch = $dbSize['UF_XML_ID'];
                }
            }
        }

        return $bestMatch;
    }

    private function extractSizePart($str)
    {
        foreach ($this->mainSeparators as $separator) {
            $parts = explode($separator, $str);
            if (count($parts) > 1) {
                return end($parts);
            }
        }

        foreach ($this->additionalSeparators as $separator) {
            $parts = explode($separator, $str);
            if (count($parts) > 1) {
                return end($parts);
            }
        }

        return $str;
    }

    public function updateDatabase()
    {
        if (empty($this->updateCollection)) {
            return;
        }

        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $tableName = ModuleDataTable::getTableName();

        $updateCases = [];
        foreach ($this->updateCollection as $element) {
            $updateCases[] = "WHEN {$sqlHelper->forSql($element['ID'])} THEN '{$sqlHelper->forSql($element['SIZE_VALUE_ID'])}'";
        }

        $updateSql = "
            UPDATE {$tableName}
            SET SIZE_VALUE_ID = CASE ID
                " . implode(' ', $updateCases) . "
            END
            WHERE ID IN (" . implode(',', array_column($this->updateCollection, 'ID')) . ")
        ";

        $connection->queryExecute($updateSql);
    }
}