<?php

namespace NineteenNinetyFour\SignOff;

use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;
use Statamic\Statamic;

/**
 * Which collections need sign-off, and their steps (config: sign-off.collections).
 */
class Rules
{
    /**
     * The handles of the collections that have steps.
     *
     * @return array<int, string>
     */
    public function collections(): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys((array) config('sign-off.collections', []))),
            fn (string $handle) => $this->covers($handle),
        ));
    }

    public function covers(Entry|Collection|string $subject): bool
    {
        return $this->steps($subject) !== [];
    }

    /**
     * @return array<int, Step>
     */
    public function steps(Entry|Collection|string $subject): array
    {
        $handle = $this->handle($subject);
        $steps = [];

        foreach (array_values((array) config("sign-off.collections.{$handle}.steps", [])) as $index => $config) {
            if (! is_array($config)) {
                continue;
            }

            $step = Step::fromConfig($config, $index);

            // Two steps with one handle would share an approval, so the
            // second gets its own.
            $unique = $step->handle;
            for ($n = 2; isset($steps[$unique]); $n++) {
                $unique = "{$step->handle}_{$n}";
            }

            $steps[$unique] = $unique === $step->handle ? $step : new Step($unique, $step->title, $step->approvers);
        }

        return array_values($steps);
    }

    public function step(Entry|Collection|string $subject, string $handle): ?Step
    {
        foreach ($this->steps($subject) as $step) {
            if ($step->handle === $handle) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Sign-off runs on Statamic Pro, for collections with revisions on.
     * Anything else is left to Statamic's own rules.
     */
    public function enforced(Entry $entry): bool
    {
        return Statamic::pro() && $this->covers($entry) && $entry->revisionsEnabled();
    }

    /**
     * Setup problems are shown to people who can fix them.
     */
    public function showsProblemsTo(?\Statamic\Contracts\Auth\User $user): bool
    {
        return $user !== null && ($user->isSuper() || $user->hasPermission('configure collections'));
    }

    /**
     * Whether the collection is listed in the config at all, working or not.
     */
    public function configured(Entry|Collection|string $subject): bool
    {
        return array_key_exists($this->handle($subject), (array) config('sign-off.collections', []));
    }

    /**
     * What's wrong with the setup, worst first. Problems with no collection
     * stop sign-off everywhere; the rest are about one collection.
     *
     * @return array<int, array{collection: ?string, message: string, blocking: bool}>
     */
    public function problems(): array
    {
        $problems = [];
        $add = function (?string $collection, string $message, bool $blocking = true) use (&$problems) {
            $problems[] = compact('collection', 'message', 'blocking');
        };

        if (! Statamic::pro()) {
            $add(null, __('Sign-off needs Statamic Pro. Until then, entries are published as usual.'));
        }

        if (! config('statamic.revisions.enabled')) {
            $add(null, __('Revisions are off, so sign-off isn\'t running. Set STATAMIC_REVISIONS_ENABLED=true.'));
        }

        foreach ((array) config('sign-off.collections', []) as $handle => $config) {
            $handle = (string) $handle;
            $collection = \Statamic\Facades\Collection::find($handle);
            $steps = (array) ($config['steps'] ?? []);

            if ($collection === null) {
                $add($handle, __('There is no collection with this handle.'));

                continue;
            }

            if (! $collection->revisionsEnabled()) {
                $add($handle, __('Revisions are off for this collection, so sign-off isn\'t running for it. Turn on revisions in the collection\'s settings.'));
            }

            if ($steps === []) {
                $add($handle, __('It has no steps, so sign-off isn\'t running for it.'));
            }

            $seen = [];

            foreach (array_values($steps) as $index => $step) {
                $number = $index + 1;

                if (! is_array($step)) {
                    $add($handle, __('Step :number isn\'t a list of settings, so it\'s ignored.', ['number' => $number]));

                    continue;
                }

                $built = Step::fromConfig($step, $index);
                $name = $built->title;

                if (($step['handle'] ?? '') === '') {
                    $add($handle, __('Step :number (:step) has no handle, so it uses ":handle". Give it one, so reviews in progress keep their place if the steps change.', ['number' => $number, 'step' => $name, 'handle' => $built->handle]), false);
                } elseif (isset($seen[$built->handle])) {
                    $add($handle, __('Two steps have the handle ":handle". Each needs its own, or reviews in progress can lose their place.', ['handle' => $built->handle]), false);
                }

                $seen[$built->handle] = true;

                foreach ($this->unknownApprovers($built) as $problem) {
                    $add($handle, __(':step: :problem', ['step' => $name, 'problem' => $problem]), false);
                }
            }
        }

        return $problems;
    }

    /**
     * @return array<int, string>
     */
    public function problemsFor(Entry|Collection|string $subject): array
    {
        $handle = $this->handle($subject);

        return collect($this->problems())
            ->filter(fn (array $problem) => $problem['collection'] === null || $problem['collection'] === $handle)
            ->pluck('message')->values()->all();
    }

    /**
     * Approvers that match nobody: until they're fixed, only super users
     * can approve the step.
     *
     * @return array<int, string>
     */
    protected function unknownApprovers(Step $step): array
    {
        if ($step->approvers === 'publishers') {
            return [];
        }

        if (is_string($step->approvers)) {
            return [__('approvers should be "publishers" or a list of roles, groups, users or a permission, not ":value". Only super users can approve it.', ['value' => $step->approvers])];
        }

        if ($step->approvers === []) {
            return [__('nobody is named to approve it, so only super users can.')];
        }

        if (array_is_list($step->approvers)) {
            return [__('approvers needs to say what each name is, like [\'roles\' => [\'legal\']]. Only super users can approve it.')];
        }

        $problems = [];

        foreach ((array) ($step->approvers['roles'] ?? []) as $role) {
            if (! Role::find($role)) {
                $problems[] = __('there is no role ":handle".', ['handle' => $role]);
            }
        }

        foreach ((array) ($step->approvers['groups'] ?? []) as $group) {
            if (! UserGroup::find($group)) {
                $problems[] = __('there is no user group ":handle".', ['handle' => $group]);
            }
        }

        foreach ((array) ($step->approvers['users'] ?? []) as $user) {
            if (! User::find($user) && ! User::findByEmail($user)) {
                $problems[] = __('there is no user ":handle".', ['handle' => $user]);
            }
        }

        $known = ['roles', 'groups', 'users', 'permission', 'publishers'];
        foreach (array_diff(array_map('strval', array_keys($step->approvers)), $known) as $key) {
            $problems[] = __('":key" isn\'t a kind of approver. Use roles, groups, users, permission or publishers.', ['key' => $key]);
        }

        return $problems;
    }

    protected function handle(Entry|Collection|string $subject): string
    {
        return match (true) {
            $subject instanceof Entry => $subject->collectionHandle(),
            $subject instanceof Collection => $subject->handle(),
            default => $subject,
        };
    }
}
