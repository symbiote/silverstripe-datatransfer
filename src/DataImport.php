<?php

namespace Symbiote\DataTransfer;

use Exception;
use SilverStripe\Assets\File;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;

class DataImport extends DataObject
{
    private static $table_name = 'DataImport';

    private static $db = [
        'Title' => 'Varchar',
        'ImportKey' => 'Varchar',
        'SuppliedKey' => 'Varchar',
        'DoImport' => 'Boolean',
        'ImportData' => 'Text',
    ];

    private static $allowed_import_urls = [];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->dataFieldByName('ImportKey')->setReadonly(true);
        $fields->dataFieldByName('SuppliedKey')->setRightTitle('Enter the import key to trigger this import. WARNING: This _will_ overwrite data');
        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        if (!$this->ImportKey) {
            $this->ImportKey = mt_rand(10000, 99999);
        }

        if ($this->DoImport && ($this->SuppliedKey == $this->ImportKey)) {
            $this->ImportKey = mt_rand(10000, 99999);
            $this->DoImport = false;

            $data = json_decode($this->ImportData, true);
            if ($data && isset($data['items'])) {
                $this->importItems($data);
            }
        }

        $this->SuppliedKey = '';
    }

    public function importItems($dataset)
    {
        Environment::increaseTimeLimitTo(300);

        $bindOnes = function ($item, $object) {
            foreach ($item['one'] as $name => $id) {
                $one = $this->findItem($id['id']);
                if ($one) {
                    $f = "{$name}ID";
                    $object->$f = $one->ID;
                }
            }
        };

        $bindMany = function ($item, $object) {
            foreach ($item['many'] as $name => $listOfItems) {
                $list = $object->$name();
                if (!$list || !($list instanceof ManyManyList || $list instanceof HasManyList)) {
                    continue;
                }
                foreach ($listOfItems as $id) {
                    $one = $this->findItem($id['id']);
                    if ($one) {
                        $list->add($one);
                    }
                }
            }
        };

        $bindElements = function ($item, $object) {
            foreach ($item['elements'] as $id) {
                $element = $this->findItem($id['id']);
                if ($element) {
                    $object->Elements()->Elements()->add($element);
                }
            }
        };

        foreach ($dataset['items'] as $item) {
            $type = $item['id']['ClassName'];
            $object = $this->findItem($item['id']);

            if (!$object) {
                if ($this->existingOnly($type)) {
                    continue;
                } else {
                    $object = $type::create();
                }
            }

            if ($object instanceof File) {
                unset($item['fields']['File']);
            }
            $object->update($item['fields']);

            if (isset($item['one'])) {
                $bindOnes($item, $object);
            }

            if (isset($item['many'])) {
                $bindMany($item, $object);
            }

            if (isset($item['elements'])) {
                $bindElements($item, $object);
            }

            try {
                $object->write();
                // also set the created date if needbe
                if (isset($item['id']['Created'])) {
                    $object->Created = $item['id']['Created'];
                    $object->write();
                }

                $this->cache[$this->keyHash($item['id'])] = $object;

                if ($object instanceof File) {
                    // check the item fields path key
                    $filePath = $item['fields']['FilePath'] ?? null;
                    $allowed = file_exists(BASE_PATH . DIRECTORY_SEPARATOR . trim($filePath, "/")) ||
                                count(array_filter(self::config()->allowed_import_urls, function ($path) use ($filePath) {
                                    return strpos($filePath, $path) === 0;
                                })) > 0;

                    if ($filePath && $allowed) {
                        if (strpos($filePath, '://') > 0) {
                            $object->setFromStream(fopen($filePath, "r"), $item['fields']['Name'] ?? basename($filePath));
                        } else {
                            $object->setFromLocalFile(BASE_PATH . DIRECTORY_SEPARATOR . trim($filePath, "/"));
                        }

                        $object->write();
                    }
                }
            } catch (Exception $e) {
                // let's just keep going!
            }

        }

        foreach ($dataset['items'] as $item) {
            $type = $item['id']['ClassName'];
            $object = $this->findItem($item['id']);

            if (!$object) {
                // NOOO
                continue;
            }

            // redo ones and manys
            if (isset($item['one'])) {
                $bindOnes($item, $object);
                $object->write();
            }

            if (isset($item['many'])) {
                $bindMany($item, $object);
            }
        }
    }

    private $cache = [];

    public function findItem($key)
    {
        $hash = $this->keyHash($key);
        $type = $key['ClassName'];

        if ($this->existingOnly($type)) {
            unset($key['Created']);
        }

        if (!isset($this->cache[$hash])) {
            unset($key['ClassName']);
            $this->cache[$hash] = $type::get()->filter($key)->first();
        }

        return $this->cache[$hash];
    }

    protected function keyHash($key)
    {
        return md5(json_encode($key));
    }

    protected function existingOnly($type)
    {
        $config = self::config()->import_config ?? [];
        $config = $config[$type] ?? [];
        $existing_only = $config['existing_only'] ?? false;
        return $existing_only === true;
    }
}
