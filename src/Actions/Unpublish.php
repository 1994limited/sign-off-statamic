<?php

namespace NineteenNinetyFour\SignOff\Actions;

use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Actions\Unpublish as StatamicUnpublish;
use Statamic\Contracts\Entries\Entry;
use Statamic\Policies\EntryPolicy;

/**
 * Statamic's own Unpublish action (in entry listings), which asks whether
 * the user may publish. Sign-off says no to that for entries that need
 * signing off, but taking an entry down follows Statamic's usual rules
 * (config: sign-off.unpublish_without_sign_off), so it asks those here.
 */
class Unpublish extends StatamicUnpublish
{
    protected static $handle = 'unpublish';

    public function authorize($user, $entry)
    {
        $signOff = app(SignOff::class);

        if ($entry instanceof Entry && $signOff->enforced($entry) && config('sign-off.unpublish_without_sign_off', true)) {
            return $user->isSuper() || app(EntryPolicy::class)->publish($user, $entry);
        }

        return parent::authorize($user, $entry);
    }
}
