<?php

namespace NineteenNinetyFour\SignOff\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NineteenNinetyFour\SignOff\Submission;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * A step was approved. $user approved it.
 */
class EntryApproved
{
    use Dispatchable;

    public function __construct(
        public readonly Submission $submission,
        public readonly Entry $entry,
        public readonly User $user,
        public readonly string $step,
    ) {}
}
