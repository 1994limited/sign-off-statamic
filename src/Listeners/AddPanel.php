<?php

namespace NineteenNinetyFour\SignOff\Listeners;

use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Events\EntryBlueprintFound;
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

        if (! $entry || ! Statamic::isCpRoute() || ! $this->signOff->enforced($entry)) {
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
