<?php
namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Logger;

Loader::includeModule('highloadblock');

class SizeMatcher
{
    private $elements;
    private $sizeMap;
    private $mainSeparators = ['|'];
    private $additionalSeparators = ['/'];
    private $updateCollection = [];
    private $hlblockId;
    private $moduleId;

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->hlblockId = Option::get($this->moduleId, 'SIZE_HLB_ID');

        //Logger::log("Инициализация SizeMatcher с moduleId = {$this->moduleId} и hlblockId = {$this->hlblockId}");

        try {
            $this->loadElementsAndSizes();
        } catch (\Exception $e) {
            Logger::log("Ошибка при инициализации SizeMatcher: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Загрузка элементов и размеров оптимизированным способом для уменьшения количества запросов к базе данных.
     */
    private function loadElementsAndSizes()
    {
        //Logger::log("Начало загрузки элементов и размеров в loadElementsAndSizes()");

        try {
            // Шаг 1: Загрузка элементов с необходимыми полями
            $elementsResult = ModuleDataTable::getList([
                'select' => ['ID', 'ELEMENT_NAME'],
                'filter' => [
                    '!TARGET_SECTION_ID' => 'a:0:{}',
                ],
            ]);

            $this->elements = [];
            $normalizedElementSizes = [];

            while ($element = $elementsResult->fetch()) {
                $sizePart = $this->extractSizePart($element['ELEMENT_NAME']);
                $normalizedSize = $this->normalizeSize($sizePart);
                $element['NORMALIZED_SIZE'] = $normalizedSize;
                $this->elements[] = $element;

                // Собираем все уникальные нормализованные размеры из элементов
                if (!empty($normalizedSize)) {
                    $normalizedElementSizes[$normalizedSize] = true;
                }
            }

            //Logger::log("Успешно загружено " . count($this->elements) . " элементов.");

            // Шаг 2: Загрузка размеров из Highload Block и построение маппинга
            $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();

            if (!$hlblock) {
                throw new \Exception("Highload-блок с ID {$this->hlblockId} не найден.");
            }

            $entity = HighloadBlockTable::compileEntity($hlblock);
            $entityDataClass = $entity->getDataClass();

            $rsData = $entityDataClass::getList([
                'select' => ['ID', 'UF_NAME', 'UF_XML_ID'],
            ]);

            $this->sizeMap = [];

            while ($item = $rsData->fetch()) {
                $normalizedDbSize = $this->normalizeSize($item['UF_NAME']);
                if (!empty($normalizedDbSize)) {
                    $this->sizeMap[$normalizedDbSize][] = $item;
                }
            }

            //Logger::log("Успешно загружено " . count($this->sizeMap) . " размеров из Highload-блока.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в loadElementsAndSizes(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    public function matchSizes()
    {
        //Logger::log("Начало сопоставления размеров в matchSizes()");

        try {
            foreach ($this->elements as $element) {
                $normalizedElementSize = $element['NORMALIZED_SIZE'];

                if (empty($normalizedElementSize)) {
                    continue;
                }

                // Попытка найти точное совпадение в маппинге размеров
                if (isset($this->sizeMap[$normalizedElementSize])) {
                    $bestMatch = $this->selectBestMatch($normalizedElementSize, $this->sizeMap[$normalizedElementSize]);

                    if ($bestMatch !== null) {
                        $this->updateCollection[] = [
                            'ID' => $element['ID'],
                            'SIZE_VALUE_ID' => $bestMatch['UF_XML_ID'],
                        ];
                        continue;
                    }
                }

                // Если нет точного совпадения, попытка найти лучшее частичное совпадение
                $bestMatch = null;
                $bestMatchScore = 0;

                foreach ($this->sizeMap as $normalizedDbSize => $dbSizes) {
                    foreach ($dbSizes as $dbSize) {
                        $score = $this->calculateMatchScore($normalizedElementSize, $normalizedDbSize);

                        if ($score > $bestMatchScore) {
                            $bestMatchScore = $score;
                            $bestMatch = $dbSize['UF_XML_ID'];
                        }
                    }
                }

                if ($bestMatch !== null) {
                    $this->updateCollection[] = [
                        'ID' => $element['ID'],
                        'SIZE_VALUE_ID' => $bestMatch,
                    ];
                }
            }

            //Logger::log("Сопоставление размеров завершено. Найдено соответствий: " . count($this->updateCollection));
        } catch (\Exception $e) {
            Logger::log("Ошибка в matchSizes(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    /**
     * Выбор лучшего совпадения из нескольких размеров с одинаковым нормализованным размером.
     *
     * @param string $elementSize Нормализованный размер из элемента.
     * @param array $dbSizes Массив размеров из базы данных.
     * @return array|null Лучший совпадающий размер или null.
     */
    private function selectBestMatch($elementSize, $dbSizes)
    {
        try {
            // Так как размеры нормализованы, можно выбрать первый
            return $dbSizes[0];
        } catch (\Exception $e) {
            Logger::log("Ошибка в selectBestMatch(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Вычисление оценки совпадения между двумя строками размеров.
     *
     * @param string $size1 Первая строка размера.
     * @param string $size2 Вторая строка размера.
     * @return int Оценка совпадения.
     */
    private function calculateMatchScore($size1, $size2)
    {
        try {
            $score = 0;
            $size1Parts = preg_split('/[\s-]+/', $size1);
            $size2Parts = preg_split('/[\s-]+/', $size2);

            foreach ($size1Parts as $index => $part1) {
                if (isset($size2Parts[$index])) {
                    $part2 = $size2Parts[$index];
                    if ($this->isPartialMatch($part1, $part2)) {
                        $score += $this->getPartScore($part1, $part2);
                    }
                }
            }

            return $score;
        } catch (\Exception $e) {
            Logger::log("Ошибка в calculateMatchScore(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Проверка частичного совпадения двух частей размера.
     *
     * @param string $part1 Первая часть размера.
     * @param string $part2 Вторая часть размера.
     * @return bool True если части частично совпадают, иначе false.
     */
    private function isPartialMatch($part1, $part2)
    {
        try {
            if ($part1 === $part2) {
                return true;
            }

            $value1 = $this->parseFraction($part1);
            $value2 = $this->parseFraction($part2);

            return abs($value1 - $value2) < 0.001;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Парсинг строки с дробью или числом в float.
     *
     * @param string $str Строка для парсинга.
     * @return float Распарсенное число.
     * @throws \Exception Если строка не является допустимым числом.
     */
    private function parseFraction($str)
    {
        if (strpos($str, '/') !== false) {
            list($numerator, $denominator) = explode('/', $str);
            $numerator = trim($numerator);
            $denominator = trim($denominator);

            if (!is_numeric($numerator) || !is_numeric($denominator) || $denominator == 0) {
                throw new \Exception("Недопустимая дробь: $str");
            }

            return floatval($numerator) / floatval($denominator);
        }

        if (!is_numeric($str)) {
            throw new \Exception("Недопустимое число: $str");
        }

        return floatval($str);
    }

    /**
     * Получение оценки для совпадения двух частей размера.
     *
     * @param string $part1 Первая часть размера.
     * @param string $part2 Вторая часть размера.
     * @return int Оценка части.
     */
    private function getPartScore($part1, $part2)
    {
        try {
            if ($part1 === $part2) {
                return strlen($part1) * 2;
            }
            return strlen($part1);
        } catch (\Exception $e) {
            Logger::log("Ошибка в getPartScore(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Нормализация строки размера для лучшего сравнения.
     *
     * @param string $size Строка размера для нормализации.
     * @return string Нормализованная строка размера.
     */
    private function normalizeSize($size)
    {
        try {
            $normalized = preg_replace('/[^a-zA-Z0-9\-\/\s]/', '', $size);
            $normalized = strtolower($normalized);

            return trim($normalized);
        } catch (\Exception $e) {
            Logger::log("Ошибка в normalizeSize(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Извлечение части размера из названия элемента.
     *
     * @param string $str Название элемента.
     * @return string Извлечённая часть размера.
     */
    private function extractSizePart($str)
    {
        try {
            foreach ($this->mainSeparators as $separator) {
                $parts = explode($separator, $str);
                if (count($parts) > 1) {
                    return trim(end($parts));
                }
            }

            foreach ($this->additionalSeparators as $separator) {
                $parts = explode($separator, $str);
                if (count($parts) > 1) {
                    return trim(implode($separator, array_slice($parts, -2)));
                }
            }

            return trim($str);
        } catch (\Exception $e) {
            Logger::log("Ошибка в extractSizePart(): " . $e->getMessage(), "ERROR");
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

            // Подготовка массового обновления с использованием CASE WHEN
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
            //Logger::log("Успешно обновлено " . count($this->updateCollection) . " записей в базе данных.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateDatabase(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }
}