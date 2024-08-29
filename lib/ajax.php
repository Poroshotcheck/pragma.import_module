<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;

if (!Loader::includeModule('iblock')) {
    die('IBlock module is not installed');
}

$request = Application::getInstance()->getContext()->getRequest();
$iblockId = intval($request->getQuery('IBLOCK_ID'));

if ($iblockId > 0) {
    echo getSectionOptionsHtml($iblockId); 
}

function getSectionOptionsHtml($iblockId, $selectedId = null)
{
    if (empty($iblockId)) {
        return '';
    }

    $rsSections = SectionTable::getList([
        'filter' => ['IBLOCK_ID' => intval($iblockId)],
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

function buildSectionOptions($sections, $selectedId = null, $level = 0)
{
    $result = '';
    foreach ($sections as $section) {
        $selected = ($section['ID'] == intval($selectedId)) ? 'selected' : '';
        $result .= '<option value="' . intval($section['ID']) . '" ' . $selected . '>'
            . str_repeat(" ", $level * 3) . htmlspecialcharsbx($section['NAME'])
            . '</option>';
        if (!empty($section['CHILDREN'])) {
            $result .= buildSectionOptions($section['CHILDREN'], $selectedId, $level + 1);
        }
    }
    return $result;
}

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");
?>