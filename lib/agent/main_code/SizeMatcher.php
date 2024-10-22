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

        // Optimized data loading
        $this->loadElementsAndSizes();
    }

    /**
     * Load elements and sizes in an optimized way to reduce database queries.
     */
    private function loadElementsAndSizes()
    {
        // Step 1: Load elements with necessary fields only
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

            // Collect all unique normalized sizes from elements
            if (!empty($normalizedSize)) {
                $normalizedElementSizes[$normalizedSize] = true;
            }
        }

        // Step 2: Load sizes from Highload Block and build a mapping
        $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
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
    }

    public function matchSizes()
    {
        foreach ($this->elements as $element) {
            $normalizedElementSize = $element['NORMALIZED_SIZE'];

            if (empty($normalizedElementSize)) {
                continue;
            }

            // Attempt to find an exact match in the size map
            if (isset($this->sizeMap[$normalizedElementSize])) {
                $bestMatch = $this->selectBestMatch($element['NORMALIZED_SIZE'], $this->sizeMap[$normalizedElementSize]);

                if ($bestMatch !== null) {
                    $this->updateCollection[] = [
                        'ID' => $element['ID'],
                        'SIZE_VALUE_ID' => $bestMatch['UF_XML_ID'],
                    ];
                    continue;
                }
            }

            // If no exact match, attempt to find the best partial match
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
    }

    /**
     * Select the best match from multiple sizes with the same normalized size.
     *
     * @param string $elementSize The normalized size from the element.
     * @param array $dbSizes The array of sizes from the database.
     * @return array|null The best matching size data or null.
     */
    private function selectBestMatch($elementSize, $dbSizes)
    {
        // Since sizes are normalized, we can select the first one
        return $dbSizes[0];
    }

    /**
     * Calculate a match score between two size strings.
     *
     * @param string $size1 The first size string.
     * @param string $size2 The second size string.
     * @return int The match score.
     */
    private function calculateMatchScore($size1, $size2)
    {
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
    }

    /**
     * Check if two size parts partially match.
     *
     * @param string $part1 The first size part.
     * @param string $part2 The second size part.
     * @return bool True if parts partially match, false otherwise.
     */
    private function isPartialMatch($part1, $part2)
    {
        if ($part1 === $part2) {
            return true;
        }

        try {
            $value1 = $this->parseFraction($part1);
            $value2 = $this->parseFraction($part2);

            return abs($value1 - $value2) < 0.001;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Parse a fraction or number string into a float.
     *
     * @param string $str The string to parse.
     * @return float The parsed number.
     * @throws \Exception If the string is not a valid number.
     */
    private function parseFraction($str)
    {
        if (strpos($str, '/') !== false) {
            list($numerator, $denominator) = explode('/', $str);
            $numerator = trim($numerator);
            $denominator = trim($denominator);

            if (!is_numeric($numerator) || !is_numeric($denominator) || $denominator == 0) {
                throw new \Exception("Invalid fraction: $str");
            }

            return floatval($numerator) / floatval($denominator);
        }

        if (!is_numeric($str)) {
            throw new \Exception("Invalid number: $str");
        }

        return floatval($str);
    }

    /**
     * Get the score for matching two size parts.
     *
     * @param string $part1 The first size part.
     * @param string $part2 The second size part.
     * @return int The part score.
     */
    private function getPartScore($part1, $part2)
    {
        if ($part1 === $part2) {
            return strlen($part1) * 2;
        }
        return strlen($part1);
    }

    /**
     * Normalize a size string for better comparison.
     *
     * @param string $size The size string to normalize.
     * @return string The normalized size string.
     */
    private function normalizeSize($size)
    {
        $normalized = preg_replace('/[^a-zA-Z0-9\-\/\s]/', '', $size);
        $normalized = strtolower($normalized);

        return trim($normalized);
    }

    /**
     * Extract the size part from the element name.
     *
     * @param string $str The element name.
     * @return string The extracted size part.
     */
    private function extractSizePart($str)
    {
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
    }

    public function updateDatabase()
    {
        if (empty($this->updateCollection)) {
            return;
        }

        $connection = Application::getConnection();
        $sqlHelper = $connection->getSqlHelper();
        $tableName = ModuleDataTable::getTableName();

        // Prepare the bulk update using CASE WHEN
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