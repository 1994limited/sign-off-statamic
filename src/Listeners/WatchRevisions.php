<?php

namespace NineteenNinetyFour\SignOff\Listeners;

use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Events\EntrySaved;
use Statamic\Events\RevisionSaved;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Keeps sign-off's history true to what happened outside it:
 *
 * - a publish that wasn't a final approval (someone allowed to skip
 *   sign-off) is recorded on the entry;
 * - a change to content in review (only possible for someone the lock
 *   doesn't stop, or from code) makes its approvals outdated.
 */
class WatchRevisions
{
    public function __construct(protected SignOff $signOff) {}

    public function revisionSaved(RevisionSaved $event): void
    {
        $revision = $event->revision;

        if (! str_starts_with((string) $revision->key(), 'collections/')) {
            return;
        }

        $entry = Entry::find(basename((string) $revision->key()));

        if (! $entry || ! $this->signOff->enforced($entry) || $this->signOff->isPublishing($entry)) {
            return;
        }

        $user = $this->user($revision->user());

        if ($revision->action() === 'publish') {
            $this->signOff->recordSkip($entry, $user, $revision->message());

            return;
        }

        if ($revision->isWorkingCopy()) {
            $this->signOff->contentChanged($entry, $user);
        }
    }

    /**
     * An entry that was never published is edited in place, not in a
     * working copy.
     */
    public function entrySaved(EntrySaved $event): void
    {
        $entry = $event->entry;

        if ($entry->published() || ! $this->signOff->enforced($entry)) {
            return;
        }

        $this->signOff->contentChanged($entry, User::current());
    }

    protected function user(mixed $user): ?UserContract
    {
        return match (true) {
            $user instanceof UserContract => $user,
            is_string($user) && $user !== '' => User::find($user),
            default => User::current(),
        };
    }
}
