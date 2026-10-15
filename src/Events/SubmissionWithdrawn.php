<?php

namespace NineteenNinetyFour\SignOff\Events;

use Illuminate\Foundation\Events\Dispatchable;
use NineteenNinetyFour\SignOff\Submission;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * The submission was withdrawn. $user withdrew it.
 */
class SubmissionWithdrawn
{
    use Dispatchable;

    public function __construct(
        public readonly Submission $submission,
        public readonly Entry $entry,
        public readonly User $user,
    ) {}
}
