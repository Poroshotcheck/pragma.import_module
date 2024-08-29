<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;

$module_id = 'pragma.import_module';
Loc::loadMessages(__FILE__);

if ($APPLICATION->GetGroupRight($module_id) < "S") {
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
}

Loader::includeModule($module_id);
Loader::includeModule('iblock');

// Подключение файла с настройками по умолчанию
require_once __DIR__ . '/default_option.php';
// Подключение класса Logger
require_once __DIR__ . '/lib/Logger.php';

// Инициализация логгера
$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.import_module/logs/import.log";
\Pragma\ImportModule\Logger::init($logFile);

// Проверка наличия агентов
$checkAgentId = Option::get($module_id, "CHECK_AGENT_ID", 0);
$importAgentId = Option::get($module_id, "IMPORT_AGENT_ID", 0);

$agentExists = true;

// Проверка агента CheckAgent
if (!$checkAgentId || !\CAgent::GetByID($checkAgentId)->Fetch()) {
    $checkAgentId = \CAgent::AddAgent(
        "\\Pragma\\ImportModule\\Agent\\CheckAgent::run();",
        $module_id,
        "N",
        300,
        "",
        "Y",
        date("d.m.Y H:i:s"),
        100
    );
    Option::set($module_id, "CHECK_AGENT_ID", $checkAgentId);
    \CAgent::Update($checkAgentId, array("ACTIVE" => "N"));

    if (!$checkAgentId) {
        $agentExists = false;
        \Pragma\ImportModule\Logger::log("Ошибка создания агента CheckAgent", "ERROR");
        CAdminMessage::ShowMessage(Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_CREATION_ERROR"));
    }
}

// Проверка агента ImportAgent
if (!$importAgentId || !\CAgent::GetByID($importAgentId)->Fetch()) {
    $importAgentId = \CAgent::AddAgent(
        "\\Pragma\\ImportModule\\Agent\\ImportAgent::run();",
        $module_id,
        "N",
        86400,
        "",
        "Y",
        date("d.m.Y H:i:s", time() + 86400),
        100
    );
    Option::set($module_id, "IMPORT_AGENT_ID", $importAgentId);
    \CAgent::Update($importAgentId, array("ACTIVE" => "N")); // Деактивируем агент после создания

    if (!$importAgentId) {
        $agentExists = false;
        \Pragma\ImportModule\Logger::log("Ошибка создания агента ImportAgent", "ERROR");
        CAdminMessage::ShowMessage(Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_CREATION_ERROR"));
    }
}

// Синхронизация настроек
if ($agentExists) {
    $importAgentInfo = \CAgent::GetByID($importAgentId)->Fetch();
    Option::set($module_id, "AGENT_INTERVAL", $importAgentInfo['AGENT_INTERVAL']);
    Option::set($module_id, "AGENT_NEXT_EXEC", $importAgentInfo['NEXT_EXEC']);
    Option::set($module_id, "AUTO_MODE", $importAgentInfo['ACTIVE'] === "Y" ? "Y" : "N");

    // Сохраняем актуальные данные агента в переменные
    $agentInterval = $importAgentInfo['AGENT_INTERVAL'];
    $agentNextExec = $importAgentInfo['NEXT_EXEC'];
} else {
    CAdminMessage::ShowMessage(Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_NOT_FOUND"));
}

// Функция для получения HTML-кода опций разделов для select
function getSectionOptionsHtml($iblockId, $selectedId = null)
{
    if (empty($iblockId)) {
        return '';
    }

    $rsSections = SectionTable::getList([
        'filter' => ['IBLOCK_ID' => $iblockId],
        'select' => ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL'],
        'order' => ['LEFT_MARGIN' => 'ASC']
    ]);

    $sections = [];
    while ($section = $rsSections->fetch()) {
        $sections[$section['ID']] = $section;
    }

    // Строим дерево разделов
    $tree = [];
    foreach ($sections as &$section) {
        if ($section['IBLOCK_SECTION_ID'] && isset($sections[$section['IBLOCK_SECTION_ID']])) {
            $sections[$section['IBLOCK_SECTION_ID']]['CHILDREN'][] = &$section;
        } else {
            $tree[] = &$section;
        }
    }

    return buildSectionOptions($tree, $selectedId);
}

// Функция для рекурсивного построения опций разделов
function buildSectionOptions($sections, $selectedId = null, $level = 0)
{
    $result = '';
    foreach ($sections as $section) {
        $selected = ($section['ID'] == $selectedId) ? 'selected' : '';
        $result .= '<option value="' . $section['ID'] . '" ' . $selected . '>'
            . str_repeat(" ", $level * 3) . htmlspecialcharsbx($section['NAME'])
            . '</option>';
        if (!empty($section['CHILDREN'])) {
            $result .= buildSectionOptions($section['CHILDREN'], $selectedId, $level + 1);
        }
    }
    return $result;
}

// Обработка отправки формы
$request = Application::getInstance()->getContext()->getRequest(); // Получение объекта запроса
if ($request->isPost() && strlen($request->getPost("Update")) > 0 && check_bitrix_sessid()) {
    // Получение данных из запроса безопасным способом
    $iblockIdImport = intval($request->getPost("IBLOCK_ID_IMPORT"));
    $iblockIdCatalog = intval($request->getPost("IBLOCK_ID_CATALOG"));
    $autoMode = $request->getPost("AUTO_MODE") ? "Y" : "N";
    $delayTime = intval($request->getPost("DELAY_TIME"));
    $agentInterval = intval($request->getPost("AGENT_INTERVAL"));
    $agentNextExec = htmlspecialcharsbx($request->getPost("AGENT_NEXT_EXEC")); // Экранирование
    $sectionMappings = $request->getPost("SECTION_MAPPINGS");

    // Сохранение настроек
    Option::set($module_id, "IBLOCK_ID_IMPORT", $iblockIdImport);
    Option::set($module_id, "IBLOCK_ID_CATALOG", $iblockIdCatalog);
    Option::set($module_id, "AUTO_MODE", $autoMode);

    if ($autoMode === "Y") {
        Option::set($module_id, "DELAY_TIME", $delayTime);
    } else {
        Option::set($module_id, "AGENT_INTERVAL", $agentInterval);
        Option::set($module_id, "AGENT_NEXT_EXEC", $agentNextExec);
    }

    // Обработка сопоставлений разделов
    if (is_array($sectionMappings)) {
        foreach ($sectionMappings as &$mapping) {
            if (isset($mapping['SECTION_ID'])) {
                $mapping['SECTION_ID'] = intval($mapping['SECTION_ID']); // Приведение к целому числу
                $section = \Bitrix\Iblock\SectionTable::getRowById($mapping['SECTION_ID']);
                if ($section) {
                    $mapping['DEPTH_LEVEL'] = intval($section['DEPTH_LEVEL']); // Приведение к целому числу
                }
            }
            // Экранирование свойств
            if (isset($mapping['PROPERTIES']) && is_array($mapping['PROPERTIES'])) {
                foreach ($mapping['PROPERTIES'] as &$property) {
                    $property = htmlspecialcharsbx($property); // Экранирование HTML
                }
            }
        }
        Option::set($module_id, "SECTION_MAPPINGS", serialize($sectionMappings));
    }

    // Обновляем агент
    if ($importAgentId > 0) {
        $agentFields = [
            "ACTIVE" => $autoMode === "Y" ? "N" : "Y",
        ];

        $agentFields["AGENT_INTERVAL"] = intval($_POST["AGENT_INTERVAL"]);
        $agentNextExec = $_POST["AGENT_NEXT_EXEC"];

        if (!empty($agentNextExec) && checkdate(date('m', strtotime($agentNextExec)), date('d', strtotime($agentNextExec)), date('Y', strtotime($agentNextExec)))) {
            $agentNextExec = date('d.m.Y H:i:s', strtotime($agentNextExec));
            $agentFields["NEXT_EXEC"] = $agentNextExec;
        }

        $updateResult = \CAgent::Update($importAgentId, $agentFields);

        if (!$updateResult) {
            \Pragma\ImportModule\Logger::log("Ошибка обновления агента: " . $importAgentId . " - " . $APPLICATION->GetException(), "ERROR");
            echo "<div class='adm-info-message'>" . Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_UPDATE_ERROR") . ": " . $APPLICATION->GetException() . "</div>";
        }
    }

    // Записываем все настройки модуля в JSON-файл
    $settings = [
        'IBLOCK_ID_IMPORT' => Option::get($module_id, "IBLOCK_ID_IMPORT"),
        'IBLOCK_ID_CATALOG' => Option::get($module_id, "IBLOCK_ID_CATALOG"),
        'AUTO_MODE' => Option::get($module_id, "AUTO_MODE"), // Исправлено отображение AUTO_MODE
        'DELAY_TIME' => Option::get($module_id, "DELAY_TIME"),
        'AGENT_INTERVAL' => Option::get($module_id, "AGENT_INTERVAL"),
        'AGENT_NEXT_EXEC' => Option::get($module_id, "AGENT_NEXT_EXEC"),
        'SECTION_MAPPINGS' => unserialize(Option::get($module_id, "SECTION_MAPPINGS")),
        'CHECK_AGENT_ID' => Option::get($module_id, "CHECK_AGENT_ID"), // Добавлено ID агента CheckAgent
        'IMPORT_AGENT_ID' => Option::get($module_id, "IMPORT_AGENT_ID"), // Добавлено ID агента ImportAgent
    ];

    $logFilePath = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.import_module/logs/settings.json";
    file_put_contents($logFilePath, json_encode($settings, JSON_PRETTY_PRINT));

    LocalRedirect($APPLICATION->GetCurPage() . "?mid=" . urlencode($module_id) . "&lang=" . LANGUAGE_ID);
}

// Получение текущих значений настроек
$iblockIdImport = Option::get($module_id, "IBLOCK_ID_IMPORT", $pragma_import_module_default_option['IBLOCK_ID_IMPORT']);
$iblockIdCatalog = Option::get($module_id, "IBLOCK_ID_CATALOG", $pragma_import_module_default_option['IBLOCK_ID_CATALOG']);
$autoMode = Option::get($module_id, "AUTO_MODE", $pragma_import_module_default_option['AUTO_MODE']);
$delayTime = Option::get($module_id, "DELAY_TIME", $pragma_import_module_default_option['DELAY_TIME']);
$agentInterval = Option::get($module_id, "AGENT_INTERVAL", $pragma_import_module_default_option['AGENT_INTERVAL']);
$agentNextExec = Option::get($module_id, "AGENT_NEXT_EXEC", '');
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

// Массив с вкладками настроек
$aTabs = [
    [
        "DIV" => "edit1",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SETTINGS"),
        "ICON" => "pragma_import_module_settings",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SETTINGS"),
    ],
    [ // Новый таб
        "DIV" => "edit2",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SECTION_MAPPINGS"), // Название таба
        "ICON" => "pragma_import_module_section_mappings",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SECTION_MAPPINGS_TITLE"), // Заголовок таба
    ],
];

// Создание объекта CAdminTabControl для управления вкладками
$tabControl = new CAdminTabControl("tabControl", $aTabs);

// Подключение CSS
$APPLICATION->SetAdditionalCSS('/local/modules/pragma.import_module/lib/css/styles.css');

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

    <!-- Режим запуска -->
    <tr>
        <td width="40%">
            <label for="AUTO_MODE"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_AUTO_MODE") ?>:</label>
        </td>
        <td width="60%">
            <input type="checkbox" name="AUTO_MODE" id="AUTO_MODE" value="Y" <?= $autoMode === "Y" ? "" : "checked" ?>>
        </td>
    </tr>

    <!-- Задержка запуска (для автоматического режима) -->
    <tr id="delay_time_row" style="<?= $autoMode === 'Y' ? '' : 'display: none;' ?>">
        <td width="40%">
            <label for="DELAY_TIME"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_DELAY_TIME") ?>:</label>
        </td>
        <td width="60%">
            <input type="number" name="DELAY_TIME" id="DELAY_TIME" value="<?= $delayTime ?>" min="0">
            <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_MINUTES") ?>
        </td>
    </tr>

    <!-- Интервал запуска агента (для ручного режима) -->
    <tr id="agent_interval_row" style="<?= $autoMode === 'N' ? '' : 'display: none;' ?>">
        <td width="40%">
            <label for="AGENT_INTERVAL"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_INTERVAL") ?>:</label>
        </td>
        <td width="60%">
            <input type="number" name="AGENT_INTERVAL" id="AGENT_INTERVAL" value="<?= $agentInterval ?>" min="0">
            <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SECONDS") ?>
        </td>
    </tr>

    <!-- Время следующего запуска агента (для ручного режима) -->
    <tr id="agent_next_exec_row" style="<?= $autoMode === 'N' ? '' : 'display: none;' ?>">
        <td width="40%">
            <label for="AGENT_NEXT_EXEC"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_NEXT_EXEC") ?>:</label>
        </td>
        <td width="60%">
            <input type="datetime-local" name="AGENT_NEXT_EXEC" id="AGENT_NEXT_EXEC"
                value="<?= date('Y-m-d\TH:i', strtotime($agentNextExec)) ?>">
        </td>
    </tr>

    <? $tabControl->BeginNextTab(); ?>

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
                        <select name="SECTION_MAPPINGS[<?= $index ?>][SECTION_ID]" class="section-select"
                            data-index="<?= $index ?>">
                            <!-- <option value=""><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SELECT_SECTION") ?></option> -->
                            <?php
                            // Если IBLOCK_ID_CATALOG выбран, загружаем опции разделов
                            if ($iblockIdCatalog) {
                                echo getSectionOptionsHtml($iblockIdCatalog, $mapping['SECTION_ID']);
                            }
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

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var autoModeCheckbox = document.getElementById('AUTO_MODE');
        var delayTimeRow = document.getElementById('delay_time_row');
        var agentIntervalRow = document.getElementById('agent_interval_row');
        var agentNextExecRow = document.getElementById('agent_next_exec_row');

        function toggleModeSettings() {
            if (autoModeCheckbox.checked) {
                delayTimeRow.style.display = '';
                agentIntervalRow.style.display = 'none';
                agentNextExecRow.style.display = 'none';
            } else {
                delayTimeRow.style.display = 'none';
                agentIntervalRow.style.display = '';
                agentNextExecRow.style.display = '';
            }
        }

        autoModeCheckbox.addEventListener('change', toggleModeSettings);
        toggleModeSettings();

        // Обновление списка разделов при изменении инфоблока каталога
        document.getElementById('IBLOCK_ID_CATALOG').addEventListener('change', function () {
            const iblockId = this.value;
            if (iblockId) {
                updateSectionOptions(iblockId);
            } else {
                // Если инфоблок не выбран, очищаем все списки разделов
                const sectionSelects = document.querySelectorAll('.section-select');
                sectionSelects.forEach(select => {
                    select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
                });
            }
        });
    });

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
                    // Сохраняем текущее выбранное значение
                    const currentValue = select.value;
                    select.innerHTML = html;

                    // Восстанавливаем выбранное значение, если оно существует в новом списке
                    if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                        select.value = currentValue;
                    } else if (selectedSectionId) {
                        const option = select.querySelector(`option[value="${selectedSectionId}"]`);
                        if (option) {
                            option.selected = true;
                        }
                    }
                });
            })
            .catch(error => console.error('Error fetching section options:', error));
    }

    document.getElementById('resetButton').addEventListener('click', function (event) {
        event.preventDefault();

        // Загружаем значения по умолчанию
        const defaultOptions = <?= CUtil::PhpToJSObject($pragma_import_module_default_option) ?>;

        // Устанавливаем значения по умолчанию для полей формы
        document.getElementById('IBLOCK_ID_IMPORT').value = defaultOptions.IBLOCK_ID_IMPORT;
        document.getElementById('IBLOCK_ID_CATALOG').value = defaultOptions.IBLOCK_ID_CATALOG;
        document.getElementById('AUTO_MODE').checked = defaultOptions.AUTO_MODE === 'Y';
        document.getElementById('DELAY_TIME').value = defaultOptions.DELAY_TIME;
        document.getElementById('AGENT_INTERVAL').value = <?= $agentInterval ?>; // Используем актуальные данные агента
        document.getElementById('AGENT_NEXT_EXEC').value = '<?= date('Y-m-d\TH:i', strtotime($agentNextExec)) ?>'; // Используем актуальные данные агента

        // Очищаем все сопоставления разделов
        const container = document.getElementById('section_mappings_container');
        container.innerHTML = '';

        // Добавляем одно пустое сопоставление
        addMapping();

        // Очищаем все списки разделов
        const sectionSelects = document.querySelectorAll('.section-select');
        sectionSelects.forEach(select => {
            select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
        });

        // Если есть значение по умолчанию для IBLOCK_ID_CATALOG, загружаем разделы
        if (defaultOptions.IBLOCK_ID_CATALOG) {
            updateSectionOptions(defaultOptions.IBLOCK_ID_CATALOG);
        }

        // Вызываем функцию для отображения/скрытия полей в зависимости от режима запуска
        toggleModeSettings();
    });
</script>

<template id="section-mapping-template">
    <div class="section-mapping">
        <div class="select-wrapper">
            <select name="SECTION_MAPPINGS[{index}][SECTION_ID]" class="section-select">
                <option value=""><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SELECT_SECTION") ?></option>
            </select>
            <button type="button" onclick="removeMapping(this)">
                <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?>
            </button>
        </div>
        <div class="properties-container">
            <div class="property">
                <input type="text" name="SECTION_MAPPINGS[{index}][PROPERTIES][]">
                <button type="button" onclick="removeProperty(this)">
                    <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?>
                </button>
            </div>
        </div>
        <button type="button" onclick="addProperty(this)">
            <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_PROPERTY") ?>
        </button>
    </div>
</template>