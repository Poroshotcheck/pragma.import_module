<?php
define("NO_KEEP_STATISTIC", true);
define("NO_AGENT_CHECK", true);
define('PUBLIC_AJAX_MODE', true);

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/ModuleDataTable.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/CacheHelper.php");
require_once($_SERVER["DOCUMENT_ROOT"] . "/local/modules/pragma.importmodule/lib/SectionHelper.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Iblock\SectionTable;
use Bitrix\Catalog\ProductTable;
use Pragma\ImportModule\ModuleDataTable;
use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\CacheHelper;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

// Подключаем необходимые модули
if (!Loader::includeModule('iblock')) {
    die('IBlock module is not installed');
}
if (!Loader::includeModule('catalog')) {
    die('Catalog module is not installed');
}
if (!Loader::includeModule('pragma.importmodule')) {
    die('Pragma Import Module is not installed');
}

// Проверка прав доступа пользователя
global $APPLICATION;

require_once __DIR__ . '/install/version.php';
$moduleId = $arModuleVersion['MODULE_ID'];

if ($APPLICATION->GetGroupRight($moduleId) < "W") {
    echo 'Access denied';
    die();
}

// Основной код для обработки данных
$pragma_import_module_default_option = [
    'IBLOCK_ID_IMPORT' => 0,
    'IBLOCK_ID_CATALOG' => 0,
    // Добавьте остальные опции по умолчанию, если они есть
];

$iblockIdImport = Option::get($moduleId, "IBLOCK_ID_IMPORT", $pragma_import_module_default_option['IBLOCK_ID_IMPORT']);
$iblockIdCatalog = Option::get($moduleId, "IBLOCK_ID_CATALOG", $pragma_import_module_default_option['IBLOCK_ID_CATALOG']);

$targetOffersIblockId = \CCatalogSKU::GetInfoByProductIBlock($iblockIdCatalog)['IBLOCK_ID'];

// Получаем действие
$action = $_GET['action'] ?? '';

// Если действие — получить поставщиков
if ($action === 'get_suppliers') {
    // Получаем список поставщиков
    $cacheSections = CacheHelper::getCachedSections($iblockIdImport);
    if (empty($cacheSections)) {
        $supplierSections = SectionTable::getList([
            'filter' => ['IBLOCK_ID' => $iblockIdImport],
            'select' => ['ID', 'NAME'],
        ])->fetchAll();
    } else {
        $supplierSections = [];
        foreach ($cacheSections as $cacheSection) {
            $supplierSections[] = [
                "ID" => $cacheSection["ID"],
                "NAME" => $cacheSection["NAME"],
            ];
        }
    }

    // Возвращаем данные в формате JSON
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($supplierSections);
    exit;
}

// Устанавливаем лимит на количество элементов на странице
$limit = 500;

// Получаем параметры фильтра из запроса
$filterParams = $_GET['filter'] ?? [];

// Получаем параметры сортировки
$allowedSortFields = [
    'ELEMENT_NAME',
    'CHAIN_TOGEZER',
    'SIZE_VALUE_ID',
    'COLOR_VALUE_ID',
    'SOURCE_SECTION_ID',
    'TARGET_SECTION_ID',
    // Добавьте другие поля, если необходимо
];

$sortField = isset($_GET['sort_field']) && in_array($_GET['sort_field'], $allowedSortFields) ? $_GET['sort_field'] : 'ELEMENT_NAME';
$sortOrder = isset($_GET['sort_order']) && in_array(strtoupper($_GET['sort_order']), ['ASC', 'DESC']) ? strtoupper($_GET['sort_order']) : 'ASC';

// Формируем фильтр для запроса данных
$dataFilter = [];

// Обрабатываем фильтры согласно предоставленной логике
if (isset($filterParams['TARGET_SECTION_ID']) && $filterParams['TARGET_SECTION_ID'] !== 'all') {
    if ($filterParams['TARGET_SECTION_ID'] === 'filled') {
        $dataFilter['!TARGET_SECTION_ID'] = 'a:0:{}';
    } elseif ($filterParams['TARGET_SECTION_ID'] === 'empty') {
        $dataFilter['TARGET_SECTION_ID'] = 'a:0:{}';
    }
}

if (isset($filterParams['CHAIN_TOGEZER']) && $filterParams['CHAIN_TOGEZER'] !== 'all') {
    if ($filterParams['CHAIN_TOGEZER'] === 'filled') {
        $dataFilter['!CHAIN_TOGEZER'] = false;
    } elseif ($filterParams['CHAIN_TOGEZER'] === 'empty') {
        $dataFilter['CHAIN_TOGEZER'] = false;
    }
}

if (isset($filterParams['COLOR_VALUE_ID']) && $filterParams['COLOR_VALUE_ID'] !== 'all') {
    if ($filterParams['COLOR_VALUE_ID'] === 'filled') {
        $dataFilter['!COLOR_VALUE_ID'] = false;
    } elseif ($filterParams['COLOR_VALUE_ID'] === 'empty') {
        $dataFilter['COLOR_VALUE_ID'] = false;
    }
}

if (isset($filterParams['SIZE_VALUE_ID']) && $filterParams['SIZE_VALUE_ID'] !== 'all') {
    if ($filterParams['SIZE_VALUE_ID'] === 'filled') {
        $dataFilter['!SIZE_VALUE_ID'] = false;
    } elseif ($filterParams['SIZE_VALUE_ID'] === 'empty') {
        $dataFilter['SIZE_VALUE_ID'] = false;
    }
}

// Фильтр по поставщику
if (isset($filterParams['SUPPLIER_ID']) && $filterParams['SUPPLIER_ID'] !== '') {
    $dataFilter['=SOURCE_SECTION_ID'] = $filterParams['SUPPLIER_ID'];
}

// Фильтр по "Существует в каталоге"
$existsInCatalogFilter = isset($filterParams['EXISTS_IN_CATALOG']) ? $filterParams['EXISTS_IN_CATALOG'] : 'all';

// Получаем текущую страницу для пагинации
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Определяем, нужно ли получать все элементы
$fetchAll = $existsInCatalogFilter !== 'all';

if ($fetchAll) {
    // Необходимо получить все элементы для применения фильтра "Существует в каталоге"
    $elementsResult = ModuleDataTable::getList([
        'filter' => $dataFilter,
        'order' => [$sortField => $sortOrder],
        'select' => ['*'],
    ]);

    $allElements = [];

    while ($element = $elementsResult->fetch()) {
        // Инициализируем свойство EXIST
        $element['EXIST'] = [
            'VALUE' => 'false',
            'TYPE' => '',
            'IBLOCK_ID' => '',
            'ID' => '',
        ];

        // Проверяем по ELEMENT_XML_ID в инфоблоке каталога и его торговых предложениях
        $catalogIblockId = $iblockIdCatalog;
        $offerIblockId = $targetOffersIblockId;

        // Ищем элемент в каталоге
        $catalogElement = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $catalogIblockId,
                'XML_ID' => $element['ELEMENT_XML_ID'],
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        )->Fetch();

        $existsInCatalog = false;

        if ($catalogElement) {
            // Элемент найден в каталоге
            $element['EXIST']['VALUE'] = 'true';
            $element['EXIST']['TYPE'] = ProductTable::TYPE_PRODUCT;
            $element['EXIST']['IBLOCK_ID'] = $catalogElement['IBLOCK_ID'];
            $element['EXIST']['ID'] = $catalogElement['ID'];
            $element['EXIST']['SECTIONS'] = [];

            // Получаем привязки к разделам
            $dbSections = \CIBlockElement::GetElementGroups($catalogElement['ID'], true);
            while ($section = $dbSections->Fetch()) {
                $element['EXIST']['SECTIONS'][] = "{$section['NAME']} ({$section['ID']})";
            }
            $existsInCatalog = true;
        } else {
            // Если не найден в каталоге, проверяем в торговых предложениях
            $offerElement = \CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => $offerIblockId,
                    'XML_ID' => $element['ELEMENT_XML_ID'],
                ],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'PROPERTY_CML2_LINK']
            )->Fetch();

            if ($offerElement) {
                // Элемент найден в торговых предложениях
                $element['EXIST']['VALUE'] = 'true';
                $element['EXIST']['TYPE'] = ProductTable::TYPE_OFFER;
                $element['EXIST']['IBLOCK_ID'] = $offerElement['IBLOCK_ID'];
                $element['EXIST']['ID'] = $offerElement['ID'];
                $element['EXIST']['SECTIONS'] = [];

                // Получаем родительский товар
                $parentId = $offerElement['PROPERTY_CML2_LINK_VALUE'];
                $parentElement = \CIBlockElement::GetByID($parentId)->Fetch();

                if ($parentElement) {
                    // Получаем привязки родителя к разделам
                    $dbSections = \CIBlockElement::GetElementGroups($parentElement['ID'], true);
                    while ($section = $dbSections->Fetch()) {
                        $element['EXIST']['SECTIONS'][] = "{$section['NAME']} ({$section['ID']})";
                    }
                }
                $existsInCatalog = true;
            }
        }

        // Применяем фильтр по "Существует в каталоге"
        if ($existsInCatalogFilter === 'yes' && !$existsInCatalog) {
            continue;
        } elseif ($existsInCatalogFilter === 'no' && $existsInCatalog) {
            continue;
        }

        // Получаем название поставщика по SOURCE_SECTION_ID из инфоблока импорта
        $supplierSection = SectionTable::getList([
            'filter' => ['=ID' => $element['SOURCE_SECTION_ID'], 'IBLOCK_ID' => $iblockIdImport],
            'select' => ['ID', 'NAME'],
        ])->fetch();
        $element['SUPPLIER'] = $supplierSection ? "{$supplierSection['NAME']} ({$supplierSection['ID']})" : "Не найден";

        // Получаем название раздела TARGET_SECTION_ID из инфоблока каталога
        if (!empty($element['TARGET_SECTION_ID']) && is_array($element['TARGET_SECTION_ID'])) {
            $targetSectionId = $element['TARGET_SECTION_ID'][0];
            $targetSection = SectionTable::getList([
                'filter' => ['=ID' => $targetSectionId, 'IBLOCK_ID' => $iblockIdCatalog],
                'select' => ['ID', 'NAME'],
            ])->fetch();
            $element['TARGET_SECTION'] = $targetSection ? "{$targetSection['NAME']} ({$targetSection['ID']})" : "Не найден";
        } else {
            $element['TARGET_SECTION'] = "Не распределён";
        }

        $allElements[] = $element;
    }

    // Рассчитываем общее количество элементов после фильтрации
    $totalElements = count($allElements);

    // Реализуем пагинацию
    $elements = array_slice($allElements, $offset, $limit);
    $totalPages = ceil($totalElements / $limit);

} else {
    // Получаем элементы с лимитом и смещением
    $elementsResult = ModuleDataTable::getList([
        'filter' => $dataFilter,
        'order' => [$sortField => $sortOrder],
        'limit' => $limit,
        'offset' => $offset,
        'count_total' => true,
    ]);

    $totalElements = $elementsResult->getCount();
    $totalPages = ceil($totalElements / $limit);

    $elements = [];

    while ($element = $elementsResult->fetch()) {
        // Инициализируем свойство EXIST
        $element['EXIST'] = [
            'VALUE' => 'false',
            'TYPE' => '',
            'IBLOCK_ID' => '',
            'ID' => '',
        ];

        // Проверяем по ELEMENT_XML_ID в инфоблоке каталога и его торговых предложениях
        $catalogIblockId = $iblockIdCatalog;
        $offerIblockId = $targetOffersIblockId;

        // Ищем элемент в каталоге
        $catalogElement = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $catalogIblockId,
                'XML_ID' => $element['ELEMENT_XML_ID'],
            ],
            false,
            false,
            ['ID', 'IBLOCK_ID']
        )->Fetch();

        $existsInCatalog = false;

        if ($catalogElement) {
            // Элемент найден в каталоге
            $element['EXIST']['VALUE'] = 'true';
            $element['EXIST']['TYPE'] = ProductTable::TYPE_PRODUCT;
            $element['EXIST']['IBLOCK_ID'] = $catalogElement['IBLOCK_ID'];
            $element['EXIST']['ID'] = $catalogElement['ID'];
            $element['EXIST']['SECTIONS'] = [];

            // Получаем привязки к разделам
            $dbSections = \CIBlockElement::GetElementGroups($catalogElement['ID'], true);
            while ($section = $dbSections->Fetch()) {
                $element['EXIST']['SECTIONS'][] = "{$section['NAME']} ({$section['ID']})";
            }
            $existsInCatalog = true;
        } else {
            // Если не найден в каталоге, проверяем в торговых предложениях
            $offerElement = \CIBlockElement::GetList(
                [],
                [
                    'IBLOCK_ID' => $offerIblockId,
                    'XML_ID' => $element['ELEMENT_XML_ID'],
                ],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'PROPERTY_CML2_LINK']
            )->Fetch();

            if ($offerElement) {
                // Элемент найден в торговых предложениях
                $element['EXIST']['VALUE'] = 'true';
                $element['EXIST']['TYPE'] = ProductTable::TYPE_OFFER;
                $element['EXIST']['IBLOCK_ID'] = $offerElement['IBLOCK_ID'];
                $element['EXIST']['ID'] = $offerElement['ID'];
                $element['EXIST']['SECTIONS'] = [];

                // Получаем родительский товар
                $parentId = $offerElement['PROPERTY_CML2_LINK_VALUE'];
                $parentElement = \CIBlockElement::GetByID($parentId)->Fetch();

                if ($parentElement) {
                    // Получаем привязки родителя к разделам
                    $dbSections = \CIBlockElement::GetElementGroups($parentElement['ID'], true);
                    while ($section = $dbSections->Fetch()) {
                        $element['EXIST']['SECTIONS'][] = "{$section['NAME']} ({$section['ID']})";
                    }
                }
                $existsInCatalog = true;
            }
        }

        // Получаем название поставщика по SOURCE_SECTION_ID из инфоблока импорта
        $supplierSection = SectionTable::getList([
            'filter' => ['=ID' => $element['SOURCE_SECTION_ID'], 'IBLOCK_ID' => $iblockIdImport],
            'select' => ['ID', 'NAME'],
        ])->fetch();
        $element['SUPPLIER'] = $supplierSection ? "{$supplierSection['NAME']} ({$supplierSection['ID']})" : "Не найден";

        // Получаем название раздела TARGET_SECTION_ID из инфоблока каталога
        if (!empty($element['TARGET_SECTION_ID']) && is_array($element['TARGET_SECTION_ID'])) {
            $targetSectionId = $element['TARGET_SECTION_ID'][0];
            $targetSection = SectionTable::getList([
                'filter' => ['=ID' => $targetSectionId, 'IBLOCK_ID' => $iblockIdCatalog],
                'select' => ['ID', 'NAME'],
            ])->fetch();
            $element['TARGET_SECTION'] = $targetSection ? "{$targetSection['NAME']} ({$targetSection['ID']})" : "Не найден";
        } else {
            $element['TARGET_SECTION'] = "Не распределён";
        }

        $elements[] = $element;
    }
}

