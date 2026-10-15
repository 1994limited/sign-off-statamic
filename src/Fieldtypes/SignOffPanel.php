<?php

namespace NineteenNinetyFour\SignOff\Fieldtypes;

use NineteenNinetyFour\SignOff\Panel;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;
use Statamic\Fields\Fieldtype;

/**
 * The Sign-off panel in an entry's sidebar. Sign-off adds it to entries in
 * covered collections (see Listeners\AddPanel); it isn't one you add to a
 * blueprint, and it never saves anything.
 */
class SignOffPanel extends Fieldtype
{
    protected static $handle = 'sign_off';

    protected $selectable = false;

    protected $icon = 'checkmark';

    public function preload()
    {
        $entry = $this->field?->parent();

        return [
            'panel' => $entry instanceof Entry ? app(Panel::class)->for($entry, User::current()) : null,
        ];
    }

    public function preProcess($data)
    {
        return null;
    }

    public function process($data)
    {
        return null;
    }

    public function augment($value)
    {
        return null;
    }
}
