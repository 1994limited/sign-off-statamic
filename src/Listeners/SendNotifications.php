<?php

namespace NineteenNinetyFour\SignOff\Listeners;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use NineteenNinetyFour\SignOff\Events\EntryApproved;
use NineteenNinetyFour\SignOff\Events\EntryRejected;
use NineteenNinetyFour\SignOff\Events\EntrySignedOff;
use NineteenNinetyFour\SignOff\Events\EntrySubmitted;
use NineteenNinetyFour\SignOff\Notifications\EntrySentBack;
use NineteenNinetyFour\SignOff\Notifications\EntrySignedOff as SignedOffMail;
use NineteenNinetyFour\SignOff\Notifications\ReviewNeeded;
use NineteenNinetyFour\SignOff\Notifications\SignOffNotification;
use NineteenNinetyFour\SignOff\Rules;
use NineteenNinetyFour\SignOff\Submission;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User as Users;
use Throwable;

/**
 * Emails for sign-off (config: sign-off.notifications.mail):
 *
 * - reviewers, when an entry reaches their step;
 * - the person who submitted it, when it's sent back or signed off.
 *
 * Nobody is emailed about something they did themselves. A failed email
 * is logged and never stops the review.
 */
class SendNotifications
{
    public function __construct(protected Rules $rules) {}

    public function submitted(EntrySubmitted $event): void
    {
        $this->askForReview($event->submission, $event->entry, $event->user);
    }

    public function approved(EntryApproved $event): void
    {
        // After the last step there's no one left to ask: it's published,
        // and EntrySignedOff follows.
        if ($event->submission->currentStep() !== null) {
            $this->askForReview($event->submission, $event->entry, $event->user);
        }
    }

    public function rejected(EntryRejected $event): void
    {
        $this->send(
            $this->submitter($event->submission, $event->user),
            new EntrySentBack($this->details($event->submission, $event->entry, $event->user, $event->step, $event->reason)),
        );
    }

    public function signedOff(EntrySignedOff $event): void
    {
        $this->send(
            $this->submitter($event->submission, $event->user),
            new SignedOffMail($this->details($event->submission, $event->entry, $event->user)),
        );
    }

    /**
     * Who can approve the step the entry is at now.
     *
     * @return Collection<int, User>
     */
    public function reviewers(Submission $submission, Entry $entry): Collection
    {
        $step = ($handle = $submission->currentStep()['handle'] ?? null) ? $this->rules->step($entry, $handle) : null;

        if (! $step) {
            return collect();
        }

        $users = Users::all();
        $named = $users->filter(fn (User $user) => $step->names($user, $entry));

        // A step only super users can approve: ask them.
        if ($named->isEmpty()) {
            $named = $users->filter(fn (User $user) => $user->isSuper());
        }

        // The submitter can't review their own entry unless allowed.
        if (! config('sign-off.self_approval', false)) {
            $named = $named->reject(fn (User $user) => (string) $user->id() === $submission->submittedBy() && ! $user->isSuper());
        }

        return $named->values();
    }

    protected function askForReview(Submission $submission, Entry $entry, User $actor): void
    {
        $this->send(
            $this->reviewers($submission, $entry)->reject(fn (User $user) => (string) $user->id() === (string) $actor->id()),
            new ReviewNeeded($this->details($submission, $entry, Users::find($submission->submittedBy()) ?? $actor, $submission->currentStep()['handle'] ?? null, $submission->note())),
        );
    }

    /**
     * @return Collection<int, User>
     */
    protected function submitter(Submission $submission, User $actor): Collection
    {
        $user = Users::find($submission->submittedBy());

        return collect($user && (string) $user->id() !== (string) $actor->id() ? [$user] : []);
    }

    protected function details(Submission $submission, Entry $entry, User $by, ?string $step = null, ?string $comment = null): array
    {
        $names = collect($submission->history())
            ->where('type', 'approved')
            ->map(function (array $event) use ($submission) {
                $user = Users::find((string) ($event['user'] ?? ''));
                $title = collect($submission->steps())->firstWhere('handle', $event['step'] ?? null)['title'] ?? null;

                return trim(($user ? ($user->name() ?: $user->email()) : __('A former user')).($title ? " ({$title})" : ''));
            });

        return [
            'entry' => (string) ($entry->value('title') ?? $entry->slug()),
            'collection' => __($entry->collection()->title()),
            'url' => $entry->editUrl(),
            'by' => $by->name() ?: (string) $by->email(),
            'step' => $step ? (collect($submission->steps())->firstWhere('handle', $step)['title'] ?? $step) : null,
            'comment' => $comment,
            'approvers' => $names->implode(', ') ?: null,
        ];
    }

    protected function send(Collection $users, SignOffNotification $notification): void
    {
        if (! config('sign-off.notifications.mail', true)) {
            return;
        }

        $users = $users->filter(fn (User $user) => filled($user->email()))->values();

        if ($users->isEmpty()) {
            return;
        }

        try {
            Notification::send($users, $notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
