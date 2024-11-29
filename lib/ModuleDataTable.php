<?php

namespace Pragma\ImportModule;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\Entity\IntegerField;
use Bitrix\Main\Entity\StringField;
use Bitrix\Main\Entity\TextField;
use Bitrix\Main\Config\Option;

class ModuleDataTable extends DataManager 
{
    private static function getModuleVersionData()
    {
        $arModuleVersion = [];
        include __DIR__ . '/../install/version.php';
        return $arModuleVersion;
    }
    public static function getTableName()
    {
        return 'pragma_importmodule_module_data'; 
    }

    public static function getMap()
    {
        $moduleId = self::getModuleVersionData()['MODULE_ID']; 
        $importMappings = unserialize(Option::get($moduleId, "IMPORT_MAPPINGS"));
    
        $fields = [
            new IntegerField('ID', [
                'primary' => true,
                'autocomplete' => true,
            ]),
            new TextField('TARGET_SECTION_ID', [ 
                'serialized' => true 
            ]), 
            new IntegerField('SOURCE_SECTION_ID'),
            new IntegerField('ELEMENT_ID'),
            new StringField('ELEMENT_NAME', ['size' => 255]),
            new StringField('ELEMENT_XML_ID', ['size' => 255]),
            new StringField('SIZE_VALUE_ID', ['size' => 255]),
            new StringField('COLOR_VALUE_ID', ['size' => 255]),
            new StringField('TYPE_VALUE_ID', ['size' => 255]),
            new StringField('CHAIN_TOGEZER', [
                'size' => 255,
                'default_value' => ''
            ]),
             // New field for FILTER_PROPERTIES
             new TextField('CATALOG_PROPERTIES', [
                'serialized' => true
            ]),
            new TextField('OFFER_PROPERTIES', [
                'serialized' => true
            ]),
        ];
    

        if (!empty($importMappings)) {
            foreach ($importMappings as $mapping) {
                foreach ($mapping['PROPERTIES'] as $property) {
                    $code = $property['CODE'];
                    $fields[] = new StringField($code, ['size' => 255]);
                }
            }
        } else {

            $fields[] = new StringField('ELEMENT_ARTICLE', ['size' => 255]);
            $fields[] = new StringField('ELEMENT_BARCODE', ['size' => 255]);
        }
    
        return $fields;
    }
}