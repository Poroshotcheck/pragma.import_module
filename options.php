<?php

$module_id = 'pragma.import_module'; // Замените на ID вашего модуля

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;

Loc::loadMessages(__FILE__);

if ($APPLICATION->GetGroupRight($module_id) < "S") {
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
}

if (!Loader::includeModule('iblock')) {
    ShowError(Loc::getMessage("IBLOCK_MODULE_NOT_INSTALLED"));
    return;
}

// Подключение файла с настройками по умолчанию
require_once __DIR__ . '/default_option.php';

// Обработка отправки формы
if ($_SERVER["REQUEST_METHOD"] == "POST" && strlen($_POST["Update"]) > 0 && check_bitrix_sessid()) {
    Option::set($module_id, "IBLOCK_ID_IMPORT", $_POST["IBLOCK_ID_IMPORT"]);
    Option::set($module_id, "IBLOCK_ID_CATALOG", $_POST["IBLOCK_ID_CATALOG"]);
    Option::set($module_id, "AGENT_TIME", $_POST["AGENT_TIME"]);

    // Проверяем наличие данных о сопоставлениях
    if (isset($_POST["SECTION_MAPPINGS"]) && is_array($_POST["SECTION_MAPPINGS"])) {
        $sectionMappings = $_POST["SECTION_MAPPINGS"];

        // Добавляем DEPTH_LEVEL в SECTION_MAPPINGS (Можно оптимизировать, используя AJAX)
        foreach ($sectionMappings as &$mapping) {
            if (isset($mapping['SECTION_ID'])) {
                $section = \Bitrix\Iblock\SectionTable::getRowById($mapping['SECTION_ID']);
                if ($section) {
                    $mapping['DEPTH_LEVEL'] = $section['DEPTH_LEVEL'];
                }
            }
        }
        file_put_contents(__DIR__ . "/sectionMappings.txt", print_r($sectionMappings, true));
        Option::set($module_id, "SECTION_MAPPINGS", serialize($sectionMappings));
    }

    LocalRedirect($APPLICATION->GetCurPage() . "?mid=" . urlencode($module_id) . "&lang=" . LANGUAGE_ID);
}

// Получение текущих значений настроек
$iblockIdImport = Option::get($module_id, "IBLOCK_ID_IMPORT", $pragma_import_module_default_option['IBLOCK_ID_IMPORT']);
$iblockIdCatalog = Option::get($module_id, "IBLOCK_ID_CATALOG", $pragma_import_module_default_option['IBLOCK_ID_CATALOG']);
$agentTime = Option::get($module_id, "AGENT_TIME", $pragma_import_module_default_option['AGENT_TIME']);
$sectionMappings = unserialize(Option::get($module_id, "SECTION_MAPPINGS"));


// Получение списка инфоблоков
$arIblocks = [];
$rsIblocks = IblockTable::getList([
    'select' => ['ID', 'NAME'],
    'order' => ['NAME' => 'ASC']
]);
while ($arIblock = $rsIblocks->fetch()) {
    $arIblocks[$arIblock["ID"]] = $arIblock["NAME"];
}

$aTabs = [
    [
        "DIV" => "edit1",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SETTINGS"),
        "ICON" => "pragma_import_module_settings",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SETTINGS"),
    ],
];

$tabControl = new CAdminTabControl("tabControl", $aTabs);

$tabControl->Begin();
?>

