<?php

namespace Symbiote\DataTransfer;

use DNADesign\ElementalList\Model\ElementList;
use SilverStripe\Assets\File;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\MemberPassword;
use Symbiote\MultiValueField\Fields\KeyValueField;
use Symbiote\MultiValueField\ORM\FieldType\MultiValueField;

class DataExport extends DataObject
{
    private static $table_name = 'DataExport';

    private static $db = [
        'Title' => 'Varchar',
        'Type' => 'Varchar(255)',
        'Filter' => MultiValueField::class,
        'IncludeRelations' => 'Boolean',
        'IncludeAllFields' => 'Boolean',
        'DoExport' => 'Boolean',
        'ExportedData' => 'Text',
    ];

    private static $max_export = 100;

    /**
     * The fields and relationships to export for a given type
     *
     * For fields, rather than specifying each individually, use '*' to grab all
     *
     * For relationships, the true/false indicates whether to
     * export the actual objects for those relationships or not.
     *
     * @var array
     */
    private static $export_fields = [
        Member::class => [
            'id' => [
                'Email'
            ],
            'fields' => [
                'FirstName',
                'Surname',
                'Email'
            ]
        ],
        Group::class => [
            'fields' => [
                'Title',
                'Description',
            ],
            'one' => [
                'Parent' => false,
            ],
            'many' => [
                'Groups' => true,
            ]
        ],
        SiteTree::class => [
            'fields' => [
                'Title',
                'URLSegment',
                'Summary',
                'Content',
                'MenuTitle',
                'ShowInMenus',
                'ShowInSearch',
            ],
            'one' => [
                'ElementalArea' => true,
                'Parent' => false,
            ],
            'many' => [
                'Terms' => false,
                'AllChildren' => true,
            ]
        ]
    ];

    private static $export_cache;

    private static $config_cache = [];

    /**
     * Classes listed in here won't be affected by the `IncludeAllFields` flag.
     * They will either need a configured list of fields to be exported, or will
     * default to 'Title', 'Name', 'Content', 'HTML'.
     */
    private static $export_protected_classes = [
        Member::class,
        MemberPassword::class,
    ];

    private static $defaults = [
        'IncludeRelations' => true,
        'IncludeAllFields' => false,
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('Filter', KeyValueField::create('Filter'));

        $types = ClassInfo::subclassesFor(DataObject::class);
        // Making search by class name easier since `DropdownField` doesn't
        // support partial matches
        $types = array_map(function ($type) {
            $segments = explode('\\', $type);

            return $segments[count($segments) - 1] . " ({$type})";
        }, $types);
        asort($types);

        $fields->replaceField('Type', DropdownField::create('Type', 'Data type', $types)->setEmptyString('Please select'));

        $fields->dataFieldByName('IncludeRelations')->setRightTitle('Setting this false will exclude any related object being exported regardless of configuration');
        $fields->dataFieldByName('IncludeAllFields')->setRightTitle('Setting this will overwrite configs and export the objects with all the properties. (Does not apply to protected classes.)');

        $fields->dataFieldByName('ExportedData')->setReadonly(true);

        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if ($this->DoExport) {
            $this->DoExport = false;

            if ($this->Type) {
                $this->ExportedData = $this->export();
            }
        }
    }

    public function export()
    {
        Environment::increaseTimeLimitTo(300);

        $list = DataList::create($this->Type);
        $filter = $this->Filter->getValues();
        if ($filter) {
            $list = $list->filter($filter);
        }

        $ex = [];
        // $dependent = [];

        if ($list->count() > self::config()->max_export) {
            return json_encode(['error' => 'More than ' . self::config()->max_export . ' items found, please supply a filter'], JSON_PRETTY_PRINT);
        }

        foreach ($list as $item) {
            $item = $this->exportObject($item, $ex);
            if ($item) {
                $ex[] = $item;
            }
        }

        // reverse the order of creation so dependent items get created first
        $ex = array_reverse($ex);

        return json_encode(['items' => $ex], JSON_PRETTY_PRINT);
    }

