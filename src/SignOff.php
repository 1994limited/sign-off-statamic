<?php

namespace NineteenNinetyFour\SignOff;

use Illuminate\Support\Collection;
use NineteenNinetyFour\SignOff\Contracts\SubmissionRepository;
use NineteenNinetyFour\SignOff\Events\EntryApproved;
use NineteenNinetyFour\SignOff\Events\EntryRejected;
use NineteenNinetyFour\SignOff\Events\EntrySignedOff;
use NineteenNinetyFour\SignOff\Events\EntrySubmitted;
use NineteenNinetyFour\SignOff\Events\SubmissionWithdrawn;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Policies\EntryPolicy;

/**
 * Everything that can happen to an entry on its way to being published,
 * and the rules for who can do it. Actions, the Control Panel and your
 * own code all go through here.
 */
class SignOff
{
    /** @var array<string, true> entries being published by a final approval */
    protected static array $publishing = [];

    public function __construct(
        protected Rules $rules,
        protected SubmissionRepository $submissions,
    ) {}

    public function rules(): Rules
    {
        return $this->rules;
    }

    public function submissions(): SubmissionRepository
    {
        return $this->submissions;
    }

    public function enforced(Entry $entry): bool
    {
        return $this->rules->enforced($entry);
    }

    /**
     * The entry's submission in review, if any.
     */
    public function open(Entry $entry): ?Submission
    {
        return $this->submissions->openFor((string) $entry->id());
    }

    /**
     * @return Collection<int, Submission>
     */
    public function history(Entry $entry): Collection
    {
        return $this->submissions->forEntry((string) $entry->id());
    }

    public function isLocked(Entry $entry): bool
    {
        return config('sign-off.lock_during_review', true) && $this->enforced($entry) && $this->open($entry) !== null;
    }

    /**
     * Whether there's anything to review: an entry that was never
     * published, or a published entry with changes in its working copy.
     */
    public function hasChanges(Entry $entry): bool
    {
        return ! $entry->published() || $entry->hasWorkingCopy();
    }

    /*
    |--------------------------------------------------------------------------
    | Submitting
    |--------------------------------------------------------------------------
    */

    public function whyCannotSubmit(User $user, Entry $entry): ?string
    {
        return match (true) {
            ! $this->enforced($entry) => __('sign-off::messages.not_covered'),
            $this->open($entry) !== null => __('sign-off::messages.already_in_review'),
            ! $this->hasChanges($entry) => __('sign-off::messages.nothing_to_review'),
            ! $this->canEdit($user, $entry) => __('sign-off::messages.cannot_edit'),
            default => null,
        };
    }

    public function submit(User $user, Entry $entry, ?string $note = null): Submission
    {
        if ($reason = $this->whyCannotSubmit($user, $entry)) {
            throw new SignOffException($reason);
        }

        $content = Fingerprint::content($entry);
        $submission = Submission::make((string) $entry->id(), $entry->collectionHandle(), (string) $entry->locale(), $this->rules->steps($entry))
            ->submit((string) $user->id(), Fingerprint::hash($content), $this->clean($note), $content);

        // Only the round before this one is ever compared with.
        $this->history($entry)->slice(1)->filter(fn (Submission $old) => $old->snapshot() !== null)
            ->each(fn (Submission $old) => $this->submissions->save($old->forgetSnapshot()));

        $this->save($submission);

        EntrySubmitted::dispatch($submission, $entry, $user);

        return $submission;
    }

    /*
    |--------------------------------------------------------------------------
    | Reviewing
    |--------------------------------------------------------------------------
    */

    public function whyCannotReview(User $user, Entry $entry): ?string
    {
        $submission = $this->open($entry);
        $step = $submission?->currentStep();

        return match (true) {
            $submission === null || $step === null => __('sign-off::messages.not_in_review'),
            ! $this->stepFor($entry, $step['handle'])?->canBeApprovedBy($user, $entry) => __('sign-off::messages.not_your_step', ['step' => $step['title']]),
            ! config('sign-off.self_approval', false) && $submission->submittedBy() === (string) $user->id() && ! $user->isSuper() => __('sign-off::messages.own_submission'),
            default => null,
        };
    }

    public function canReview(User $user, Entry $entry): bool
    {
        return $this->whyCannotReview($user, $entry) === null;
    }

    /**
     * Approve the current step. The last step's approval publishes the entry.
     */
    public function approve(User $user, Entry $entry, ?string $comment = null): Submission
    {
        if ($reason = $this->whyCannotReview($user, $entry)) {
            throw new SignOffException($reason);
        }

        $submission = $this->open($entry);
        $this->ensureUnchanged($submission, $entry);

        $step = $submission->currentStep();
        $submission->approve($step['handle'], (string) $user->id(), $this->clean($comment));
        $this->save($submission);

        EntryApproved::dispatch($submission, $entry, $user, $step['handle']);

        if ($submission->isLastStep($step['handle'])) {
            $this->publish($entry, $user, $submission);
        }

        return $submission;
    }

