<?php

namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\ModuleDataTable;
use Bitrix\Main\Application;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

class TradeOfferSorter
{
    private $moduleId;
    private $importMappings;

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->importMappings = unserialize(Option::get($this->moduleId, "IMPORT_MAPPINGS"));
    }

    public function sortTradeOffers()
    {
        //Logger::log("Начало сортировки торговых предложений: sortTradeOffers()");

        try {
            $updateCollection = $this->findChainElements($this->importMappings);

            if (!empty($updateCollection)) {
                $this->updateChainTogezer($updateCollection);
            } else {
                //Logger::log("Нет элементов для обновления.");
            }

            //Logger::log("Завершение сортировки торговых предложений: sortTradeOffers()");
        } catch (\Exception $e) {
            Logger::log("Ошибка в sortTradeOffers(): " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
        }
    }

    /**
     * Получает отфильтрованную коллекцию элементов по ID исходного раздела
     * @param int $sourceSectionId ID исходного раздела
     * @return \Bitrix\Main\ORM\Query\Result
     */
    private function getSourceFilteredCollection($sourceSectionId)
    {
        try {
            $collection = ModuleDataTable::getList([
                'filter' => ['SOURCE_SECTION_ID' => $sourceSectionId, '!TARGET_SECTION_ID' => 'a:0:{}'],
                'select' => ['*']
            ]);
            //Logger::log("Получена коллекция элементов для SOURCE_SECTION_ID = {$sourceSectionId}");
            return $collection;
        } catch (\Exception $e) {
            Logger::log("Ошибка в getSourceFilteredCollection(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Проверяет совпадение имен элементов
     * @param array $baseWords Базовые слова
     * @param array $compareWords Сравниваемые слова
     * @param int $totalMatches Необходимое количество совпадений
     * @return bool
     */
    private function nameMatches($baseWords, $compareWords, $totalMatches)
    {
        try {
            if (count($baseWords) < $totalMatches || count($compareWords) < $totalMatches) {
                return false;
            }

            for ($i = 0; $i < $totalMatches; $i++) {
                if ($baseWords[$i] !== $compareWords[$i]) {
                    return false;
                }
            }

            return true;
        } catch (\Exception $e) {
            Logger::log("Ошибка в nameMatches(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Проверяет совпадение значений свойств
     * @param string $baseValue Базовое значение
     * @param string $compareValue Сравниваемое значение
     * @param int $matches Необходимое количество совпадающих символов
     * @return bool
     */
    private function propertyMatches($baseValue, $compareValue, $matches)
    {
        try {
            if (empty($baseValue) || empty($compareValue)) {
                return false;
            }

            $baseLength = mb_strlen($baseValue);
            $compareLength = mb_strlen($compareValue);

            if ($baseLength <= $matches || $compareLength <= $matches) {
                $minLength = min($baseLength, $compareLength);
                return mb_substr($baseValue, 0, $minLength) === mb_substr($compareValue, 0, $minLength);
            }

            $diff = abs($baseLength - $compareLength);
            if ($baseLength > $compareLength) {
                $baseValue = mb_substr($baseValue, $diff);
            } else {
                $compareValue = mb_substr($compareValue, $diff);
            }

            return mb_substr($baseValue, 0, -$matches) === mb_substr($compareValue, 0, -$matches);
        } catch (\Exception $e) {
            Logger::log("Ошибка в propertyMatches(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Находит связанные элементы и возвращает коллекцию для обновления
     * @param array $importMappings Массив импортируемых маппингов
     * @return array Коллекция элементов для обновления
     */
    private function findChainElements($importMappings)
    {
        try {
            //Logger::log("Начало findChainElements()");

            $chainTogezerId = 1;
            $updateCollection = [];

            foreach ($importMappings as $importMapping) {
                //Logger::log("Обработка importMapping для SECTION_ID = {$importMapping['SECTION_ID']}");
                $collection = $this->getSourceFilteredCollection($importMapping["SECTION_ID"]);

                $elements = [];
                foreach ($collection as $element) {
                    $element['NAME_WORDS'] = explode(' ', mb_strtolower($element['ELEMENT_NAME']));
                    $elements[$element['TARGET_SECTION_ID'][0]][] = $element;
                }

                foreach ($elements as $targetSectionId => $sectionElements) {
                    //Logger::log("Обработка элементов для TARGET_SECTION_ID = {$targetSectionId}");
                    if (!empty($importMapping["PROPERTIES"])) {
                        $propertyCode = reset($importMapping["PROPERTIES"])["CODE"];
                        $sectionElements = array_filter($sectionElements, function ($element) use ($propertyCode) {
                            return !empty($element[$propertyCode]);
                        });
                    }

                    $linkedGroups = $this->findLinkedElements($sectionElements, $importMapping);

                    foreach ($linkedGroups as $linkedGroup) {
                        foreach ($linkedGroup as $element) {
                            $updateCollection[] = [
                                'ID' => $element['ID'],
                                'CHAIN_TOGEZER' => $chainTogezerId
                            ];
                        }
                        //Logger::log("Найдена связанная группа с CHAIN_TOGEZER = {$chainTogezerId}");
                        $chainTogezerId++;
                    }
                }
            }

            return $updateCollection;
        } catch (\Exception $e) {
            Logger::log("Ошибка в findChainElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Находит связанные элементы в группе
     * @param array $elements Массив элементов
     * @param array $importMapping Параметры импорта
     * @return array
     */
    private function findLinkedElements($elements, $importMapping)
    {
        try {
            $linkedElements = [];
            $processedElements = [];

            foreach ($elements as $i => $element1) {
                if (in_array($element1["ID"], $processedElements)) {
                    continue;
                }

                $currentGroup = [$element1];

                for ($j = $i + 1; $j < count($elements); $j++) {
                    $element2 = $elements[$j];

                    if (in_array($element2["ID"], $processedElements)) {
                        continue;
                    }

                    $totalMatchesOk = ($importMapping["TOTAL_MATCHES"] === 0) ||
                                      $this->nameMatches($element1['NAME_WORDS'], $element2['NAME_WORDS'], $importMapping["TOTAL_MATCHES"]);

                    $propertiesOk = !empty($importMapping["PROPERTIES"]);
                    if ($propertiesOk) {
                        $propertiesOk = false;
                        foreach ($importMapping["PROPERTIES"] as $prop) {
                            if ($this->propertyMatches($element1[$prop["CODE"]], $element2[$prop["CODE"]], $prop["MATCHES"])) {
                                $propertiesOk = true;
                                break;
                            }
                        }
                    }

                    if ($totalMatchesOk && $propertiesOk) {
                        $currentGroup[] = $element2;
                        $processedElements[] = $element2["ID"];
                    }
                }

                if (count($currentGroup) > 1) {
                    $linkedElements[] = $currentGroup;
                    $processedElements = array_merge($processedElements, array_column($currentGroup, "ID"));
                }
            }

            //Logger::log("Найдено " . count($linkedElements) . " связанных групп элементов.");
            return $linkedElements;
        } catch (\Exception $e) {
            Logger::log("Ошибка в findLinkedElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Обновляет CHAIN_TOGEZER для элементов
     * @param array $updateCollection Коллекция элементов для обновления
     */
    private function updateChainTogezer($updateCollection)
    {
        //Logger::log("Начало добавления связей для торговых предложений: updateChainTogezer()");
       // $startTime = microtime(true);

        try {
            $connection = Application::getConnection();
            $sqlHelper = $connection->getSqlHelper();
            $tableName = ModuleDataTable::getTableName();

            $updateCases = [];
            foreach ($updateCollection as $element) {
                $updateCases[] = "WHEN {$sqlHelper->forSql($element['ID'])} THEN {$sqlHelper->forSql($element['CHAIN_TOGEZER'])}";
            }

            $updateSql = "
                UPDATE {$tableName}
                SET CHAIN_TOGEZER = CASE ID
                    " . implode(' ', $updateCases) . "
                END
                WHERE ID IN (" . implode(',', array_column($updateCollection, 'ID')) . ")
            ";

            $connection->queryExecute($updateSql);
            //Logger::log("Успешно обновлено " . count($updateCollection) . " элементов в базе данных: updateChainTogezer()");
        } catch (\Exception $e) {
            Logger::log("Ошибка при обновлении связей: " . $e->getMessage(), "ERROR");
            Logger::log("Трассировка: " . $e->getTraceAsString(), "ERROR");
            throw $e;
        }

        //$endTime = microtime(true);
       // $executionTime = round($endTime - $startTime, 3);
       // Logger::log("Завершение сортировки торговых предложений: updateChainTogezer(). Время выполнения: {$executionTime} сек");
    }
}