<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post(); ?>
    <? $tabControl->BeginNextTab(); ?>

    <!-- Инфоблок для импорта -->
    <tr>
        <td width="40%">
            <label for="IBLOCK_ID_IMPORT"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_IBLOCK_IMPORT") ?>:</label>
        </td>
        <td width="60%">
            <select name="IBLOCK_ID_IMPORT" id="IBLOCK_ID_IMPORT">
                <option value=""><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SELECT_IBLOCK") ?></option>
                <? foreach ($arIblocks as $id => $name): ?>
                    <option value="<?= $id ?>" <? if ($iblockIdImport == $id)
                          echo "selected"; ?>><?= $name ?></option>
                <? endforeach; ?>
            </select>
        </td>
    </tr>

    <!-- Инфоблок каталога -->
    <tr>
        <td width="40%">
            <label for="IBLOCK_ID_CATALOG"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_IBLOCK_CATALOG") ?>:</label>
        </td>
        <td width="60%">
            <select name="IBLOCK_ID_CATALOG" id="IBLOCK_ID_CATALOG">
                <option value=""><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SELECT_IBLOCK") ?></option>
                <? foreach ($arIblocks as $id => $name): ?>
                    <option value="<?= $id ?>" <? if ($iblockIdCatalog == $id)
                          echo "selected"; ?>><?= $name ?></option>
                <? endforeach; ?>
            </select>
        </td>
    </tr>

    <!-- Время запуска агента -->
    <tr>
        <td width="40%">
            <label for="AGENT_TIME"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_TIME") ?>:</label>
        </td>
        <td width="60%">
            <input type="time" name="AGENT_TIME" id="AGENT_TIME" value="<?= $agentTime ?>">
        </td>
    </tr>

    <!-- Множественная настройка сопоставления разделов и свойств -->
    <tr>
        <td colspan="2">
            <div id="section_mappings_container">
                <?php
                if (empty($sectionMappings)) {
                    $sectionMappings = [['SECTION_ID' => '', 'PROPERTIES' => ['']]];
                }
                foreach ($sectionMappings as $index => $mapping):
                    ?>
                    <div class="section-mapping">
                        <select name="SECTION_MAPPINGS[<?= $index ?>][SECTION_ID]" class="section-select">
                            <?= $mapping["SECTION_ID"] ?>
                            <option value=""><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SELECT_SECTION") ?></option>
                            <?php
                            // Опции будут загружены через AJAX
                            ?>
                        </select>
                        <div class="properties-container">
                            <?php foreach ($mapping['PROPERTIES'] as $propIndex => $property): ?>
                                <div class="property">
                                    <input type="text" name="SECTION_MAPPINGS[<?= $index ?>][PROPERTIES][]"
                                        value="<?= htmlspecialcharsbx($property) ?>">
                                    <button type="button"
                                        onclick="removeProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button"
                            onclick="addProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_PROPERTY") ?></button>
                        <button type="button"
                            onclick="removeMapping(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addMapping()"
                class="add-mapping-button"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_MAPPING") ?></button>
        </td>
    </tr>

    <? $tabControl->Buttons(); ?>
    <input type="submit" name="Update" value="<?= Loc::getMessage("MAIN_SAVE") ?>"
        title="<?= Loc::getMessage("MAIN_OPT_SAVE_TITLE") ?>" class="adm-btn-save">
    <input type="reset" name="reset" id="resetButton" value="<?= Loc::getMessage("MAIN_RESET") ?>">
    <? $tabControl->End(); ?>
</form>

<template id="section-mapping-template">
    <div class="section-mapping">
        <select name="SECTION_MAPPINGS[{index}][SECTION_ID]" class="section-select">
            <option value=""><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SELECT_SECTION") ?></option>
        </select>
        <div class="properties-container">
            <div class="property">
                <input type="text" name="SECTION_MAPPINGS[{index}][PROPERTIES][]">
                <button type="button"
                    onclick="removeProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?></button>
            </div>
        </div>
        <button type="button"
            onclick="addProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_PROPERTY") ?></button>
        <button type="button"
            onclick="removeMapping(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?></button>
    </div>
</template>

