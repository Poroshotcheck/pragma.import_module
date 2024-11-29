<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Pragma\ImportModule\Logger;
use Pragma\ImportModule\SectionHelper;
use Pragma\ImportModule\CacheHelper;
use Pragma\ImportModule\AgentManager;
use Pragma\ImportModule\IblockHelper;
use Pragma\ImportModule\PropertyHelper;
use Pragma\ImportModule\OptionsHelper;

$module_id = PRAGMA_IMPORT_MODULE_ID;
Loc::loadMessages(__FILE__);

if ($APPLICATION->GetGroupRight($module_id) < "S") {
    $APPLICATION->AuthForm(Loc::getMessage("ACCESS_DENIED"));
}

Loader::includeModule($module_id);
Loader::includeModule('iblock');

// Подключение файла с настройками по умолчанию
require_once __DIR__ . '/default_option.php';

// Инициализация логгера
$logFile = $_SERVER['DOCUMENT_ROOT'] . "/local/modules/pragma.importmodule/logs/import.log";
Logger::init($logFile);

// Инициализация AgentManager
$agentManager = new AgentManager();

// Проверка наличия агентов и их создание, если необходимо
if (!$agentManager->getAgentInfo($agentManager->getAgentIdByName('CheckAgent'))) {
    $agentManager->createAgent(\Pragma\ImportModule\Agent\CheckAgent::class, 300, date("d.m.Y H:i:s"), true);
}
if (!$agentManager->getAgentInfo($agentManager->getAgentIdByName('ImportAgent'))) {
    $agentManager->createAgent(\Pragma\ImportModule\Agent\ImportAgent::class, 86400, date("d.m.Y H:i:s", time() + 86400), false);
}

// Получение информации об агенте ImportAgent
$importAgentId = $agentManager->getAgentIdByName('ImportAgent');
$importAgentInfo = $agentManager->getAgentInfo($importAgentId);

// Синхронизация настроек
if ($importAgentInfo) {
    Option::set($module_id, "AGENT_INTERVAL", $importAgentInfo['AGENT_INTERVAL']);
    Option::set($module_id, "AGENT_NEXT_EXEC", $importAgentInfo['NEXT_EXEC']);

    // Сохраняем актуальные данные агента в переменные
    $agentInterval = $importAgentInfo['AGENT_INTERVAL'];
    $agentNextExec = $importAgentInfo['NEXT_EXEC'];
}

// Проверяем наличие сообщения в сессии и выводим его
if ($message = $_SESSION['PRAGMA_IMPORT_MODULE_MESSAGE']) {
    unset($_SESSION['PRAGMA_IMPORT_MODULE_MESSAGE']); // Удаляем сообщение из сессии
    CAdminMessage::ShowMessage([
        "MESSAGE" => $message,
        "TYPE" => "ERROR"
    ]);
}

