<?php

namespace NineteenNinetyFour\SignOff;

use Statamic\Contracts\Entries\Collection;
use Statamic\Contracts\Entries\Entry;
use Statamic\Statamic;

/**
 * Which collections need sign-off, and their steps (config: sign-off.collections).
 */
class Rules
{
    public function covers(Entry|Collection|string $subject): bool
    {
        return $this->steps($subject) !== [];
    }

    /**
     * @return array<int, Step>
     */
    public function steps(Entry|Collection|string $subject): array
    {
        $handle = match (true) {
            $subject instanceof Entry => $subject->collectionHandle(),
            $subject instanceof Collection => $subject->handle(),
            default => $subject,
        };

        return array_map(
            fn (array $step) => Step::fromConfig($step),
            array_values((array) config("sign-off.collections.{$handle}.steps", [])),
        );
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
     * Collections configured for sign-off that can't use it yet, and why.
     *
     * @return array<string, string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach (array_keys((array) config('sign-off.collections', [])) as $handle) {
            $collection = \Statamic\Facades\Collection::find($handle);

            $problems[$handle] = match (true) {
                ! Statamic::pro() => __('Sign-off needs Statamic Pro.'),
                ! config('statamic.revisions.enabled') => __('Revisions are off. Set STATAMIC_REVISIONS_ENABLED=true.'),
                $collection === null => __('There is no collection with this handle.'),
                ! $collection->revisionsEnabled() => __('Revisions are off for this collection. Turn them on in its settings.'),
                $this->steps($handle) === [] => __('It has no steps.'),
                default => null,
            };
        }

        return array_filter($problems);
    }
}
