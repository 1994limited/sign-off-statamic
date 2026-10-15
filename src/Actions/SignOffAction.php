<?php

namespace NineteenNinetyFour\SignOff\Actions;

use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\CP\Toast;
use Statamic\Facades\User;

/**
 * Sign-off's actions work on one entry at a time, in its menu on the
 * entry's page and in the collection's listing, and reload the page
 * afterwards (an entry in review becomes read-only).
 */
abstract class SignOffAction extends Action
{
    public function visibleTo($item)
    {
        return $item instanceof Entry && $this->signOff()->enforced($item) && $this->shows($item);
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $item)
    {
        return $item instanceof Entry && $this->allowed(User::fromUser($user), $item);
    }

    public function triggersFullPageRefresh(): bool
    {
        return true;
    }

    /** Whether run() succeeded, so redirect() knows whether to reload. */
    protected bool $succeeded = false;

    /**
     * Back to the entry, freshly loaded, after it worked: on its page, the
     * full refresh alone doesn't pick up the lock. (A failure stays put, so
     * its message shows.)
     */
    public function redirect($items, $values)
    {
        return $this->succeeded && ($this->context['view'] ?? null) === 'form' ? $items->first()?->editUrl() : false;
    }

    /**
     * Report success: a toast that survives the reload, and the message.
     */
    protected function done(string $message): string
    {
        $this->succeeded = true;
        Toast::success($message);

        return $message;
    }

    public function bypassesDirtyWarning(): bool
    {
        return false;
    }

    /**
     * Whether the action belongs on this entry right now.
     */
    abstract protected function shows(Entry $entry): bool;

    /**
     * Whether this user may run it.
     */
    abstract protected function allowed(\Statamic\Contracts\Auth\User $user, Entry $entry): bool;

    protected function signOff(): SignOff
    {
        return app(SignOff::class);
    }

    protected function entry($items): Entry
    {
        return $items->first();
    }
}
