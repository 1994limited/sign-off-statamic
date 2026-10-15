<?php

namespace NineteenNinetyFour\SignOff;

use Carbon\CarbonImmutable;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User as Users;

/**
 * What the Sign-off panel on an entry shows: where it is, who's next,
 * why it was sent back, and what happened before, for the person looking.
 */
class Panel
{
    /** @var array<string, string> */
    protected array $names = [];

    public function __construct(protected SignOff $signOff) {}

    public function for(Entry $entry, ?User $viewer): array
    {
        $history = $this->signOff->history($entry);
        $open = $history->first(fn (Submission $submission) => $submission->isOpen());
        $latest = $open ?? $history->first();
        $state = $latest?->state() ?? 'none';

        // A round that was sent back stays the news until the next submission.
        $rejection = $state === Submission::REJECTED ? $this->rejection($latest) : null;

        return [
            'state' => $state,
            'headline' => $this->headline($state, $latest, $entry),
            'steps' => $this->steps($entry, $open),
            'submitted' => $open ? [
                'by' => $this->name($open->submittedBy()),
                'at' => $this->when($open->submittedAt()),
                'note' => $open->note(),
            ] : null,
            'rejection' => $rejection,
            'next' => $viewer ? $this->next($viewer, $entry, $open) : null,
            'history' => $history->take(5)->map(fn (Submission $submission) => $this->round($submission))->values()->all(),
            'more_history' => max(0, $history->count() - 5),
        ];
    }

    protected function headline(string $state, ?Submission $latest, Entry $entry): string
    {
        return match ($state) {
            Submission::IN_REVIEW => __('Waiting for :step', ['step' => $latest->currentStep()['title'] ?? '']),
            Submission::REJECTED => __('Sent back for changes'),
            Submission::WITHDRAWN => __('Taken out of review'),
            Submission::OUTDATED => __('Changed after it was submitted'),
            Submission::PUBLISHED => __('Signed off and published'),
            Submission::SKIPPED => __('Published without sign-off'),
            default => $this->signOff->hasChanges($entry) ? __('Not submitted yet') : __('No changes to sign off'),
        };
    }

    /**
     * Each step with how far it's got: done (by whom), current, or to come.
     */
    protected function steps(Entry $entry, ?Submission $open): array
    {
        $approvals = $open ? $open->history()->where('type', 'approved')->keyBy('step') : collect();
        $current = $open?->currentStep()['handle'] ?? null;
        $steps = $open ? $open->steps() : array_map(fn (Step $step) => $step->toArray(), $this->signOff->rules()->steps($entry));

        return array_map(function (array $step) use ($approvals, $current, $entry) {
            $approval = $approvals->get($step['handle']);

            return [
                'handle' => $step['handle'],
                'title' => $step['title'],
                'status' => match (true) {
                    $approval !== null => 'done',
                    $step['handle'] === $current => 'current',
                    default => 'waiting',
                },
                'by' => $approval ? $this->name($approval['user'] ?? '') : null,
                'at' => $approval ? $this->when(Submission::date($approval['at'] ?? null)) : null,
                'comment' => $approval['comment'] ?? null,
                'approvers' => $this->signOff->stepFor($entry, $step['handle'])?->describeApprovers(),
            ];
        }, $steps);
    }

    protected function rejection(Submission $submission): ?array
    {
        $event = $submission->history()->where('type', 'rejected')->last();

        if (! $event) {
            return null;
        }

        return [
            'by' => $this->name($event['user'] ?? ''),
            'step' => $this->stepTitle($submission, $event['step'] ?? null),
            'at' => $this->when(Submission::date($event['at'] ?? null)),
            'reason' => $event['comment'] ?? null,
        ];
    }

    /**
     * What the viewer can do next, in words. The actions themselves are in
     * the entry's menu.
     */
    protected function next(User $viewer, Entry $entry, ?Submission $open): ?string
    {
        if (! $open) {
            return $this->signOff->whyCannotSubmit($viewer, $entry) === null
                ? __('When it\'s ready, choose Submit for sign-off from the ••• menu.')
                : null;
        }

        if ($this->signOff->canReview($viewer, $entry)) {
            return __('It\'s your turn: Approve or Send back from the ••• menu.');
        }

        if ($open->submittedBy() === (string) $viewer->id()) {
            return __('It can\'t be changed while it\'s in review. To make changes, choose Withdraw from review from the ••• menu.');
        }

        return null;
    }

    protected function round(Submission $submission): array
    {
        return [
            'id' => $submission->id(),
            'state' => $submission->state(),
            'at' => $this->when($submission->submittedAt() ?? $submission->updatedAt()),
            'events' => $submission->history()->map(fn (array $event) => [
                'type' => $event['type'],
                'label' => $this->label($event['type']),
                'step' => $this->stepTitle($submission, $event['step'] ?? null),
                'by' => $this->name($event['user'] ?? ''),
                'at' => $this->when(Submission::date($event['at'] ?? null)),
                'comment' => $event['comment'] ?? null,
            ])->values()->all(),
        ];
    }

    protected function label(string $type): string
    {
        return match ($type) {
            'submitted' => __('Submitted'),
            'approved' => __('Approved'),
            'rejected' => __('Sent back'),
            'withdrawn' => __('Withdrawn'),
            'published' => __('Published'),
            'changed' => __('Changed after submission'),
            'published_without_sign_off' => __('Published without sign-off'),
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    protected function stepTitle(Submission $submission, ?string $handle): ?string
    {
        if ($handle === null) {
            return null;
        }

        return collect($submission->steps())->firstWhere('handle', $handle)['title'] ?? $handle;
    }

    protected function name(string $id): string
    {
        if ($id === '') {
            return __('Someone');
        }

        return $this->names[$id] ??= (function () use ($id) {
            $user = Users::find($id);

            return $user ? ($user->name() ?: $user->email()) : __('A former user');
        })();
    }

    /**
     * @return array{iso: string, human: string, full: string}|null
     */
    protected function when(?CarbonImmutable $date): ?array
    {
        return $date ? [
            'iso' => $date->toIso8601String(),
            'human' => $date->diffForHumans(),
            'full' => $date->format('j M Y, H:i'),
        ] : null;
    }
}
