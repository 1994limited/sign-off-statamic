<?php

namespace NineteenNinetyFour\SignOff;

use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;

/**
 * One step an entry goes through before it's published, and who can
 * approve it (config: sign-off.collections.*.steps).
 */
final class Step
{
    /**
     * @param  string|array<string, mixed>  $approvers  "publishers", or roles / groups / users / permission
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $title,
        public readonly string|array $approvers,
    ) {}

    public static function fromConfig(array $step): self
    {
        $handle = (string) ($step['handle'] ?? '');

        if ($handle === '') {
            throw new \InvalidArgumentException('Each sign-off step needs a handle.');
        }

        return new self($handle, (string) ($step['title'] ?? ucfirst($handle)), $step['approvers'] ?? 'publishers');
    }

    public function canBeApprovedBy(User $user, Entry $entry): bool
    {
        return $user->isSuper() || $this->names($user, $entry);
    }

    /**
     * Whether the step's own rule names the user (super users aside: they
     * can approve any step, but aren't asked to unless nobody else can).
     */
    public function names(User $user, Entry $entry): bool
    {
        if ($this->approvers === 'publishers') {
            return $user->hasPermission("publish {$entry->collectionHandle()} entries");
        }

        $approvers = (array) $this->approvers;

        if (array_intersect((array) ($approvers['roles'] ?? []), $user->roles()->map->handle()->values()->all())) {
            return true;
        }

        if (array_intersect((array) ($approvers['groups'] ?? []), $user->groups()->map->handle()->values()->all())) {
            return true;
        }

        $users = array_map('strval', (array) ($approvers['users'] ?? []));

        if (in_array((string) $user->id(), $users, true) || in_array((string) $user->email(), $users, true)) {
            return true;
        }

        if (isset($approvers['permission']) && $user->hasPermission((string) $approvers['permission'])) {
            return true;
        }

        if (isset($approvers['publishers']) && $approvers['publishers'] && $user->hasPermission("publish {$entry->collectionHandle()} entries")) {
            return true;
        }

        return false;
    }

    /**
     * Who can approve, in words, for the Control Panel.
     */
    public function describeApprovers(): string
    {
        if ($this->approvers === 'publishers') {
            return __('Anyone who can publish');
        }

        $parts = [];
        $approvers = (array) $this->approvers;

        foreach (['roles' => 'Role', 'groups' => 'Group', 'users' => 'User'] as $key => $label) {
            foreach ((array) ($approvers[$key] ?? []) as $value) {
                $parts[] = __($label).': '.$value;
            }
        }

        if (isset($approvers['permission'])) {
            $parts[] = __('Permission').': '.$approvers['permission'];
        }

        if (! empty($approvers['publishers'])) {
            $parts[] = __('Anyone who can publish');
        }

        return implode(', ', $parts) ?: __('Super users only');
    }

    public function toArray(): array
    {
        return ['handle' => $this->handle, 'title' => $this->title];
    }
}
