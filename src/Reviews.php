<?php

namespace NineteenNinetyFour\SignOff;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use NineteenNinetyFour\SignOff\Concerns\DescribesSubmissions;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection as Collections;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;

/**
 * What's in review, for one person: what's waiting for them, what was
 * sent back to them, and what has been signed off before. Only entries
 * they can see are included. The review page and the dashboard widget
 * show these.
 */
class Reviews
{
    use DescribesSubmissions;

    public const FINISHED = [
        Submission::PUBLISHED,
        Submission::REJECTED,
        Submission::WITHDRAWN,
        Submission::OUTDATED,
        Submission::SKIPPED,
    ];

    /** @var array<string, Entry|null> */
    protected array $entries = [];

    public function __construct(protected SignOff $signOff) {}

    /**
     * The covered collections the user can see entries in.
     *
     * @return array<int, string>
     */
    public function collectionsFor(User $user): array
    {
        return array_values(array_filter(
            $this->signOff->rules()->collections(),
            fn (string $handle) => ($collection = Collections::find($handle)) && $user->can('view', $collection),
        ));
    }

    /**
     * Entries whose current step the user can approve, longest waiting first.
     *
     * @return Collection<int, Submission>
     */
    public function waitingFor(User $user, ?string $collection = null): Collection
    {
        return $this->inReview($user, $collection)
            ->filter(fn (Submission $submission) => $this->signOff->canReview($user, $this->entry($submission)))
            ->sortBy(fn (Submission $submission) => $submission->waitingSince()?->format('U.u') ?? 0)
            ->values();
    }

    /**
     * Everything in review that the user can see, oldest submission first.
     *
     * @return Collection<int, Submission>
     */
    public function inReview(User $user, ?string $collection = null): Collection
    {
        $collections = $this->scope($user, $collection);

        return $this->signOff->submissions()->open()
            ->filter(fn (Submission $submission) => in_array($submission->collection(), $collections, true))
            ->filter(fn (Submission $submission) => $this->visible($user, $submission))
            ->values();
    }

    /**
     * Finished rounds, newest first.
     */
    public function history(User $user, int $page = 1, ?string $collection = null, int $perPage = 25): LengthAwarePaginator
    {
        return $this->signOff->submissions()->paginate($perPage, $page, self::FINISHED, $this->scope($user, $collection) ?: ['-']);
    }

    /**
     * The user's entries that were sent back, and not resubmitted since.
     *
     * @return Collection<int, Submission>
     */
    public function sentBackTo(User $user, int $limit = 5): Collection
    {
        return $this->signOff->submissions()->submittedBy((string) $user->id(), 50)
            ->filter(fn (Submission $submission) => $submission->state() === Submission::REJECTED)
            ->filter(fn (Submission $submission) => $this->visible($user, $submission))
            ->filter(fn (Submission $submission) => $this->signOff->history($this->entry($submission))->first()?->id() === $submission->id())
            ->take($limit)
            ->values();
    }

    /**
     * The user's own entries that are in review now.
     *
     * @return Collection<int, Submission>
     */
    public function submittedBy(User $user, int $limit = 5): Collection
    {
        return $this->signOff->submissions()->submittedBy((string) $user->id(), 50)
            ->filter(fn (Submission $submission) => $submission->isOpen())
            ->filter(fn (Submission $submission) => $this->visible($user, $submission))
            ->take($limit)
            ->values();
    }

    /**
     * A submission as a row in a list.
     */
    public function row(Submission $submission, User $user): array
    {
        $entry = $this->entry($submission);
        $step = $submission->currentStep();
        $last = $submission->lastEvent();
        $collection = Collections::find($submission->collection());

        return [
            'id' => $submission->id(),
            'state' => $submission->state(),
            'entry' => [
                'title' => $entry ? (string) ($entry->value('title') ?? $entry->slug()) : __('Deleted entry'),
                'edit_url' => $entry?->editUrl(),
                'missing' => $entry === null,
            ],
            'collection' => $collection ? __($collection->title()) : $submission->collection(),
            'site' => Site::multiEnabled() ? Site::get($submission->site())?->name() : null,
            'step' => $step['title'] ?? null,
            'progress' => $step ? __(':done of :total approved', ['done' => count($submission->approvedSteps()), 'total' => count($submission->steps())]) : null,
            'submitted_by' => $this->name($submission->submittedBy()),
            'submitted_at' => $this->when($submission->submittedAt()),
            'waiting_since' => $submission->isOpen() ? $this->when($submission->waitingSince()) : null,
            'note' => $submission->note(),
            'last' => $last ? $this->event($submission, $last) : null,
            'can_review' => $entry && $submission->isOpen() && $this->signOff->canReview($user, $entry),
        ];
    }

    /**
     * @param  iterable<int, Submission>  $submissions
     */
    public function rows(iterable $submissions, User $user): array
    {
        return collect($submissions)->map(fn (Submission $submission) => $this->row($submission, $user))->values()->all();
    }

    /**
     * @return array<int, string>
     */
    protected function scope(User $user, ?string $collection): array
    {
        $collections = $this->collectionsFor($user);

        return $collection === null ? $collections : array_values(array_intersect($collections, [$collection]));
    }

    /**
     * Whether the entry is still there and the user can see it.
     */
    protected function visible(User $user, Submission $submission): bool
    {
        $entry = $this->entry($submission);

        return $entry !== null && $user->can('view', $entry);
    }

    protected function entry(Submission $submission): ?Entry
    {
        return array_key_exists($id = $submission->entryId(), $this->entries)
            ? $this->entries[$id]
            : $this->entries[$id] = Entries::find($id);
    }
}
