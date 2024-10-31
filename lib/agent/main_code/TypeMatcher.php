<?php
namespace Pragma\ImportModule\Agent\MainCode;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Highloadblock\HighloadBlockTable;
use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\Logger;

Loader::includeModule('highloadblock');

class TypeMatcher
{
    private $elements;
    private $mainSeparators = ['|'];
    private $additionalSeparators = ['/'];
    private $updateCollection = [];
    private $moduleId;
    private $hlblockId;
    private $existingTypes = [];

    public function __construct($moduleId)
    {
        $this->moduleId = $moduleId;
        $this->hlblockId = Option::get($this->moduleId, 'TYPE_HLB_ID');

        //Logger::log("Инициализация TypeMatcher с moduleId = {$this->moduleId} и hlblockId = {$this->hlblockId}");

        try {
            $this->loadElements();
            $this->loadExistingTypes();
        } catch (\Exception $e) {
            Logger::log("Ошибка при инициализации TypeMatcher: " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Загрузка элементов оптимизированным способом для уменьшения количества запросов к базе данных.
     */
    private function loadElements()
    {
        //Logger::log("Начало загрузки элементов в loadElements()");

        try {
            // Загрузка элементов с необходимыми полями
            $elementsResult = ModuleDataTable::getList([
                'select' => ['ID', 'ELEMENT_NAME', 'SIZE_VALUE_ID'],
                'filter' => [
                    '!TARGET_SECTION_ID' => 'a:0:{}',
                ],
            ]);

            $this->elements = [];

            while ($element = $elementsResult->fetch()) {
                $this->elements[] = $element;
            }

            //Logger::log("Успешно загружено " . count($this->elements) . " элементов.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в loadElements(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    /**
     * Загрузка существующих типов из Highload-блока для проверки на дубликаты.
     */
    private function loadExistingTypes()
    {
        //Logger::log("Начало загрузки существующих типов в loadExistingTypes()");

        try {
            $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
            if (!$hlblock) {
                throw new \Exception("Highload-блок с ID {$this->hlblockId} не найден.");
            }

            $entity = HighloadBlockTable::compileEntity($hlblock);
            $entityDataClass = $entity->getDataClass();

            $rsData = $entityDataClass::getList([
                'select' => ['UF_NAME', 'UF_XML_ID'],
            ]);

            $this->existingTypes = [];

            while ($item = $rsData->fetch()) {
                $typeValue = $item['UF_NAME']; // Предполагаем, что UF_NAME и UF_XML_ID одинаковы
                $this->existingTypes[$typeValue] = true;
            }

            //Logger::log("Успешно загружено " . count($this->existingTypes) . " существующих типов.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в loadExistingTypes(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    /**
     * Сопоставление типов и подготовка данных для обновления базы данных.
     */
    public function matchTypes()
    {
        //Logger::log("Начало сопоставления типов в matchTypes()");

        try {
            foreach ($this->elements as $element) {
                $typeValue = $this->extractType($element['ELEMENT_NAME'], $element['SIZE_VALUE_ID']);

                if (!empty($typeValue)) {
                    $this->updateCollection[] = [
                        'ID' => $element['ID'],
                        'TYPE_VALUE_ID' => $typeValue,
                    ];
                }
            }

            //Logger::log("Сопоставление типов завершено. Найдено соответствий: " . count($this->updateCollection));
        } catch (\Exception $e) {
            Logger::log("Ошибка в matchTypes(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    /**
     * Создание отсутствующих записей TYPE_VALUE_ID в Highload-блоке.
     */
    public function createMissingTypes()
    {
        //Logger::log("Начало создания отсутствующих типов в createMissingTypes()");

        try {
            // Собираем все уникальные TYPE_VALUE_ID из updateCollection
            $typeValues = array_unique(array_column($this->updateCollection, 'TYPE_VALUE_ID'));

            // Определяем, какие TYPE_VALUE_ID отсутствуют в HLB
            $missingTypes = [];
            foreach ($typeValues as $typeValue) {
                if (!isset($this->existingTypes[$typeValue])) {
                    $missingTypes[] = $typeValue;
                }
            }

            if (!empty($missingTypes)) {
                // Получаем entity data class для HLB
                $hlblock = HighloadBlockTable::getById($this->hlblockId)->fetch();
                $entity = HighloadBlockTable::compileEntity($hlblock);
                $entityDataClass = $entity->getDataClass();

                foreach ($missingTypes as $typeValue) {
                    $result = $entityDataClass::add([
                        'UF_NAME' => $typeValue,
                        'UF_XML_ID' => $typeValue,
                    ]);

                    if ($result->isSuccess()) {
                        // Добавляем в existingTypes, чтобы избежать повторного добавления
                        $this->existingTypes[$typeValue] = true;
                        Logger::log("Добавлен новый тип в HLB: {$typeValue}");
                    } else {
                        // Обработка ошибок
                        $errors = implode(", ", $result->getErrorMessages());
                        Logger::log("Ошибка при добавлении типа {$typeValue} в HLB: {$errors}", "ERROR");
                    }
                }
            } else {
                Logger::log("Все типы уже существуют в HLB. Новых типов для добавления нет.");
            }
        } catch (\Exception $e) {
            //Logger::log("Ошибка в createMissingTypes(): " . $e->getMessage(), "ERROR");
            throw $e;
        }

    }

    /**
     * Извлечение типа из названия элемента, используя те же разделители и SIZE_VALUE_ID.
     *
     * @param string $elementName Название элемента.
     * @param string $sizeValueId SIZE_VALUE_ID элемента.
     * @return string Извлечённый тип.
     */
    private function extractType($elementName, $sizeValueId)
    {
        try {
            // Если SIZE_VALUE_ID доступен, извлекаем тип до размера
            if (!empty($sizeValueId)) {
                // Используем основные разделители сначала
                foreach ($this->mainSeparators as $separator) {
                    if (strpos($elementName, $separator) !== false) {
                        $parts = explode($separator, $elementName);
                        // Удаляем часть размера из конца
                        $typeParts = array_slice($parts, 0, -1);
                        $type = implode($separator, $typeParts);
                        return trim($type);
                    }
                }

                // Используем дополнительные разделители, если основные не найдены
                foreach ($this->additionalSeparators as $separator) {
                    if (strpos($elementName, $separator) !== false) {
                        $parts = explode($separator, $elementName);
                        // Удаляем часть размера из конца
                        $typeParts = array_slice($parts, 0, -1);
                        $type = implode($separator, $typeParts);
                        return trim($type);
                    }
                }

                // Если разделители не найдены, возвращаем полное название как тип
                return trim($elementName);
            }

            // Если SIZE_VALUE_ID недоступен, используем полное ELEMENT_NAME как TYPE_VALUE_ID
            return trim($elementName);
        } catch (\Exception $e) {
            Logger::log("Ошибка в extractType() для элемента '{$elementName}': " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }

    /**
     * Обновление базы данных с сопоставленными типами.
     */
    public function updateDatabase()
    {
        //Logger::log("Начало обновления базы данных в updateDatabase()");

        if (empty($this->updateCollection)) {
            // Logger::log("Нет данных для обновления в базе данных.");
            return;
        }

        try {
            $connection = Application::getConnection();
            $sqlHelper = $connection->getSqlHelper();
            $tableName = ModuleDataTable::getTableName();

            // Подготовка массового обновления с использованием CASE WHEN
            $updateCases = [];
            foreach ($this->updateCollection as $element) {
                $id = intval($element['ID']);
                $typeValueId = $sqlHelper->forSql($element['TYPE_VALUE_ID']);
                $updateCases[] = "WHEN {$id} THEN '{$typeValueId}'";
            }

            $ids = array_map('intval', array_column($this->updateCollection, 'ID'));

            $updateSql = "
                UPDATE {$tableName}
                SET TYPE_VALUE_ID = CASE ID
                    " . implode(' ', $updateCases) . "
                END
                WHERE ID IN (" . implode(',', $ids) . ")
            ";

            $connection->queryExecute($updateSql);
            //Logger::log("Успешно обновлено " . count($this->updateCollection) . " записей в базе данных.");
        } catch (\Exception $e) {
            Logger::log("Ошибка в updateDatabase(): " . $e->getMessage(), "ERROR");
            throw $e;
        }
    }
}