    /**
     * Converts an object into a serialised form used for sending over the wire
     *
     * By default, takes the properties defined directly on it and any has_ones and
     * converts to a format readable by the unsyncroObject method
     *
     * @param DataObject $item
     *          The item to export
     * @param array $dependent
     *          An array to store extra related objects
     */
    public function exportObject(DataObject $item, &$dependent)
    {
        if (!$item->canView()) {
            return;
        }
        if (!$item->Created) {
            return;
        }

        $config = $this->configForType($item);

        $ones = $config['one'] ?? null;
        $many = $config['many'] ?? null;
        $elements = $config['elements'] ?? false;

        $export_protected = self::config()->export_protected_classes;
        if ($this->IncludeAllFields && !in_array(get_class($item), $export_protected)) {
            $props = '*';
            $elements = true;
        } else {
            $props = $config['fields'] ?? ['Title', 'Name', 'Content', 'HTML'];
        }

        $properties = array(
            'id' => $this->makeContentId($item),
            'fields' => [],
        );

        $ignore = array('Created', 'ID', 'Version');
        if (!$props || $props == '*') {
            $props = Config::inst()->get(get_class($item), 'db');
            foreach ($ignore as $unset) {
                unset($props[$unset]);
            }
            $props = array_keys($props);
        }

        foreach ($props as $name) {
            // check for multivalue fields explicitly
            $obj = $item->dbObject($name);
            if ($obj instanceof MultiValueField) {
                $v = $obj->getValues();
                if (is_array($v)) {
                    $properties['fields'][$name] = $v;
                }
            } else {
                $properties['fields'][$name] = $item->$name;
            }
        }

        if ($item instanceof File) {
            $properties['fields']['FilePath'] = $item->getAbsoluteURL();
        }

        if ($ones) {
            $properties['one'] = [];
            foreach ($ones as $name => $expand) {
                // get the object
                $object = $item->hasMethod($name) ? $item->$name() : null;
                if ($object && $object->exists()) {
                    $properties['one'][$name] = array('id' => $this->makeContentId($object));
                }
                if ($expand && $this->IncludeRelations) {
                    if ($object->Created) {
                        $dependent[] = $this->exportObject($object, $dependent);
                    }
                }
            }
        }

        if ($many) {
            $properties['many'] = [];
            foreach ($many as $name => $expand) {
                $rel = $item->hasMethod($name) ? $item->$name() : null;
                foreach ($rel as $object) {
                    if ($object && $object->exists()) {
                        $properties['many'][$name][] = array('id' => $this->makeContentId($object));
                    }
                    if ($expand && $this->IncludeRelations) {
                        if ($object->Created) {
                            $dependent[] = $this->exportObject($object, $dependent);
                        }
                    }
                }
            }
        }

        if ($elements === true && is_subclass_of($item, ElementList::class)) {
            $elements = $item->Elements()->Elements()->toArray();

            foreach ($elements as $object) {
                if ($object && $object->exists()) {
                    $properties['elements'][] = ['id' => $this->makeContentId($object)];
                }
                if ($this->IncludeRelations) {
                    if ($object->Created) {
                        $dependent[] = $this->exportObject($object, $dependent);
                    }
                }
            }
        }

        return $properties;
    }

    protected function configForType($type)
    {
        if (!is_string($type)) {
            $type = get_class($type);
        }

        if (isset(self::$config_cache[$type])) {
            return self::$config_cache[$type];
        }
        $config = [];
        foreach (self::config()->export_fields as $typeInfo => $data) {
            if (is_a($type, $typeInfo, true)) {
                $config = array_merge_recursive($config, $data);
            }
        }

        // ensure classname is there, it's used for loading later!
        if (isset($config['id']) && !in_array('ClassName', $config['id'])) {
            $config['id'][] = 'ClassName';
        }

        self::$config_cache[$type] = $config;
        return self::$config_cache[$type];
    }

    protected function makeContentId($item)
    {
        $config = $this->configForType($item);
        $fields = $config['id'] ?? ['Created', 'ClassName'];

        $parts = [];
        foreach ($fields as $defaultField) {
            $parts[$defaultField] = $item->$defaultField;
        }

        if ($item->OwnerClassName) {
            $parts['OwnerClassName'] = $item->OwnerClassName;
        }
        if ($item->hasDatabaseField('Title')) {
            $parts['Title'] = $item->Title;
        }
        if ($item->hasDatabaseField('Name')) {
            $parts['Name'] = $item->Name;
        }
        if ($item->hasDatabaseField('URLSegment')) {
            $parts['URLSegment'] = $item->URLSegment;
        }

        return $parts;
    }
}
