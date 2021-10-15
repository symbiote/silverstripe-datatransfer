<?php

namespace Symbiote\DataTransfer;

use DNADesign\Elemental\Models\BaseElement;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Symbiote\DataTransfer\DataImport;
use Symbiote\Multisites\Model\Site;
use Symbiote\Multisites\Multisites;

class SeedWebsiteTask extends BuildTask
{
    private static $segment = 'SeedWebsiteTask';

    /**
     * Array of glob strings for matching import locations
     *
     * @var string[]
     */
    private static $import_paths = [];

    /**
     * Set test email to reset password
     * @var string
     */
    private static $tester_email = '';

    /**
     * Set new password
     * @var string
     */
    private static $tester_pass = '';

    /**
     * Don't run the task in production site, set production host
     * @var string
     */
    private static $production_host = '';

    /**
     * For Multisite hosts, set an array of strings
     * @var string[]
     */
    private static $host_aliases = [];

    public function run($request)
    {
        if (!Director::is_cli()) {
            exit("Please run from CLI");
        }

        Versioned::set_stage(Versioned::DRAFT);

        // need to make sure the 'default' site is set, if available
        if (class_exists(Multisites::class)) {
            $cache = Injector::inst()->get(CacheInterface::class . '.multisites');
            if ($cache->has(Multisites::CACHE_KEY)) {
                $cache->delete(Multisites::CACHE_KEY);
            }

            Multisites::inst()->build();

            $defaultSite = Multisites::inst()->getDefaultSite();
            if ($defaultSite && $defaultSite->ID && self::config()->production_host && $defaultSite->Host == self::config()->production_host) {
                exit("Cannot run on production environment");
            }
        }


        $paths = self::config()->import_paths;
        if ($request->getVar('paths')) {
            $supplied = explode(',', $request->getVar('paths'));
            $supplied = array_filter($supplied, function ($p) use ($paths) {
                return in_array($p, $paths);
            });

            $paths = $supplied;
        }

        // find data imports
        foreach ($paths as $path) {
            $path = BASE_PATH . DIRECTORY_SEPARATOR . $path;
            if (!file_exists($path)) {
                Debug::message("Missing import file $path");
                continue;
            }
            $data = json_decode(file_get_contents($path), true);

            // just need a shell
            $import = DataImport::create();
            if ($data && isset($data['items'])) {
                Debug::message("Importing data from $path");
                $import->importItems($data);
                foreach ($data['items'] as $item) {
                    $object = $import->findItem($item['id']);

                    if ($object instanceof Site) {
                        Debug::message("Setting host aliases");

                        $object->IsDefault = true;
                        $object->obj('HostAliases')->setValue(self::config()->host_aliases);
                        $object->write();
                    }

                    if ($object && !($object instanceof BaseElement)) {
                        if ($object->hasMethod('publishRecursive')) {
                            Debug::message("Publishing $object->Title", false);
                            $object->publishRecursive();
                        } else  if ($object->hasMethod('doPublish')) {
                            $object->doPublish();
                        }
                    }
                }
            }
        }

        $testerEmail = self::config()->tester_email;

        $tester = Member::get()->filter('Email', $testerEmail)->first();

        $pass = self::config()->tester_pass;
        if (strlen($pass) == 0) {
            $pass = strrev($testerEmail);
        }

        if ($tester && $tester->exists()) {
            Debug::message("Resetting testing user password");
            $tester->changePassword($pass);
        }

        if (class_exists(Multisites::class)) {
            Multisites::inst()->build();
        }
    }
}