// Обработка отправки формы
$request = Application::getInstance()->getContext()->getRequest();
if ($request->isPost() && strlen($request->getPost("Update")) > 0 && check_bitrix_sessid()) {
    // Получение данных из запроса безопасным способом
    $iblockIdImport = intval($request->getPost("IBLOCK_ID_IMPORT"));
    $iblockIdCatalog = intval($request->getPost("IBLOCK_ID_CATALOG"));
    $autoMode = $request->getPost("AUTO_MODE") ? "Y" : "N";
    $typeMode = $request->getPost("TYPE_MODE") ? "Y" : "N";
    $delayTime = intval($request->getPost("DELAY_TIME"));
    $agentInterval = intval($request->getPost("AGENT_INTERVAL"));
    $agentNextExec = htmlspecialcharsbx($request->getPost("AGENT_NEXT_EXEC"));
    $sectionMappings = [];
    $rawSectionMappings = $request->getPost("SECTION_MAPPINGS");
    
    if (is_array($rawSectionMappings)) {
        foreach ($rawSectionMappings as $index => $mapping) {
            if (!empty($mapping['SECTION_ID'])) {
                $sectionMappings[$index] = [
                    'SECTION_ID' => $mapping['SECTION_ID'],
                    'PROPERTIES' => isset($mapping['PROPERTIES']) ? array_filter($mapping['PROPERTIES']) : [],
                    'FILTER_PROPERTIES' => isset($mapping['FILTER_PROPERTIES']) ? $mapping['FILTER_PROPERTIES'] : []
                ];
            }
        }
    }

    // Process section mappings with the filter properties
    $duplicatePropertiesMessage = '';
    OptionsHelper::processSectionMappings($sectionMappings, $duplicatePropertiesMessage);

    $importMappings = $request->getPost("IMPORT_MAPPINGS");
    $enableLogging = $request->getPost("ENABLE_LOGGING") ? "Y" : "N";

    // Получение выбранных свойств из запроса
    $selectedCatalogProperties = array_keys($request->getPost("CATALOG_PROPERTIES") ?: []);
    $selectedOffersProperties = array_keys($request->getPost("OFFERS_PROPERTIES") ?: []);

    // Сохранение настроек
    Option::set($module_id, "IBLOCK_ID_IMPORT", $iblockIdImport);
    Option::set($module_id, "IBLOCK_ID_CATALOG", $iblockIdCatalog);
    Option::set($module_id, "AUTO_MODE", $autoMode);
    Option::set($module_id, "TYPE_MODE", $typeMode);
    if ($autoMode === "Y") {
        Option::set($module_id, "DELAY_TIME", $delayTime);
    } else {
        Option::set($module_id, "AGENT_INTERVAL", $agentInterval);
        Option::set($module_id, "AGENT_NEXT_EXEC", $agentNextExec);
    }
    Option::set($module_id, "ENABLE_LOGGING", $enableLogging);
    // Сохраняем выбранные свойства
    Option::set($module_id, "SELECTED_CATALOG_PROPERTIES", serialize($selectedCatalogProperties));
    Option::set($module_id, "SELECTED_OFFERS_PROPERTIES", serialize($selectedOffersProperties));

    // Обработка IMPORT_MAPPINGS
    $duplicateSectionsMessage = '';
    OptionsHelper::processImportMappings($importMappings, $duplicateSectionsMessage);

    // Обновляем агент
    if ($importAgentId > 0) {
        $agentFields = [
            "ACTIVE" => $autoMode === "Y" ? "N" : "Y",
            "AGENT_INTERVAL" => intval($_POST["AGENT_INTERVAL"])
        ];
        $agentNextExec = $_POST["AGENT_NEXT_EXEC"];
        if (!empty($agentNextExec) && checkdate(date('m', strtotime($agentNextExec)), date('d', strtotime($agentNextExec)), date('Y', strtotime($agentNextExec)))) {
            $agentNextExec = date('d.m.Y H:i:s', strtotime($agentNextExec));
            $agentFields["NEXT_EXEC"] = $agentNextExec;
        }
        $updateResult = $agentManager->updateAgent($importAgentId, $agentFields);
        if (!$updateResult) {
            Logger::log(Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_UPDATE_ERROR_LOG", ["#AGENT_ID#" => $importAgentId, "#EXCEPTION#" => $APPLICATION->GetException()]), "ERROR");
            echo "<div class='adm-info-message'>" . Loc::getMessage("PRAGMA_IMPORT_MODULE_AGENT_UPDATE_ERROR") . ": " . $APPLICATION->GetException() . "</div>";
        }
    }

    // Обновляем кэш после сохранения настроек
    CacheHelper::updateIblocksCache();
    if ($iblockIdCatalog > 0) {
        CacheHelper::updateSectionsCache($iblockIdCatalog);
    }
    if ($iblockIdImport > 0) {
        CacheHelper::updateSectionsCache($iblockIdImport);
        CacheHelper::updatePropertiesCache($iblockIdImport, 'S');
    }

    // Сохраняем сообщения об ошибках в сессии
    $message = '';
    if ($duplicatePropertiesMessage) {
        $message .= Loc::getMessage("PRAGMA_IMPORT_MODULE_DUPLICATE_PROPERTIES_ERROR") . "\n\n" . $duplicatePropertiesMessage;
    }
    if ($duplicateSectionsMessage) {
        $message .= Loc::getMessage("PRAGMA_IMPORT_MODULE_DUPLICATE_SECTIONS_ERROR") . "\n\n" . $duplicateSectionsMessage;
    }
    if ($message) {
        $_SESSION['PRAGMA_IMPORT_MODULE_MESSAGE'] = $message;
    }

    LocalRedirect($APPLICATION->GetCurPage() . "?mid=" . urlencode($module_id) . "&lang=" . LANGUAGE_ID);
}

// Получение текущих значений настроек
$iblockIdImport = Option::get($module_id, "IBLOCK_ID_IMPORT", $pragma_import_module_default_option['IBLOCK_ID_IMPORT']);
$iblockIdCatalog = Option::get($module_id, "IBLOCK_ID_CATALOG", $pragma_import_module_default_option['IBLOCK_ID_CATALOG']);
$autoMode = Option::get($module_id, "AUTO_MODE", $pragma_import_module_default_option['AUTO_MODE']);
$typeMode = Option::get($module_id, "TYPE_MODE", $pragma_import_module_default_option['TYPE_MODE']);
$delayTime = Option::get($module_id, "DELAY_TIME", $pragma_import_module_default_option['DELAY_TIME']);
$agentInterval = Option::get($module_id, "AGENT_INTERVAL", $pragma_import_module_default_option['AGENT_INTERVAL']);
$agentNextExec = Option::get($module_id, "AGENT_NEXT_EXEC", '');
$sectionMappings = unserialize(Option::get($module_id, "SECTION_MAPPINGS"));
$importMappings = unserialize(Option::get($module_id, "IMPORT_MAPPINGS"));
$enableLogging = Option::get($module_id, "ENABLE_LOGGING", $pragma_import_module_default_option['ENABLE_LOGGING']);

// Получение сохранённых выбранных свойств
$selectedCatalogProperties = unserialize(Option::get($module_id, "SELECTED_CATALOG_PROPERTIES", serialize([]))) ?: [];
$selectedOffersProperties = unserialize(Option::get($module_id, "SELECTED_OFFERS_PROPERTIES", serialize([]))) ?: [];

// Получаем все свойства через PropertyHelper
$allProps = PropertyHelper::getAllProperties($iblockIdCatalog, $selectedCatalogProperties, $selectedOffersProperties);

$catalogListProperties = $allProps['catalogListProperties'];
$offersListProperties = $allProps['offersListProperties'];
$allProperties = [
    'CATALOG' => $allProps['catalogProperties'],
    'OFFERS' => $allProps['offerProperties']
];

// Фильтруем выбранные свойства, оставляя только существующие
$selectedCatalogProperties = array_intersect(
    $selectedCatalogProperties, 
    array_keys($catalogListProperties)
);

$selectedOffersProperties = array_intersect(
    $selectedOffersProperties, 
    array_keys($offersListProperties)
);

// Получение списка инфоблоков
$arIblocks = IblockHelper::getIblocks();

// Обработка скачивания лог-файла
if (isset($_GET['download'])) {
    $file = basename($_GET['download']);
    $filePath = __DIR__ . '/logs/' . $file;
    if (file_exists($filePath)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        readfile($filePath);
        exit;
    } else {
        echo "Файл не найден.";
    }
}

// Обработка удаления лог-файла
if (isset($_GET['delete'])) {
    $file = basename($_GET['delete']);
    $filePath = __DIR__ . '/logs/' . $file;
    if (file_exists($filePath)) {
        unlink($filePath);
        // Перенаправление после удаления
        header('Location: ' . $APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID);
        exit;
    } else {
        echo "Файл не найден.";
    }
}

// Массив с вкладками настроек
$aTabs = [
    [
        "DIV" => "edit1",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SETTINGS"),
        "ICON" => "pragma_import_module_settings",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SETTINGS"),
    ],
    [
        "DIV" => "edit2",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SECTION_MAPPINGS"),
        "ICON" => "pragma_import_module_section_mappings",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_SECTION_MAPPINGS_TITLE"),
    ],
    [
        "DIV" => "edit3",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_IMPORT_MAPPINGS"),
        "ICON" => "pragma_import_module_import_mappings",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_IMPORT_MAPPINGS_TITLE"),
    ],
    [
        "DIV" => "edit5",
        "TAB" => Loc::getMessage("PRAGMA_IMPORT_MODULE_DATA_TAB"),
        "ICON" => "pragma_import_module_data",
        "TITLE" => Loc::getMessage("PRAGMA_IMPORT_MODULE_DATA_TAB_TITLE"),
    ],
    [
        "DIV" => "edit4",
        "TAB" => Loc::getMessage("PRAGMA_LOG_MODULE_TAB"),
        "ICON" => "pragma_import_module_import_mappings",
        "TITLE" => Loc::getMessage("PRAGMA_LOG_MODULE_TITLE"),
    ],
];

// Создание объекта CAdminTabControl для управления вкладками
$tabControl = new CAdminTabControl("tabControl", $aTabs);
// Подключение CSS
$APPLICATION->SetAdditionalCSS('/local/modules/pragma.importmodule/lib/css/styles.css');

$tabControl->Begin();
?>
<script>
    var allProperties = <?= CUtil::PhpToJSObject($allProperties) ?>;
</script>
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
            <input type="checkbox" name="AUTO_MODE" id="AUTO_MODE" value="<?= $autoMode ?>" <?= $autoMode == "N" ? "" : "checked" ?>>
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

    <!-- Простые/торговые предложения для элементов без группы -->
    <tr>
        <td width="40%">
            <label for="TYPE_MODE"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_TYPE_MODE") ?>:</label>
        </td>
        <td width="60%">
            <input type="checkbox" name="TYPE_MODE" id="TYPE_MODE" value="<?= $typeMode ?>" <?= $typeMode == "N" ? "" : "checked" ?>>
        </td>
    </tr>

    <tr class="heading">
    <td colspan="2"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_CATALOG_PROPERTIES") ?></td>
</tr>
<tr>
    <td colspan="2">
        <div class="catalog-properties-container">
            <div class="properties-group">
                <div class="heading"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_CATALOG_PROPERTIES") ?></div>
                <div class="properties-table">
                    <?php if (!empty($catalogListProperties)): ?>
                        <?php foreach ($catalogListProperties as $code => $name): ?>
                            <div class="property-row">
                                <div class="property-name">
                                    <label for="CATALOG_PROPERTIES[<?= htmlspecialcharsbx($code) ?>]">
                                        <?= htmlspecialcharsbx($name) ?>:
                                    </label>
                                </div>
                                <div class="property-checkbox">
                                    <input type="checkbox" 
                                           name="CATALOG_PROPERTIES[<?= htmlspecialcharsbx($code) ?>]" 
                                           value="Y"
                                           <?= in_array($code, $selectedCatalogProperties) ? "checked" : "" ?>>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-properties"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_NO_LIST_PROPERTIES") ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Свойства торговых предложений -->
            <div class="properties-group">
                <div class="heading"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SKU_PROPERTIES") ?></div>
                <div class="properties-table">
                    <?php if (!empty($offersListProperties)): ?>
                        <?php foreach ($offersListProperties as $code => $name): ?>
                            <div class="property-row">
                                <div class="property-name">
                                    <label for="OFFERS_PROPERTIES[<?= htmlspecialcharsbx($code) ?>]">
                                        <?= htmlspecialcharsbx($name) ?>:
                                    </label>
                                </div>
                                <div class="property-checkbox">
                                    <input type="checkbox" 
                                           name="OFFERS_PROPERTIES[<?= htmlspecialcharsbx($code) ?>]" 
                                           value="Y"
                                           <?= in_array($code, $selectedOffersProperties) ? "checked" : "" ?>>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-properties"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_NO_SKU_PROPERTIES") ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </td>