// Генерируем HTML таблицы
ob_start();
?>

<table>
    <tr>
        <th><a href="#" class="sort-link" data-field="ELEMENT_NAME" data-order="<?= ($sortField == 'ELEMENT_NAME' && $sortOrder == 'ASC') ? 'DESC' : 'ASC' ?>">Название<?= ($sortField == 'ELEMENT_NAME') ? ($sortOrder == 'ASC' ? ' ▲' : ' ▼') : '' ?></a></th>
        <th><a href="#" class="sort-link" data-field="SOURCE_SECTION_ID" data-order="<?= ($sortField == 'SOURCE_SECTION_ID' && $sortOrder == 'ASC') ? 'DESC' : 'ASC' ?>">Поставщик<?= ($sortField == 'SOURCE_SECTION_ID') ? ($sortOrder == 'ASC' ? ' ▲' : ' ▼') : '' ?></a></th>
        <th><a href="#" class="sort-link" data-field="TARGET_SECTION_ID" data-order="<?= ($sortField == 'TARGET_SECTION_ID' && $sortOrder == 'ASC') ? 'DESC' : 'ASC' ?>">Распределен в каталоге<?= ($sortField == 'TARGET_SECTION_ID') ? ($sortOrder == 'ASC' ? ' ▲' : ' ▼') : '' ?></a></th>
        <th><a href="#" class="sort-link" data-field="CHAIN_TOGEZER" data-order="<?= ($sortField == 'CHAIN_TOGEZER' && $sortOrder == 'ASC') ? 'DESC' : 'ASC' ?>">Связан<?= ($sortField == 'CHAIN_TOGEZER') ? ($sortOrder == 'ASC' ? ' ▲' : ' ▼') : '' ?></a></th>
        <th><a href="#" class="sort-link" data-field="SIZE_VALUE_ID" data-order="<?= ($sortField == 'SIZE_VALUE_ID' && $sortOrder == 'ASC') ? 'DESC' : 'ASC' ?>">Размер<?= ($sortField == 'SIZE_VALUE_ID') ? ($sortOrder == 'ASC' ? ' ▲' : ' ▼') : '' ?></a></th>
        <th><a href="#" class="sort-link" data-field="COLOR_VALUE_ID" data-order="<?= ($sortField == 'COLOR_VALUE_ID' && $sortOrder == 'ASC') ? 'DESC' : 'ASC' ?>">Цвет<?= ($sortField == 'COLOR_VALUE_ID') ? ($sortOrder == 'ASC' ? ' ▲' : ' ▼') : '' ?></a></th>
        <th>Существует в каталоге</th>
    </tr>
    <?php foreach ($elements as $element): ?>
        <tr>
            <td><?= htmlspecialchars($element['ELEMENT_NAME']) ?></td>
            <td><?= htmlspecialchars($element['SUPPLIER']) ?></td>
            <td><?= htmlspecialchars($element['TARGET_SECTION']) ?></td>
            <td><?= htmlspecialchars($element['CHAIN_TOGEZER']) ?></td>
            <td><?= htmlspecialchars($element['SIZE_VALUE_ID']) ?></td>
            <td><?= htmlspecialchars($element['COLOR_VALUE_ID']) ?></td>
            <td>
                <?php if ($element['EXIST']['VALUE'] === 'true'): ?>
                    Тип: <?= $element['EXIST']['TYPE'] ?><br>
                    Инфоблок ID: <?= $element['EXIST']['IBLOCK_ID'] ?><br>
                    Элемент ID: <?= $element['EXIST']['ID'] ?><br>
                    Разделы: <?= implode(', ', $element['EXIST']['SECTIONS']) ?>
                <?php else: ?>
                    Не существует
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php
// Реализуем пагинацию
if ($totalPages > 1):
    ?>
    <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p == $page): ?>
                <strong><?= $p ?></strong>
            <?php else: ?>
                <a href="#" data-page="<?= $p ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php
endif;

$content = ob_get_clean();

// Выводим HTML контент
echo $content;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
?>