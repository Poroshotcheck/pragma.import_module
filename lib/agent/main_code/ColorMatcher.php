<?php
namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\ModuleDataTable;

Loader::includeModule('highloadblock');

class ColorMatcher
{
    private $elements = [];
    private $colors = [];
    private $updateCollection = [];
    private $hlblockId;
    private $moduleId;

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->hlblockId = Option::get($this->moduleId, 'COLOR_HLB_ID');
        $this->loadElements();
        $this->loadColors();
    }

    private function loadElements()
    {
        $this->elements = ModuleDataTable::getList([
            'filter' => [
                '!TARGET_SECTION_ID' => 'a:0:{}',
            ],
        ])->fetchAll();
    }

    private function loadColors()
    {
        $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
        $entity = HighloadBlockTable::compileEntity($hlblock);
        $entityDataClass = $entity->getDataClass();

        $rsData = $entityDataClass::getList([
            'select' => ['ID', 'UF_NAME', 'UF_XML_ID', 'UF_SIMILAR'],
        ]);

        while ($item = $rsData->fetch()) {
            $keywords = [];
            if (!empty($item['UF_NAME'])) {
                $keywords[] = $item['UF_NAME'];
            }
            if (!empty($item['UF_SIMILAR'])) {
                if (is_array($item['UF_SIMILAR'])) {
                    $similarNames = array_map('trim', $item['UF_SIMILAR']);
                    $keywords = array_merge($keywords, $similarNames);
                } else {
                    $similarNames = explode(',', $item['UF_SIMILAR']);
                    $similarNames = array_map('trim', $similarNames);
                    $keywords = array_merge($keywords, $similarNames);
                }
            }
            $this->colors[] = [
                'ID' => $item['ID'],
                'UF_NAME' => $item['UF_NAME'],
                'UF_XML_ID' => $item['UF_XML_ID'],
                'KEYWORDS' => $keywords,
            ];
        }
    }

    public function matchColors()
    {
        foreach ($this->elements as $element) {
            $colorXmlId = $this->findMatchingColor($element['ELEMENT_NAME']);
            if ($colorXmlId !== null) {
                $this->updateCollection[] = [
                    'ID' => $element['ID'],
                    'COLOR_VALUE_ID' => $colorXmlId,
                ];
            }
        }
    }

    private function findMatchingColor(string $elementName)
    {
        $bestMatchXmlId = null;
        $bestMatchLength = 0;

        foreach ($this->colors as $color) {
            foreach ($color['KEYWORDS'] as $keyword) {
                if (mb_stripos($elementName, $keyword) !== false) {
                    $keywordLength = mb_strlen($keyword);
                    if ($keywordLength > $bestMatchLength) {
                        $bestMatchLength = $keywordLength;
                        $bestMatchXmlId = $color['UF_XML_ID'];
                    }
                }
            }
        }

        return $bestMatchXmlId;
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
            $elementId = intval($element['ID']);
            $colorValueId = $sqlHelper->forSql($element['COLOR_VALUE_ID']);
            $updateCases[] = "WHEN {$elementId} THEN '{$colorValueId}'";
        }

        $updateSql = "
            UPDATE {$tableName}
            SET COLOR_VALUE_ID = CASE ID
                " . implode(' ', $updateCases) . "
            END
            WHERE ID IN (" . implode(',', array_column($this->updateCollection, 'ID')) . ")
        ";

        $connection->queryExecute($updateSql);
    }
}