</tr>

    <? $tabControl->BeginNextTab(); ?>

        <tr>
            <td colspan="2">
                <div id="section_mappings_container">
                    <?php
                    if (empty($sectionMappings)) {
                        $sectionMappings = [['SECTION_ID' => '', 'PROPERTIES' => ['']]];
                    }

                    // Получаем данные из кэша один раз перед циклом
                    $cachedData = CacheHelper::getCachedSections($iblockIdCatalog);
                    $sections = $cachedData ? $cachedData[0] : [];

                    foreach ($sectionMappings as $index => $mapping):
                    ?>
                        <div class="section-mapping">
                            <div class="select-wrapper">
                                <div>
                                    <input type="text" class="search-input" placeholder="Поиск..." style="display: none;">
                                    <select name="SECTION_MAPPINGS[<?= $index ?>][SECTION_ID]" class="section-select"
                                        data-index="<?= $index ?>">
                                            <?php
                                            if ($iblockIdCatalog) {
                                                echo SectionHelper::getSectionOptionsHtml($iblockIdCatalog, $mapping['SECTION_ID'], $sections);
                                            }
                                            ?>
                                        </select>
                                </div>
                                <button type="button" onclick="removeMapping(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?></button>
                            </div>
                            
                            <!-- Контейнер для свойств фильтра -->
                            <div class="filter-properties-section">
                                <div class="filter-section-header"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_CATALOG_PROPERTIES") ?></div>
                                <div class="filter-properties-tabs">
                                    <?php foreach ($allProperties['CATALOG'] as $uniqueKey => $propertyData): ?>
                                        <div class="tabs-container">
                                            <!-- <div class="tabs-label"><?= htmlspecialcharsbx($propertyData['NAME']) ?>:</div> -->
                                            <div class="tabs-wrapper">
                                                <?php foreach ($propertyData['VALUES'] as $enumId => $enumData): ?>
                                                    <?php
                                                    $originalCode = $propertyData['ORIGINAL_CODE'];
                                                    $selectedValues = $mapping['FILTER_PROPERTIES']['CATALOG_' . $originalCode] ?? [];
                                                    ?>
                                                    <label class="tab-label">
                                                        <input type="checkbox" 
                                                            name="SECTION_MAPPINGS[<?= $index ?>][FILTER_PROPERTIES][CATALOG_<?= htmlspecialcharsbx($originalCode) ?>][]"
                                                            value="<?= htmlspecialcharsbx($enumId) ?>"
                                                            <?= in_array($enumId, $selectedValues) ? 'checked' : '' ?>>
                                                        <span class="tab-text">
                                                            <?= htmlspecialcharsbx($enumData['VALUE']) ?>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="filter-properties-section">
                                <div class="filter-section-header"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SKU_PROPERTIES") ?></div>
                                <div class="filter-properties-tabs">
                                    <?php foreach ($allProperties['OFFERS'] as $uniqueKey => $propertyData): ?>
                                        <div class="tabs-container">
                                            <!-- <div class="tabs-label"><?= htmlspecialcharsbx($propertyData['NAME']) ?>:</div> -->
                                            <div class="tabs-wrapper">
                                                <?php foreach ($propertyData['VALUES'] as $enumId => $enumData): ?>
                                                    <?php
                                                    $originalCode = $propertyData['ORIGINAL_CODE'];
                                                    $selectedValues = $mapping['FILTER_PROPERTIES']['OFFER_' . $originalCode] ?? [];
                                                    ?>
                                                    <label class="tab-label">
                                                        <input type="checkbox" 
                                                            name="SECTION_MAPPINGS[<?= $index ?>][FILTER_PROPERTIES][OFFER_<?= htmlspecialcharsbx($originalCode) ?>][]"
                                                            value="<?= htmlspecialcharsbx($enumId) ?>"
                                                            <?= in_array($enumId, $selectedValues) ? 'checked' : '' ?>>
                                                        <span class="tab-text">
                                                            <?= htmlspecialcharsbx($enumData['VALUE']) ?>
                                                        </span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

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
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" onclick="addMapping()"
                    class="add-mapping-button"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_MAPPING") ?></button>
            </td>
        </tr>

    <? $tabControl->BeginNextTab(); ?>

    <!-- Множественная настройка сопоставления разделов и свойств для IBLOCK_ID_IMPORT -->
    <tr>
        <td colspan="2">
            <div id="import_mappings_container">
                <?php
                if (empty($importMappings)) {
                    $importMappings = [
                        [
                            'SECTION_ID' => '',
                            'TOTAL_MATCHES' => 0,
                            'PROPERTIES' => []
                        ]
                    ];
                }

                // Получаем данные из кэша один раз перед циклом 
                $cachedSectionsImport = CacheHelper::getCachedSections($iblockIdImport);
                $sectionsImport = $cachedSectionsImport ? $cachedSectionsImport[0] : [];
                $cachedPropertiesImport = CacheHelper::getCachedProperties($iblockIdImport);
                $propertiesImport = $cachedPropertiesImport ? $cachedPropertiesImport[0] : [];

                foreach ($importMappings as $sectionId => $mapping):
                    ?>
                    <div class="import-mapping" data-section-id="<?= $sectionId ?>">
                        <select name="IMPORT_MAPPINGS[<?= $sectionId ?>][SECTION_ID]" class="import-section-select"
                            data-index="<?= $sectionId ?>">
                            <?php
                            if ($iblockIdImport) {
                                echo SectionHelper::getSectionOptionsHtml($iblockIdImport, $mapping['SECTION_ID'], $sectionsImport);
                            }
                            ?>
                        </select>
                        <button type="button"
                            onclick="removeImportMapping(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?></button>

                        <div class="total-matches-container">
                            <label for="IMPORT_MAPPINGS[<?= $sectionId ?>][TOTAL_MATCHES]">
                                <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_TOTAL_MATCHES") ?>:
                            </label>
                            <select name="IMPORT_MAPPINGS[<?= $sectionId ?>][TOTAL_MATCHES]"
                                id="IMPORT_MAPPINGS[<?= $sectionId ?>][TOTAL_MATCHES]">
                                <?php for ($i = 0; $i <= 5; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($mapping['TOTAL_MATCHES'] == $i) ? 'selected' : '' ?>><?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="import-properties-container" data-index="<?= $sectionId ?>">
                            <?php foreach ($mapping['PROPERTIES'] as $propCode => $property): ?>
                                <div class="import-property" data-property-code="<?= $propCode ?>">
                                    <select name="IMPORT_MAPPINGS[<?= $sectionId ?>][PROPERTIES][<?= $propCode ?>][CODE]"
                                        class="property-select-import" data-index="<?= $propCode ?>">
                                        <?php
                                        echo PropertyHelper::getPropertyOptionsHtml($iblockIdImport, $property['CODE'], $propertiesImport, "S");
                                        ?>
                                    </select>
                                    <select name="IMPORT_MAPPINGS[<?= $sectionId ?>][PROPERTIES][<?= $propCode ?>][MATCHES]">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <option value="<?= $i ?>" <?= ($mapping['PROPERTIES'][$propCode]['MATCHES'] == $i) ? 'selected' : '' ?>>
                                                <?= $i ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                    <button type="button"
                                        onclick="removeImportProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="add-import-property-button"
                            onclick="addImportProperty('<?= $sectionId ?>')"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_PROPERTY") ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" onclick="addImportMapping()"
                class="add-mapping-button"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_MAPPING") ?></button>
        </td>
    </tr>
    <? $tabControl->BeginNextTab(); ?>

    <!-- Контейнер для таблицы -->
    <?php include __DIR__ . '/data_table.php'; ?>

    <?
    $tabControl->BeginNextTab();

    // Получаем список лог-файлов из директории logs/
    $logDir = __DIR__ . '/logs/';
    $logFiles = array();
    foreach (glob($logDir . '*.log') as $filePath) {
        $fileName = basename($filePath);
        $fileTime = filemtime($filePath);
        $logFiles[$fileName] = $fileTime;
    }
    arsort($logFiles);
    ?>
    <tr>
        <td width="40%">
            <label for="ENABLE_LOGGING"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ENABLE_LOGGING") ?>:</label>
        </td>
        <td width="60%">
            <input type="checkbox" name="ENABLE_LOGGING" id="ENABLE_LOGGING" value="Y" <?= $enableLogging == "Y" ? "checked" : "" ?>>
        </td>
    </tr>
    <br>
    <!-- Вывод логов -->
    <tr>
        <td colspan="2">
            <div class="log-files">
                <?php foreach ($logFiles as $logFile => $fileTime): ?>
                    <div class="log-file">
                        <div class="log-header">
                            <h3 onclick="toggleLogContent('<?= md5($logFile) ?>')">
                                <?= htmlspecialchars($logFile) ?> (<?= date('d.m.Y H:i', $fileTime) ?>)
                            </h3>
                            <div class="log-actions">
                                <a
                                    href="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&lang=<?= LANGUAGE_ID ?>&download=<?= urlencode($logFile) ?>">Скачать</a>
                                |
                                <a href="#" class="delete-log" data-file="<?= htmlspecialchars($logFile) ?>">Удалить</a>
                            </div>
                        </div>
                        <div id="<?= md5($logFile) ?>" class="log-content">
                            <pre><?php echo htmlspecialchars(file_get_contents($logDir . $logFile)); ?></pre>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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
        <div class="select-wrapper">
            <div>
                <input type="text" class="search-input" placeholder="Поиск..." style="display: none;">
                <select name="SECTION_MAPPINGS[{index}][SECTION_ID]" class="section-select">
                </select>
            </div>
            <button type="button" onclick="removeMapping(this)">
                <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?>
            </button>
        </div>
        
        <!-- Контейнер для свойств фильтра -->
        <div class="filter-properties-section">
            <div class="filter-section-header"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_CATALOG_PROPERTIES") ?></div>
            <div class="filter-properties-tabs">
                <!-- Здесь будут свойства каталога -->
            </div>
        </div>
        
        <div class="filter-properties-section">
            <div class="filter-section-header"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_SKU_PROPERTIES") ?></div>
            <div class="filter-properties-tabs">
                <!-- Здесь будут свойства ТП -->
            </div>
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

<template id="import-mapping-template">
    <div class="import-mapping" data-section-id="{index}">
        <select name="IMPORT_MAPPINGS[{index}][SECTION_ID]" class="import-section-select" data-index="{index}">
            <!-- Опции разделов будут загружены сюда -->
        </select>
        <button type="button"
            onclick="removeImportMapping(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_MAPPING") ?></button>

        <div class="total-matches-container">
            <label for="IMPORT_MAPPINGS[{index}][TOTAL_MATCHES]">
                <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_TOTAL_MATCHES") ?>:
            </label>
            <select name="IMPORT_MAPPINGS[{index}][TOTAL_MATCHES]" id="IMPORT_MAPPINGS[{index}][TOTAL_MATCHES]">
                <option value="0">0</option>
                <option value="1">1</option>
                <option value="2">2</option>
                <option value="3">3</option>
                <option value="4">4</option>
                <option value="5">5</option>
            </select>
        </div>

        <div class="import-properties-container" data-index="{index}">
            <!-- Сюда будут добавляться свойства -->
        </div>

        <button type="button" class="add-import-property-button" onclick="addImportProperty('{index}')">
            <?= Loc::getMessage("PRAGMA_IMPORT_MODULE_ADD_PROPERTY") ?>
        </button>
    </div>
