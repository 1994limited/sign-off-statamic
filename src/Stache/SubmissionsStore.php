<?php

namespace NineteenNinetyFour\SignOff\Stache;

use Illuminate\Support\Facades\Log;
use NineteenNinetyFour\SignOff\Submission;
use Statamic\Facades\YAML;
use Statamic\Stache\Stores\BasicStore;
use Statamic\Yaml\ParseException;

/**
 * The file driver's submissions, one YAML file each, named by ID.
 */
class SubmissionsStore extends BasicStore
{
    protected $storeIndexes = ['entry_id', 'state'];

    public function key()
    {
        return 'sign-off-submissions';
    }

    public function makeItemFromFile($path, $contents)
    {
        try {
            $data = YAML::file($path)->parse($contents);
        } catch (ParseException) {
            Log::warning('Could not parse sign-off submission file: '.$path);
            $data = [];
        }

        return Submission::fromArray(pathinfo($path, PATHINFO_FILENAME), is_array($data) ? $data : [])->initialPath($path);
    }
}