    public function reject(User $user, Entry $entry, string $comment): Submission
    {
        if ($reason = $this->whyCannotReview($user, $entry)) {
            throw new SignOffException($reason);
        }

        $comment = $this->clean($comment);

        if ($comment === null) {
            throw new SignOffException(__('sign-off::messages.reason_required'));
        }

        $submission = $this->open($entry);
        $step = $submission->currentStep();
        $submission->reject($step['handle'], (string) $user->id(), $comment);
        $this->save($submission);

        EntryRejected::dispatch($submission, $entry, $user, $step['handle'], $comment);

        return $submission;
    }

    /*
    |--------------------------------------------------------------------------
    | Withdrawing
    |--------------------------------------------------------------------------
    */

    public function whyCannotWithdraw(User $user, Entry $entry): ?string
    {
        $submission = $this->open($entry);

        return match (true) {
            $submission === null => __('sign-off::messages.not_in_review'),
            $submission->submittedBy() !== (string) $user->id() && ! $user->isSuper() => __('sign-off::messages.not_your_submission'),
            default => null,
        };
    }

    public function withdraw(User $user, Entry $entry, ?string $comment = null): Submission
    {
        if ($reason = $this->whyCannotWithdraw($user, $entry)) {
            throw new SignOffException($reason);
        }

        $submission = $this->open($entry)->withdraw((string) $user->id(), $this->clean($comment));
        $this->save($submission);

        SubmissionWithdrawn::dispatch($submission, $entry, $user);

        return $submission;
    }

    /*
    |--------------------------------------------------------------------------
    | Publishing
    |--------------------------------------------------------------------------
    */

    /**
     * Whether the user can publish without sign-off: the "Publish without
     * sign-off" permission, or a super user (unless turned off).
     */
    public function canSkip(User $user): bool
    {
        return $user->hasPermission('publish without sign-off')
            || ($user->isSuper() && config('sign-off.super_users_can_skip', true));
    }

    public function isPublishing(Entry $entry): bool
    {
        return isset(static::$publishing[(string) $entry->id()]);
    }

    /**
     * After the last approval: publish what was signed off, through
     * Statamic's own publish (so it's a revision like any other).
     */
    protected function publish(Entry $entry, User $user, Submission $submission): void
    {
        static::$publishing[(string) $entry->id()] = true;

        try {
            $published = $entry->publish([
                'user' => $user,
                'message' => __('sign-off::messages.revision_message', ['steps' => collect($submission->steps())->pluck('title')->implode(', ')]),
            ]);
        } finally {
            unset(static::$publishing[(string) $entry->id()]);
        }

        if ($published === false) {
            throw new SignOffException(__('sign-off::messages.publish_failed'));
        }

        $submission->markPublished((string) $user->id());
        $this->save($submission);

        EntrySignedOff::dispatch($submission, $published, $user);
    }

    /**
     * Someone allowed to skip sign-off published the entry directly.
     * Recorded, so the entry's history shows it.
     */
    public function recordSkip(Entry $entry, ?User $user, ?string $message = null): void
    {
        if ($open = $this->open($entry)) {
            $open->markOutdated($user ? (string) $user->id() : null);
            $this->save($open);
        }

        $skip = Submission::make((string) $entry->id(), $entry->collectionHandle(), (string) $entry->locale(), $this->rules->steps($entry))
            ->markSkipped($user ? (string) $user->id() : '', $this->clean($message));

        $this->save($skip);
    }

    /**
     * The entry's content changed. If it was in review, its approvals no
     * longer apply: it goes back to the author to submit again.
     */
    public function contentChanged(Entry $entry, ?User $user): void
    {
        if ($this->isPublishing($entry) || ! ($open = $this->open($entry))) {
            return;
        }

        if ($open->fingerprint() !== Fingerprint::of($entry)) {
            $open->markOutdated($user ? (string) $user->id() : null);
            $this->save($open);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function stepFor(Entry $entry, string $handle): ?Step
    {
        return $this->rules->step($entry, $handle);
    }

    /**
     * Whether the user could edit the entry by Statamic's own rules (the
     * review lock aside).
     */
    protected function canEdit(User $user, Entry $entry): bool
    {
        return app(EntryPolicy::class)->edit($user, $entry)
            || $user->isSuper();
    }

    protected function ensureUnchanged(Submission $submission, Entry $entry): void
    {
        if ($submission->fingerprint() !== Fingerprint::of($entry)) {
            $submission->markOutdated(null);
            $this->save($submission);

            throw new SignOffException(__('sign-off::messages.changed_since'));
        }
    }

    protected function save(Submission $submission): void
    {
        $this->submissions->save($submission->touch());
    }

    protected function clean(?string $text): ?string
    {
        $text = trim(preg_replace('/<[^>]*>/', '', (string) $text) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }
}
