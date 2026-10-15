<?php

namespace NineteenNinetyFour\SignOff\Listeners;

use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\User;
use Statamic\Statamic;

/**
 * Puts the Sign-off panel at the top of an entry's sidebar, in the Control
 * Panel, for entries in covered collections. Its visibility is "computed",
 * so Statamic never saves a value for it.
 */
class AddPanel
{
    public const HANDLE = 'sign_off';

    public function __construct(protected SignOff $signOff) {}

    public function handle(EntryBlueprintFound $event): void
    {
        $entry = $event->entry;

        if (! $entry || ! Statamic::isCpRoute()) {
            return;
        }

        // Where sign-off is set up but not running, the people who can fix
        // it are told why.
        $rules = $this->signOff->rules();

        if (! $this->signOff->enforced($entry) && ! ($rules->configured($entry) && $rules->showsProblemsTo(User::current()))) {
            return;
        }

        $event->blueprint->ensureFieldPrepended(self::HANDLE, [
            'type' => 'sign_off',
            'display' => __('Sign-off'),
            'visibility' => 'computed',
            'listable' => false,
        ], 'sidebar');
    }
}