</template>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.delete-log').forEach(function (element) {
            element.addEventListener('click', function (event) {
                event.preventDefault();
                var fileName = this.getAttribute('data-file');
                var logFileDiv = this.closest('.log-file');
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/local/modules/pragma.importmodule/lib/ajax.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function () {
                    if (xhr.status === 200) {
                        if (xhr.responseText.trim() === 'success') {
                            // Remove the log file element from the DOM
                            logFileDiv.parentNode.removeChild(logFileDiv);
                        } else {
                            alert('Ошибка при удалении файла: ' + xhr.responseText);
                        }
                    } else {
                        alert('Ошибка сервера при удалении файла.');
                    }
                };
                xhr.send('action=delete_log&file=' + encodeURIComponent(fileName) + '&' + '<?= bitrix_sessid_get() ?>');
            });
        });
    });

    function toggleLogContent(id) {
        var content = document.getElementById(id);
        var logFileDiv = content.closest('.log-file');
        if (logFileDiv) {
            logFileDiv.classList.toggle('open');
        }
    }

    // Функция для добавления поля фильтрации к select
    function addSearchToSelect(select) {
        const wrapper = select.closest('.select-wrapper');
        if (!wrapper) return;

        let searchInput = wrapper.querySelector('.search-input');

        if (!searchInput) {
            searchInput = document.createElement('input');
            searchInput.type = 'text';
            searchInput.className = 'search-input';
            searchInput.placeholder = 'Поиск...';
            searchInput.style.display = 'none';
            wrapper.insertBefore(searchInput, select); // Вставляем input перед select
        }

        let isSearchActive = false;

        select.addEventListener('mousedown', function (event) {
            if (this.options.length > 10 && !isSearchActive) {
                event.preventDefault();
                this.size = 10;
                searchInput.style.display = 'block';
                searchInput.focus();
                isSearchActive = true;
            }
        });

        searchInput.addEventListener('input', function () {
            const filter = this.value.toLowerCase();

            const options = Array.from(select.options);
            const optionsByValue = {};
            options.forEach(option => {
                optionsByValue[option.value] = option;
                option.style.display = 'none'; // Скрываем все опции изначально
            });

            options.forEach(option => {
                const text = option.text.toLowerCase();
                const matches = text.includes(filter);
                if (matches) {
                    option.style.display = ''; // Показываем подходящую опцию
                    // Также показываем всех родительских опций
                    let parentId = option.getAttribute('data-parent-id');
                    while (parentId) {
                        const parentOption = optionsByValue[parentId];
                        if (parentOption && parentOption.style.display === 'none') {
                            parentOption.style.display = '';
                            parentId = parentOption.getAttribute('data-parent-id');
                        } else {
                            parentId = null;
                        }
                    }
                }
            });

            // Всегда отображаем разделы верхнего уровня
            // options.forEach(option => {
            //     const level = parseInt(option.getAttribute('data-level'));
            //     if (level === 0) {
            //         option.style.display = '';
            //     }
            // });
        });

        document.addEventListener('click', function (event) {
            if (!wrapper.contains(event.target) && isSearchActive) {
                select.size = 0;
                searchInput.style.display = 'none';
                searchInput.value = '';
                Array.from(select.options).forEach(option => {
                    option.style.display = '';
                });
                isSearchActive = false;
            }
        });

        select.addEventListener('change', function () {
            if (isSearchActive) {
                select.size = 0;
                searchInput.style.display = 'none';
                searchInput.value = '';
                Array.from(select.options).forEach(option => {
                    option.style.display = '';
                });
                isSearchActive = false;
            }
        });
    }

    function addMapping() {
        const template = document.getElementById('section-mapping-template').content.cloneNode(true);
        const container = document.getElementById('section_mappings_container');
        const index = container.children.length;

        template.querySelector('select').name = `SECTION_MAPPINGS[${index}][SECTION_ID]`;
        template.querySelectorAll('input').forEach(input => {
            input.name = `SECTION_MAPPINGS[${index}][PROPERTIES][]`;
        });

        container.appendChild(template);

        const newSelect = container.lastElementChild.querySelector('.section-select');
        updateSectionOptions(document.getElementById('IBLOCK_ID_CATALOG').value, newSelect);
        addSearchToSelect(newSelect);
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
<button type="button"
    onclick="removeProperty(this)"><?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?></button>
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

        fetch(`/local/modules/pragma.importmodule/lib/ajax.php?IBLOCK_ID=${iblockId}`)
            .then(response => response.text())
            .then(html => {
                const selectsToUpdate = selectElement ? [selectElement] : document.querySelectorAll('.section-select');
                selectsToUpdate.forEach(select => {
                    const currentValue = select.value;
                    select.innerHTML = html;

                    if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                        select.value = currentValue;
                    } else if (selectedSectionId) {
                        const option = select.querySelector(`option[value="${selectedSectionId}"]`);
                        if (option) {
                            option.selected = true;
                        }
                    }

                    // Добавляем фильтр к каждому обновленному select
                    addSearchToSelect(select);
                });
            })
            .catch(error => console.error('Error fetching section options:', error));
    }

    function addImportMapping() {
        const template = document.getElementById('import-mapping-template');
        const container = document.getElementById('import_mappings_container');
        if (!template || !container) {
            console.error('Template or container not found');
            return;
        }

        const index = Date.now(); // Используем timestamp для уникального индекса

        // Клонируем содержимое шаблона
        const newMapping = template.content.cloneNode(true).firstElementChild;
        newMapping.dataset.sectionId = index;

        // Обновляем имена и индексы в новом элементе
        newMapping.querySelector('select.import-section-select').name = `IMPORT_MAPPINGS[${index}][SECTION_ID]`;
        newMapping.querySelector('select.import-section-select').dataset.index = index;
        newMapping.querySelector('select[id^="IMPORT_MAPPINGS"]').name = `IMPORT_MAPPINGS[${index}][TOTAL_MATCHES]`;
        newMapping.querySelector('select[id^="IMPORT_MAPPINGS"]').id = `IMPORT_MAPPINGS[${index}][TOTAL_MATCHES]`;
        newMapping.querySelector('.import-properties-container').dataset.index = index;
        newMapping.querySelector('button[onclick^="addImportProperty"]').setAttribute('onclick',
            `addImportProperty('${index}')`);

        // Добавляем новый элемент в контейнер
        container.appendChild(newMapping);

        // Загружаем опции для нового select раздела
        const newSectionSelect = newMapping.querySelector('.import-section-select');
        updateImportSectionOptions(document.getElementById('IBLOCK_ID_IMPORT').value, newSectionSelect);
    }

    function removeImportMapping(button) {
        button.closest('.import-mapping').remove();
    }

    function addImportProperty(mappingIndex) {
        const mapping = typeof mappingIndex === 'object'
            ? mappingIndex.closest('.import-mapping')
            : document.querySelector(`.import-mapping[data-section-id="${mappingIndex}"]`);

        if (!mapping) {
            console.error(`Mapping not found for index ${mappingIndex}`);
            return;
        }

        const propertiesContainer = mapping.querySelector('.import-properties-container');
        if (!propertiesContainer) {
            console.error(`Properties container not found in mapping ${mappingIndex}`);
            return;
        }

        const iblockIdImport = document.getElementById('IBLOCK_ID_IMPORT').value;
        const sectionId = mapping.dataset.sectionId;

        // Создаем новый div для свойства
        const newProperty = document.createElement('div');
        newProperty.className = 'import-property';

        // Создаем новый select для выбора кода свойства
        const propertyCodeSelect = document.createElement('select');
        propertyCodeSelect.className = 'property-select-import';
        propertyCodeSelect.dataset.index = ''; // Изначально data-index пустой

        // Добавляем select для выбора кода свойства в newProperty
        newProperty.appendChild(propertyCodeSelect);

        // Дбавляем select для matches
        const matchesSelect = document.createElement('select');
        matchesSelect.innerHTML = `
<option value="1">1</option>
<option value="2">2</option>
<option value="3">3</option>
<option value="4">4</option>
<option value="5">5</option>
`;
        newProperty.appendChild(matchesSelect);

        // Добавляем кнопку удаления
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.setAttribute('onclick', 'removeImportProperty(this)');
        newProperty.appendChild(removeButton);
        removeButton.textContent = '<?= Loc::getMessage("PRAGMA_IMPORT_MODULE_REMOVE_PROPERTY") ?>';

        // Добавляем newProperty в propertiesContainer
        propertiesContainer.appendChild(newProperty);

        // Загружаем доступные коды свойств
        updatePropertyOptionsImport(iblockIdImport, propertyCodeSelect, function () {
            // Получаем код выбранного свойства
            const propertyCode = propertyCodeSelect.value;

            // Обновляем имена и атрибуты после добавления в DOM
            newProperty.dataset.propertyCode = propertyCode;
            propertyCodeSelect.name = `IMPORT_MAPPINGS[${sectionId}][PROPERTIES][${propertyCode}][CODE]`;
            propertyCodeSelect.dataset.index = propertyCode;
            matchesSelect.name = `IMPORT_MAPPINGS[${sectionId}][PROPERTIES][${propertyCode}][MATCHES]`;
        });

        // Добавляем обработчик изменения кода свойства
        propertyCodeSelect.addEventListener('change', function () {
            // Обновляем имя select для matches при изменении кода свойства
            matchesSelect.name = `IMPORT_MAPPINGS[${sectionId}][PROPERTIES][${this.value}][MATCHES]`;
            newProperty.dataset.propertyCode = this.value;

            // Обновляем data-index при изменении кода свойства
            this.dataset.index = this.value;
            this.name = `IMPORT_MAPPINGS[${sectionId}][PROPERTIES][${this.value}][CODE]`;
        });
    }

    function removeImportProperty(button) {
        button.closest('.import-property').remove();
    }

    // Функция для загрузки разделов для второго таба
    function updateImportSectionOptions(iblockId, selectElement = null) {
        if (!iblockId) return;
        const sessid = BX.bitrix_sessid(); // Get the session ID

        fetch(`/local/modules/pragma.importmodule/lib/ajax.php?IBLOCK_ID=${iblockId}&sessid=${sessid}`)
            .then(response => response.text())
            .then(html => {
                const selectsToUpdate = selectElement ? [selectElement] : document.querySelectorAll('.import-section-select');
                selectsToUpdate.forEach(select => {
                    const currentValue = select.value;
                    select.innerHTML = html;

                    if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                        select.value = currentValue;
                    } else {
                        select.selectedIndex = 0;
                    }

                    // Reset properties for this mapping
                    const propertiesContainer = select.closest('.import-mapping').querySelector('.import-properties-container');
                    if (propertiesContainer) {
                        propertiesContainer.innerHTML = '';
                        // Add the first property
                        const mappingIndex = select.closest('.import-mapping').dataset.sectionId;
                        addImportProperty(mappingIndex);
                    }
                });
            })
            .catch(error => console.error('Error fetching import section options:', error));
    }

    function updateAllImportProperties(iblockId) {
        if (!iblockId) return;
        const sessid = BX.bitrix_sessid();

        fetch(`/local/modules/pragma.importmodule/lib/ajax.php?IBLOCK_ID=${iblockId}&PROPERTY=Y&sessid=${sessid}`)
            .then(response => response.text())
            .then(html => {
                const propertySelects = document.querySelectorAll('.import-property .property-select-import');
                propertySelects.forEach(select => {
                    const previousValue = select.value;
                    select.innerHTML = html;

                    // Re-select previous value if it exists
                    if (select.querySelector(`option[value="${previousValue}"]`)) {
                        select.value = previousValue;
                    } else {
                        select.selectedIndex = 0;
                    }

                    // Update name attributes
                    const newPropertyCode = select.value;
                    const mappingElement = select.closest('.import-mapping');
                    const mappingIndex = mappingElement.dataset.sectionId;
                    const matchesSelect = select.nextElementSibling; // Assuming matchesSelect follows propertySelect
                    select.name = `IMPORT_MAPPINGS[${mappingIndex}][PROPERTIES][${newPropertyCode}][CODE]`;
                    matchesSelect.name = `IMPORT_MAPPINGS[${mappingIndex}][PROPERTIES][${newPropertyCode}][MATCHES]`;
                });
            })
            .catch(error => console.error('Error fetching import property options:', error));
    }

    function updateImportSectionOptions(iblockId, selectElement = null) {
        if (!iblockId) return;
        const sessid = BX.bitrix_sessid(); // Get the session ID

        fetch(`/local/modules/pragma.importmodule/lib/ajax.php?IBLOCK_ID=${iblockId}&sessid=${sessid}`)
            .then(response => response.text())
            .then(html => {
                const selectsToUpdate = selectElement ? [selectElement] : document.querySelectorAll('.import-section-select');
                selectsToUpdate.forEach(select => {
                    const currentValue = select.value;
                    select.innerHTML = html;

                    if (currentValue && select.querySelector(`option[value="${currentValue}"]`)) {
                        select.value = currentValue;
                    } else {
                        select.selectedIndex = 0;
                    }

                    // Reset properties for this mapping
                    const propertiesContainer = select.closest('.import-mapping').querySelector('.import-properties-container');
                    if (propertiesContainer) {
                        propertiesContainer.innerHTML = '';
                        // Add the first property
                        const mappingIndex = select.closest('.import-mapping').dataset.sectionId;
                        addImportProperty(mappingIndex);
                    }
                });
            })
            .catch(error => console.error('Error fetching import section options:', error));
    }

    function updatePropertyOptionsImport(iblockId, selectElement, callback = null) {
        if (!iblockId || !selectElement) return;

        fetch(`/local/modules/pragma.importmodule/lib/ajax.php?IBLOCK_ID=${iblockId}&PROPERTY=Y`)
            .then(response => response.text())
            .then(html => {
                selectElement.innerHTML = html;
                if (callback) {
                    callback();
                }
            })
            .catch(error => console.error('Error fetching import property options:', error));
    }

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
                const sectionSelects = document.querySelectorAll('.section-select');
                sectionSelects.forEach(select => {
                    select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
                    addSearchToSelect(select);
                });
            }
        });

        document.getElementById('IBLOCK_ID_IMPORT').addEventListener('change', function () {
            const iblockId = this.value;
            if (iblockId) {
                // Update the sections in the import mappings
                updateImportSectionOptions(iblockId);

                // Update the properties in the import mappings
                updateAllImportProperties(iblockId);
            } else {
                // Clear the import mappings if no IBLOCK_ID_IMPORT is selected
                const importMappingsContainer = document.getElementById('import_mappings_container');
                importMappingsContainer.innerHTML = '';
            }
        });

        // Инициализация фильтров для существующих select при загрузке страницы
        const existingSelects = document.querySelectorAll('.section-select');
        existingSelects.forEach(select => {
            addSearchToSelect(select);
        });

        // Остальные обработчики событий и инициализации...
        document.getElementById('resetButton').addEventListener('click', function (event) {
            event.preventDefault();

            // Загружаем значения по умолчанию
            const defaultOptions = <?= CUtil::PhpToJSObject($pragma_import_module_default_option) ?>;

            // Устанавливаем значения по умолчанию для поле формы
            document.getElementById('IBLOCK_ID_IMPORT').value = defaultOptions.IBLOCK_ID_IMPORT;
            document.getElementById('IBLOCK_ID_CATALOG').value = defaultOptions.IBLOCK_ID_CATALOG;
            document.getElementById('AUTO_MODE').checked = defaultOptions.AUTO_MODE;
            document.getElementById('TYPE_MODE').checked = defaultOptions.TYPE_MODE;
            document.getElementById('DELAY_TIME').value = defaultOptions.DELAY_TIME;
            document.getElementById('AGENT_INTERVAL').value = <?= $agentInterval ?>;
            document.getElementById('AGENT_NEXT_EXEC').value = '<?= date('Y-m-d\TH:i', strtotime($agentNextExec)) ?>';

            // Очищаем все сопоставления разделов
            const container = document.getElementById('section_mappings_container');
            container.innerHTML = '';

            // Очищаем все сопоставления импорта
            const importContainer = document.getElementById('import_mappings_container');
            importContainer.innerHTML = '';

            // Добавляем одно пустое сопоставление для каждого контейнера
            addMapping();
            addImportMapping();

            // Очищаем все списки разделов
            const sectionSelects = document.querySelectorAll('.section-select, .import-section-select');
            sectionSelects.forEach(select => {
                select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
            });

            // Очищаем все списки свойств
            const propertySelects = document.querySelectorAll('.property-select-import');
            propertySelects.forEach(select => {
                select.innerHTML = '<option value="">' + select.options[0].text + '</option>';
            });

            // Если есть значение по умолчанию для IBLOCK_ID_CATALOG, загружаем разделы
            if (defaultOptions.IBLOCK_ID_CATALOG) {
                updateSectionOptions(defaultOptions.IBLOCK_ID_CATALOG);
            }

            // Если есть значение по умолчанию для IBLOCK_ID_IMPORT, загружаем разделы импорта
            if (defaultOptions.IBLOCK_ID_IMPORT) {
                updateImportSectionOptions(defaultOptions.IBLOCK_ID_IMPORT);
                updatePropertyOptionsImport(defaultOptions.IBLOCK_ID_IMPORT); // Загружаем свойства импорта
            }

            // Вызываем функцию для отображения/скрытия полей в зависимости от режима запуска
            toggleModeSettings();
        });
    });
</script>