<script>
    function addMapping() {
        const template = document.getElementById('section-mapping-template').content.cloneNode(true);
        const container = document.getElementById('section_mappings_container');
        const index = container.children.length;

        // Обновляем индексы
        template.querySelector('select').name = `SECTION_MAPPINGS[${index}][SECTION_ID]`;
        template.querySelectorAll('input').forEach(input => {
            input.name = `SECTION_MAPPINGS[${index}][PROPERTIES][]`;
        });

        container.appendChild(template);

        // Загружаем опции для нового select
        updateSectionOptions(document.getElementById('IBLOCK_ID_CATALOG').value, template.querySelector('select'));
    }

    function removeMapping(button) {
        button.closest('.section-mapping').remove();
    }

    function addProperty(button) {
        var container = button.closest('.section-mapping').querySelector('.properties-container');
        var newProperty = document.createElement('div');
        newProperty.className = 'property';
        newProperty.innerHTML = `
            <input type="text" name="">
            <button type="button" onclick="removeProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?></button>
        `;
        container.appendChild(newProperty);

        // Обновляем имя поля
        var sectionSelect = button.closest('.section-mapping').querySelector('select');
        var sectionIndex = sectionSelect.name.match(/\[(.*?)\]/)[1];
        newProperty.querySelector('input').name = `SECTION_MAPPINGS[${sectionIndex}][PROPERTIES][]`;
    }

    function removeProperty(button) {
        button.closest('.property').remove();
    }

    // Функция для обновления списка разделов
    function updateSectionOptions(iblockId, selectElement = null, selectedSectionId = null) {
        if (!iblockId) return;

        fetch(`/local/modules/pragma.import_module/lib/ajax.php?IBLOCK_ID=${iblockId}`)
            .then(response => response.text())
            .then(html => {
                const selectsToUpdate = selectElement ? [selectElement] : document.querySelectorAll('.section-select');
                selectsToUpdate.forEach(select => {
                    select.innerHTML = html;

                    // Установка атрибута selected на нужный option
                    if (selectedSectionId) {
                        const option = select.querySelector(`option[value="${selectedSectionId}"]`);
                        if (option) {
                            option.selected = true;
                        }
                    }
                });
            })
            .catch(error => console.error('Error fetching section options:', error));
    }

    // Загрузка списка разделов при загрузке страницы
    document.addEventListener('DOMContentLoaded', function () {
        const iblockIdCatalog = document.getElementById('IBLOCK_ID_CATALOG').value;
        if (iblockIdCatalog) {
            <?php foreach ($sectionMappings as $index => $mapping): ?>
                updateSectionOptions(iblockIdCatalog, document.querySelector(`select[name="SECTION_MAPPINGS[<?= $index ?>][SECTION_ID]"]`), <?= $mapping['SECTION_ID'] ?>);
            <?php endforeach; ?>
        }
    });

    // Обновление списка разделов при изменении инфоблока каталога
    document.getElementById('IBLOCK_ID_CATALOG').addEventListener('change', function () {
        const iblockId = this.value;
        updateSectionOptions(iblockId);
    });

    // Обработчик события для кнопки "Сбросить"
    document.getElementById('resetButton').addEventListener('click', function (event) {
        event.preventDefault();

        // Устанавливаем значения по умолчанию из $pragma_import_module_default_option
        document.getElementById('IBLOCK_ID_IMPORT').value = '<?= $pragma_import_module_default_option['IBLOCK_ID_IMPORT'] ?>';
        document.getElementById('IBLOCK_ID_CATALOG').value = '<?= $pragma_import_module_default_option['IBLOCK_ID_CATALOG'] ?>';
        document.getElementById('AGENT_TIME').value = '<?= $pragma_import_module_default_option['AGENT_TIME'] ?>';

        // Очищаем контейнер сопоставлений разделов
        document.getElementById('section_mappings_container').innerHTML = '';

        // Добавляем одно пустое сопоставление разделов
        addMapping();
    });
</script>

<style>
    /* Стили для кнопок */
    .section-mapping button {
        background-color: #007bff;
        /* Цвет фона */
        border: none;
        /* Убираем границу */
        color: white;
        /* Цвет текста */
        padding: 5px 10px;
        /* Отступы */
        text-align: center;
        /* Выравнивание текста */
        text-decoration: none;
        /* Убираем подчеркивание */
        display: inline-block;
        /* Отображение как блочный элемент */
        font-size: 14px;
        /* Размер шрифта */
        margin: 4px 2px;
        /* Отступы */
        cursor: pointer;
        /* Курсор в виде руки */
        border-radius: 5px;
        /* Скругленные углы */
    }

    .add-mapping-button {
        background-color: #28a745;
        /* Зеленый цвет фона */
        border: none;
        /* Убираем границу */
        color: white;
        /* Цвет текста */
        padding: 5px 10px;
        /* Отступы */
        text-align: center;
        /* Выравнивание текста */
        text-decoration: none;
        /* Убираем подчеркивание */
        display: inline-block;
        /* Отображение как блочный элемент */
        font-size: 14px;
        /* Размер шрифта */
        margin: 4px 2px;
        /* Отступы */
        cursor: pointer;
        /* Курсор в виде руки */
        border-radius: 5px;
        /* Скругленные углы */
    }

    .add-mapping-button:hover {
        background-color: #218838;
        /* Темно-зеленый цвет фона при наведении */
    }

    .section-mapping button:hover {
        background-color: #0056b3;
        /* Цвет фона при наведении */
    }

    /* Стили для кнопки "Удалить" (крестик) */
    .section-mapping button[onclick="removeProperty(this)"] {
        background-color: #dc3545;
        /* Красный цвет фона */
    }

    .section-mapping button[onclick="removeProperty(this)"]:hover {
        background-color: #c82333;
        /* Темно-красный цвет фона при наведении */
    }

    #section_mappings_container button[onclick^="add"],
    form button[onclick^="add"] {
        background-color: #28a745;
        /* Зеленый цвет фона */
    }

    #section_mappings_container button[onclick^="add"]:hover,
    form button[onclick^="add"]:hover {
        background-color: #218838;
        /* Темно-зеленый цвет фона при наведении */
    }
</style>