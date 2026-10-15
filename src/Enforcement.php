<?php

namespace NineteenNinetyFour\SignOff;

use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;
use Statamic\Statamic;

/**
 * Sign-off's say in Statamic's permission checks (a Gate::before, so it
 * applies before Statamic's own rules, super users included).
 *
 * - Publishing an entry in a covered collection is refused unless it's the
 *   last approval doing it, or the user can skip sign-off.
 * - Making a new entry published straight away is refused the same way.
 * - Editing an entry in review is refused while it's locked.
 *
 * Anything else returns null: Statamic decides as usual.
 */
class Enforcement
{
    public function __construct(protected SignOff $signOff) {}

    public function check(mixed $user, string $ability, array $arguments): ?bool
    {
        if (! in_array($ability, ['publish', 'edit', 'update'], true) || ! Statamic::pro()) {
            return null;
        }

        $subject = $arguments[0] ?? null;

        if ($ability === 'publish' && $subject === Entry::class) {
            return $this->publishingNewEntry($user, $arguments[1] ?? null);
        }

        if (! $subject instanceof Entry || ! $this->signOff->enforced($subject)) {
            return null;
        }

        if ($ability === 'publish') {
            return $this->publishing($user, $subject);
        }

        return $this->signOff->isLocked($subject) ? false : null;
    }

    protected function publishing(mixed $user, Entry $entry): ?bool
    {
        if ($this->signOff->isPublishing($entry) || $this->isUnpublishing()) {
            return null;
        }

        $user = User::fromUser($user);

        return $user && $this->signOff->canSkip($user) ? null : false;
    }

    /**
     * Creating an entry asks whether it may be published straight away.
     */
    protected function publishingNewEntry(mixed $user, mixed $collection): ?bool
    {
        if (! $collection instanceof Collection || ! $this->signOff->rules()->covers($collection) || ! $collection->revisionsEnabled()) {
            return null;
        }

        $user = User::fromUser($user);

        return $user && $this->signOff->canSkip($user) ? null : false;
    }

    /**
     * Taking an entry down uses the same permission check as publishing.
     * Unless turned off, it's left to Statamic's normal rules.
     */
    protected function isUnpublishing(): bool
    {
        return config('sign-off.unpublish_without_sign_off', true)
            && str_ends_with((string) request()->route()?->getName(), 'collections.entries.published.destroy');
    }
}
