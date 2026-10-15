<?php

namespace NineteenNinetyFour\SignOff\Stache;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use NineteenNinetyFour\SignOff\Contracts\SubmissionRepository;
use NineteenNinetyFour\SignOff\Submission;
use Statamic\Facades\Stache;

class StacheSubmissionRepository implements SubmissionRepository
{
    protected function store(): SubmissionsStore
    {
        return Stache::store('sign-off-submissions');
    }

    public function find(string $id): ?Submission
    {
        return $this->store()->getItem($id);
    }

    public function forEntry(string $entryId): Collection
    {
        return $this->where('entry_id', $entryId)
            ->sortByDesc(fn (Submission $submission) => $submission->submittedAt()?->format('U.u') ?? 0)
            ->values();
    }

    public function openFor(string $entryId): ?Submission
    {
        return $this->forEntry($entryId)->first(fn (Submission $submission) => $submission->isOpen());
    }

    public function open(): Collection
    {
        return $this->where('state', Submission::IN_REVIEW)
            ->sortBy(fn (Submission $submission) => $submission->submittedAt()?->format('U.u') ?? 0)
            ->values();
    }

    public function paginate(int $perPage = 25, int $page = 1, array $states = [], array $collections = []): LengthAwarePaginator
    {
        $submissions = $this->all()
            ->when($states, fn ($all) => $all->filter(fn (Submission $submission) => in_array($submission->state(), $states, true)))
            ->when($collections, fn ($all) => $all->filter(fn (Submission $submission) => in_array($submission->collection(), $collections, true)))
            ->sortByDesc(fn (Submission $submission) => $submission->updatedAt()?->format('U.u') ?? 0)
            ->values();

        return new LengthAwarePaginator($submissions->forPage($page, $perPage)->values(), $submissions->count(), $perPage, $page);
    }

    public function submittedBy(string $userId, int $limit = 20): Collection
    {
        return $this->all()
            ->filter(fn (Submission $submission) => $submission->submittedBy() === $userId)
            ->sortByDesc(fn (Submission $submission) => $submission->updatedAt()?->format('U.u') ?? 0)
            ->take($limit)
            ->values();
    }

    public function all(): Collection
    {
        return $this->store()->getItems($this->store()->paths()->keys())->filter()->values();
    }

    public function save(Submission $submission): void
    {
        $this->store()->save($submission);
    }

    public function delete(Submission $submission): void
    {
        $this->store()->delete($submission);
    }

    /**
     * @return Collection<int, Submission>
     */
    protected function where(string $index, string $value): Collection
    {
        $keys = $this->store()->index($index)->items()->filter(fn ($item) => $item === $value)->keys();

        return $this->store()->getItems($keys)->filter()->values();
    }
}
