<?php
namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Logger;

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

        //Logger::log("Инициализация ColorMatcher с moduleId = {$this->moduleId} и hlblockId = {$this->hlblockId}");

        try {
            $this->loadElements();
            $this->loadColors();
        } catch (\Exception $e) {
            Logger::log("Ошибка при инициализации ColorMatcher: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    private function loadElements()
    {
        //Logger::log("Начало загрузки элементов в loadElements()");

        try {
            $this->elements = ModuleDataTable::getList([
                'filter' => [
                    '!TARGET_SECTION_ID' => 'a:0:{}',
                ],
            ])->fetchAll();

            //Logger::log("Успешно загружено " . count($this->elements) . " элементов.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в loadElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    private function loadColors()
    {
        //Logger::log("Начало загрузки цветов в loadColors()");

        try {
            $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();

            if (!$hlblock) {
                throw new \Exception("Highload-блок с ID {$this->hlblockId} не найден.");
            }

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

            //Logger::log("Успешно загружено " . count($this->colors) . " цветов.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в loadColors(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public function matchColors()
    {
        //Logger::log("Начало сопоставления цветов в matchColors()");

        try {
            foreach ($this->elements as $element) {
                $colorXmlId = $this->findMatchingColor($element['ELEMENT_NAME']);
                if ($colorXmlId !== null) {
                    $this->updateCollection[] = [
                        'ID' => $element['ID'],
                        'COLOR_VALUE_ID' => $colorXmlId,
                    ];
                }
            }

            //Logger::log("Сопоставление цветов завершено. Найдено соответствий: " . count($this->updateCollection));
        } catch (\Exception $e) {
            Logger::log("Ошибка в matchColors(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    private function findMatchingColor(string $elementName)
    {
        try {
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
        } catch (\Exception $e) {
            Logger::log("Ошибка в findMatchingColor() для элемента '{$elementName}': " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public function updateDatabase()
    {
        //Logger::log("Начало обновления базы данных в updateDatabase()");

        if (empty($this->updateCollection)) {
            Logger::log("Нет данных для обновления в базе данных.");
            return;
        }

        try {
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
            //Logger::log("Успешно обновлено " . count($this->updateCollection) . " записей в базе данных.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateDatabase(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }
}